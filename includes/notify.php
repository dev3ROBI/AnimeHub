<?php
/**
 * Notification writes.
 *
 * One place decides what a notification looks like, so the episode alerts
 * (check_notifications.php) and the one-off notes fired from other pages
 * (registration) cannot drift apart.
 *
 * Every row goes through kp_notify_once(). The table's
 * (user_id, anime_slug, episode) unique key is what makes "notify exactly
 * once" hold — even if two requests race — so no caller has to remember what
 * it already sent, and re-running a check is always safe.
 */

include_once __DIR__ . '/progress.php';
include_once __DIR__ . '/watch_time.php';
include_once __DIR__ . '/achievements.php';

/** Used in copy; header.php's <title> is the source of truth for the name. */
if (!defined('KP_SITE_NAME')) define('KP_SITE_NAME', 'KitsuPlay');

/** Slug prefixes for the non-episode notifications. */
const KP_NOTIFY_BADGE = 'badge:';
const KP_NOTIFY_RANK  = 'rank:';
const KP_NOTIFY_WELCOME = 'welcome';

/**
 * Write a notification unless this (user, slug, episode) already has one.
 *
 * Returns true only when a row was actually inserted, so callers can count
 * what they sent (and stay silent when there is nothing new).
 */
function kp_notify_once($user_id, $type, $title, $slug, $episode, $message, $expiresDays = 14) {
    global $pdo;
    if (!$pdo || !$user_id) return false;

    $episode = (int)$episode;
    $expires = $expiresDays > 0 ? date('Y-m-d H:i:s', time() + $expiresDays * 86400) : null;

    try {
        // is_read is spelled out rather than left to the column default: every
        // row this writes is new, so it must never start out read.
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notifications
                (user_id, notification_type, anime_title, anime_slug, episode, message, is_read, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([
            (int)$user_id,
            (string)$type,
            (string)$title,
            (string)$slug,
            $episode,
            (string)$message,
            $expires,
        ]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        // A missing table/column must never break the page that writes it.
        return false;
    }
}

/**
 * Where clicking a notification should go.
 *
 * Derived from the slug instead of stored, so the link stays correct when the
 * URL shape changes (e.g. TMDB TV gaining its &season= parameter) without a
 * migration over existing rows.
 */
function kp_notify_link($slug, $episode = 0) {
    $slug = (string)$slug;
    $episode = (int)$episode;

    if ($slug === '' ) return './profile.php';
    if ($slug === KP_NOTIFY_WELCOME) return './index.php';
    if (strpos($slug, KP_NOTIFY_BADGE) === 0 || strpos($slug, KP_NOTIFY_RANK) === 0) {
        return './profile.php?tab=profile';
    }

    $url = './watch.php?id=' . urlencode($slug);

    if ($episode > 0) {
        // TMDB TV stores (season - 1) * 10000 + episode, so the stored key has
        // to be unfolded again before it can be put back in the URL.
        $split = progress_split_episode_key($slug, $episode);
        if (!empty($split['season'])) {
            $url .= '&season=' . (int)$split['season'];
        }
        $url .= '&ep=' . (int)$split['episode'];
    }

    return $url;
}

/** Font Awesome class for a notification row. */
function kp_notify_icon($type, $slug = '') {
    $slug = (string)$slug;

    if (strpos($slug, KP_NOTIFY_BADGE) === 0) {
        $id = substr($slug, strlen(KP_NOTIFY_BADGE));
        foreach (kp_badge_catalog() as $badge) {
            if ($badge['id'] === $id) return $badge['icon'];
        }
        return 'fa-solid fa-medal';
    }
    if (strpos($slug, KP_NOTIFY_RANK) === 0)    return 'fa-solid fa-arrow-up-right-dots';
    if ($slug === KP_NOTIFY_WELCOME)            return 'fa-solid fa-hand-sparkles';

    switch ($type) {
        case 'follow':  return 'fa-solid fa-heart';
        case 'system':  return 'fa-solid fa-circle-info';
        case 'episode': return progress_is_tv_slug($slug) ? 'fa-solid fa-clapperboard' : 'fa-solid fa-tv';
        default:        return 'fa-solid fa-bell';
    }
}

/** "2h 10m" / "42m 54s" / "45s" — matches the profile card's format. */
function kp_notify_clock($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm ' . ($seconds % 60) . 's';
    return $seconds . 's';
}

/**
 * Welcome note — written once per account.
 *
 * Called from register.php for new users and from the notification check for
 * accounts that predate this, which is why it is idempotent by design.
 */
function kp_notify_welcome($user_id, $name = '') {
    $name = trim((string)$name);
    $hello = $name !== '' ? 'Welcome to ' . KP_SITE_NAME . ', ' . $name . '!' : 'Welcome to ' . KP_SITE_NAME . '!';

    return kp_notify_once(
        $user_id,
        'system',
        'Welcome aboard',
        KP_NOTIFY_WELCOME,
        0,
        $hello . ' Follow a show or put one in your watch list and we will tell you the moment a new episode drops.',
        0
    );
}

/** The numbers every badge is measured against — same inputs as the profile card. */
function kp_milestone_stats($user_id) {
    global $pdo;
    if (!$pdo || !$user_id) return null;

    $user_id = (int)$user_id;

    $totals = watch_time_totals($user_id);
    $seconds = (int)($totals['seconds'] ?? 0);
    $episodes = (int)($totals['episodes'] ?? 0);

    $countOf = function (string $table) use ($pdo, $user_id): int {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE user_id = ?");
            $stmt->execute([$user_id]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };

    if ($episodes <= 0) $episodes = $countOf('watch_history');

    // Joined date → membership days (the Loyal Member badge).
    $days = 0;
    try {
        $stmt = $pdo->prepare("SELECT User_Join FROM users WHERE User_ID = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $joined = (string)($stmt->fetchColumn() ?: '');
        if ($joined !== '') {
            $ts = strtotime($joined);
            if ($ts) $days = max(0, (int)floor((time() - $ts) / 86400));
        }
    } catch (Throwable $e) {
        $days = 0;
    }

    $streak = kp_watch_streak($user_id);

    $rankIndex = 1;
    foreach (watch_time_rank_ladder($user_id) as $i => $tier) {
        if (!empty($tier['current'])) { $rankIndex = $i + 1; break; }
    }

    return [
        'episodes'  => $episodes,
        'seconds'   => $seconds,
        'watchlist' => $countOf('watchlist'),
        'likes'     => $countOf('likes'),
        'days'      => $days,
        // A badge should not vanish when a streak breaks, so the best run counts.
        'streak'    => max((int)$streak['current'], (int)$streak['longest']),
        'late'      => kp_watch_late_sessions($user_id),
        'rank'      => $rankIndex,
    ];
}

/**
 * Badge unlocks and rank-ups, written at most once each.
 *
 * Runs off the same derived numbers as the profile page, so a badge can never
 * be announced without the panel actually showing it as unlocked.
 *
 * @return array { badges, ranks, welcome } counts of what was written
 */
function kp_notify_milestones($user_id) {
    $sent = ['badges' => 0, 'rank' => 0];

    $stats = kp_milestone_stats($user_id);
    if (!$stats) return $sent;

    foreach (kp_achievements($stats) as $badge) {
        if (empty($badge['unlocked'])) continue;

        $ok = kp_notify_once(
            $user_id,
            'system',
            $badge['label'],
            KP_NOTIFY_BADGE . $badge['id'],
            0,
            'Badge unlocked — ' . $badge['hint'] . '. See it on your profile.',
            0
        );
        if ($ok) $sent['badges']++;
    }

    $ladder = watch_time_rank_ladder($user_id);
    $tierIndex = 1;
    $tier = null;
    foreach ($ladder as $i => $row) {
        if (!empty($row['current'])) { $tierIndex = $i + 1; $tier = $row; }
    }

    if ($tier && $tierIndex > 1) {
        $ok = kp_notify_once(
            $user_id,
            'system',
            'Rank up: ' . $tier['title'],
            KP_NOTIFY_RANK . $tier['key'],
            0,
            'You climbed to ' . $tier['title'] . ' with ' . kp_notify_clock($stats['seconds']) . ' watched. Keep going.',
            0
        );
        if ($ok) $sent['rank']++;
    }

    return $sent;
}
