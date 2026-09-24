<?php
/**
 * Unified catalog layer.
 *
 * Every page talks to this file instead of a specific provider. It fans out
 * across the enabled sources in CATALOG_ORDER (AniList -> ReAnime -> Jikan)
 * and returns one consistent item shape:
 *
 *   id, provider, provider_id, anilist_id, mal_id, slug, title, poster,
 *   banner, description, type, format, status, episodes, episode, duration,
 *   genres, studios, studio, score, rating, year, season, aired, relations,
 *   recommendations, episodes_list
 */

include_once __DIR__ . '/anilist_api.php';
include_once __DIR__ . '/jikan_api.php';
include_once __DIR__ . '/reanime_api.php';
include_once __DIR__ . '/anikuro_api.php';
include_once __DIR__ . '/tmdb_movie_api.php';

// ─── ID scheme: "{provider}:{remote id}" ───────────────────────────────

function catalog_make_id($provider, $remoteId) {
    return $provider . ':' . (string)$remoteId;
}

/**
 * Split a raw watch.php?id= value into provider + remote id.
 * Unknown prefixes mean "legacy local database row".
 */
function catalog_parse_id($raw) {
    $raw = (string)$raw;
    $providers = ['anilist', 'reanime', 'jikan', 'anikuro', 'tmdb'];
    foreach ($providers as $p) {
        if (strpos($raw, $p . ':') === 0) {
            $remote = substr($raw, strlen($p) + 1);
            return $remote === '' ? null : ['provider' => $p, 'id' => $remote];
        }
    }
    return ['provider' => 'legacy', 'id' => $raw];
}

function catalog_is_api_id($raw) {
    $parsed = catalog_parse_id($raw);
    return $parsed !== null && $parsed['provider'] !== 'legacy';
}

// ─── Item helpers ──────────────────────────────────────────────────────

function catalog_placeholder_poster() {
    return './uploads/thumbnails/default.png';
}

/** Guarantee every key a template might read exists. */
function catalog_fill_item($item, $provider = null) {
    if (!is_array($item)) return null;
    if ($provider && empty($item['provider'])) $item['provider'] = $provider;
    if (!empty($item['provider']) && empty($item['provider_id'])) {
        $item['provider_id'] = (string)($item['id'] ?? '');
    }

    $defaults = [
        'id' => null, 'provider' => null, 'provider_id' => null,
        'anilist_id' => null, 'mal_id' => null, 'slug' => null,
        'title' => 'Unknown', 'title_romaji' => null, 'title_english' => null,
        'title_native' => null,
        'poster' => '', 'banner' => '', 'description' => '',
        'type' => 'TV', 'format' => null, 'status' => '',
        'episodes' => 0, 'aired_episodes' => 0, 'episode' => null, 'episode_number' => null,
        'duration' => null, 'duration_min' => null,
        'genres' => [], 'studios' => [], 'studio' => null,
        'score' => null, 'rating' => 'N/A', 'popularity' => 0,
        'season' => null, 'year' => null, 'aired' => null,
        'language' => null, 'country' => null,
        'next_airing' => null, 'trailer' => null, 'is_adult' => false, 'has_dub' => false,
        'relations' => [], 'recommendations' => [], 'episodes_list' => [],
        'external_links' => [],
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $item)) $item[$k] = $v;
    }

    if (!is_array($item['genres'])) $item['genres'] = [];
    if (!is_array($item['studios'])) $item['studios'] = [];
    if (!is_array($item['relations'])) $item['relations'] = [];
    if (!is_array($item['recommendations'])) $item['recommendations'] = [];
    if (!is_array($item['episodes_list'])) $item['episodes_list'] = [];

    if ($item['episode_number'] === null && $item['episode'] !== null) {
        $item['episode_number'] = $item['episode'];
    }
    if ((!isset($item['rating']) || $item['rating'] === null || $item['rating'] === '') && !empty($item['score'])) {
        $item['rating'] = $item['score'];
    }
    if (empty($item['poster'])) $item['poster'] = catalog_placeholder_poster();

    return $item;
}

