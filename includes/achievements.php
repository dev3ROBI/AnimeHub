<?php
/**
 * Profile achievements.
 *
 * Every badge and the watch streak are derived from tables the app already
 * writes (watch_history, watch_time, watchlist, likes, users), so a badge can
 * never disagree with the numbers printed next to it — and nothing has to be
 * migrated or kept in sync on write.
 */

/**
 * Distinct days with watch activity, newest first ("Y-m-d").
 *
 * `watch_time` is the real playback evidence; `watch_history` covers accounts
 * (and legacy rows) that were opened before watch time existed.
 */
function kp_watch_active_days($user_id, $limit = 400) {
    global $pdo;
    if (!$pdo || !$user_id) return [];

    $days = [];
    $limit = max(1, (int)$limit);

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT DATE(updated_at) AS d
               FROM watch_time
              WHERE user_id = ? AND seconds > 0
              ORDER BY d DESC
              LIMIT " . $limit
        );
        $stmt->execute([(int)$user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            if ($d) $days[(string)$d] = true;
        }
    } catch (Exception $e) {
        // fall through to history
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT DATE(watched_at) AS d
               FROM watch_history
              WHERE user_id = ? AND watched_at IS NOT NULL
              ORDER BY d DESC
              LIMIT " . $limit
        );
        $stmt->execute([(int)$user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            if ($d) $days[(string)$d] = true;
        }
    } catch (Exception $e) {
        // history is optional
    }

    $out = array_keys($days);
    rsort($out);
    return array_slice($out, 0, $limit);
}

/**
 * Current + longest watching streak in days.
 *
 * The current streak still counts when the last active day was yesterday —
 * a streak should not look broken just because today has not started yet.
 */
function kp_watch_streak($user_id) {
    $days = kp_watch_active_days($user_id, 400);
    if (!$days) {
        return ['current' => 0, 'longest' => 0, 'last' => null, 'active_days' => 0];
    }

    $toTs = static function (string $day): int {
        $ts = strtotime($day . ' 00:00:00');
        return $ts === false ? 0 : $ts;
    };

    $todayTs = $toTs(date('Y-m-d'));
    $gapToNow = (int)floor(($todayTs - $toTs($days[0])) / 86400);

    $current = 0;
    if ($gapToNow <= 1) {
        $current = 1;
        for ($i = 1; $i < count($days); $i++) {
            if ($toTs($days[$i - 1]) - $toTs($days[$i]) !== 86400) break;
            $current++;
        }
    }

    // Longest run anywhere in the history.
    $longest = 1;
    $run     = 1;
    for ($i = 1; $i < count($days); $i++) {
        if ($toTs($days[$i - 1]) - $toTs($days[$i]) === 86400) {
            $run++;
            if ($run > $longest) $longest = $run;
        } else {
            $run = 1;
        }
    }

    return [
        'current'     => $current,
        'longest'     => max($longest, $current),
        'last'        => $days[0],
        'active_days' => count($days),
    ];
}

