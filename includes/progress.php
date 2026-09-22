<?php
/**
 * Watch progress helpers.
 *
 * Progress rows live in `video_progress`, keyed by
 *   video_id = "{provider}:{remoteId}:{episode}"
 * so an AniList episode and a legacy local episode can never collide.
 *
 * `watch_history` stays the coarse "which episode did they open" record
 * (it is what makes episode-level resume work for embed players, where the
 * exact playback position is not readable across origins).
 */

include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/watch_time.php';

/** Composite key used by the video_progress table. */
function progress_video_id($animeId, $episode) {
    return (string)$animeId . ':' . (int)$episode;
}

// ─── TMDB TV: one stored episode number per (season, episode) ──────────
//
// TMDB restarts episode numbers at 1 in every season, so a plain "episode 2"
// would make S2E2 and S1E2 the same row in watch_history, watch_time and
// video_progress — one resume bar, one watched-episode count, one continue
// watching card for two different episodes. Folding the season into the number
// keeps every one of those tables keyed per episode. Season 1 keeps the plain
// number, so rows written before this existed stay valid.
if (!defined('KP_TV_SEASON_STRIDE')) define('KP_TV_SEASON_STRIDE', 10000);

/** Is this catalogue id a TMDB TV show (the only provider with per-season numbering)? */
function progress_is_tv_slug($animeId) {
    return (bool)preg_match('/^tmdb:tv:\d+$/', (string)$animeId);
}

/** Stored episode number for a TMDB TV episode: (season - 1) * stride + episode. */
function progress_tv_episode_key($season, $episode) {
    $season  = max(1, (int)$season);
    $episode = max(1, (int)$episode);
    return ($season - 1) * KP_TV_SEASON_STRIDE + $episode;
}

/**
 * Stored episode number for any catalogue item. Non-TV ids keep their plain
 * episode number; TMDB TV folds the season in when one is known.
 */
function progress_episode_key($animeId, $episode, $season = 0) {
    if ($season > 0 && progress_is_tv_slug($animeId)) {
        return progress_tv_episode_key($season, $episode);
    }
    return (int)$episode;
}

/** Season a stored episode number belongs to. 0 when the id is not TMDB TV. */
function progress_stored_season($animeId, $storedEpisode) {
    if (!progress_is_tv_slug($animeId)) return 0;
    $stored = max(1, (int)$storedEpisode);
    return intdiv($stored - 1, KP_TV_SEASON_STRIDE) + 1;
}

/** Plain ("Season 3, Episode 4") numbers behind a stored episode number. */
function progress_split_episode_key($animeId, $storedEpisode) {
    $stored  = max(1, (int)$storedEpisode);
    $season  = progress_stored_season($animeId, $stored);
    if ($season === 0) return ['season' => 0, 'episode' => $stored];
    return [
        'season'  => $season,
        'episode' => $stored - ($season - 1) * KP_TV_SEASON_STRIDE,
    ];
}

/** Saved position in seconds for one episode, 0 when unknown. */
function progress_get($user_id, $animeId, $episode) {
    global $pdo;
    if (!$pdo || !$user_id || $animeId === null) return 0.0;
    try {
        $stmt = $pdo->prepare("SELECT last_position FROM video_progress WHERE user_id = ? AND video_id = ? LIMIT 1");
        $stmt->execute([(int)$user_id, progress_video_id($animeId, $episode)]);
        return (float)($stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        return 0.0;
    }
}

/**
 * Persist a playback position against an already-built video_progress.video_id.
 *
 * The upsert relies on the table's UNIQUE key over (user_id, video_id). If that
 * key is missing MySQL raises "Duplicate entry" and the row never updates, so
 * the failure is logged (and reported) instead of being swallowed.
 */
function progress_save_raw($user_id, $video_id, $position) {
    global $pdo;
    $video_id = trim((string)$video_id);
    if (!$pdo || !$user_id || $video_id === '') return false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO video_progress (user_id, video_id, last_position)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_position = VALUES(last_position),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([(int)$user_id, $video_id, (float)$position]);
        return true;
    } catch (Exception $e) {
        error_log('[progress] save error: ' . $e->getMessage());
        return false;
    }
}

/** Persist a playback position for "{animeId}:{episode}". */
function progress_save($user_id, $animeId, $episode, $position) {
    if ($animeId === null) return false;

    return progress_save_raw($user_id, progress_video_id($animeId, $episode), $position);
}