function catalog_fill_items($items, $provider = null) {
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $it) {
        $filled = catalog_fill_item($it, $provider);
        if ($filled) $out[] = $filled;
    }
    return $out;
}

/** Deduplicate by anilist id / mal id / title, preserving order. */
function catalog_dedupe($items) {
    $seen = [];
    $out = [];
    foreach ((array)$items as $it) {
        if (!is_array($it)) continue;
        $key = 'a:' . ($it['anilist_id'] ?? '')
             . '|m:' . ($it['mal_id'] ?? '')
             . '|t:' . strtolower(trim((string)($it['title'] ?? '')));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $it;
    }
    return $out;
}

// ─── Home ──────────────────────────────────────────────────────────────

/**
 * Rows for the home page. Returns:
 *   ['airing' => [...], 'trending' => [...], 'popular' => [...], 'provider' => '...']
 */
function catalog_home($limit = 20) {
    $out = ['airing' => [], 'trending' => [], 'popular' => [], 'provider' => null];

    if (ANILIST_ENABLED) {
        $airing   = anilist_airing(1, $limit);
        $trending = anilist_trending(1, $limit);
        $popular  = anilist_popular(1, $limit);
        if ($airing || $trending || $popular) {
            $out['airing']   = catalog_fill_items($airing, 'anilist');
            $out['trending'] = catalog_fill_items($trending, 'anilist');
            $out['popular']  = catalog_fill_items($popular, 'anilist');
            $out['provider'] = 'anilist';
            return $out;
        }
        error_log('[catalog] AniList returned nothing, falling back');
    }

    if (REANIME_ENABLED) {
        $home = reanime_home($limit);
        if (!empty($home['latest_aired']) || !empty($home['top_weekly'])) {
            $out['airing']   = catalog_fill_items($home['latest_aired'] ?? [], 'reanime');
            $out['trending'] = catalog_fill_items($home['top_weekly'] ?? [], 'reanime');
            $out['popular']  = $out['airing'];
            $out['provider'] = 'reanime';
            return $out;
        }
    }

    if (JIKAN_ENABLED) {
        $airing = catalog_fill_items(jikan_airing($limit), 'jikan');
        $top    = catalog_fill_items(jikan_top($limit), 'jikan');
        if ($airing || $top) {
            $out['airing']   = $airing;
            $out['trending'] = $top;
            $out['popular']  = $top;
            $out['provider'] = 'jikan';
        }
    }

    return $out;
}

function catalog_trending($limit = 20) {
    if (ANILIST_ENABLED) {
        $items = catalog_fill_items(anilist_trending(1, $limit), 'anilist');
        if ($items) return $items;
    }
    if (REANIME_ENABLED) {
        $items = catalog_fill_items(reanime_top('week', $limit), 'reanime');
        if ($items) return $items;
    }
    return catalog_fill_items(jikan_top($limit), 'jikan');
}

/**
 * Trending rows for one time window, used by the sidebar tabs.
 *
 *   day   — what AniList is trending on right now
 *   week  — most popular currently-airing titles
 *   month — this season's most popular titles
 *
 * Anything unrecognised falls back to the day window.
 */
function catalog_trending_period($period = 'day', $limit = 10) {
    $period = strtolower(trim((string)$period));
    if (!in_array($period, ['day', 'week', 'month'], true)) $period = 'day';
    $limit = max(3, min(50, (int)$limit));

    if (ANILIST_ENABLED) {
        if ($period === 'week') {
            $items = catalog_fill_items(anilist_airing(1, $limit), 'anilist');
        } elseif ($period === 'month') {
            $items = catalog_fill_items(anilist_season(null, null, $limit), 'anilist');
        } else {
            $items = catalog_fill_items(anilist_trending(1, $limit), 'anilist');
        }
        if ($items) return catalog_dedupe($items);
    }

    if (REANIME_ENABLED) {
        $items = catalog_fill_items(reanime_top($period, $limit), 'reanime');
        if ($items) return catalog_dedupe($items);
    }

    return catalog_dedupe(catalog_trending($limit));
}

