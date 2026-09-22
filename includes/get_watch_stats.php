<?php
/**
 * Watch statistics for the profile → Stats tab.
 *
 * Two different questions, two different tables:
 *
 *   watch_history  — which episodes were opened (episode/anime counts)
 *   watch_time     — how many seconds were actually played (hours, heatmap,
 *                    per-anime ranking)
 *
 * Animation on the page is driven entirely by the JSON below, so the numbers
 * here are the single source of truth.
 *
 * Returns:
 *   total_episodes, total_anime   from watch_history (fallback: watch_time)
 *   total_hours, total_seconds    real watch time
 *   watched_episodes              episodes with tracked seconds
 *   week_seconds, avg_episode_seconds
 *   heatmap                       { 'YYYY-MM-DD': seconds } for 30 days
 *   heatmap_episodes              { 'YYYY-MM-DD': episode count }
 *   top_anime                     [{ slug, title, poster, seconds, time, episodes }]
 *   top_genres                    [{ name, seconds, episodes, share }], time weighted
 *   weekly_pattern, weekly_unit   weekday buckets + the unit they are in
 */
session_start();

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/progress.php';
include_once __DIR__ . '/watch_time.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['userID'];

// ─── Episode / anime counts (what was opened) ─────────────────────────
$totalEpisodes = 0;
$totalAnime    = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT CONCAT(anime_slug, ':', episode_number)) AS episodes,
               COUNT(DISTINCT anime_slug)                             AS anime
        FROM watch_history
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalEpisodes = (int)($row['episodes'] ?? 0);
    $totalAnime    = (int)($row['anime'] ?? 0);
} catch (Exception $e) {
    $totalEpisodes = 0;
    $totalAnime    = 0;
}

// ─── Watch time (what was actually played) ────────────────────────────
$timeTotals = watch_time_totals($user_id);
$totalSeconds = (int)$timeTotals['seconds'];

if ($totalSeconds <= 0) {
    // Before watch_time existed, only the resume position was stored, so use it
    // as a last-resort estimate rather than showing 0 hours.
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(last_position), 0) FROM video_progress WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $totalSeconds = (int)round((float)$stmt->fetchColumn());
    } catch (Exception $e) {
        $totalSeconds = 0;
    }
}

// Episode/anime counts fall back to the tracked table when history is empty.
if ($totalEpisodes === 0 && $timeTotals['episodes'] > 0) {
    $totalEpisodes = (int)$timeTotals['episodes'];
}
if ($totalAnime === 0 && $timeTotals['anime'] > 0) {
    $totalAnime = (int)$timeTotals['anime'];
}

$weekSeconds = watch_time_this_week($user_id);
$avgEpisodeSeconds = $timeTotals['episodes'] > 0
    ? (int)round($totalSeconds / $timeTotals['episodes'])
    : 0;

// ─── Heatmap: seconds watched per day, last 30 days ───────────────────
$dailySeconds = watch_time_daily($user_id, 30);
$heatmap = [];
$heatmapEpisodes = [];
foreach ($dailySeconds as $day => $seconds) {
    $heatmap[$day] = (int)$seconds;
}

// Episode counts per day come from the history table (it records every open).
try {
    $stmt = $pdo->prepare("
        SELECT DATE(watched_at) AS day, COUNT(*) AS count
        FROM watch_history
        WHERE user_id = ? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(watched_at)
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $heatmapEpisodes[(string)$row['day']] = (int)$row['count'];
    }
} catch (Exception $e) {
    $heatmapEpisodes = [];
}

// ─── Most-watched anime (needs catalogue titles/posters) ──────────────
$topAnime = [];
foreach (watch_time_top_anime($user_id, 6) as $row) {
    $slug = (string)($row['anime_slug'] ?? '');
    if ($slug === '') continue;

    $seconds = (int)($row['seconds'] ?? 0);
    if ($seconds <= 0) continue;

    $title  = $slug;
    $poster = '';
    $info   = catalog_info($slug);
    if ($info) {
        $title  = $info['title'] ?: $slug;
        $poster = $info['poster'] ?: '';
    }

    $topAnime[] = [
        'slug'     => $slug,
        'title'    => $title,
        'poster'   => $poster,
        'seconds'  => $seconds,
        'time'     => progress_format_time($seconds),
        'episodes' => (int)($row['episodes'] ?? 0),
        'url'      => './watch.php?id=' . urlencode($slug),
    ];
}

