<?php
/**
 * Notification engine + feed.
 *
 * Runs the alerts the user asked for (episode drops for everything they are
 * watching or following, plus badge/rank/welcome notes) and then returns the
 * page of notifications the UI renders. Generation is idempotent — every row
 * is written through kp_notify_once(), which relies on the table's
 * (user_id, anime_slug, episode) unique key — so this can safely run on every
 * page load and every poll.
 *
 * What counts as "watching":
 *   - finished an episode recently              (watch_history)
 *   - followed on any anime page                (follows)
 *   - marked Watching in the watch list         (watchlist.status = 'watching')
 *
 * Which preference gates what:
 *   - "Episode alerts"  → shows we watch / have in progress
 *   - "Follow alerts"   → shows only followed (a follow is an explicit opt-in)
 *   - "System alerts"   → welcome, badge unlocks, rank-ups
 */
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/anilist_api.php';
include_once __DIR__ . '/progress.php';
include_once __DIR__ . '/notify.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['notifications' => [], 'unread' => 0]);
    exit;
}

$user_id = (int)$_SESSION['userID'];

try {
    // Auto-migrate: ensure new columns exist
    $cols = $pdo->query("SHOW COLUMNS FROM `notifications`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notification_type', $cols)) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `notification_type` VARCHAR(30) NOT NULL DEFAULT 'episode' AFTER `user_id`");
    }
    if (!in_array('expires_at', $cols)) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `created_at`");
    }
    if (!in_array('uniq_notif_entry', array_column($pdo->query("SHOW INDEX FROM `notifications`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name'))) {
        try { $pdo->exec("ALTER TABLE `notifications` ADD UNIQUE INDEX `uniq_notif_entry` (`user_id`, `anime_slug`, `episode`)"); } catch (Exception $e) {}
    }

    // Follows live in their own table, and installs that predate the Follow
    // button simply do not have it — creating it here keeps follow alerts (and
    // save_follow.php) working instead of failing silently.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `follows` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT          NOT NULL,
        `anime_slug`   VARCHAR(255) NOT NULL,
        `anime_title`  VARCHAR(255) NOT NULL DEFAULT '',
        `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE INDEX `unique_follow` (`user_id`, `anime_slug`),
        INDEX `idx_follow_user` (`user_id`),
        INDEX `idx_follow_slug` (`anime_slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure notification_settings table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `notification_settings` (
        `user_id`          INT UNSIGNED NOT NULL PRIMARY KEY,
        `episode_alerts`   TINYINT(1)   NOT NULL DEFAULT 1,
        `follow_alerts`    TINYINT(1)   NOT NULL DEFAULT 1,
        `system_alerts`    TINYINT(1)   NOT NULL DEFAULT 1,
        `sound_enabled`    TINYINT(1)   NOT NULL DEFAULT 0,
        `toast_enabled`    TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Migration issues — continue with what we have
}

$type_filter  = $_GET['type'] ?? '';
$unread_only  = !empty($_GET['unread']);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// Cleanup expired notifications
try {
    $pdo->prepare("DELETE FROM notifications WHERE expires_at IS NOT NULL AND expires_at < NOW()")->execute();
} catch (Exception $e) {}

// Load user preferences
$prefs = ['episode_alerts' => 1, 'follow_alerts' => 1, 'system_alerts' => 1];
try {
    $pstmt = $pdo->prepare("SELECT episode_alerts, follow_alerts, system_alerts FROM notification_settings WHERE user_id = ?");
    $pstmt->execute([$user_id]);
    $prefRow = $pstmt->fetch(PDO::FETCH_ASSOC);
    if ($prefRow) {
        $prefs = $prefRow;
    }
} catch (Exception $e) {}

// ─── Sweep throttle ───────────────────────────────────────────────────
//
// This endpoint runs on page load and on every poll, and two of the sweeps
// below talk to providers (one call per show/title). A new episode is not
// time-critical to the minute, so each sweep keeps its own per-session stamp
// and an idle poll costs nothing.
$nowTs = time();
$sweepDue = function (string $key, int $seconds) use ($nowTs): bool {
    $last = (int)($_SESSION[$key] ?? 0);
    if ($nowTs - $last < $seconds) return false;
    $_SESSION[$key] = $nowTs;
    return true;
};

// ─── What the user is watching ────────────────────────────────────────
//
// One entry per title, tagged with which trigger put it there, because the
// three sources answer to two different preferences.
$tracked = [];

$track = function (string $slug, string $via, int $lastEp = 0, string $title = '', int $activity = 0) use (&$tracked) {
    if ($slug === '') return;
    if (!isset($tracked[$slug])) {
        $tracked[$slug] = [
            'slug'     => $slug,
            'kind'     => progress_is_tv_slug($slug) ? 'tv' : 'anilist',
            'remote'   => 0,
            'last_ep'  => 0,
            'title'    => '',
            'episode'  => false,
            'follow'   => false,
            // When the user last touched the title; used to pick which shows
            // are worth asking the provider about (see the TV section).
            'activity' => 0,
        ];
        // "tmdb:tv:224263" / "anilist:123" — the id is the last segment.
        $bits = explode(':', $slug);
        $last = end($bits);
        $tracked[$slug]['remote'] = is_numeric($last) ? (int)$last : 0;
    }

    $tracked[$slug][$via] = true;
    if ($lastEp > (int)$tracked[$slug]['last_ep']) $tracked[$slug]['last_ep'] = $lastEp;
    if ($title !== '' && $tracked[$slug]['title'] === '') $tracked[$slug]['title'] = $title;
    if ($activity > (int)$tracked[$slug]['activity']) $tracked[$slug]['activity'] = $activity;
};

// 1. Watched recently — the strongest "I am watching this" signal.
foreach (progress_recent_anime($user_id, 50) as $r) {
    $slug = (string)($r['anime_slug'] ?? '');
    $ep   = (int)($r['episode_number'] ?? 0);
    if ($slug === '' || $ep <= 0) continue;

    $parts = explode(':', $slug, 2);
    if ($parts[0] !== 'anilist' && !progress_is_tv_slug($slug)) continue;

    $track($slug, 'episode', $ep);
}

// Highest stored episode per title, so a title that is only on the watch list
// still knows where the user left off — plus when it was last touched.
try {
    $stmt = $pdo->prepare("SELECT anime_slug, MAX(episode_number) AS last_ep, MAX(watched_at) AS last_at FROM watch_history WHERE user_id = ? GROUP BY anime_slug");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string)($row['anime_slug'] ?? '');
        if ($slug === '' || !isset($tracked[$slug])) continue;

        $last = (int)($row['last_ep'] ?? 0);
        if ($last > (int)$tracked[$slug]['last_ep']) $tracked[$slug]['last_ep'] = $last;

        $at = strtotime((string)($row['last_at'] ?? '')) ?: 0;
        if ($at > (int)$tracked[$slug]['activity']) $tracked[$slug]['activity'] = $at;
    }
} catch (Exception $e) {}

// 2. Followed titles (an explicit opt-in, so they answer to Follow alerts).
//    Any provider slug is accepted: anime follows use "anilist:N" and TMDB
//    TV follows use "tmdb:tv:N" — that is what lets a TV follow produce
//    episode alerts even when the user never played an episode.
try {
    $fstmt = $pdo->prepare("SELECT anime_slug, anime_title FROM follows WHERE user_id = ?");
    $fstmt->execute([$user_id]);
    foreach ($fstmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $slug = (string)($f['anime_slug'] ?? '');
        if (!preg_match('/^anilist:\d+$/', $slug) && !preg_match('/^tmdb:tv:\d+$/', $slug)) continue;
        $track($slug, 'follow', 0, (string)($f['anime_title'] ?? ''));
    }
} catch (Exception $e) {}

// 3. Watch list rows marked Watching — a new episode of a show in progress is
//    exactly what the "in progress" status is asking to hear about.
try {
    $wstmt = $pdo->prepare("SELECT imdb_id, updated_at FROM watchlist WHERE user_id = ? AND status = 'watching'");
    $wstmt->execute([$user_id]);
    foreach ($wstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string)($row['imdb_id'] ?? '');
        if ($slug === '') continue;
        if (!preg_match('/^anilist:\d+$/', $slug) && !progress_is_tv_slug($slug)) continue;
        $track($slug, 'follow', 0, '', (int)(strtotime((string)($row['updated_at'] ?? '')) ?: 0));
    }
} catch (Exception $e) {}