function catalog_airing($limit = 24) {
    if (ANILIST_ENABLED) {
        $items = catalog_fill_items(anilist_airing(1, $limit), 'anilist');
        if ($items) return $items;
    }
    if (REANIME_ENABLED) {
        $items = catalog_fill_items(reanime_home($limit)['latest_aired'] ?? [], 'reanime');
        if ($items) return $items;
    }
    return catalog_fill_items(jikan_airing($limit), 'jikan');
}

/** Genre list and sort options for the genre browser. */
function catalog_genres() {
    return anilist_genres();
}

function catalog_sort_options() {
    return [
        'popular'  => 'Most Popular',
        'score'    => 'Top Rated',
        'trending' => 'Trending',
        'newest'   => 'Newest',
    ];
}

/** Normalize a raw genre name against the known list (case-insensitive). */
function catalog_normalize_genre($genre) {
    $genre = trim((string)$genre);
    if ($genre === '') return null;
    foreach (catalog_genres() as $known) {
        if (strcasecmp($known, $genre) === 0) return $known;
    }
    return null;
}

/**
 * Browse one genre.
 *
 * @return array ['items' => [...], 'page' => int, 'has_next' => bool, 'genre' => string]
 */
function catalog_by_genre($genre, $page = 1, $perPage = 24, $sort = 'popular') {
    $genre = catalog_normalize_genre($genre);
    $page = max(1, (int)$page);

    if ($genre === null) {
        return ['items' => [], 'page' => 1, 'has_next' => false, 'genre' => null];
    }

    if (ANILIST_ENABLED) {
        $result = anilist_by_genre($genre, $page, $perPage, $sort);
        $items = catalog_fill_items($result['items'] ?? [], 'anilist');
        if ($items || $page > 1) {
            return [
                'items'    => $items,
                'page'     => $page,
                'has_next' => !empty($result['has_next']),
                'genre'    => $genre,
            ];
        }
    }

    // Last resort: Jikan's top list (it has no genre-by-genre browse here).
    return [
        'items'    => catalog_fill_items(jikan_top($perPage), 'jikan'),
        'page'     => $page,
        'has_next' => false,
        'genre'    => $genre,
    ];
}

// ─── Search ────────────────────────────────────────────────────────────

/**
 * Search across providers and merge. AniList is queried first and its hits
 * are placed at the top; the other providers fill in anything missing.
 */
function catalog_search($term, $limit = 20) {
    $term = trim((string)$term);
    if ($term === '') return [];

    $merged = [];
    $order = catalog_order();

    foreach ($order as $provider) {
        if (count(catalog_dedupe($merged)) >= $limit) break;
        try {
            switch ($provider) {
                case 'anilist':
                    $merged = array_merge($merged, anilist_search($term, $limit));
                    break;
                case 'reanime':
                    $merged = array_merge($merged, reanime_search($term, $limit)['results'] ?? []);
                    break;
                case 'jikan':
                    $merged = array_merge($merged, jikan_search($term, $limit));
                    break;
                case 'anikuro':
                    $ak = anikuro_search($term, $limit);
                    $merged = array_merge($merged, $ak['results'] ?? []);
                    break;
            }
        } catch (Exception $e) {
            error_log("[catalog] search via $provider failed: " . $e->getMessage());
        }
    }

    // Also search TMDB for movies and TV
    if (TMDB_ENABLED && count(catalog_dedupe($merged)) < $limit) {
        try {
            $remaining = $limit - count(catalog_dedupe($merged));
            $movies = tmdb_movie_search($term, 1);
            $tv = tmdb_tv_search($term, 1);
            $merged = array_merge($merged, array_slice($movies, 0, $remaining), array_slice($tv, 0, $remaining));
        } catch (Exception $e) {
            error_log("[catalog] TMDB search failed: " . $e->getMessage());
        }
    }

    $merged = catalog_dedupe(catalog_fill_items($merged));
    return array_slice($merged, 0, $limit);
}