// ─── Most watched genres (weighted by real watch time) ───────────────
//
// Counting history rows ranks whatever has the most episodes; the seconds
// table says what was actually watched. Only the titles holding most of the
// time are resolved — catalogue lookups are cache-first, but capping the
// sample keeps this a bounded number of them however long the history is.
$topGenres = [];
$genreBasis = 0;
$genreByTime = $totalSeconds > 0;
try {
    $weights = watch_time_anime_map($user_id); // slug => [seconds, episodes]

    // Titles opened but not tracked yet (fresh accounts have history first).
    $stmt = $pdo->prepare("
        SELECT anime_slug, COUNT(*) AS episodes
          FROM watch_history
         WHERE user_id = ?
         GROUP BY anime_slug
         ORDER BY MAX(watched_at) DESC
         LIMIT 12
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string)($row['anime_slug'] ?? '');
        if ($slug === '' || isset($weights[$slug])) continue;
        $weights[$slug] = ['seconds' => 0, 'episodes' => (int)($row['episodes'] ?? 0)];
    }

    // Heaviest first, then only the top handful reach the catalogue.
    uasort($weights, static fn($a, $b) => ($b['seconds'] ?: $b['episodes']) <=> ($a['seconds'] ?: $a['episodes']));
    $sample = array_slice($weights, 0, 12, true);

    $genreSeconds  = [];
    $genreEpisodes = [];
    foreach ($sample as $slug => $agg) {
        // Legacy rows have episodes but no tracked seconds yet.
        $weight = $genreByTime ? (float)$agg['seconds'] : (float)$agg['episodes'];
        if ($weight <= 0) continue;

        $info = catalog_info($slug);
        if (!$info) continue;

        $genres = array_values(array_unique(array_filter(array_map('trim', (array)($info['genres'] ?? [])))));
        if (!$genres) continue;

        $genreBasis++;
        foreach ($genres as $g) {
            // A title's weight is split across its genres, so the totals still
            // add up to what was watched instead of double counting it.
            $genreSeconds[$g]  = ($genreSeconds[$g] ?? 0) + ($weight / count($genres));
            $genreEpisodes[$g] = ($genreEpisodes[$g] ?? 0) + (int)$agg['episodes'];
        }
    }

    arsort($genreSeconds);
    $topWeight = $genreSeconds ? max($genreSeconds) : 0;
    foreach (array_slice($genreSeconds, 0, 8, true) as $name => $weight) {
        $topGenres[] = [
            'name'     => $name,
            'seconds'  => (int)round($genreByTime ? $weight : 0),
            'episodes' => (int)($genreEpisodes[$name] ?? 0),
            'share'    => $topWeight > 0 ? (int)round($weight / $topWeight * 100) : 0,
        ];
    }
} catch (Exception $e) {
    $topGenres = [];
}

// ─── Weekly pattern: which day of the week gets the most watching ────
$weeklyPattern = [];
// The unit follows whichever table answered. It used to be guessed from the
// 30-day heatmap window, which called an account that watched last month an
// "episodes" account even though its seconds were right there.
$weeklyUnit = 'seconds';
try {
    $stmt = $pdo->prepare("
        SELECT DAYNAME(updated_at) AS day_name, COALESCE(SUM(seconds), 0) AS seconds
        FROM watch_time
        WHERE user_id = ? AND seconds > 0
        GROUP BY DAYNAME(updated_at), DAYOFWEEK(updated_at)
        ORDER BY DAYOFWEEK(updated_at)
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $weeklyPattern[$row['day_name']] = (int)$row['seconds'];
    }
} catch (Exception $e) {
    $weeklyPattern = [];
}

// Older accounts have history but no tracked seconds yet.
if (empty($weeklyPattern)) {
    try {
        $stmt = $pdo->prepare("
            SELECT DAYNAME(watched_at) AS day_name, COUNT(*) AS count
            FROM watch_history
            WHERE user_id = ? AND watched_at IS NOT NULL
            GROUP BY DAYNAME(watched_at), DAYOFWEEK(watched_at)
            ORDER BY DAYOFWEEK(watched_at)
        ");
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $weeklyPattern[$row['day_name']] = (int)$row['count'];
        }
        if (!empty($weeklyPattern)) $weeklyUnit = 'episodes';
    } catch (Exception $e) {
        // keep it empty
    }
}

echo json_encode([
    'total_episodes'       => $totalEpisodes,
    'total_anime'          => $totalAnime,
    'total_hours'          => round($totalSeconds / 3600, 1),
    'total_seconds'        => $totalSeconds,
    'total_time'           => progress_format_time($totalSeconds),
    'watched_episodes'     => (int)$timeTotals['episodes'],
    'week_seconds'         => $weekSeconds,
    'week_time'            => progress_format_time($weekSeconds),
    'avg_episode_seconds'  => $avgEpisodeSeconds,
    'avg_episode_time'     => progress_format_time($avgEpisodeSeconds),
    'heatmap'              => $heatmap,
    'heatmap_episodes'     => $heatmapEpisodes,
    'top_anime'            => $topAnime,
    'top_genres'           => $topGenres,
    'top_genre_basis'      => $genreBasis,
    'weekly_pattern'       => $weeklyPattern,
    'weekly_unit'          => $weeklyUnit,
]);
