<?php
/**
 * FlixHQ (flixhq.vc) — direct servers for MOVIES and TV in our own player.
 *
 * Chain (all plain HTTP, no cookies, no JS engine):
 *   GET /search?keyword={q}                  → result items (film-name links)
 *   GET /watch-movie|watch-series/{slug}/    → data-token on #main-wrapper
 *                                              or #series-player
 *   GET /episode/{slug}/s{SS}-e{NN}/         → data-token (TV episodes;
 *                                              falls back to the series page's
 *                                              own episode links)
 *   POST /ajax/ajax.php players|players_show={token}
 *                                            → [{name, link, en_sub}, …]
 *   GET {link}                               → embed page with a plaintext
 *                                              sources config (kaembed/vidmoly)
 *   GET {subget|sub.info JSON}               → [{file,label,kind}, …] captions
 *
 * Servers this cannot open (mfw09's attestation/PoW API, videasy's cipher)
 * are simply skipped — Vidmoly covers the working direct path.
 */

include_once __DIR__ . '/http.php';

function flixhq_enabled() {
    return defined('FLIXHQ_ENABLED') && FLIXHQ_ENABLED;
}

function flixhq_timeout() {
    return defined('FLIXHQ_TIMEOUT') ? max(4, (int)FLIXHQ_TIMEOUT) : 10;
}

function flixhq_base() {
    $bases = $GLOBALS['FLIXHQ_BASE_URLS'] ?? ['https://flixhq.vc'];
    return rtrim((string)reset($bases), '/');
}

/**
 * GET/POST returning [code, body] (code 0 = network failure after one
 * retry). Its own cURL instead of api_http so callers can tell a real
 * 404/500 apart from a timeout — only definitive misses may be
 * negative-cached, a network blip must never poison the page cache.
 */
function fh_http($url, array $headers = [], $timeout = null, $post = null) {
    $baseHeaders = [
        'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ];
    foreach ($headers as $k => $v) {
        $baseHeaders[] = is_int($k) ? $v : ($k . ': ' . $v);
    }

    $timeout = $timeout ?? flixhq_timeout();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 4,
            CURLOPT_CONNECTTIMEOUT  => 6,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_ENCODING        => '',
            CURLOPT_HTTPHEADER      => $baseHeaders,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : (string)$post);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code > 0) return [$code, is_string($body) ? $body : ''];
        // Network failure — one immediate retry, then give up with code 0.
        error_log("[flixhq] $err ($url)");
    }
    return [0, ''];
}

/**
 * GET a token page (cached). Classification mirrors ToonStream's fix:
 * only a real 404/410 (or a token-less 200 that IS the site's own miss
 * shell) is a cacheable MISS — Cloudflare 429/403/5xx challenges are
 * TRANSIENT and must never be negative-cached, or one rate-limit burst
 * would blank the provider for the whole TTL (observed live: alternates
 * between full and empty results). Positive entries must carry the
 * data-token the callers exist for, so a cached challenge page can't
 * masquerade as one either.
 */
function flixhq_page($path) {
    $path = '/' . ltrim((string)$path, '/');
    $key = api_cache_key('flixhq', ['page', $path]);
    $hit = api_cache_get($key);
    if (is_array($hit) && array_key_exists('html', $hit)) return (string)$hit['html'];

    [$code, $body] = fh_http(flixhq_base() . $path, ['Referer: ' . flixhq_base() . '/']);
    $hasToken = $body !== '' && strpos($body, 'data-token') !== false;
    if ($code === 200 && $hasToken) {
        api_cache_set($key, 'flixhq', ['html' => $body], defined('FLIXHQ_PAGE_TTL') ? (int)FLIXHQ_PAGE_TTL : 300);
        return $body;
    }
    if ($code === 200 && strlen($body) > 500) return $body;   // big but token-less (markup drift): pass through, uncached
    if ($code === 404 || $code === 410 || ($code === 200 && $body !== '' && strlen($body) < 500)) {
        api_cache_set($key, 'flixhq', ['html' => ''], defined('FLIXHQ_PAGE_TTL') ? (int)FLIXHQ_PAGE_TTL : 300);
    }
    // code 0 (already retried), 429/403/5xx challenges: transient, uncached.
    return '';
}