/** Does any of a title's sources have its alerts switched on? */
$alertsOn = function (array $t) use ($prefs): bool {
    if (!empty($t['episode']) && !empty($prefs['episode_alerts'])) return true;
    if (!empty($t['follow'])  && !empty($prefs['follow_alerts']))  return true;
    return false;
};

// ─── Episode alerts: anime (AniList airing schedule) ──────────────────
if (!empty($tracked)) {
    $now = time();

    // The schedule cache is keyed by the exact window, so passing time() meant a
    // fresh key every second and a full page-by-page refetch on every poll.
    // Rounding down to the hour keeps the answer (episodes still to air, plus
    // the hour in progress) while letting the cache do its job.
    $fromTs = $now - ($now % 3600);
    $toTs   = $fromTs + 7 * 86400;

    try {
        $schedule = anilist_schedule($fromTs, $toTs, 'Asia/Dhaka');
    } catch (Exception $e) {
        $schedule = [];
    }

    $airingMap = [];
    if (is_array($schedule)) {
        foreach ($schedule as $day) {
            foreach (($day['episodes'] ?? []) as $ep) {
                $id = (int)($ep['anilist_id'] ?? 0);
                if ($id > 0) {
                    $airingMap[$id] = [
                        'episode'  => (int)($ep['episode'] ?? 0),
                        'title'    => $ep['title'] ?? '',
                        'time'     => $ep['airing_at'] ?? '',
                        'airingAt' => (int)($ep['airingAt'] ?? 0),
                    ];
                }
            }
        }
    }

    // Cleanup premature notifications: delete any notification for an episode that hasn't aired yet
    try {
        $existingNotifs = $pdo->prepare("SELECT id, anime_slug, episode FROM notifications WHERE user_id = ?");
        $existingNotifs->execute([$user_id]);
        foreach ($existingNotifs->fetchAll(PDO::FETCH_ASSOC) as $en) {
            $parts = explode(':', $en['anime_slug'], 2);
            if (count($parts) === 2 && $parts[0] === 'anilist') {
                $checkId = (int)$parts[1];
                $checkEp = (int)$en['episode'];
                // If this episode is in the upcoming schedule and hasn't aired yet, delete the premature notification
                if (isset($airingMap[$checkId]) && $airingMap[$checkId]['episode'] === $checkEp) {
                    $airingAt = (int)($airingMap[$checkId]['airingAt'] ?? 0);
                    if ($airingAt > time()) {
                        $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$en['id']]);
                    }
                }
            }
        }
    } catch (Exception $e) {}

    foreach ($tracked as $info) {
        if ($info['kind'] !== 'anilist') continue;
        if (!$alertsOn($info)) continue;

        $anilistId = (int)$info['remote'];
        if (!isset($airingMap[$anilistId])) continue;

        $next = $airingMap[$anilistId];
        $hasAired = !empty($next['airingAt']) && $next['airingAt'] <= time();
        if ($next['episode'] <= (int)$info['last_ep'] || !$hasAired) continue;

        $title = $info['title'] ?: ($next['title'] ?: $anilistId);
        $name  = trim((string)($next['title'] ?? ''));
        $msg   = 'Episode ' . $next['episode'] . ' is out' . ($name !== '' && $name !== $title ? ' — ' . $name : '') . '!';

        kp_notify_once($user_id, 'episode', $title, $info['slug'], $next['episode'], $msg, 14);
    }
}

