<?php
/**
 * TMDB client — used only for title logos.
 *
 * AniList/Jikan have no logo artwork, so the hero slider asks TMDB for the
 * transparent title image. Everything here is optional: with no key configured
 * (or TMDB_ENABLED = false) every function returns null and the slider keeps
 * its plain text title.
 *
 * Docs: https://developer.themoviedb.org/reference/intro/getting-started
 */

include_once __DIR__ . '/http.php';

// ─── Transport ─────────────────────────────────────────────────────────

/**
 * Is a TMDB credential configured?
 *
 * The values live in config/config.php (TMDB_API_KEY / TMDB_ACCESS_TOKEN) —
 * this only checks that one of them is non-empty.
 */
function tmdb_enabled() {
    return TMDB_ENABLED && (TMDB_API_KEY !== '' || TMDB_ACCESS_TOKEN !== '');
}

/**
 * GET a TMDB endpoint. Returns the decoded JSON, or null on any failure.
 *
 * Both credential styles are supported: a v3 api_key query parameter and a
 * v4 bearer token header (the latter wins when both are present).
 */
function tmdb_get($path, array $query = []) {
    if (!tmdb_enabled()) return null;

    if (TMDB_ACCESS_TOKEN === '' && TMDB_API_KEY !== '') {
        $query['api_key'] = TMDB_API_KEY;
    }

    $url = rtrim(TMDB_BASE_URL, '/') . '/' . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query);

    $headers = [];
    if (TMDB_ACCESS_TOKEN !== '') {
        $headers['Authorization'] = 'Bearer ' . TMDB_ACCESS_TOKEN;
    }

    // Diagnostics: `?debug=1` on title_logos.php reports these, and the
    // counters below stop a failed request from being cached as "no logo".
    $GLOBALS['__tmdb_attempts'] = (int)($GLOBALS['__tmdb_attempts'] ?? 0) + 1;
    $GLOBALS['__tmdb_last_url'] = preg_replace('/api_key=[^&]+/', 'api_key=***', $url);

    $res = api_http($url, ['timeout' => 15, 'label' => 'TMDB', 'headers' => $headers]);
    if (!is_array($res)) {
        $GLOBALS['__tmdb_failures'] = (int)($GLOBALS['__tmdb_failures'] ?? 0) + 1;
        return null;
    }

    return $res;
}

// ─── Lookups ───────────────────────────────────────────────────────────

/**
 * "Mushoku Tensei: Jobless Reincarnation Season 3" -> the base show title.
 *
 * TMDB keeps one row per show, so a season-qualified title almost never
 * matches the /find mapping or a search result.
 */
function tmdb_base_title($title) {
    $title = trim((string)$title);
    $title = preg_replace('/\s*[:\-–]\s*(season|part|cour)\s*\d+.*$/i', '', $title);
    $title = preg_replace('/\s+(season|part|cour)\s*\d+.*$/i', '', $title);
    $title = preg_replace('/\s+(\d+(st|nd|rd|th))\s+season.*$/i', '', $title);
    return trim((string)$title);
}

/**
 * Resolve a TMDB tv id for an anime row.
 *
 * Order: the MyAnimeList mapping (exact), then a title search — first the full
 * title, then the season-stripped title — each with and without the year,
 * because streaming dates often differ from TMDB's first-air date.
 */
function tmdb_tv_id($malId = null, $title = '', $year = null) {
    $malId = (int)$malId;
    if ($malId > 0) {
        $found = tmdb_get('/find/' . $malId, ['external_source' => 'myanimelist_id']);
        $id = $found['tv_results'][0]['id'] ?? null;
        if ($id) return (int)$id;
    }

    $title = trim((string)$title);
    if ($title === '') return null;

    $candidates = array_values(array_unique(array_filter([
        $title,
        tmdb_base_title($title),
    ])));

    foreach ($candidates as $candidate) {
        $query = ['query' => $candidate, 'include_adult' => 'false'];
        if ($year) $query['first_air_date_year'] = (int)$year;

        $res = tmdb_get('/search/tv', $query);
        $id = $res['results'][0]['id'] ?? null;
        if ($id) return (int)$id;

        if ($year) {
            $res = tmdb_get('/search/tv', ['query' => $candidate, 'include_adult' => 'false']);
            $id = $res['results'][0]['id'] ?? null;
            if ($id) return (int)$id;
        }
    }

    return null;
}

/**
 * One verbose, uncached lookup — powers title_logos.php?debug=1.
 * Never echoes the credential itself.
 */