// ─── Detail ────────────────────────────────────────────────────────────

/** Provider-specific detail lookup. */
function catalog_info_from($provider, $remoteId) {
    switch ($provider) {
        case 'anilist':
            return anilist_info((int)$remoteId);

        case 'reanime':
            $item = reanime_info($remoteId);
            if (!$item) return null;
            $item['id'] = catalog_make_id('reanime', $remoteId);
            $item['provider'] = 'reanime';
            $item['provider_id'] = (string)$remoteId;
            $item['slug'] = $remoteId;
            return $item;

        case 'jikan':
            return jikan_info((int)$remoteId);

        case 'anikuro':
            $item = anikuro_info($remoteId);
            if (!$item) return null;
            $item['id'] = catalog_make_id('anikuro', $remoteId);
            $item['provider'] = 'anikuro';
            $item['provider_id'] = (string)$remoteId;
            return $item;

        case 'tmdb':
            // remoteId is "movie:123" or "tv:123"
            if (preg_match('/^movie:(\d+)$/', $remoteId, $m)) {
                return tmdb_movie_detail((int)$m[1]);
            } elseif (preg_match('/^tv:(\d+)$/', $remoteId, $m)) {
                return tmdb_tv_detail((int)$m[1]);
            }
            return null;
    }
    return null;
}

/**
 * Detail lookup with cross-provider fallback.
 *
 * @param string $rawId  e.g. "anilist:21", "reanime:one-piece-xamk74"
 * @param string|null $hintTitle  used to re-search the next provider
 * @return array|null
 */
function catalog_info($rawId, $hintTitle = null) {
    $parsed = catalog_parse_id($rawId);
    if (!$parsed) return null;

    $provider = $parsed['provider'];
    if ($provider === 'legacy') return null;

    $remoteId = $parsed['id'];
    $item = catalog_info_from($provider, $remoteId);

    if (is_array($item)) {
        return catalog_fill_item($item, $provider);
    }

    // The requested provider failed — try the remaining ones.
    $known = ['anilist', 'reanime', 'jikan'];
    $known = array_values(array_filter($known, fn($p) => $p !== $provider));

    // A Jikan/MAL id maps straight onto AniList's idMal, so no title needed.
    if ($provider === 'jikan' && ANILIST_ENABLED && in_array('anilist', catalog_order(), true)) {
        $byMal = anilist_info_by_mal((int)$remoteId);
        if (is_array($byMal)) {
            error_log("[catalog] 'jikan:$remoteId' resolved through AniList idMal");
            return catalog_fill_item($byMal, 'anilist');
        }
    }

    $title = $hintTitle;
    if (!$title && $provider === 'reanime') {
        // The slug doubles as a readable title.
        $title = ucwords(str_replace(['-', '_'], ' ', $remoteId));
        $title = preg_replace('/\s+[a-z0-9]{5,8}$/i', '', $title); // strip trailing hash
    }

    foreach ($known as $p) {
        if (!in_array($p, catalog_order(), true)) continue;
        if (!$title) break;

        $results = $p === 'anilist'
            ? anilist_search($title, 5)
            : ($p === 'reanime'
                ? (reanime_search($title, 5)['results'] ?? [])
                : jikan_search($title, 5));

        foreach ((array)$results as $cand) {
            if (empty($cand['id'])) continue;
            $found = catalog_info_from($p, $cand['provider_id'] ?? $cand['id']);
            if (is_array($found)) {
                error_log("[catalog] '$rawId' resolved via fallback provider '$p'");
                return catalog_fill_item($found, $p);
            }
        }
    }

    return null;
}