// ─── Episode alerts: TV series (TMDB, season aware) ───────────────────
//
// Only the dozen most recently active shows are asked about: each one is a
// (cached) provider call, and a show the user has not touched in months is not
// something a "new episode" alert should chase.
$tvTracked = array_filter($tracked, static fn($t) => $t['kind'] === 'tv' && !empty($t['remote']));
if (!empty($tvTracked)) {
    uasort($tvTracked, static fn($a, $b) => $b['activity'] <=> $a['activity']);
    $tvTracked = array_slice($tvTracked, 0, 12, true);
}
if (!empty($tvTracked) && $sweepDue('kp_tv_sweep_at', 300)) {
    include_once __DIR__ . '/tmdb_movie_api.php';

    // "New episode" means new: an episode that aired ages ago is not news, it
    // is just the last one of a show the user drifted away from.
    $freshWindow = 30 * 86400;

    foreach ($tvTracked as $info) {
        if (!$alertsOn($info)) continue;

        try {
            $detail = tmdb_tv_detail($info['remote']);
        } catch (Throwable $e) {
            $detail = null;
        }
        if (!is_array($detail)) continue;

        // Remember the title so later sweeps (and the feed rows) show a name
        // even when the follow row was saved without one.
        if ($info['title'] === '' && !empty($detail['title'])) {
            try {
                $pdo->prepare("UPDATE follows SET anime_title = ? WHERE user_id = ? AND anime_slug = ?")
                    ->execute([(string)$detail['title'], $user_id, $info['slug']]);
            } catch (Exception $e) {}
        }

        // TMDB reports the newest episode it knows about; anything with a
        // future air date is not out yet and must not be announced.
        $latest = $detail['last_episode_to_air'] ?? null;
        if (!is_array($latest)) continue;

        $season = (int)($latest['season_number'] ?? 0);
        $epNo   = (int)($latest['episode_number'] ?? 0);
        $airDate = (string)($latest['air_date'] ?? '');
        if ($season <= 0 || $epNo <= 0) continue;

        $airedAt = $airDate !== '' ? strtotime($airDate) : false;
        if ($airedAt !== false) {
            if ($airedAt > time()) continue;                       // not out yet
            if ($airedAt < time() - $freshWindow) continue;         // old news
        }

        // Stored the same way the player stores it, so the link and the
        // resume position land on the same episode.
        $key = progress_tv_episode_key($season, $epNo);
        if ($key <= (int)$info['last_ep']) continue;

        $epName = trim((string)($latest['name'] ?? ''));
        $title  = $info['title'] !== '' ? $info['title'] : (string)($detail['title'] ?? $info['slug']);
        $msg    = 'Season ' . $season . ', Episode ' . $epNo . ' is out'
                . ($epName !== '' && $epName !== $title ? ' — ' . $epName : '') . '!';

        kp_notify_once($user_id, 'episode', $title, $info['slug'], $key, $msg, 14);
    }
}

