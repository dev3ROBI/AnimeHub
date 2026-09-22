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
include_once '../includes/achievements.php';

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

// ─── Rank system ──────────────────────────────────────────────────────
// The ladder is the single source of truth so the card and the modal can
// never disagree about which tier is current or what comes next.
$rank   = watch_time_rank($userID);
$ladder = watch_time_rank_ladder($userID);

$currentTier = $ladder[0];
$nextTier    = null;
foreach ($ladder as $tier) {
    if ($tier['current']) {
        $currentTier = $tier;
    } elseif ($nextTier === null && !$tier['unlocked']) {
        $nextTier = $tier;
    }
}

$rankSeconds   = (int)($rank['seconds'] ?? 0);
$rankProgress  = 0;
$secondsToNext = null;

if ($nextTier) {
    $range         = max(1, $nextTier['min'] - $currentTier['min']);
    $rankProgress  = (int)min(100, max(0, round(($rankSeconds - $currentTier['min']) / $range * 100)));
    $secondsToNext = max(0, $nextTier['min'] - $rankSeconds);
}

$weeklySeconds = watch_time_this_week($userID);

// Compact "2h 05m" / "42m 54s" label used by the rank card.
$fmtWatch = static function (int $seconds): string {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm ' . ($seconds % 60) . 's';
    return $seconds . 's';
};

// Tier thresholds in words for the ladder: 1800 -> "30 minutes", 604800 -> "7 days".
$fmtTier = static function (int $seconds): string {
    if ($seconds >= 86400) {
        $days = intdiv($seconds, 86400);
        return $days . ($days === 1 ? ' day' : ' days');
    }
    if ($seconds >= 3600) {
        $hours = intdiv($seconds, 3600);
        $mins  = intdiv($seconds % 3600, 60);
        $label = $hours . ($hours === 1 ? ' hour' : ' hours');
        return $mins > 0 ? $label . ' ' . $mins . ' min' : $label;
    }
    $mins = max(1, intdiv($seconds, 60));
    return $mins . ($mins === 1 ? ' minute' : ' minutes');
};

$unlockedCount = count(array_filter($ladder, static fn($t) => !empty($t['unlocked'])));

$joinedTs = !empty($user['User_Join']) ? strtotime((string)$user['User_Join']) : 0;
$memberDays = $joinedTs ? max(0, (int)floor((time() - $joinedTs) / 86400)) : 0;

// ─── Streak + badges ──────────────────────────────────────────────────
// Both are derived from tables the page already reads, so they can never
// disagree with the percentages and counters shown above.
$streak     = kp_watch_streak($userID);
$lateNights = kp_watch_late_sessions($userID);

$rankIndex = 1;
foreach ($ladder as $i => $tier) {
    if (!empty($tier['current'])) { $rankIndex = $i + 1; break; }
}