/** Episode list for any provider. */
function catalog_episodes($rawId, $hintTitle = null) {
    $parsed = catalog_parse_id($rawId);
    if (!$parsed || $parsed['provider'] === 'legacy') return [];

    $provider = $parsed['provider'];
    $remoteId = $parsed['id'];

    switch ($provider) {
        case 'anilist':
            $info = anilist_info((int)$remoteId);
            return $info['episodes_list'] ?? [];

        case 'reanime':
            $eps = reanime_episodes($remoteId);
            if ($eps) return $eps;
            break;

        case 'jikan':
            $eps = jikan_episodes((int)$remoteId);
            if ($eps) return $eps;
            $info = jikan_info((int)$remoteId);
            if ($info) return catalog_synthetic_episodes($info);
            break;

        case 'anikuro':
            return anikuro_episodes($remoteId) ?: [];

        case 'tmdb':
            if (preg_match('/^tv:(\d+)$/', $remoteId, $m)) {
                $detail = tmdb_tv_detail((int)$m[1]);
                return $detail['episodes_list'] ?? [];
            }
            // Movie has a single "episode"
            return [['number' => 1, 'title' => '', 'image' => '', 'aired' => '']];

        default:
            break;
    }

    // Nothing from the requested provider: fall back to a full detail lookup.
    if ($provider !== 'anilist') {
        $info = catalog_info($rawId, $hintTitle);
        if ($info) {
            if (!empty($info['episodes_list'])) return $info['episodes_list'];
            return catalog_synthetic_episodes($info);
        }
    }

    return [];
}

/** Build numbered episodes from a total count. */
function catalog_synthetic_episodes($info, $max = 2000) {
    $total = (int)max($info['episodes'] ?? 0, $info['aired_episodes'] ?? 0);
    if ($total <= 0) return [];
    $total = min($total, $max);

    $existing = [];
    foreach (($info['episodes_list'] ?? []) as $ep) {
        if (isset($ep['number'])) $existing[(int)$ep['number']] = $ep;
    }

    $out = [];
    for ($i = 1; $i <= $total; $i++) {
        $out[] = $existing[$i] ?? ['number' => $i, 'title' => '', 'image' => '', 'aired' => ''];
    }
    return $out;
}

// ─── Schedule ──────────────────────────────────────────────────────────

/**
 * Weekly schedule grouped by day.
 *
 * @param string $tz
 * @param int    $weekOffset  0 = current week
 * @return array  [['date'=>..,'day'=>..,'episodes'=>[item,...]], ...]
 */
function catalog_schedule($tz = 'Asia/Dhaka', $weekOffset = 0) {
    $tzObj = new DateTimeZone($tz);
    $monday = new DateTime('now', $tzObj);
    $monday->setTime(0, 0, 0);
    $monday->modify('monday this week');
    if ($weekOffset != 0) {
        $monday->modify(($weekOffset > 0 ? '+' : '') . (int)$weekOffset . ' week');
    }
    $sunday = clone $monday;
    $sunday->modify('+7 day');

    if (ANILIST_ENABLED) {
        $buckets = anilist_schedule($monday->getTimestamp(), $sunday->getTimestamp(), $tz);
        if ($buckets) {
            foreach ($buckets as &$b) {
                $b['episodes'] = catalog_fill_items($b['episodes'], 'anilist');
            }
            unset($b);
            return $buckets;
        }
    }

    if (REANIME_ENABLED) {
        $raw = reanime_schedule($tz, $weekOffset);
        $entries = $raw['entries'] ?? [];
        if ($entries) {
            $out = [];
            foreach ($entries as $b) {
                if (!is_array($b)) continue;
                $b['episodes'] = catalog_fill_items($b['episodes'] ?? [], 'reanime');
                $out[] = $b;
            }
            if ($out) return $out;
        }
    }

    // Jikan has no bulk week endpoint — fetch each weekday and group.
    if (JIKAN_ENABLED) {
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $day = clone $monday;
            $day->modify("+$i day");
            $eps = jikan_schedule(strtolower($day->format('l')), 25);
            if (!$eps) continue;
            $out[] = [
                'date'     => $day->format('Y-m-d'),
                'day'      => $day->format('l'),
                'episodes' => catalog_fill_items($eps, 'jikan'),
            ];
        }
        if ($out) return $out;
    }

    return [];
}