function tmdb_debug_logo($malId = 0, $title = '', $year = 0) {
    $GLOBALS['__tmdb_attempts'] = 0;
    $GLOBALS['__tmdb_failures'] = 0;
    $GLOBALS['__tmdb_last_url'] = null;

    $tvId = tmdb_enabled() ? tmdb_tv_id($malId, $title, $year) : null;
    $logoCount = 0;
    $logo = null;

    if ($tvId) {
        $images = tmdb_get('/tv/' . $tvId . '/images', ['include_image_language' => 'en,ja,null']);
        $logoCount = count($images['logos'] ?? []);
        $logo = tmdb_pick_logo($images['logos'] ?? []);
    }

    return [
        'mal_id'      => (int)$malId,
        'title'       => (string)$title,
        'year'        => (int)$year,
        'base_title'  => tmdb_base_title($title),
        'tv_id'       => $tvId,
        'logo_count'  => $logoCount,
        'logo_url'    => $logo,
        'requests'    => (int)($GLOBALS['__tmdb_attempts'] ?? 0),
        'failures'    => (int)($GLOBALS['__tmdb_failures'] ?? 0),
        'last_request'=> $GLOBALS['__tmdb_last_url'] ?? null,
    ];
}

/**
 * Pick the best title logo out of a TMDB `logos` array.
 *
 * English art first, then Japanese, then language-neutral; ties break on
 * vote score, and very tall/narrow art (poster crops) is penalised.
 */
function tmdb_pick_logo(array $logos) {
    if (!$logos) return null;

    $score = function ($logo) {
        $lang = (string)($logo['iso_639_1'] ?? '');
        $score = 0;
        if ($lang === 'en')      $score += 40;
        elseif ($lang === 'ja')  $score += 12;
        elseif ($lang === '')    $score += 6;

        $score += min(30, (float)($logo['vote_average'] ?? 0));
        $score += min(20, (float)($logo['vote_count'] ?? 0));

        $w = (float)($logo['width'] ?? 0);
        $h = (float)($logo['height'] ?? 0);
        if ($h > 0) {
            $ratio = $w / $h;
            if ($ratio >= 1.6 && $ratio <= 6) $score += 15;
            elseif ($ratio < 1.2) $score -= 25;
        }
        return $score;
    };

    usort($logos, fn($a, $b) => $score($b) <=> $score($a));

    $file = $logos[0]['file_path'] ?? null;
    if (!$file) return null;

    return rtrim(TMDB_IMAGE_BASE, '/') . '/' . ltrim($file, '/');
}

/**
 * Hand-picked logo from $KITSUPLAY_TITLE_LOGOS (config/config.php).
 *
 * Checked before TMDB, so a logo can be used with no API key at all.
 */
function title_logo_override($malId = null, $title = '') {
    $map = $GLOBALS['KITSUPLAY_TITLE_LOGOS'] ?? [];
    if (!is_array($map) || !$map) return null;

    $malId = (int)$malId;
    if ($malId > 0 && !empty($map['mal:' . $malId])) {
        return (string)$map['mal:' . $malId];
    }

    $title = trim((string)$title);
    if ($title !== '' && !empty($map['title:' . strtolower($title)])) {
        return (string)$map['title:' . strtolower($title)];
    }

    return null;
}

/**
 * Best logo available for a catalogue row: manual override first, then TMDB.
 * Null means "keep the text title".
 */
function anime_title_logo(array $item) {
    $override = title_logo_override($item['mal_id'] ?? 0, $item['title'] ?? '');
    if ($override !== null) return $override;

    return tmdb_title_logo($item);
}

/**
 * Title logo URL for one catalogue item, or null.
 *
 * Results (including "no logo") are cached in api_cache, so the second page
 * load costs no TMDB requests.
 */
function tmdb_title_logo(array $item) {
    if (!tmdb_enabled()) return null;

    $malId = (int)($item['mal_id'] ?? 0);
    $title = (string)($item['title'] ?? '');
    $year  = (int)($item['year'] ?? 0);
    if ($malId <= 0 && $title === '') return null;

    $key = api_cache_key('tmdb', ['logo:v2', $malId, strtolower($title), $year]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit['url'] ?? null;

    $GLOBALS['__tmdb_attempts'] = 0;
    $GLOBALS['__tmdb_failures'] = 0;

    $url = null;
    $tvId = tmdb_tv_id($malId, $title, $year);
    if ($tvId) {
        $images = tmdb_get('/tv/' . $tvId . '/images', [
            'include_image_language' => 'en,ja,null',
        ]);
        $url = tmdb_pick_logo($images['logos'] ?? []);
    }

    // Only a real answer is worth remembering: a title TMDB genuinely has no
    // logo for is cached, but a timed-out or rejected request is retried.
    if ($url !== null || (int)($GLOBALS['__tmdb_failures'] ?? 0) === 0) {
        api_cache_set($key, 'tmdb', ['url' => $url], CACHE_TTL_LOGO);
    }

    return $url;
}