$badgeStats = [
    'episodes'  => $watchedEpisodes > 0 ? $watchedEpisodes : $counts['episodes'],
    'seconds'   => $totalWatchSeconds,
    'watchlist' => $counts['watchlist'],
    'likes'     => $counts['likes'],
    'days'      => $memberDays,
    // A badge should not be lost when a streak breaks, so the best run counts.
    'streak'    => max($streak['current'], $streak['longest']),
    'late'      => $lateNights,
    'rank'      => $rankIndex,
];
$badges         = kp_achievements($badgeStats);
$badgesUnlocked = count(array_filter($badges, static fn($b) => !empty($b['unlocked'])));
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

    <!-- Rank + activity share a row on wide screens -->
    <div class="kp-prof-split">

    <!-- Rank Card -->
    <div class="kp-panel kp-rank-panel">
        <span class="kp-rank-glow" aria-hidden="true" style="background:<?= htmlspecialchars($rank['color']) ?>;"></span>

        <div class="kp-rank-hero">
            <div class="kp-rank-badge" style="color:<?= htmlspecialchars($rank['color']) ?>; border-color:<?= htmlspecialchars($rank['color']) ?>59; background:linear-gradient(145deg, <?= htmlspecialchars($rank['color']) ?>30, <?= htmlspecialchars($rank['color']) ?>0d); box-shadow:0 10px 26px <?= htmlspecialchars($rank['color']) ?>33;">
                <i class="<?= htmlspecialchars($rank['icon']) ?>"></i>
            </div>
            <div class="kp-rank-info">
                <span class="kp-rank-eyebrow"><i class="fa-solid fa-medal"></i> Your rank</span>
                <span class="kp-rank-title" style="color:<?= htmlspecialchars($rank['color']) ?>;"><?= htmlspecialchars($rank['title']) ?></span>
                <span class="kp-rank-sub">
                    <strong><?= $fmtWatch($rankSeconds) ?></strong> watched
                    <?php if ($nextTier): ?>
                        · <?= progress_format_time($secondsToNext) ?> to <?= htmlspecialchars($nextTier['title']) ?>
                    <?php else: ?>
                        · Max rank reached
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($nextTier): ?>
            <div class="kp-rank-ring" role="img"
                 aria-label="<?= $rankProgress ?>% of the way to <?= htmlspecialchars($nextTier['title']) ?>"
                 style="background: conic-gradient(<?= htmlspecialchars($rank['color']) ?> <?= $rankProgress ?>%, rgba(255,255,255,0.08) <?= $rankProgress ?>%);">
                <span><strong><?= $rankProgress ?>%</strong><small>to next</small></span>
            </div>
            <?php else: ?>
            <div class="kp-rank-ring is-max" role="img" aria-label="Maximum rank reached"
                 style="background: conic-gradient(<?= htmlspecialchars($rank['color']) ?> 100%, rgba(255,255,255,0.08) 0);">
                <span><i class="fa-solid fa-check"></i><small>max</small></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="kp-rank-track">
            <div class="kp-rank-bar">
                <div class="kp-rank-fill"
                     style="width:<?= $nextTier ? $rankProgress : 100 ?>%; background:linear-gradient(90deg, <?= htmlspecialchars($rank['color']) ?>b3, <?= htmlspecialchars($rank['color']) ?>);"></div>
            </div>
            <div class="kp-rank-nodes">
                <span class="kp-rank-node is-now"
                      style="color:<?= htmlspecialchars($currentTier['color']) ?>; border-color:<?= htmlspecialchars($currentTier['color']) ?>59; background:<?= htmlspecialchars($currentTier['color']) ?>1f;">
                    <i class="<?= htmlspecialchars($currentTier['icon']) ?>"></i> <?= htmlspecialchars($currentTier['title']) ?>
                </span>
                <?php if ($nextTier): ?>
                <i class="fa-solid fa-arrow-right-long kp-rank-arrow" aria-hidden="true"></i>
                <span class="kp-rank-node is-next">
                    <i class="<?= htmlspecialchars($nextTier['icon']) ?>" style="color:<?= htmlspecialchars($nextTier['color']) ?>;"></i>
                    <?= htmlspecialchars($nextTier['title']) ?>
                    <small>· <?= progress_format_time($secondsToNext) ?> left</small>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="kp-rank-foot">
            <span class="kp-rank-week">
                <i class="fa-solid fa-bolt"></i> This week <strong><?= $fmtWatch($weeklySeconds) ?></strong>
            </span>
            <button type="button" class="kp-rank-viewall" id="kp-rank-viewall">
                <i class="fa-solid fa-layer-group"></i> View all ranks
            </button>
        </div>
    </div>

    <!-- Rank ladder modal -->
    <div class="kp-avatar-modal" id="kp-rank-modal" aria-hidden="true">
        <div class="kp-avatar-dialog kp-rank-dialog" role="dialog" aria-labelledby="kp-rank-modal-title">
            <div class="kp-rank-modal-head">
                <div class="kp-rank-modal-head-badge"
                     style="color:<?= htmlspecialchars($rank['color']) ?>; border-color:<?= htmlspecialchars($rank['color']) ?>59; background:linear-gradient(145deg, <?= htmlspecialchars($rank['color']) ?>30, <?= htmlspecialchars($rank['color']) ?>0d);">
                    <i class="<?= htmlspecialchars($rank['icon']) ?>"></i>
                </div>
                <div class="kp-rank-modal-head-copy">
                    <h3 id="kp-rank-modal-title">Rank Ladder</h3>
                    <p>
                        You are <strong style="color:<?= htmlspecialchars($rank['color']) ?>;"><?= htmlspecialchars($rank['title']) ?></strong>
                        <?php if ($nextTier): ?>
                            — <?= progress_format_time($secondsToNext) ?> to reach <?= htmlspecialchars($nextTier['title']) ?>
                        <?php else: ?>
                            — the top tier, nothing left to unlock
                        <?php endif; ?>
                    </p>
                </div>
                <button type="button" class="kp-rank-close" id="kp-rank-modal-close" aria-label="Close rank ladder">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="kp-rank-summary">
                <div class="kp-rank-summary-top">
                    <span class="kp-rank-summary-time">
                        <i class="fa-solid fa-clock"></i> <strong><?= $fmtWatch($rankSeconds) ?></strong> watched
                    </span>
                    <span class="kp-rank-summary-pct">
                        <?= $unlockedCount ?> of <?= count($ladder) ?> tiers unlocked
                    </span>
                </div>
                <div class="kp-rank-summary-bar">
                    <div class="kp-rank-summary-fill"
                         style="width:<?= $nextTier ? $rankProgress : 100 ?>%; background:linear-gradient(90deg, <?= htmlspecialchars($rank['color']) ?>b3, <?= htmlspecialchars($rank['color']) ?>);"></div>
                </div>
            </div>

            <div class="kp-rank-ladder">
                <?php foreach ($ladder as $i => $tier): ?>
                <?php
                    $tierState = $tier['current'] ? 'is-current' : ($tier['unlocked'] ? 'is-unlocked' : 'is-locked');
                    $tierLeft  = max(0, (int)$tier['min'] - $rankSeconds);
                ?>
                <div class="kp-rank-tier <?= $tierState ?>"
                     style="--kp-tier:<?= htmlspecialchars($tier['color']) ?>; --kp-tier-soft:<?= htmlspecialchars($tier['color']) ?>1f; --kp-tier-line:<?= htmlspecialchars($tier['color']) ?>59;">
                    <div class="kp-rank-tier-rail">
                        <div class="kp-rank-tier-badge">
                            <i class="<?= htmlspecialchars($tier['icon']) ?>"></i>
                        </div>
                    </div>
                    <div class="kp-rank-tier-info">
                        <span class="kp-rank-tier-title">
                            <?= htmlspecialchars($tier['title']) ?>
                            <em class="kp-rank-tier-lv">Tier <?= $i + 1 ?></em>
                        </span>
                        <span class="kp-rank-tier-req">
                            <?php if ((int)$tier['min'] <= 0): ?>
                                Everyone starts here
                            <?php elseif ($tier['unlocked']): ?>
                                Reached at <?= htmlspecialchars($fmtTier((int)$tier['min'])) ?> of watch time
                            <?php else: ?>
                                Watch <?= htmlspecialchars($fmtTier((int)$tier['min'])) ?> to unlock
                            <?php endif; ?>
                        </span>
                        <?php if ($tier['current'] && $nextTier): ?>
                        <span class="kp-rank-tier-mini">
                            <span class="kp-rank-tier-mini-bar"><span style="width:<?= $rankProgress ?>%;"></span></span>
                            <small><?= $rankProgress ?>% of the way to <?= htmlspecialchars($nextTier['title']) ?></small>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($tier['current']): ?>
                        <span class="kp-rank-tier-tag is-current"><i class="fa-solid fa-location-dot"></i> You are here</span>
                    <?php elseif ($tier['unlocked']): ?>
                        <span class="kp-rank-tier-tag is-unlocked"><i class="fas fa-check"></i> Unlocked</span>
                    <?php else: ?>
                        <span class="kp-rank-tier-tag is-locked"><i class="fas fa-lock"></i> <?= progress_format_time($tierLeft) ?> left</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="kp-rank-modal-foot">
                <i class="fa-solid fa-circle-info"></i>
                Ranks unlock on their own as your watch time grows — nothing to claim.
            </div>
        </div>
    </div>

    <div class="kp-panel kp-activity-panel">
        <div class="kp-panel-head">
            <i class="fas fa-chart-simple"></i>
            <h3>Your Activity</h3>
            <span class="kp-panel-note">All time</span>
        </div>
        <div class="kp-activity-grid">
            <?php
            // Each row owns its accent colour; `-soft` is the same colour at
            // low alpha for the icon tile and hover ring.
            $activityCards = [
                [
                    'icon'   => 'fa-solid fa-tv',
                    'value'  => $watchedEpisodes,
                    'label'  => 'Episodes tracked',
                    'hint'   => 'Episodes you have opened',
                    'accent' => '#ff2e63',
                    'tab'    => 'continue-watching',
                    'link'   => 'Open continue watching',
                ],
                [
                    'icon'   => 'fa-solid fa-heart',
                    'value'  => $counts['watchlist'],
                    'label'  => 'In watchlist',
                    'hint'   => 'Saved to watch later',
                    'accent' => '#ff7a9c',
                    'tab'    => 'watch-list',
                    'link'   => 'Open your watch list',
                ],
                [
                    'icon'   => 'fa-solid fa-thumbs-up',
                    'value'  => $counts['likes'],
                    'label'  => 'Liked',
                    'hint'   => 'Anime you gave a thumbs up',
                    'accent' => '#4dabf7',
                    'tab'    => '',
                    'link'   => '',
                ],
                [
                    'icon'   => 'fa-solid fa-fire',
                    'value'  => $streak['current'],
                    'label'  => 'Day streak',
                    'hint'   => $streak['longest'] > $streak['current']
                        ? 'Best run: ' . $streak['longest'] . ' days'
                        : ($streak['current'] > 0 ? 'Keep it going!' : 'Watch something to start one'),
                    'accent' => '#f97316',
                    'tab'    => '',
                    'link'   => '',
                ],
            ];
            ?>
            <?php foreach ($activityCards as $card): ?>
            <?php $cardStyle = '--kp-accent:' . $card['accent'] . '; --kp-accent-soft:' . $card['accent'] . '26;'; ?>
            <?php if ($card['tab'] !== ''): ?>
            <a class="kp-act-card" href="?tab=<?= htmlspecialchars($card['tab'], ENT_QUOTES, 'UTF-8') ?>" style="<?= $cardStyle ?>"
               title="<?= htmlspecialchars($card['link'], ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
            <div class="kp-act-card" style="<?= $cardStyle ?>">
            <?php endif; ?>
                <span class="kp-act-icon"><i class="<?= htmlspecialchars($card['icon']) ?>"></i></span>
                <span class="kp-act-copy">
                    <strong><?= number_format($card['value']) ?></strong>
                    <span class="kp-act-label"><?= htmlspecialchars($card['label']) ?></span>
                    <span class="kp-act-hint"><?= htmlspecialchars($card['hint']) ?></span>
                </span>
                <?php if ($card['tab'] !== ''): ?>
                <span class="kp-act-chevron"><i class="fa-solid fa-chevron-right"></i></span>
                <?php endif; ?>
            <?php if ($card['tab'] !== ''): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    </div>
    <!-- /Rank + activity -->

    <!-- Badges. Class names are prefixed kp-ach-* so the watch page's own
         .kp-badge provider pill (a stylesheet every page loads) cannot restyle
         them. -->
    <div class="kp-panel kp-ach-panel">
        <div class="kp-panel-head">
            <i class="fas fa-award"></i>
            <h3>Badges</h3>
            <span class="kp-panel-note">
                <?= (int)$badgesUnlocked ?> of <?= count($badges) ?> unlocked
                <?php if ($streak['current'] > 0): ?>
                    &middot; <?= (int)$streak['current'] ?>-day streak
                <?php endif; ?>
            </span>
        </div>

        <div class="kp-ach-grid">
            <?php foreach ($badges as $badge): ?>
            <div class="kp-ach<?= $badge['unlocked'] ? ' is-unlocked' : '' ?>"
                 style="--kp-accent:<?= htmlspecialchars($badge['accent']) ?>; --kp-accent-soft:<?= htmlspecialchars($badge['accent']) ?>24; --kp-accent-line:<?= htmlspecialchars($badge['accent']) ?>59;"
                 title="<?= htmlspecialchars($badge['label'] . ' — ' . $badge['hint'], ENT_QUOTES, 'UTF-8') ?>">

                <span class="kp-ach-icon">
                    <i class="<?= htmlspecialchars($badge['icon']) ?>"></i>
                    <?php if ($badge['unlocked']): ?>
                        <span class="kp-ach-tick"><i class="fas fa-check"></i></span>
                    <?php endif; ?>
                </span>

                <span class="kp-ach-copy">
                    <strong><?= htmlspecialchars($badge['label']) ?></strong>
                    <span class="kp-ach-hint"><?= htmlspecialchars($badge['hint']) ?></span>
                </span>

                <span class="kp-ach-state">
                    <?php if ($badge['unlocked']): ?>
                        <i class="fas fa-circle-check"></i>
                    <?php else: ?>
                        <small><?= number_format((int)$badge['value']) ?>/<?= number_format((int)$badge['target']) ?></small>
                    <?php endif; ?>
                </span>

                <?php if (!$badge['unlocked']): ?>
                <span class="kp-ach-progress" aria-hidden="true">
                    <span style="width:<?= (int)$badge['percent'] ?>%"></span>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="kp-ach-foot">
            <i class="fas fa-circle-info"></i>
            Badges fill up on their own while you watch — nothing to claim.
        </p>
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
