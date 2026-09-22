<?php
/**
 * Continue Watching feed.
 *
 * Merges two sources:
 *   1. catalogue-backed anime, from watch_history + video_progress
 *   2. locally stored movies/episodes, from video_progress
 *
 * Every row exposes a ready-to-use `url` so the front end does not need to
 * know which provider an entry came from, plus a `next_url` for the follow-up
 * episode when the catalogue knows how long the series is.
 */
session_start();

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

include_once 'db.php';
include_once 'progress.php';
include_once 'catalog.php';

$user_id = $_SESSION['userID'];
$continue_watching = [];

/**
 * Rough "how far through" estimate.
 *
 * Embed players are cross-origin, so the real runtime is not readable. The
 * catalogue duration is used when it exists; otherwise a 24 minute episode is
 * assumed — but never for a movie, where that assumption would show a finished
 * progress bar next to a half-watched film.
 */
function cw_percent($position, $duration_min, $is_movie = false) {
    $position = (float)$position;
    if ($position <= 0) return 0;

    if ((int)$duration_min > 0) {
        $total = (int)$duration_min * 60;
    } elseif ($is_movie) {
        return 0;
    } else {
        $total = 24 * 60;
    }

    return max(0, min(100, (int)round($position / $total * 100)));
}

// ─── 1. Catalogue anime (AniList / ReAnime / Jikan) ────────────────────
// Totals per series power the "12 ep · 4:30:00 watched" line on each card.
$watchTimeMap = watch_time_anime_map($user_id);

try {
    foreach (progress_recent_anime($user_id, 50) as $row) {
        $animeId = $row['anime_slug'] ?? '';
        if ($animeId === '') continue;

        $info = catalog_info($animeId);
        if (!$info) continue;

        // watch_history stores a season-aware number for TMDB TV (see
        // progress_episode_key()), which is the handle every lookup needs;
        // the split gives back the plain "season + episode" for the card.
        $storedEpisode = (int)$row['episode_number'];
        $split         = progress_split_episode_key($animeId, $storedEpisode);
        $episode       = $split['episode'];
        $season        = $split['season'];

        // Resume point from video_progress, runtime + watched seconds from
        // watch_time (the player's own reading of the file).
        $state    = progress_card_seconds($user_id, $animeId, $storedEpisode, $info);
        // Embeds cannot report a position, so the tracked seconds are the only
        // evidence of progress — the card shows the furthest of the two.
        $position = $state['progress'];
        $total    = (int)max($info['episodes'] ?? 0, $info['aired_episodes'] ?? 0);

        // Per-season episode counts, so the next episode rolls into the next
        // season instead of offering "S2 E9" on an eight-episode season.
        $seasonTotal = 0;
        if ($season > 0 && !empty($info['episodes_list'])) {
            foreach ($info['episodes_list'] as $epRow) {
                if ((int)($epRow['season'] ?? 1) === $season) $seasonTotal++;
            }
        }

        $nextEp     = null;
        $nextSeason = $season;
        if ($seasonTotal > 0) {
            if ($episode < $seasonTotal) {
                $nextEp = $episode + 1;
            } else {
                foreach ($info['episodes_list'] as $epRow) {
                    if ((int)($epRow['season'] ?? 1) === $season + 1 && (int)($epRow['number'] ?? 0) === 1) {
                        $nextEp     = 1;
                        $nextSeason = $season + 1;
                        break;
                    }
                }
            }
        } elseif ($total === 0 || $episode < $total) {
            $nextEp = $episode + 1;
        }

        $continue_watching[] = [
            'type'              => 'anime',
            'provider'          => $info['provider'] ?? null,
            'video_id'          => $animeId,
            'url'               => './watch.php?id=' . urlencode($animeId)
                                    . ($season > 0 ? '&season=' . $season : '')
                                    . '&ep=' . $episode,
            'next_url'          => $nextEp
                ? './watch.php?id=' . urlencode($animeId)
                    . ($nextSeason > 0 ? '&season=' . $nextSeason : '')
                    . '&ep=' . $nextEp
                : null,
            'title'             => $info['title'] ?? 'Unknown',
            'poster'            => $info['poster'] ?? './uploads/thumbnails/default.png',
            'banner'            => $info['banner'] ?? '',
            'imdb_rating'       => $info['score'] ?? 'N/A',
            'format'            => $info['format'] ?? '',
            'genres'            => array_slice((array)($info['genres'] ?? []), 0, 3),
            'episode_number'    => $episode,
            'next_episode'      => $nextEp,
            'total_episodes'    => $total,
            'season_number'     => $season > 0 ? $season : null,
            'season_id'         => null,
            'episode_id'        => null,
            'show_imdb_id'      => $animeId,
            'last_position'     => progress_format_time($position),
            'position_seconds'  => $position,
            'runtime'           => progress_format_time($state['duration']),
            'runtime_seconds'   => $state['duration'],
            'watched_seconds'   => $state['watched'],
            'watched_time'      => progress_format_time($state['watched']),
            'watched_total'     => (int)($watchTimeMap[$animeId]['seconds'] ?? 0),
            'watched_total_time'=> progress_format_time((int)($watchTimeMap[$animeId]['seconds'] ?? 0)),
            'watched_episodes'  => (int)($watchTimeMap[$animeId]['episodes'] ?? 0),
            'percent'           => $state['percent'],
            'updated_at'        => $row['watched_at'] ?? null,
        ];
    }
} catch (Exception $e) {
    error_log('Continue watching (catalogue) failed: ' . $e->getMessage());
}

