<?php
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/anilist_api.php';
include_once __DIR__ . '/progress.php';

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

$type_filter = $_GET['type'] ?? '';
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

// Get anime the user has been watching
$watched = [];
$rows = progress_recent_anime($user_id, 50);
foreach ($rows as $r) {
    $slug = $r['anime_slug'] ?? '';
    if ($slug === '') continue;
    $ep = (int)($r['episode_number'] ?? 0);
    if ($ep <= 0) continue;

    $parts = explode(':', $slug, 2);
    if (count($parts) === 2 && $parts[0] === 'anilist' && is_numeric($parts[1])) {
        $watched[(int)$parts[1]] = ['slug' => $slug, 'last_ep' => $ep, 'title' => ''];
    }
}

// Also get followed anime from the follows table
$followed = [];
try {
    $fstmt = $pdo->prepare("SELECT anime_slug, anime_title FROM follows WHERE user_id = ?");
    $fstmt->execute([$user_id]);
    foreach ($fstmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $slug = $f['anime_slug'] ?? '';
        $parts = explode(':', $slug, 2);
        if (count($parts) === 2 && $parts[0] === 'anilist' && is_numeric($parts[1])) {
            $aid = (int)$parts[1];
            $followed[$aid] = [
                'slug'     => $slug,
                'last_ep'  => $watched[$aid]['last_ep'] ?? 0,
                'title'    => $f['anime_title'] ?? '',
            ];
        }
    }
} catch (Exception $e) {}

// Merge: followed takes priority for titles, watched provides last_ep
$allTracked = $watched;
foreach ($followed as $aid => $info) {
    if (!isset($allTracked[$aid])) {
        $allTracked[$aid] = $info;
    } else {
        if (!empty($info['title'])) {
            $allTracked[$aid]['title'] = $info['title'];
        }
    }
}

// Generate new episode notifications (if episode alerts enabled)
if (!empty($allTracked) && $prefs['episode_alerts']) {
    $now = time();
    $weekLater = $now + 7 * 86400;
    try {
        $schedule = anilist_schedule($now, $weekLater, 'Asia/Dhaka');
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

    foreach ($allTracked as $anilistId => $info) {
        if (isset($airingMap[$anilistId])) {
            $next = $airingMap[$anilistId];
            $hasAired = !empty($next['airingAt']) && $next['airingAt'] <= time();
            if ($next['episode'] > $info['last_ep'] && $hasAired) {
                $title = $info['title'] ?: ($next['title'] ?: $anilistId);
                $msg = 'Episode ' . $next['episode'] . ' is out!';
                $expires = date('Y-m-d H:i:s', time() + 14 * 86400);

                try {
                    $ins = $pdo->prepare("
                        INSERT IGNORE INTO notifications (user_id, notification_type, anime_title, anime_slug, episode, message, expires_at)
                        VALUES (?, 'episode', ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$user_id, $title, $info['slug'], $next['episode'], $msg, $expires]);
                } catch (Exception $e) {
                    // Fallback: plain INSERT without INSERT IGNORE
                    try {
                        $ins2 = $pdo->prepare("
                            INSERT INTO notifications (user_id, notification_type, anime_title, anime_slug, episode, message, expires_at)
                            VALUES (?, 'episode', ?, ?, ?, ?, ?)
                        ");
                        $ins2->execute([$user_id, $title, $info['slug'], $next['episode'], $msg, $expires]);
                    } catch (Exception $e2) {}
                }
            }
        }
    }
}

// Build query based on type filter
$where = "WHERE user_id = ?";
$params = [$user_id];

if ($type_filter && in_array($type_filter, ['episode', 'follow', 'system'])) {
    $where .= " AND notification_type = ?";
    $params[] = $type_filter;
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
    $allNotifs[] = [
        'id'       => (int)$n['id'],
        'type'     => $n['notification_type'] ?? 'episode',
        'title'    => $n['anime_title'],
        'slug'     => $n['anime_slug'],
        'episode'  => (int)$n['episode'],
        'message'  => $n['message'],
        'is_read'  => (bool)$n['is_read'],
        'time'     => $n['created_at'],
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
