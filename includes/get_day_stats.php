<?php
/**
 * One day of watch activity — the detail behind a heatmap cell.
 *
 * `watch_time` says how long was played (and per title), `watch_history` says
 * which episodes were opened, so both are read and merged: the totals come
 * from the seconds table and the episode labels from the history.
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

$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'A date is required (YYYY-MM-DD)']);
    exit;
}

$dayTs = strtotime($date . ' 00:00:00');
// Bounds: never the future, and no further back than the heatmap can reach.
if ($dayTs === false || $dayTs > time() + 86400 || $dayTs < time() - 400 * 86400) {
    http_response_code(400);
    echo json_encode(['error' => 'Date out of range']);
    exit;
}

// ─── Totals for the day ───────────────────────────────────────────────
$totals = ['seconds' => 0, 'sessions' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(seconds), 0) AS seconds,
               SUM(seconds > 0)          AS sessions
          FROM watch_time
         WHERE user_id = ? AND DATE(updated_at) = ?
    ");
    $stmt->execute([$user_id, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totals['seconds']  = (int)($row['seconds'] ?? 0);
    $totals['sessions'] = (int)($row['sessions'] ?? 0);
} catch (Exception $e) {
    // Older installs may not have watch_time yet — history still has the day.
}

// Episodes opened that day (history records every open).
$episodesOpened = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id = ? AND DATE(watched_at) = ?");
    $stmt->execute([$user_id, $date]);
    $episodesOpened = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $episodesOpened = 0;
}

// ─── Per-title breakdown ──────────────────────────────────────────────
$items = [];
try {
    $stmt = $pdo->prepare("
        SELECT anime_slug,
               COALESCE(SUM(seconds), 0) AS seconds,
               SUM(seconds > 0)          AS episodes
          FROM watch_time
         WHERE user_id = ? AND DATE(updated_at) = ? AND seconds > 0
         GROUP BY anime_slug
         ORDER BY seconds DESC
         LIMIT 12
    ");
    $stmt->execute([$user_id, $date]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $items = [];
}

// Episode labels per title (the season-aware stored numbers, unfolded).
$labels = [];
try {
    $stmt = $pdo->prepare("
        SELECT anime_slug, episode_number
          FROM watch_history
         WHERE user_id = ? AND DATE(watched_at) = ?
         ORDER BY watched_at ASC
    ");
    $stmt->execute([$user_id, $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string)($row['anime_slug'] ?? '');
        $ep   = (int)($row['episode_number'] ?? 0);
        if ($slug === '' || $ep <= 0) continue;

        $split = progress_split_episode_key($slug, $ep);
        $label = $split['season'] > 0 ? 'S' . $split['season'] . ' E' . $split['episode'] : 'Ep ' . $split['episode'];

        if (!isset($labels[$slug])) $labels[$slug] = [];
        if (!in_array($label, $labels[$slug], true)) $labels[$slug][] = $label;
    }
} catch (Exception $e) {
    $labels = [];
}

$out = [];
foreach ($items as $row) {
    $slug    = (string)($row['anime_slug'] ?? '');
    $seconds = (int)($row['seconds'] ?? 0);
    if ($slug === '' || $seconds <= 0) continue;

    $title  = $slug;
    $poster = '';
    $info   = catalog_info($slug);
    if ($info) {
        $title  = $info['title'] ?: $slug;
        $poster = $info['poster'] ?: '';
    }

    $slugs = $labels[$slug] ?? [];

    $out[] = [
        'slug'     => $slug,
        'title'    => $title,
        'poster'   => $poster,
        'seconds'  => $seconds,
        'time'     => progress_format_time($seconds),
        'episodes' => (int)($row['episodes'] ?? 0),
        'labels'   => array_slice($slugs, 0, 8),
        'more'     => max(0, count($slugs) - 8),
        'url'      => './watch.php?id=' . urlencode($slug),
    ];
}

echo json_encode([
    'date'      => $date,
    'label'     => date('D, M j, Y', $dayTs),
    'weekday'   => date('l', $dayTs),
    'is_today'  => $date === date('Y-m-d'),
    'seconds'   => (int)$totals['seconds'],
    'time'      => progress_format_time((int)$totals['seconds']),
    'sessions'  => (int)$totals['sessions'],
    'episodes'  => $episodesOpened,
    'titles'    => count($out),
    'items'     => $out,
]);