/** How many sessions started in the small hours (00:00–04:59), for the Night Owl badge. */
function kp_watch_late_sessions($user_id) {
    global $pdo;
    if (!$pdo || !$user_id) return 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM watch_time
              WHERE user_id = ? AND seconds > 0 AND HOUR(updated_at) BETWEEN 0 AND 4"
        );
        $stmt->execute([(int)$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * The badge definitions — id, icon, copy, target and which input it reads.
 *
 * `source` names the key in the stats array, so the catalogue can be reused
 * for the notification copy (label + hint) without repeating any of it.
 */
function kp_badge_catalog() {
    return [
        ['id' => 'first-episode', 'icon' => 'fa-solid fa-clapperboard',      'label' => 'First Steps',     'hint' => 'Watch 1 episode',         'source' => 'episodes',  'target' => 1,     'accent' => '#ff2e63'],
        ['id' => 'episodes-50',   'icon' => 'fa-solid fa-film',              'label' => 'Binge Starter',   'hint' => 'Track 50 episodes',       'source' => 'episodes',  'target' => 50,    'accent' => '#ff7a9c'],
        ['id' => 'episodes-250',  'icon' => 'fa-solid fa-layer-group',       'label' => 'Episode Hunter',  'hint' => 'Track 250 episodes',      'source' => 'episodes',  'target' => 250,   'accent' => '#a78bfa'],
        ['id' => 'hours-10',      'icon' => 'fa-solid fa-clock',             'label' => 'Ten Hour Club',   'hint' => 'Watch for 10 hours',      'source' => 'hours',     'target' => 10,    'accent' => '#4dabf7'],
        ['id' => 'hours-100',     'icon' => 'fa-solid fa-hourglass-half',    'label' => 'Century Club',    'hint' => 'Watch for 100 hours',     'source' => 'hours',     'target' => 100,   'accent' => '#eab308'],
        ['id' => 'streak-7',      'icon' => 'fa-solid fa-fire',              'label' => 'Week Streak',     'hint' => '7 days in a row',         'source' => 'streak',    'target' => 7,     'accent' => '#f97316'],
        ['id' => 'streak-30',     'icon' => 'fa-solid fa-fire-flame-curved', 'label' => 'Monthly Flame',   'hint' => '30 days in a row',        'source' => 'streak',    'target' => 30,    'accent' => '#ef4444'],
        ['id' => 'watchlist-25',  'icon' => 'fa-solid fa-bookmark',          'label' => 'Collector',       'hint' => 'Save 25 titles',          'source' => 'watchlist', 'target' => 25,    'accent' => '#22c55e'],
        ['id' => 'likes-10',      'icon' => 'fa-solid fa-thumbs-up',         'label' => 'Tastemaker',      'hint' => 'Like 10 titles',          'source' => 'likes',     'target' => 10,    'accent' => '#06b6d4'],
        ['id' => 'member-30',     'icon' => 'fa-solid fa-calendar-check',    'label' => 'Loyal Member',    'hint' => 'Be here 30 days',         'source' => 'days',      'target' => 30,    'accent' => '#38bdf8'],
        ['id' => 'rank-5',        'icon' => 'fa-solid fa-medal',             'label' => 'Rank Climber',    'hint' => 'Reach tier 5',            'source' => 'rank',      'target' => 5,     'accent' => '#fbbf24'],
        ['id' => 'night-owl',     'icon' => 'fa-solid fa-moon',              'label' => 'Night Owl',       'hint' => '10 late-night sessions',  'source' => 'late',      'target' => 10,    'accent' => '#818cf8'],
    ];
}

/**
 * The badge list, evaluated against the numbers the profile page has already
 * collected. Each badge carries its own progress so locked ones can show
 * "37 / 50" instead of a bare padlock.
 *
 * @param array $s episodes, seconds, watchlist, likes, days, streak, late, rank
 */
function kp_achievements(array $s) {
    $episodes = max(0, (int)($s['episodes'] ?? 0));
    $seconds  = max(0, (int)($s['seconds'] ?? 0));
    $hours    = (int)floor($seconds / 3600);
    $watchlist = max(0, (int)($s['watchlist'] ?? 0));
    $likes    = max(0, (int)($s['likes'] ?? 0));
    $days     = max(0, (int)($s['days'] ?? 0));
    $streak   = max(0, (int)($s['streak'] ?? 0));
    $late     = max(0, (int)($s['late'] ?? 0));
    $rank     = max(1, (int)($s['rank'] ?? 1));

    $values = [
        'episodes'  => $episodes,
        'hours'     => $hours,
        'streak'    => $streak,
        'watchlist' => $watchlist,
        'likes'     => $likes,
        'days'      => $days,
        'rank'      => $rank,
        'late'      => $late,
    ];

    $badges = [];
    foreach (kp_badge_catalog() as $badge) {
        $badge['value'] = $values[$badge['source']] ?? 0;
        $badges[] = $badge;
    }

    foreach ($badges as &$badge) {
        $badge['unlocked'] = $badge['value'] >= $badge['target'];
        $badge['percent']  = $badge['target'] > 0
            ? max(0, min(100, (int)round($badge['value'] / $badge['target'] * 100)))
            : 100;
    }
    unset($badge);

    return $badges;
}