// ─── System alerts: welcome, badges, rank-ups ─────────────────────────
if (!empty($prefs['system_alerts']) && $sweepDue('kp_milestone_at', 600)) {
    $userName = '';
    try {
        $nstmt = $pdo->prepare("SELECT User_Name FROM users WHERE User_ID = ? LIMIT 1");
        $nstmt->execute([$user_id]);
        $userName = (string)($nstmt->fetchColumn() ?: '');
    } catch (Exception $e) {}

    kp_notify_welcome($user_id, $userName);
    kp_notify_milestones($user_id);
}

// Build query based on type filter
$where = "WHERE user_id = ?";
$params = [$user_id];

if ($type_filter && in_array($type_filter, ['episode', 'follow', 'system'])) {
    $where .= " AND notification_type = ?";
    $params[] = $type_filter;
}

// Filtering unread on the client would page through rows it then hides, so the
// filter is applied (and counted) here instead.
if ($unread_only) {
    $where .= " AND is_read = 0";
}

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get notifications
$sql = "SELECT id, notification_type, anime_title, anime_slug, episode, message, is_read, created_at"
     . " FROM notifications $where ORDER BY created_at DESC"
     . " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allNotifs = [];
$unread = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
    if (!$n['is_read']) $unread++;
    $slug = (string)($n['anime_slug'] ?? '');
    $ep   = (int)$n['episode'];
    $allNotifs[] = [
        'id'       => (int)$n['id'],
        'type'     => $n['notification_type'] ?? 'episode',
        'title'    => $n['anime_title'],
        'slug'     => $slug,
        'episode'  => $ep,
        'message'  => $n['message'],
        'is_read'  => (bool)$n['is_read'],
        'time'     => $n['created_at'],
        // Where to go and what to draw are derived from the slug, so a link
        // shape change (TMDB TV gaining &season=) fixes existing rows too.
        'url'      => kp_notify_link($slug, $ep),
        'icon'     => kp_notify_icon($n['notification_type'] ?? 'episode', $slug),
    ];
}

// Get full unread count (not filtered)
$unreadStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$user_id]);
$totalUnread = (int)$unreadStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

echo json_encode([
    'notifications' => $allNotifs,
    'unread'        => $totalUnread,
    'total'         => $total,
    'page'          => $page,
    'limit'         => $limit,
    'pages'         => max(1, (int)ceil($total / $limit)),
]);