/** "/watch-movie/interstellar-2014-watch-online/" → "interstellar". */
function flixhq_slug_from_path($path) {
    $slug = basename(trim((string)$path, '/'));
    $slug = preg_replace('/-watch-online$/', '', $slug);
    $slug = preg_replace('/-(?:19|20)\d{2}$/', '', $slug);
    return $slug;
}

/** Same rules as ToonStream's: lowercase, alnum → dashes. */
function flixhq_slugify($title) {
    $s = strtolower(trim((string)$title));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim((string)$s, '-');
    return $s;
}

/**
 * Search results for a title: [['title','url','type'], …] where type is
 * 'movie' | 'tv' (from the /watch-movie vs /watch-series URL shape).
 */
function flixhq_search($title) {
    $q = trim((string)$title);
    if ($q === '') return [];

    $key = api_cache_key('flixhq', ['search', strtolower($q)]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $results = [];
    [$code, $html] = fh_http(flixhq_base() . '/search?keyword=' . rawurlencode($q),
        ['Referer: ' . flixhq_base() . '/']);
    if ($code === 200 && $html !== '') {
        if (preg_match_all('~class="film-name"\s*>\s*<a[^>]+href="(https?://[^"]+)"[^>]*>(.*?)</a>~s',
                $html, $m, PREG_SET_ORDER)) {
            $seen = [];
            foreach ($m as $one) {
                $url = $one[1];
                if (isset($seen[$url])) continue;
                if (strpos($url, '/watch-movie/') !== false)      $type = 'movie';
                elseif (strpos($url, '/watch-series/') !== false) $type = 'tv';
                else continue;
                $seen[$url] = true;
                $results[] = [
                    'title' => trim(html_entity_decode(strip_tags($one[2]))),
                    'url'   => $url,
                    'type'  => $type,
                ];
            }
        }
    }

    if ($results) {
        api_cache_set($key, 'flixhq', $results, defined('FLIXHQ_SEARCH_TTL') ? (int)FLIXHQ_SEARCH_TTL : 21600);
    }
    return $results;
}

/**
 * Best result for (title, type): exact slug 100 / prefix 85 / candidate
 * coverage ≥60% (the query must cover the row — same guard that keeps
 * "Naruto" from matching Boruto on ToonStream), +10 for a matching year
 * found in the slug, −25 for a conflicting one. Below 60 → null (wrong
 * title beats no title, the embed chain keeps its job).
 */
function flixhq_pick($title, $year, $type) {
    $title = trim((string)$title);
    if ($title === '') return null;
    $want = ($type === 'tv') ? '/watch-series/' : '/watch-movie/';

    $slugQ = flixhq_slugify($title);
    if ($slugQ === '') return null;

    $best = null;
    $bestScore = 0;
    foreach (flixhq_search($title) as $row) {
        if (strpos($row['url'], $want) === false) continue;   // URL shape = ground truth
        $path = '/' . trim((string)(parse_url($row['url'], PHP_URL_PATH) ?: $row['url']), '/');
        $slug = flixhq_slug_from_path($path);
        if ($slug === '') continue;

        $score = 0;
        if ($slug === $slugQ) {
            $score = 100;
        } elseif (strpos($slug, $slugQ . '-') === 0 || strpos($slugQ, $slug . '-') === 0) {
            $score = 85;
        } else {
            $a = explode('-', $slugQ);
            $b = explode('-', $slug);
            $ratio = count(array_intersect($a, $b)) / max(1, count($b));
            if ($ratio >= 0.6) $score = (int)round(60 * $ratio);
        }
        if ($score <= 0) continue;

        // Year embedded in the slug (interstellar-2014): a different year
        // is probably a remake/alternative cut of the same words.
        if ($year && preg_match('~-(?:19|20)(\d{2})$~', $slug, $ym)) {
            $slugYear = (int)('20' . $ym[1]);
            if ($slugYear >= 1900) {
                $score += (abs($slugYear - (int)$year) <= 1) ? 10 : -25;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $path;
        }
    }
    return ($best && $bestScore >= 60) ? $best : null;
}

/** The page's data-token (movies: #main-wrapper, TV: #series-player). */
function flixhq_page_token($html) {
    if (preg_match('~id="main-wrapper"[^>]*data-token="([^"]+)"~', $html, $m)) return $m[1];
    if (preg_match('~id="series-player"[^>]*data-token="([^"]+)"~', $html, $m)) return $m[1];
    if (preg_match('~data-token="([^"]+)"~', $html, $m)) return $m[1];
    return null;
}

/** Server list [{name, link}] for a watch/episode page ([] on any miss). */
function flixhq_players($path, $field) {
    $html = flixhq_page($path);
    if ($html === '') return [];
    $token = flixhq_page_token($html);
    if ($token === null) return [];

    [$code, $body] = fh_http(
        flixhq_base() . '/ajax/ajax.php',
        ['Referer: ' . flixhq_base() . $path],
        min(8, flixhq_timeout()),
        $field . '=' . rawurlencode($token)
    );
    if ($code !== 200 || $body === '') return [];
    $json = json_decode($body, true);
    if (!is_array($json)) return [];

    $out = [];
    foreach ($json as $srv) {
        if (!is_array($srv) || empty($srv['link'])) continue;
        $out[] = [
            'name' => (string)($srv['name'] ?? ''),
            'link' => (string)$srv['link'],
        ];
    }
    return $out;
}

/**
 * The episode's own path: constructed s{SS}-e{NN} first (the real
 * pattern), then the series page's own links when the numbering differs.
 * [] on a miss.
 */
function flixhq_episode_path($seriesPath, $season, $episode) {
    $season  = max(1, (int)$season);
    $episode = max(1, (int)$episode);

    $slug = basename(trim($seriesPath, '/'));
    $try = '/episode/' . $slug . '/s'
         . str_pad((string)$season, 2, '0', STR_PAD_LEFT) . '-e'
         . str_pad((string)$episode, 2, '0', STR_PAD_LEFT) . '/';
    if (flixhq_page_token(flixhq_page($try)) !== null) return $try;

    // Series pages render every season's episode links (#ss-episodes-N
    // panes) — match SxE straight off them.
    $series = flixhq_page($seriesPath);
    if ($series !== '' && preg_match_all('~href="(/episode/[^"]+)"~i', $series, $m)) {
        foreach (array_unique($m[1]) as $href) {
            if (preg_match('~s(\d+)-e(\d+)~i', $href, $em)
                && (int)$em[1] === $season && (int)$em[2] === $episode) {
                if (flixhq_page_token(flixhq_page($href)) !== null) return $href;
            }
        }
        // Same episode number, any season — a show whose site numbering
        // restarts still resolves rather than failing outright.
        foreach (array_unique($m[1]) as $href) {
            if (preg_match('~s(\d+)-e(\d+)~i', $href, $em) && (int)$em[2] === $episode) {
                if (flixhq_page_token(flixhq_page($href)) !== null) return $href;
            }
        }
    }
    return null;
}

/** subget= / sub.info= caption JSON → [{url, language, format, default}]. */
function flixhq_subtitles($serverLink) {
    $query = (string)(parse_url($serverLink, PHP_URL_QUERY) ?? '');
    if ($query === '') return [];
    parse_str($query, $qs);
    $subUrl = (string)($qs['subget'] ?? $qs['sub.info'] ?? '');
    if ($subUrl === '' || strpos($subUrl, 'http') !== 0) return [];

    $key = api_cache_key('flixhq', ['sub', md5($subUrl)]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    [$code, $body] = fh_http($subUrl, [], min(6, flixhq_timeout()));
    if ($code !== 200 || $body === '') return [];   // transient — do not cache
    $json = json_decode($body, true);
    $out = [];
    if (is_array($json)) {
        foreach ($json as $one) {
            if (!is_array($one) || empty($one['file'])) continue;
            $kind = strtolower((string)($one['kind'] ?? 'captions'));
            if ($kind === 'thumbnails') continue;
            $file = (string)$one['file'];
            $ext  = strtolower(pathinfo((string)parse_url($file, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!in_array($ext, ['vtt', 'srt'], true)) continue;
            $out[] = [
                'url'      => $file,
                'language' => (string)($one['label'] ?? 'Subtitle'),
                'format'   => $ext,
                'default'  => !empty($one['default']),
            ];
        }
    }
    api_cache_set($key, 'flixhq', $out, 21600);
    return $out;
}

/** Direct file URL out of an embed page (plaintext sources configs). */
function flixhq_extract($embedUrl, $timeout = null) {
    [$code, $html] = fh_http($embedUrl, ['Referer: ' . flixhq_base() . '/'], $timeout ?? min(8, flixhq_timeout()));
    if ($code !== 200 || $html === '') return null;
    if (stripos($html, 'Video Processing') !== false) return null;   // kaembed not ready

    $patterns = [
        "~sources:\s*\[\{\s*file:\s*'([^']+)'~",                 // kaembed / jwplayer
        '~file\s*:\s*"(https?:\/\/[^"]+)"~',                     // jwplayer double quotes
        '~<source[^>]+src="(https?:\/\/[^"]+)"~',                // plain <source>
        '~src\s*:\s*"(https?:\/\/[^"]+\.(?:m3u8|mp4)[^"]*)"~',   // JS src var
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $html, $m)) {
            $url = trim(str_replace('\\/', '/', $m[1]));
            if (preg_match('#^https?://#', $url)) return $url;
        }
    }
    return null;
}

/**
 * Player-ready source entries for one title/episode: every extractable
 * server of the FlixHQ page, tagged lang=any / audio_lang=English (the
 * chips then read [English]) and carrying the page's caption tracks.
 *
 * @param string $type  'movie' | 'tv'
 * @return array        same shape as the movie/TV endpoints' $sources
 */
function flixhq_source_entries($title, $year, $type, $season = 1, $episode = 1) {
    if (!flixhq_enabled()) return [];
    $deadline = time() + (defined('FLIXHQ_BUDGET') ? max(6, (int)FLIXHQ_BUDGET) : 12);

    $pick = flixhq_pick($title, (int)$year, $type);
    if ($pick === null) return [];

    if ($type === 'tv') {
        $path = flixhq_episode_path($pick, $season, $episode);
        if ($path === null) return [];
        $servers = flixhq_players($path, 'players_show');
    } else {
        $path = $pick;
        $servers = flixhq_players($path, 'players');
    }
    if (!$servers) return [];

    // Extractable plaintext hosts first; attestation/cipher hosts last
    // (they just waste the budget — skipped once time runs out anyway).
    $rank = function ($link) {
        $host = strtolower((string)(parse_url($link, PHP_URL_HOST) ?: ''));
        if (strpos($host, 'kaembed') !== false || strpos($host, 'vidmoly') !== false) return 0;
        if (strpos($host, 'mfw09') !== false) return 5;   // attestation + PoW API
        if (strpos($host, 'videasy') !== false) return 9; // proprietary cipher
        return 3;
    };
    usort($servers, function ($a, $b) use ($rank) {
        $d = $rank($a['link']) - $rank($b['link']);
        return $d !== 0 ? $d : 0;
    });

    $entries = [];
    $seen = [];
    foreach ($servers as $i => $srv) {
        if (time() >= $deadline || count($entries) >= 4) break;
        if (isset($seen[$srv['link']])) continue;

        $url = flixhq_extract($srv['link'], min(8, max(3, $deadline - time())));
        if ($url === null || isset($seen[$url])) continue;
        $seen[$srv['link']] = true;
        $seen[$url] = true;

        $name = trim((string)$srv['name']);
        $label = ($name === '' || strcasecmp($name, 'FlixHQ') === 0)
            ? 'FlixHQ' : 'FlixHQ ' . preg_replace('~\s+~', ' ', $name);

        // CORS: qqqcdn.cloud serves captions without ACAO — hand the browser
        // the signed same-origin proxy URL instead of the raw link.
        $subs = flixhq_subtitles($srv['link']);
        if (function_exists('subtitle_sign')) {
            foreach ($subs as &$sub) {
                $signed = subtitle_sign($sub['url'] ?? '');
                if ($signed !== null) $sub['url'] = $signed;
            }
            unset($sub);
        }

        $entries[] = [
            'key'        => 'flixhq-' . (preg_replace('~[^a-z0-9]+~', '-', strtolower($name ?: 'srv')) ?: 'srv') . '-' . $i,
            'label'      => $label,
            'type'       => 'hls',
            'mode'       => 'hls',
            'url'        => $url,
            'provider'   => 'flixhq',
            'lang'       => 'any',
            'audio_lang' => 'English',
            'dataLink'   => null,
            'headers'    => null,
            'expires_at' => null,
            'subtitles'  => $subs,
            'intro'      => null,
            'outro'      => null,
        ];
    }
    return $entries;
}