// ─── 2. Locally stored movies / episodes ───────────────────────────────
$sql = "
    (
        SELECT
            vp.video_id,
            vp.last_position,
            vp.updated_at,
            m.name AS title,
            m.imdb_poster AS poster,
            NULL AS season_number,
            NULL AS episode_number,
            'movie' AS type,
            NULL AS show_imdb_id,
            NULL AS episode_id,
            NULL AS season_id,
            m.imdb_rating AS imdb_rating
        FROM video_progress vp
        JOIN movies m ON vp.video_id = m.imdb_id
        WHERE vp.user_id = ?
    )
    UNION ALL
    (
        SELECT
            vp.video_id,
            vp.last_position,
            vp.updated_at,
            s.title AS title,
            s.imdb_poster AS poster,
            se.season_number,
            e.episode_number,
            'episode' AS type,
            s.imdb_id AS show_imdb_id,
            e.id AS episode_id,
            se.id AS season_id,
            s.imdb_rating AS imdb_rating
        FROM video_progress vp
        JOIN episodes e ON vp.video_id = e.id
        JOIN seasons se ON e.season_id = se.id
        JOIN shows s ON se.show_id = s.id
        JOIN (
            SELECT e2.season_id, MAX(vp2.updated_at) AS latest_update
            FROM video_progress vp2
            JOIN episodes e2 ON vp2.video_id = e2.id
            WHERE vp2.user_id = ?
            GROUP BY e2.season_id
        ) latest ON latest.season_id = se.id AND vp.updated_at = latest.latest_update
        WHERE vp.user_id = ?
    )
    ORDER BY updated_at DESC
    LIMIT 10
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $seconds = (float)($row['last_position'] ?? 0);
        $row['position_seconds'] = $seconds;
        $row['last_position'] = gmdate("H:i:s", (int)$seconds);
        $row['total_episodes'] = null;
        $row['provider'] = 'local';
        $row['percent'] = cw_percent($seconds, 0, $row['type'] === 'movie');
        $row['banner'] = '';
        $row['format'] = '';
        $row['genres'] = [];
        $row['next_episode'] = null;
        $row['next_url'] = null;
        if (empty($row['poster'])) $row['poster'] = './uploads/thumbnails/default.png';

        if ($row['type'] === 'movie') {
            $row['url'] = './watch.php?id=' . urlencode($row['video_id']);
        } else {
            $row['url'] = './watch.php?id=' . urlencode((string)$row['show_imdb_id'])
                        . '&season=' . urlencode((string)$row['season_id'])
                        . '&episode=' . urlencode((string)$row['episode_id']);

            // Offer the following episode when the season has one.
            try {
                $nq = $conn->prepare(
                    "SELECT e.id, e.episode_number FROM episodes e
                      WHERE e.season_id = ? AND e.episode_number > ?
                   ORDER BY e.episode_number ASC LIMIT 1"
                );
                $nq->bind_param("ii", $row['season_id'], $row['episode_number']);
                $nq->execute();
                if ($next = $nq->get_result()->fetch_assoc()) {
                    $row['next_episode'] = (int)$next['episode_number'];
                    $row['next_url'] = './watch.php?id=' . urlencode((string)$row['show_imdb_id'])
                                     . '&season=' . urlencode((string)$row['season_id'])
                                     . '&episode=' . urlencode((string)$next['id']);
                }
            } catch (Throwable $e) {
                // no next episode — fine
            }
        }

        $continue_watching[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log('Continue watching (local) failed: ' . $e->getMessage());
}

// Newest first, capped.
usort($continue_watching, function ($a, $b) {
    return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
});

header('Content-Type: application/json');
echo json_encode(array_slice($continue_watching, 0, 50));
