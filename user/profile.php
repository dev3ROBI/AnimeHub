<?php
/**
 * Profile tab: account information.
 *
 * The avatar and identity banner are rendered by profile.php itself, so this
 * tab no longer duplicates them.
 */
session_start();
if (!isset($_SESSION['userID'])) exit();

include '../includes/db.php';
include '../includes/avatars.php';
include_once '../includes/watch_time.php';
include_once '../includes/progress.php';

$userID = $_SESSION['userID'];
$stmt = $conn->prepare("SELECT * FROM users WHERE User_ID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    echo '<p style="color:#fff;">User not found.</p>';
    exit;
}

$avatarValue = (string)($user['User_Avatar'] ?? '');
$avatarInfo  = avatar_meta($avatarValue);

// Small counts shown as chips under the account fields.
$counts = ['episodes' => 0, 'watchlist' => 0, 'likes' => 0];
foreach ([
    'episodes'  => "SELECT COUNT(*) FROM watch_history WHERE user_id = ?",
    'watchlist' => "SELECT COUNT(*) FROM watchlist WHERE user_id = ?",
    'likes'     => "SELECT COUNT(*) FROM likes WHERE user_id = ?",
] as $key => $sql) {
    try {
        $q = $conn->prepare($sql);
        $q->bind_param("i", $userID);
        $q->execute();
        $counts[$key] = (int)$q->get_result()->fetch_row()[0];
    } catch (Throwable $e) {
        // ignore — counts are cosmetic
    }
}

// Actual watch time from watch_time table (not a rough estimate)
$watchTotals = watch_time_totals($userID);
$totalWatchSeconds = (int)($watchTotals['seconds'] ?? 0);
$watchedEpisodes = (int)($watchTotals['episodes'] ?? 0);

// Fallback: if watch_time is empty, use video_progress positions
if ($totalWatchSeconds <= 0) {
    try {
        $q = $pdo->prepare("SELECT COALESCE(SUM(last_position), 0) FROM video_progress WHERE user_id = ?");
        $q->execute([(int)$userID]);
        $totalWatchSeconds = (int)round((float)$q->fetchColumn());
    } catch (Exception $e) {
        $totalWatchSeconds = 0;
    }
}

// Format watch time
$watchHours = floor($totalWatchSeconds / 3600);
$watchMinutes = floor(($totalWatchSeconds % 3600) / 60);
if ($watchHours > 0) {
    $watchTimeDisplay = number_format($watchHours) . 'h ' . $watchMinutes . 'm';
} elseif ($watchMinutes > 0) {
    $watchTimeDisplay = $watchMinutes . 'm ' . ($totalWatchSeconds % 60) . 's';
} else {
    $watchTimeDisplay = $totalWatchSeconds . 's';
}

// Rank system
$rank = watch_time_rank($userID);
$rankProgress = 0;
if ($rank['next'] !== null && $rank['seconds'] > 0) {
    // Calculate progress to next rank
    $ranks = [
        [0, 'newbie'], [300, 'viewer'], [1800, 'watcher'], [7200, 'bingelord'],
        [21600, 'otaku'], [86400, 'sensei'], [259200, 'legend'],
        [604800, 'no-life'], [1209600, 'otaku-god'], [2592000, 'weeb-king'],
    ];
    $currentMin = 0;
    $nextMin = 0;
    foreach ($ranks as $i => $tier) {
        if ($tier[1] === $rank['rank'] && isset($ranks[$i + 1])) {
            $currentMin = $tier[0];
            $nextMin = $ranks[$i + 1][0];
            break;
        }
    }
    $range = $nextMin - $currentMin;
    $progress = $totalWatchSeconds - $currentMin;
    $rankProgress = $range > 0 ? min(100, max(0, round($progress / $range * 100))) : 0;
}

$joinedTs = !empty($user['User_Join']) ? strtotime((string)$user['User_Join']) : 0;
?>

