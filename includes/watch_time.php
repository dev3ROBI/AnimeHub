<?php
/**
 * Watch time tracking.
 *
 * `video_progress.last_position` is where the user stopped — it is NOT how
 * long they watched (skipping, rewinding and resuming all distort it). The
 * player therefore reports *played* seconds, and they are accumulated here.
 *
 * Rows are keyed by (user, anime_slug, episode_number) so an episode can be
 * re-watched and the seconds keep adding up. The same key shape as
 * video_progress keeps "anime:episode" joins straightforward.
 */

include_once __DIR__ . '/../config/config.php';

/** Seconds in one episode are capped at 6h so a stuck player cannot inflate stats. */
const KP_WATCH_TIME_MAX_EPISODE = 21600;

// ─── Storage ───────────────────────────────────────────────────────────

/** Table guard — mirrors the user_settings pattern used elsewhere. */
$GLOBALS['__kp_watch_time_ready'] = null;

function watch_time_ensure() {
    global $pdo;

    if ($GLOBALS['__kp_watch_time_ready'] !== null) {
        return $GLOBALS['__kp_watch_time_ready'];
    }
    if (!$pdo) return $GLOBALS['__kp_watch_time_ready'] = false;

    try {
        $pdo->query('SELECT 1 FROM watch_time LIMIT 1');
        return $GLOBALS['__kp_watch_time_ready'] = true;
    } catch (Exception $e) {
        // Missing table: create it once so the feature works on an install
        // that never ran sql/watch_time_and_reports.sql.
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS watch_time (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    user_id        INT NOT NULL,
                    anime_slug     VARCHAR(191) NOT NULL,
                    episode_number INT NOT NULL DEFAULT 0,
                    seconds        INT NOT NULL DEFAULT 0,
                    duration       INT NOT NULL DEFAULT 0,
                    started_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_watch_time (user_id, anime_slug, episode_number),
                    KEY idx_watch_time_user (user_id, updated_at),
                    KEY idx_watch_time_anime (anime_slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            return $GLOBALS['__kp_watch_time_ready'] = true;
        } catch (Exception $e2) {
            error_log('[watch_time] table unavailable: ' . $e2->getMessage());
            return $GLOBALS['__kp_watch_time_ready'] = false;
        }
    }
}

/**
 * Add played seconds to an episode.
 *
 * @param int    $user_id
 * @param string $anime_slug
 * @param int    $episode      0 for movies/specials
 * @param int    $seconds      played seconds in this heartbeat (added)
 * @param int    $duration     runtime in seconds, 0 to leave unchanged
 * @return bool
 */
function watch_time_add($user_id, $anime_slug, $episode, $seconds, $duration = 0) {
    global $pdo;

    $user_id    = (int)$user_id;
    $episode    = max(0, (int)$episode);
    $seconds    = (int)round((float)$seconds);
    $duration   = max(0, (int)round((float)$duration));
    $anime_slug = trim((string)$anime_slug);

    if (!$user_id || $anime_slug === '' || !watch_time_ensure()) return false;
    if ($seconds <= 0 && $duration <= 0) return false;

    // A single heartbeat should never claim more than a couple of minutes.
    $seconds = min($seconds, 600);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO watch_time (user_id, anime_slug, episode_number, seconds, duration)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                seconds  = LEAST(seconds + VALUES(seconds), " . KP_WATCH_TIME_MAX_EPISODE . "),
                duration = IF(VALUES(duration) > 0, VALUES(duration), duration),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$user_id, $anime_slug, $episode, $seconds, $duration]);
        return true;
    } catch (Exception $e) {
        error_log('[watch_time] save error: ' . $e->getMessage());
        return false;
    }
}

// ─── Reads ─────────────────────────────────────────────────────────────