/** Drop saved progress (used when an episode is finished). */
function progress_clear($user_id, $animeId, $episode) {
    global $pdo;
    if (!$pdo || !$user_id) return false;
    try {
        $stmt = $pdo->prepare("DELETE FROM video_progress WHERE user_id = ? AND video_id = ?");
        $stmt->execute([(int)$user_id, progress_video_id($animeId, $episode)]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * The episode the user last opened for this anime, plus its saved position.
 * Returns null when there is nothing recorded.
 */
function progress_last_episode($user_id, $animeId, $maxEpisodes = 0) {
    global $pdo;
    if (!$pdo || !$user_id || $animeId === null) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT episode_number, watched_at
            FROM watch_history
            WHERE user_id = ? AND anime_slug = ?
            ORDER BY watched_at DESC
            LIMIT 1
        ");
        $stmt->execute([(int)$user_id, (string)$animeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // The stored number already identifies the season for TMDB TV, so the
        // series-wide episode total must not be used to clamp it.
        $episode = (int)$row['episode_number'];
        $stored  = progress_is_tv_slug($animeId);
        if (!$stored && $maxEpisodes > 0 && $episode > $maxEpisodes) {
            $episode = $maxEpisodes;
        }

        $split = progress_split_episode_key($animeId, $episode);

        return [
            'episode'        => $episode,
            'episode_number' => $split['episode'],
            'season'         => $split['season'],
            'position'       => progress_get($user_id, $animeId, $episode),
            'watched_at'     => $row['watched_at'],
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Anime the user watched most recently, newest first.
 * Returns [{anime_slug, episode_number, watched_at}, ...]
 */
function progress_recent_anime($user_id, $limit = 6) {
    global $pdo;
    if (!$pdo || !$user_id) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT h.anime_slug, h.episode_number, h.watched_at
            FROM watch_history h
            JOIN (
                SELECT anime_slug, MAX(watched_at) AS latest
                FROM watch_history
                WHERE user_id = ?
                GROUP BY anime_slug
            ) newest
              ON newest.anime_slug = h.anime_slug
             AND newest.latest = h.watched_at
            WHERE h.user_id = ?
            ORDER BY h.watched_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([(int)$user_id, (int)$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('[progress] recent query error: ' . $e->getMessage());
        return [];
    }
}

/**
 * A provider runtime string ("24m", "24 min", "1h 55m") → seconds.
 * Returns 0 when nothing numeric can be read.
 */
function progress_parse_runtime($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return 0;

    $hours = preg_match('/(\d+)\s*h/i', $raw, $h) ? (int)$h[1] : 0;
    $mins  = preg_match('/(\d+)\s*m/i', $raw, $m) ? (int)$m[1] : 0;

    // A bare number is minutes, not hours ("24" → 24 minutes).
    if ($hours === 0 && $mins === 0) {
        if (preg_match('/(\d+)/', $raw, $any)) $mins = (int)$any[1];
    }

    return ($hours * 60 + $mins) * 60;
}

/**
 * Episode runtime in seconds for a catalogue row, 0 when unknown.
 *
 * `duration_min` is authoritative (AniList/ReAnime store plain minutes), the
 * human-readable `duration` string is the fallback (Jikan's "24 min per ep").
 */
function progress_item_runtime_seconds(array $info) {
    foreach (['duration_min', 'runtime_min', 'episode_duration'] as $key) {
        if (!empty($info[$key]) && is_numeric($info[$key])) {
            $seconds = (int)round((float)$info[$key] * 60);
            if ($seconds > 0) return $seconds;
        }
    }

    foreach (['duration', 'runtime'] as $key) {
        if (!empty($info[$key])) {
            $seconds = progress_parse_runtime($info[$key]);
            if ($seconds > 0) return $seconds;
        }
    }

    return 0;
}

/**
 * Position + runtime (seconds) for a "continue watching" card.
 *
 * `video_progress` holds the resume position and `watch_time` holds what the
 * player learned while the episode was open — including the real runtime, which
 * is preferred over the catalogue row because the player reads the actual file.
 *
 * `progress` is what a card should show: the furthest of the two, because an
 * embed player can never report a position, so its tracked seconds are the only
 * evidence that anything was watched at all.
 */
function progress_card_seconds($user_id, $animeId, $episode, array $info = []) {
    $position = progress_get($user_id, $animeId, $episode);
    $tracked  = watch_time_episode($user_id, $animeId, $episode);
    $watched  = (int)($tracked['seconds'] ?? 0);

    $duration = (int)($tracked['duration'] ?? 0);
    if ($duration <= 0) $duration = progress_item_runtime_seconds($info);
    if ($duration <= 0) $duration = 1440; // 24 min episode assumption

    $progress = max($position, $watched);
    if ($duration > 0) $progress = min($progress, $duration);

    return [
        'position' => $position,
        'duration' => $duration,
        'watched'  => $watched,
        'progress' => (float)$progress,
        'percent'  => $duration > 0 ? max(0, min(100, (int)round($progress / $duration * 100))) : 0,
    ];
}

/** 3725 -> "1:02:05", 125 -> "2:05". */
function progress_format_time($seconds) {
    $seconds = max(0, (int)round((float)$seconds));
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}