/**
 * Sidebar Upcoming pool: AniList schedule + unreleased premieres + TMDB
 * movies/TV. Every dated row carries next_airing.airingAt so
 * kp_group_upcoming() can split TODAY / NEXT / LATER without guessing.
 *
 * Sources:
 *   - AniList airing schedule (today → +8d) — exact episode timestamps
 *   - TMDB movie/upcoming + discover — release_date → local noon
 *   - TMDB tv/airing_today — pinned to midday today when the list row
 *     omits next_episode_to_air (the endpoint itself means "airs today")
 *   - TMDB tv/on_the_air — only when a real next air date or future
 *     premiere date is known (no invented midweek timestamps)
 *   - AniList NOT_YET_RELEASED — undated / far-future → LATER
 *
 * Past timestamps are dropped; first occurrence of an id wins (so the
 * AniList schedule's soonest airing is kept). The return is stratified so
 * a busy "today" cannot crowd NEXT/LATER out of the sidebar.
 *
 * @param int $limit max rows returned (groups slice further for display)
 * @return array
 */
function catalog_upcoming_feed($limit = 36) {
    $now = time();
    $startOfDay = (int)strtotime('today', $now);
    $items = [];
    $seen = [];

    $push = function ($item, $ts = 0, $ep = null) use (&$items, &$seen, $startOfDay) {
        if (!is_array($item) || empty($item['id']) || isset($seen[$item['id']])) return;
        $ts = (int)$ts;
        if ($ts > 0 && $ts < $startOfDay) return; // already aired before today
        if ($ts > 0) {
            $ep = ($ep === null || $ep === 0) ? ($item['next_airing']['episode'] ?? null) : $ep;
            $item['next_airing'] = ['airingAt' => $ts, 'episode' => $ep !== null ? (int)$ep : null];
        } elseif (isset($item['next_airing']['airingAt'])
            && (int)$item['next_airing']['airingAt'] < $startOfDay) {
            $item['next_airing'] = null; // stale schedule from normalize()
        }
        $seen[$item['id']] = true;
        $items[] = $item;
    };

    // Exact anime episode times — 8 days covers TODAY + the full NEXT window.
    if (ANILIST_ENABLED) {
        foreach (anilist_schedule($startOfDay, $startOfDay + 8 * 86400, 'Asia/Dhaka') as $bucket) {
            foreach (($bucket['episodes'] ?? []) as $epItem) {
                $push($epItem, (int)($epItem['airingAt'] ?? 0), $epItem['episode'] ?? null);
            }
        }
    }

    // Movies: release_date drives TODAY / NEXT / LATER. /movie/upcoming
    // sometimes returns already-released titles (region quirks), so only
    // future dates (or undated UPCOMING) enter the pool. Timestamps land at
    // local noon so a midnight release does not sort above every episode.
    foreach (tmdb_movie_upcoming(1) as $movie) {
        $date = (string)($movie['aired'] ?? '');
        $ts = $date !== '' ? strtotime($date . ' 12:00:00') : 0;
        if ($ts !== false && $ts >= $startOfDay) {
            $push($movie, $ts, null); // movies: no EP badge
        } elseif ($ts === 0 && (($movie['status'] ?? '') === 'NOT_YET_RELEASED' || ($movie['status'] ?? '') === 'UPCOMING')) {
            $push($movie, 0);
        }
    }

    // Discover fallback — /movie/upcoming can lag; pull real future releases.
    if (function_exists('tmdb_get')) {
        $discKey = api_cache_key('tmdb_movie', ['upcoming_future', date('Y-m-d', $startOfDay)]);
        $disc = api_cache_get($discKey);
        if (!is_array($disc)) {
            $raw = tmdb_get('/discover/movie', [
                'page'                   => 1,
                'language'               => 'en-US',
                'sort_by'                => 'primary_release_date.asc',
                'primary_release_date.gte' => date('Y-m-d', $startOfDay),
                'region'                 => 'US',
            ]);
            $disc = [];
            foreach (($raw['results'] ?? []) as $m) {
                $n = tmdb_movie_normalize($m);
                if ($n) $disc[] = $n;
            }
            if ($disc) api_cache_set($discKey, 'tmdb_movie', $disc, CACHE_TTL_TMDB_LIST);
        }
        foreach ((array)$disc as $movie) {
            $date = (string)($movie['aired'] ?? '');
            $ts = $date !== '' ? strtotime($date . ' 12:00:00') : 0;
            if ($ts !== false && $ts >= $startOfDay) {
                $push($movie, $ts, null);
            }
        }
    }

    // TV airing today — trust the endpoint's day, pin midday when undated.
    foreach (tmdb_tv_airing_today(1) as $show) {
        $ts = (int)($show['next_airing']['airingAt'] ?? 0);
        if ($ts < $startOfDay) $ts = $startOfDay + 12 * 3600;
        $push($show, $ts, $show['next_airing']['episode'] ?? null);
    }

    // On-the-air: only when the next episode or a future premiere is known.
    foreach (tmdb_tv_on_the_air(1) as $show) {
        $ts = (int)($show['next_airing']['airingAt'] ?? 0);
        if ($ts > $startOfDay) {
            $push($show, $ts, $show['next_airing']['episode'] ?? null);
            continue;
        }
        $premiere = (string)($show['aired'] ?? '');
        $fts = $premiere !== '' ? strtotime($premiere . ' 12:00:00') : 0;
        if ($fts !== false && $fts > $now) {
            $push($show, $fts, null);
        }
    }

    // Unreleased titles (premiere pending, often undated) → LATER.
    if (ANILIST_ENABLED) {
        foreach (anilist_upcoming_media(16) as $unreleased) {
            $ts = (int)($unreleased['next_airing']['airingAt'] ?? 0);
            if ($ts > $now) {
                $push($unreleased, $ts, $unreleased['next_airing']['episode'] ?? null);
            } else {
                $push($unreleased, 0);
            }
        }
    }

    usort($items, function ($a, $b) {
        $ta = (int)($a['next_airing']['airingAt'] ?? 0) ?: PHP_INT_MAX;
        $tb = (int)($b['next_airing']['airingAt'] ?? 0) ?: PHP_INT_MAX;
        return $ta <=> $tb;
    });

    // Stratified sample: a packed "today" must not push NEXT/LATER off the
    // list, and movie release-midnight must not crowd out episode rows —
    // within each window, interleave series then movies.
    $nextStart = $startOfDay + 86400;
    $nextEnd   = $startOfDay + 8 * 86400;
    $windows   = ['today' => [], 'next' => [], 'later' => []];
    foreach ($items as $item) {
        $ts = (int)($item['next_airing']['airingAt'] ?? 0);
        if ($ts >= $startOfDay && $ts < $nextStart) {
            $windows['today'][] = $item;
        } elseif ($ts >= $nextStart && $ts < $nextEnd) {
            $windows['next'][] = $item;
        } else {
            $windows['later'][] = $item;
        }
    }

    $limit  = max(3, (int)$limit);
    $perWin = max(3, (int)ceil($limit / 3));
    $out    = [];
    foreach ($windows as $rows) {
        $series = [];
        $movies = [];
        foreach ($rows as $item) {
            $isMovie = strtoupper((string)($item['format'] ?? '')) === 'MOVIE'
                || ($item['format'] ?? '') === 'Movie'
                || ($item['content_type'] ?? '') === 'movie';
            if ($isMovie) $movies[] = $item;
            else $series[] = $item;
        }
        $merged = [];
        $i = 0;
        while (count($series) > $i || count($movies) > $i) {
            if (isset($series[$i])) $merged[] = $series[$i];
            if (isset($movies[$i])) $merged[] = $movies[$i];
            $i++;
        }
        $out = array_merge($out, array_slice($merged, 0, $perWin));
    }
    return array_slice($out, 0, $limit);
}