/** ['seconds' => int, 'duration' => int] for one episode. */
function watch_time_episode($user_id, $anime_slug, $episode) {
    global $pdo;
    if (!$pdo || !$user_id || !watch_time_ensure()) return ['seconds' => 0, 'duration' => 0];

    try {
        $stmt = $pdo->prepare("
            SELECT seconds, duration
            FROM watch_time
            WHERE user_id = ? AND anime_slug = ? AND episode_number = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$user_id, (string)$anime_slug, (int)$episode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['seconds' => 0, 'duration' => 0];

        return ['seconds' => (int)$row['seconds'], 'duration' => (int)$row['duration']];
    } catch (Exception $e) {
        return ['seconds' => 0, 'duration' => 0];
    }
}

/** Totals for the stats tab: seconds, episodes touched, distinct anime. */
function watch_time_totals($user_id) {
    global $pdo;
    $empty = ['seconds' => 0, 'episodes' => 0, 'anime' => 0];
    if (!$pdo || !$user_id || !watch_time_ensure()) return $empty;

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(seconds), 0)                 AS seconds,
                   SUM(seconds > 0)                          AS episodes,
                   COUNT(DISTINCT anime_slug)                AS anime
            FROM watch_time
            WHERE user_id = ?
        ");
        $stmt->execute([(int)$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'seconds'  => (int)($row['seconds'] ?? 0),
            'episodes' => (int)($row['episodes'] ?? 0),
            'anime'    => (int)($row['anime'] ?? 0),
        ];
    } catch (Exception $e) {
        return $empty;
    }
}

/** Seconds watched per day for the last N days: ['2026-09-01' => 3600]. */
function watch_time_daily($user_id, $days = 30) {
    global $pdo;
    if (!$pdo || !$user_id || !watch_time_ensure()) return [];

    $days = max(1, min(365, (int)$days));

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(updated_at) AS day, COALESCE(SUM(seconds), 0) AS seconds
            FROM watch_time
            WHERE user_id = ? AND updated_at >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)
            GROUP BY DATE(updated_at)
            ORDER BY day
        ");
        $stmt->execute([(int)$user_id]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['day']] = (int)$row['seconds'];
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Per-anime totals for every anime the user has watched: slug → [seconds, episodes].
 *
 * One query for a whole page of cards instead of one query per card.
 */