<div class="tab-content">

    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-id-card"></i>
            <h3>Account Information</h3>
            <span class="kp-panel-note">Read-only — contact an admin to change these</span>
        </div>

        <div class="kp-info-grid">
            <div class="kp-info-item">
                <span class="kp-info-label"><i class="fas fa-user"></i> Username</span>
                <span class="kp-info-value"><?= htmlspecialchars($user['User_Name']) ?></span>
            </div>
            <div class="kp-info-item">
                <span class="kp-info-label"><i class="fas fa-envelope"></i> Email</span>
                <span class="kp-info-value"><?= htmlspecialchars($user['User_Email']) ?></span>
            </div>
            <div class="kp-info-item">
                <span class="kp-info-label"><i class="fas fa-user-shield"></i> Role</span>
                <span class="kp-info-value">
                    <span class="kp-role-pill"><?= htmlspecialchars(ucfirst($user['User_Role'] ?? 'user')) ?></span>
                </span>
            </div>
            <div class="kp-info-item">
                <span class="kp-info-label"><i class="fas fa-fingerprint"></i> User ID</span>
                <span class="kp-info-value">#<?= (int)$user['User_ID'] ?></span>
            </div>
            <div class="kp-info-item">
                <span class="kp-info-label"><i class="fas fa-calendar-plus"></i> Member since</span>
                <span class="kp-info-value">
                    <?= $joinedTs ? htmlspecialchars(date('F j, Y', $joinedTs)) : '—' ?>
                </span>
            </div>
            <div class="kp-info-item">
                <span class="kp-info-label"><i class="fas fa-eye"></i> Watch time</span>
                <span class="kp-info-value">
                    <?= $watchTimeDisplay ?>
                    <small style="color:#8b8f95; font-size:11px; margin-left:4px;">(<?= number_format($watchedEpisodes) ?> ep tracked)</small>
                </span>
            </div>
        </div>
    </div>

    <!-- Rank Card -->
    <div class="kp-panel kp-rank-panel">
        <div class="kp-rank-header">
            <div class="kp-rank-badge" style="background:<?= htmlspecialchars($rank['color']) ?>20; border-color:<?= htmlspecialchars($rank['color']) ?>40;">
                <i class="<?= htmlspecialchars($rank['icon']) ?>" style="color:<?= htmlspecialchars($rank['color']) ?>;"></i>
            </div>
            <div class="kp-rank-info">
                <span class="kp-rank-title" style="color:<?= htmlspecialchars($rank['color']) ?>;"><?= htmlspecialchars($rank['title']) ?></span>
                <span class="kp-rank-sub">
                    <?= $watchTimeDisplay ?> watched
                    <?php if ($rank['next'] !== null): ?>
                        · <?= progress_format_time($rank['next']) ?> to next rank
                    <?php else: ?>
                        · Max rank achieved!
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php if ($rank['next'] !== null): ?>
        <div class="kp-rank-progress">
            <div class="kp-rank-bar">
                <div class="kp-rank-fill" style="width:<?= $rankProgress ?>%; background:<?= htmlspecialchars($rank['color']) ?>;"></div>
            </div>
            <span class="kp-rank-pct"><?= $rankProgress ?>%</span>
        </div>
        <?php endif; ?>
    </div>

    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-chart-simple"></i>
            <h3>Your Activity</h3>
        </div>
        <div class="kp-activity-row">
            <div class="kp-activity">
                <i class="fas fa-tv"></i>
                <strong><?= number_format($watchedEpisodes) ?></strong>
                <span>Episodes tracked</span>
            </div>
            <div class="kp-activity">
                <i class="fas fa-heart"></i>
                <strong><?= number_format($counts['watchlist']) ?></strong>
                <span>In watchlist</span>
            </div>
            <div class="kp-activity">
                <i class="fas fa-thumbs-up"></i>
                <strong><?= number_format($counts['likes']) ?></strong>
                <span>Liked</span>
            </div>
        </div>
    </div>

    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-wand-magic-sparkles"></i>
            <h3>Anime Avatar</h3>
        </div>
        <div class="kp-avatar-row">
            <img class="kp-avatar-row-img" data-kp-avatar-img
                 src="<?= htmlspecialchars(avatar_resolve($avatarValue), ENT_QUOTES, 'UTF-8') ?>" alt="Anime avatar">
            <div class="kp-avatar-row-copy">
                <p class="kp-avatar-row-name" data-kp-avatar-name>
                    <?php if ($avatarInfo): ?>
                        <?= htmlspecialchars($avatarInfo['name']) ?><?= $avatarInfo['from'] !== '' ? ' &middot; ' . htmlspecialchars($avatarInfo['from']) : '' ?>
                    <?php else: ?>
                        No anime avatar picked yet
                    <?php endif; ?>
                </p>
                <p class="kp-avatar-row-hint">Pick any character from the gallery — no upload needed.</p>
            </div>
            <button type="button" class="kp-prof-avatar-btn" data-kp-avatar-open>
                <i class="fas fa-wand-magic-sparkles"></i>
                <span class="kp-avatar-btn-label"><?= $avatarValue !== '' ? 'Change Avatar' : 'Pick an Anime Avatar' ?></span>
            </button>
        </div>
    </div>

    <p class="kp-panel-foot">
        <i class="fas fa-circle-info"></i>
        Want to update your name or password? Use the <strong>Settings</strong> tab, or contact an admin.
    </p>
</div>