function watch_time_anime_map($user_id) {
    global $pdo;
    if (!$pdo || !$user_id || !watch_time_ensure()) return [];

    try {
        $stmt = $pdo->prepare("
            SELECT anime_slug,
                   COALESCE(SUM(seconds), 0) AS seconds,
                   SUM(seconds > 0)          AS episodes
            FROM watch_time
            WHERE user_id = ?
            GROUP BY anime_slug
        ");
        $stmt->execute([(int)$user_id]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['anime_slug']] = [
                'seconds'  => (int)$row['seconds'],
                'episodes' => (int)$row['episodes'],
            ];
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

/** Most-watched anime by tracked seconds: [['anime_slug','seconds','episodes'], ...]. */
function watch_time_top_anime($user_id, $limit = 8) {
    global $pdo;
    if (!$pdo || !$user_id || !watch_time_ensure()) return [];

    try {
        $stmt = $pdo->prepare("
            SELECT anime_slug,
                   COALESCE(SUM(seconds), 0) AS seconds,
                   SUM(seconds > 0)          AS episodes
            FROM watch_time
            WHERE user_id = ?
            GROUP BY anime_slug
            HAVING seconds > 0
            ORDER BY seconds DESC
            LIMIT " . max(1, min(50, (int)$limit)) . "
        ");
        $stmt->execute([(int)$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Rank system based on total watch time.
 *
 * Returns ['rank' => string, 'title' => string, 'icon' => string, 'color' => string, 'next' => int|null]
 * where 'next' is seconds needed to reach the next rank (null if max rank).
 */
function watch_time_rank($user_id) {
    $totals = watch_time_totals($user_id);
    $seconds = (int)($totals['seconds'] ?? 0);

    // Rank tiers: [min_seconds, rank_name, display_title, icon, color]
    $ranks = [
        [0,           'newbie',      'Newbie',          'fa-solid fa-seedling',         '#8b8f95'],
        [300,         'viewer',      'Viewer',          'fa-solid fa-eye',              '#6c757d'],
        [1800,        'watcher',     'Watcher',         'fa-solid fa-play',             '#0d6efd'],
        [7200,        'bingelord',   'Binge Lord',      'fa-solid fa-fire',             '#fd7e14'],
        [21600,       'otaku',       'Otaku',           'fa-solid fa-star',             '#ffc107'],
        [86400,       'sensei',      'Sensei',          'fa-solid fa-graduation-cap',   '#198754'],
        [259200,      'legend',      'Legendary',       'fa-solid fa-crown',            '#ff2e63'],
        [604800,      'no-life',     'No Life',         'fa-solid fa-skull',            '#9b59b6'],
        [1209600,     'otaku-god',   'Otaku God',       'fa-solid fa-bolt',             '#e74c3c'],
        [2592000,     'weeb-king',   'Weeb King',       'fa-solid fa-trophy',           '#f1c40f'],
    ];

    $current = $ranks[0];
    $nextRank = null;

    foreach ($ranks as $i => $tier) {
        if ($seconds >= $tier[0]) {
            $current = $tier;
            // Next rank threshold
            if (isset($ranks[$i + 1])) {
                $nextRank = $ranks[$i + 1][0] - $seconds;
                if ($nextRank <= 0) $nextRank = null;
            }
        }
    }

    return [
        'rank'  => $current[1],
        'title' => $current[2],
        'icon'  => $current[3],
        'color' => $current[4],
        'next'  => $nextRank,
        'seconds' => $seconds,
    ];
}

/**
 * Full rank ladder for the "View all ranks" popup.
 * Returns every tier with unlocked / current flags based on the user's total.
 */
function watch_time_rank_ladder($user_id) {
    $totals = watch_time_totals($user_id);
    $seconds = (int)($totals['seconds'] ?? 0);

    $ranks = [
        [0,           'newbie',      'Newbie',          'fa-solid fa-seedling',         '#8b8f95'],
        [300,         'viewer',      'Viewer',          'fa-solid fa-eye',              '#6c757d'],
        [1800,        'watcher',     'Watcher',         'fa-solid fa-play',             '#0d6efd'],
        [7200,        'bingelord',   'Binge Lord',      'fa-solid fa-fire',             '#fd7e14'],
        [21600,       'otaku',       'Otaku',           'fa-solid fa-star',             '#ffc107'],
        [86400,       'sensei',      'Sensei',          'fa-solid fa-graduation-cap',   '#198754'],
        [259200,      'legend',      'Legendary',       'fa-solid fa-crown',            '#ff2e63'],
        [604800,      'no-life',     'No Life',         'fa-solid fa-skull',            '#9b59b6'],
        [1209600,     'otaku-god',   'Otaku God',       'fa-solid fa-bolt',             '#e74c3c'],
        [2592000,     'weeb-king',   'Weeb King',       'fa-solid fa-trophy',           '#f1c40f'],
    ];

    $currentKey = 'newbie';
    foreach ($ranks as $tier) {
        if ($seconds >= $tier[0]) $currentKey = $tier[1];
    }

    return array_map(fn($t) => [
        'min'     => (int)$t[0],
        'key'     => $t[1],
        'title'   => $t[2],
        'icon'    => $t[3],
        'color'   => $t[4],
        'unlocked'=> $seconds >= $t[0],
        'current' => $t[1] === $currentKey,
    ], $ranks);
}

/** Seconds watched in the current calendar week (Monday → today). */
function watch_time_this_week($user_id) {
    global $pdo;
    if (!$pdo || !$user_id || !watch_time_ensure()) return 0;

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(seconds), 0)
            FROM watch_time
            WHERE user_id = ? AND YEARWEEK(updated_at, 1) = YEARWEEK(CURDATE(), 1)
        ");
        $stmt->execute([(int)$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
