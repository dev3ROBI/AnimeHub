<?php
/**
 * ToonStream scraper — Hindi / Indian-language dubs (and the sub cuts).
 *
 * The site is a Next.js app, not WordPress: a JSON search endpoint
 * (/search/all?q=…) lists every entry with a type (series|movies) and a
 * path; episodes live at /episode/{slug}-{season}x{episode}/ and their
 * player is a wall of plain iframes (emturbovid, AS-CDN, vidmoly, rubystm,
 * …). Nothing is DRM'd and nothing needs a login, so the whole scrape is:
 *
 *   title → best-matching entry → episode page → open embeds until one
 *   hands over a direct HLS/mp4 URL.
 *
 * Language: a Hindi/Tamil/Telugu-dubbed cut is usually its own entry, flagged
 * by markers in the slug ("hindi", "tamil", "muse", "sony-yay", …), while the
 * plain slug is the sub/multi-audio cut. The resolve walks BOTH sides of the
 * toggle in one pass: the requested cut owns the primary, the other cut rides
 * behind it, so the watch page can list every server with its own language
 * tag ([DUB · Hindi] / [Multi Audio]) instead of hiding rows behind the
 * SUB/DUB filter. Episode pages group their player under LANGUAGE TABS with
 * per-server cards (<a href="#options-N">…<span class="server">Ruby</span>
 * + <div id="options-N"><iframe …>) — card names ("Ruby", "HD", "cloudy")
 * become part of the server label.
 *
 * Mirrors rotate (.dad, .day, .in, .shop …): $GLOBALS['TOONSTREAM_BASE_URLS']
 * is tried in order, the same arrangement as the 8Stream base list.
 */

include_once __DIR__ . '/http.php';

function toonstream_enabled() {
    return defined('TOONSTREAM_ENABLED') && TOONSTREAM_ENABLED;
}

function toonstream_timeout() {
    return defined('TOONSTREAM_TIMEOUT') ? max(3, (int)TOONSTREAM_TIMEOUT) : 6;
}

/** Configured mirrors, normalised (no trailing slash, http(s) only). */
function toonstream_bases() {
    $out = [];
    foreach ((array)($GLOBALS['TOONSTREAM_BASE_URLS'] ?? []) as $base) {
        $base = rtrim(trim((string)$base), '/');
        if ($base !== '' && preg_match('#^https?://#i', $base)) $out[] = $base;
    }
    return $out;
}

// ─── Low-level HTTP ───────────────────────────────────────────────────

/**
 * GET a URL and return [httpCode, body, error, contentType]. Never throws.
 * Raw curl (like es_http) — the mirrors and embed hosts are decided by the
 * caller, not a fixed allowlist. $post (a urlencoded string) switches the
 * request to POST — the rubystm /dl form needs it.
 */
function ts_http($url, array $headers = [], $timeout = null, $post = null) {
    $timeout = (int)max(3, $timeout ?? toonstream_timeout());
    $hdrs = [
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ];
    foreach ($headers as $k => $v) $hdrs[] = is_int($k) ? $v : ($k . ': ' . $v);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$code, is_string($body) ? $body : '', $err, $ct];
}

// ─── Title → entry ────────────────────────────────────────────────────

function toonstream_slugify($title) {
    $s = strtolower(trim((string)$title));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim((string)$s, '-');
    return $s;
}

/** Does this entry look like the Hindi/Indian-dubbed cut? */
function toonstream_is_dub_variant($haystack) {
    return (bool)preg_match('/\bhindi\b|\bdub\b|muse[-_ ]?india/i', (string)$haystack);
}

/**
 * The language an entry carries, from its slug + catalogue title:
 * ['lang' => sub|dub|any, 'name' => Hindi|Tamil|…|null].
 *
 * Named markers win over the generic ones ("tamil" says more than "dub"),
 * and an entry with no marker at all is 'any' — the plain cut that plays on
 * both sides of the toggle. The name rides the chip as its audio tag
 * ([DUB · Hindi]); 'any' entries show [Multi Audio] when the episode page
 * says so (see toonstream_resolve).
 */
function toonstream_entry_lang($slug, $title) {
    $h = strtolower($slug . ' ' . $title);
    if (preg_match('/\btamil\b/', $h))        return ['lang' => 'dub', 'name' => 'Tamil'];
    if (preg_match('/\btelugu\b/', $h))       return ['lang' => 'dub', 'name' => 'Telugu'];
    if (preg_match('/\bmalayalam\b/', $h))    return ['lang' => 'dub', 'name' => 'Malayalam'];
    if (preg_match('/\bkannada\b/', $h))      return ['lang' => 'dub', 'name' => 'Kannada'];
    if (preg_match('/\bhindi\b|muse[-_ ]?india|sony[ _-]?yay/i', $h)) {
        return ['lang' => 'dub', 'name' => 'Hindi'];
    }
    if (preg_match('/\benglish[ _-]?dub\b/', $h)) return ['lang' => 'dub', 'name' => 'English'];
    if (preg_match('/\bdub\b/', $h))          return ['lang' => 'dub', 'name' => null];
    if (preg_match('/(^|[^a-z])sub([^a-z]|$)/', $h)) return ['lang' => 'sub', 'name' => null];
    return ['lang' => 'any', 'name' => null];
}

/**
 * Search results for a title: [['title','type','url'], …]. Cached for the
 * configured TTL — titles do not move between slugs often, and a miss is
 * worth sitting on too (api_cache_set skips empty arrays, so misses are
 * simply re-fetched).
 */
function toonstream_search($title) {
    $q = trim((string)$title);
    if ($q === '') return [];

    $key = api_cache_key('toonstream', ['search', strtolower($q)]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $results = [];
    $budget = 10;   // seconds for the whole search, across mirrors
    $started = time();
    foreach (toonstream_bases() as $base) {
        if ((time() - $started) >= $budget) break;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ((time() - $started) >= $budget) break 2;
            [$code, $body] = ts_http(
                $base . '/search/all?q=' . rawurlencode($q),
                ['Referer: ' . $base . '/'],
                min(6, max(3, $budget - (time() - $started)))
            );
            // The search route intermittently 301s (bot defence) — a second
            // identical try usually lands the real JSON.
            if ($code === 200 && $body !== '') break;
        }
        if ($code !== 200 || $body === '') continue;
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['data'])) continue;
        // A valid JSON body is a real answer — an empty list just means no
        // match; do not burn the other mirrors on it.
        foreach ((array)$json['data'] as $row) {
            if (!is_array($row) || empty($row['url'])) continue;
            $results[] = [
                'title' => (string)($row['title'] ?? ''),
                'type'  => (string)($row['type'] ?? 'series'),
                'url'   => (string)$row['url'],
            ];
        }
        break;
    }

    if ($results) {
        $ttl = defined('TOONSTREAM_SEARCH_TTL') ? (int)TOONSTREAM_SEARCH_TTL : 21600;
        api_cache_set($key, 'toonstream', $results, $ttl);
    }
    return $results;
}

/** "Season 2" hidden in a catalogue title → season number (else 1). */
function toonstream_season_for($info) {
    $title = (string)($info['title'] ?? $info['name'] ?? '');
    if (function_exists('eightstream_title_season')) {
        $n = (int)eightstream_title_season($title);
        if ($n > 0) return $n;
    }
    if (preg_match('/\bseason\s*(\d+)\b/i', $title, $m)) return max(1, (int)$m[1]);
    return 1;
}

/**
 * Candidate slugs for a title, most wanted first. The site keeps language
 * cuts as separate series entries (…-hindi-dub, …-dub, …-dub-sub) and its
 * search only ever answers with 6 popularity-ranked rows — a plain title
 * often finds NO series there even though /series/{slug}/ exists. Probing
 * these slugs directly is the reliable way in; search is the fallback.
 */
function toonstream_slug_variants($slugBase, $lang) {
    if ($lang === 'dub') {
        $suffixes = ['-hindi-dub', '-dub-sub', '-dub', '', '-sub'];
    } else {
        $suffixes = ['', '-dub-sub', '-sub', '-hindi-dub', '-dub'];
    }
    $out = [];
    foreach ($suffixes as $s) {
        $slug = $slugBase . $s;
        if (!in_array($slug, $out, true)) $out[] = $slug;
    }
    return $out;
}

/** A series page that really carries episode links (not a 404 shell). */
function toonstream_series_exists($slug) {
    $html = toonstream_page('/series/' . $slug . '/');
    if ($html === '') return false;
    if (stripos($html, '404 Not Found -') !== false) return false;
    return (bool)preg_match('#href="/episode/#i', $html);
}

/** A movie page that really is a player page (exact-slug rule only). */
function toonstream_movie_exists($slug) {
    $html = toonstream_page('/movies/' . $slug . '/');
    if ($html === '') return false;
    if (stripos($html, '404 Not Found -') !== false) return false;
    return stripos($html, '<title>404') === false;
}

/**
 * Score every search result against the catalogue title and keep the best:
 * exact slug 100, slug-prefix ("x-season-2" ⊃ "x") 85, word overlap ≥60%
 * of the CANDIDATE's tokens (the query must cover the row — otherwise
 * "naruto" would happily match "boruto-naruto-next-generations" and stream
 * the wrong show). The requested side of the toggle then nudges the variant
 * it wants — dub → +25 for a Hindi-looking slug, sub → +15 for the plain
 * cut. Below 60 the guess is not trustworthy enough to stream a wrong
 * title, so the caller treats it as a miss and the embed chain takes over.
 *
 * Returns ['path','slug','type','title','dub'] or null.
 */
function toonstream_pick($info, $lang) {
    $title = trim((string)($info['title'] ?? $info['name'] ?? ''));
    if ($title === '') return null;

    $slugFull = toonstream_slugify($title);
    if ($slugFull === '') return null;
    $slugBase = preg_replace('/-season-\d+$/', '', $slugFull);
    $wantHindi = ($lang === 'dub');

    // 1 ── exact slug probes (series first, then an exact movie — the same
    // gate the search path applies, so a "naruto-" prefix can never pull a
    // movie in for a series lookup).
    foreach (toonstream_slug_variants($slugBase, $lang) as $slug) {
        if (!toonstream_series_exists($slug)) continue;
        return [
            'path'  => '/series/' . $slug . '/',
            'slug'  => $slug,
            'type'  => 'series',
            'title' => $title,
            'dub'   => toonstream_is_dub_variant($slug),
        ];
    }
    if (toonstream_movie_exists($slugBase)) {
        return [
            'path'  => '/movies/' . $slugBase . '/',
            'slug'  => $slugBase,
            'type'  => 'movies',
            'title' => $title,
            'dub'   => toonstream_is_dub_variant($slugBase),
        ];
    }

    // 2 ── search fallback (titles whose slug differs from the catalogue
    // name — rewrites, alternate spellings).
    $rows = toonstream_search($title);
    if (!$rows && $slugBase !== $slugFull) {
        // "…Season N" qualified the query but the site keeps one entry per
        // show — ask again with the bare title; the season then rides in the
        // episode path (…-2x1/), not the series slug.
        $rows = toonstream_search(trim(preg_replace('/\s+season\s*\d+\s*$/i', '', $title)));
    }

    $best = null;
    $bestScore = 0;
    foreach ($rows as $row) {
        $path = parse_url($row['url'], PHP_URL_PATH) ?: $row['url'];
        $path = '/' . trim((string)$path, '/');
        $slug = toonstream_slugify(basename(trim($path, '/')));
        if ($slug === '') continue;

        // The JSON "type" field proved unreliable — the URL shape is the
        // ground truth (/movies/ vs /series/).
        $isMovie = (bool)preg_match('#^/movies?/#', $path);

        $score = 0;
        if ($slug === $slugFull || $slug === $slugBase) {
            $score = 100;
        } elseif (strpos($slug, $slugBase . '-') === 0 || strpos($slugBase, $slug . '-') === 0) {
            $score = 85;
        } else {
            $a = explode('-', $slugBase);
            $b = explode('-', $slug);
            $inter = count(array_intersect($a, $b));
            // Coverage of the CANDIDATE by the query: every token of the row
            // must mostly appear in the title we asked for — "naruto" (1
            // token) against "boruto-naruto-next-generations" (4 tokens) is
            // 1/4 = 0.25, rejected, where min() would have scored it 1.0.
            $ratio = $inter / max(1, count($b));
            if ($ratio >= 0.6) $score = (int)round(60 * $ratio);
        }
        if ($score <= 0) continue;

        // A movie only earns its spot by matching the title exactly — a
        // prefix like "naruto-" swallowing "naruto-shippuden-the-movie-…"
        // must not steal a series lookup.
        if ($isMovie && $score < 100) continue;
        // Series nudged over an equally good movie (site search mixes both).
        if (!$isMovie) $score += 5;

        // "…-season-2" is the wrong entry unless the title asked for it.
        if (!$isMovie && !preg_match('/season/i', $title) && preg_match('/-season-\d+$/', $slug)) {
            $score -= 20;
        }

        $dub = toonstream_is_dub_variant($slug . ' ' . $row['title']);
        if ($wantHindi && $dub)       $score += 25;
        elseif (!$wantHindi && !$dub) $score += 15;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'path'  => $path,
                'slug'  => $slug,
                'type'  => $isMovie ? 'movies' : 'series',
                'title' => $row['title'],
                'dub'   => $dub,
            ];
        }
    }
    return ($best && $bestScore >= 60) ? $best : null;
}

// ─── Page fetching ────────────────────────────────────────────────────

/** Is this the site's own "missing path" answer (500/404 + 404 page)? */
function toonstream_is_miss($code, $body) {
    if ($code === 404) return true;
    if ($body === '' || $body === null) return false;
    if ($code !== 500) return false;
    return stripos($body, '404 Not Found -') !== false || stripos($body, '<title>404') !== false;
}

/**
 * GET a site path (cached), trying each mirror until one answers.
 * Only the site's own 404 answer (or a real 404) is a cacheable MISS;
 * Cloudflare edge errors (520-524), gateway 5xx without the 404 marker,
 * and connection failures are TRANSIENT — try the next mirror, cache
 * nothing. An earlier version cached every non-200 as a miss, so one
 * origin blip (522) poisoned a title for the whole TTL.
 */
function toonstream_page($path, $ttl = null) {
    $path = '/' . ltrim((string)$path, '/');
    $key = api_cache_key('toonstream', ['page', $path]);
    $hit = api_cache_get($key);
    // Cached entries carry ['html' => …] — an empty string is a cached
    // MISS (the site answers 500 for missing paths), not an absent cache.
    if (is_array($hit) && array_key_exists('html', $hit)) return (string)$hit['html'];

    $html = '';
    $miss = false;
    foreach (toonstream_bases() as $base) {
        [$code, $body] = ts_http($base . $path, ['Referer: ' . $base . '/']);
        if ($code === 200 && strlen($body) > 500) { $html = $body; break; }
        if (toonstream_is_miss($code, $body)) {
            // The mirrors clone each other — a real miss ends the hunt.
            $miss = true;
            break;
        }
        // Transient (code 0 / CF edge error / junk shell): next mirror.
    }

    if ($html !== '') {
        $ttl = $ttl ?? (defined('TOONSTREAM_PAGE_TTL') ? (int)TOONSTREAM_PAGE_TTL : 300);
        api_cache_set($key, 'toonstream', ['html' => $html], $ttl);
    } elseif ($miss) {
        // Negative-cache a REAL miss: slug probes and constructed episode
        // paths hit dead ends constantly, and re-paying a request for each
        // one on every resolve adds seconds.
        api_cache_set($key, 'toonstream', ['html' => ''], defined('TOONSTREAM_PAGE_TTL') ? (int)TOONSTREAM_PAGE_TTL : 300);
    }
    return $html;
}

/**
 * The episode's own path. Series follow the observed
 * /episode/{slug}-{season}x{episode}/ pattern; a movie plays only episode 1
 * off its /movies/ page. The path is a guess — when the page 404s the
 * caller falls back to scanning the series page for the real link.
 */
function toonstream_episode_path(array $pick, $episode, $info) {
    $episode = max(1, (int)$episode);
    if ($pick['type'] === 'movies') {
        return $episode <= 1 ? $pick['path'] : null;
    }
    // A matched "…-season-2" slug already names the season; anything else
    // takes the season out of the catalogue title.
    $season = preg_match('/-season-\d+$/', $pick['slug']) ? 1 : toonstream_season_for($info);
    return '/episode/' . $pick['slug'] . '-' . $season . 'x' . $episode . '/';
}

/**
 * The episode's page HTML + the path it was actually found at:
 * ['html' => …, 'path' => …], or [] when the title has no such episode.
 * The constructed slug can miss in three known ways — the site splits
 * shows into /season/N pages (naruto only renders 1x1-1x52 on the base
 * page, 1x53+ live at /series/naruto/season/2), language-cut pages link
 * their episodes under the plain slug, or the episode genuinely does not
 * exist — so a miss falls through to the series' own link index.
 */
function toonstream_episode_page(array $pick, $episode, $info) {
    $episode = max(1, (int)$episode);

    // A series page must carry server cards to count — the site keeps
    // stale orphans around (one-piece-2x62 exists but is an empty shell
    // while the series list stops at 1x61), and a server-less 200 from
    // the fast path must not shadow the real listing. Movie pages are
    // taken as-is (their card markup is proven in resolve, and the index
    // walker is series-only anyway).
    $accept = function ($html) use ($pick) {
        return $html !== '' && ($pick['type'] === 'movies' || toonstream_page_cards($html) !== []);
    };

    // Fast path: constructed -{S}x{E} (single-season shows land here and
    // repeats are served from the page cache, including cached misses).
    $serverless = null;
    $path = toonstream_episode_path($pick, $episode, $info);
    if ($path !== null) {
        $html = toonstream_page($path);
        if ($html !== '') {
            if ($accept($html)) return ['html' => $html, 'path' => $path];
            $serverless = ['html' => $html, 'path' => $path];
        }
    }

    if ($pick['type'] === 'movies') return [];

    // Language-cut pages link the PLAIN slug (/series/naruto-shippuden-
    // hindi-dub/ lists /episode/naruto-shippuden-1x1/) — retry with the
    // cut suffix stripped before walking seasons.
    $plain = preg_replace('/-(hindi-dub|dub-sub|dub|sub)$/', '', $pick['slug']);
    if ($plain !== $pick['slug']) {
        $season = preg_match('/-season-\d+$/', $pick['slug']) ? 1 : toonstream_season_for($info);
        $path2 = '/episode/' . $plain . '-' . $season . 'x' . $episode . '/';
        $html = toonstream_page($path2);
        if ($accept($html)) return ['html' => $html, 'path' => $path2];
        if ($html !== '' && $serverless === null) $serverless = ['html' => $html, 'path' => $path2];
    }

    $idx = toonstream_index_episode($pick, $episode, $info);
    if ($idx) return $idx;
    return $serverless ?? [];
}

/** E → href map from a series/season page (S>0 links win over 0x specials). */
function toonstream_page_epmap($html) {
    if ($html === '' || !preg_match_all('~href="(/episode/[^"]+)"~i', $html, $m)) return [];
    $map = [];
    foreach (array_unique($m[1]) as $href) {
        if (preg_match('~-(\d+)x(\d+)/?$~', $href, $em)) {
            $s = (int)$em[1];
            $e = (int)$em[2];
        } elseif (preg_match('~-(\d+)/?$~', $href, $em)) {
            $s = 1;
            $e = (int)$em[1];
        } else continue;
        if ($e < 1) continue;
        if (!isset($map[$e]) || ($map[$e]['s'] < 1 && $s > 0)) {
            $map[$e] = ['href' => $href, 's' => $s];
        }
    }
    return $map;
}

/** [minE, maxE] across regular (S>0) links — [0,0] when there are none. */
function toonstream_ep_range($map) {
    $min = PHP_INT_MAX;
    $max = 0;
    foreach ($map as $e => $one) {
        if ($one['s'] < 1) continue;
        $min = min($min, (int)$e);
        $max = max($max, (int)$e);
    }
    return $max > 0 ? [$min, $max] : [0, 0];
}

/**
 * Walk the series' own episode links: base page first, then /season/{N}
 * pages. The wanted number is CONTINUOUS across seasons (naruto s2 =
 * 2x53-2x104), so each page's range re-anchors the guess — galloping up
 * or down until the number lands in range (≤8 fetches, all page-cached).
 */
function toonstream_index_episode(array $pick, $episode, $info) {
    $slug = $pick['slug'];

    $baseHtml = toonstream_page('/series/' . $slug . '/');
    $map  = toonstream_page_epmap($baseHtml);
    $base = toonstream_ep_range($map);
    if (isset($map[$episode]) && $map[$episode]['s'] >= 1) {
        $href = $map[$episode]['href'];
        $html = toonstream_page($href);
        if ($html !== '') return ['html' => $html, 'path' => $href];
    }

    // Estimate the starting season: the base range gives the average
    // season length; a specials-only base assumes ~50.
    if ($base[1] > 0 && $episode > $base[1]) {
        $s = 1 + (int)ceil(($episode - $base[1]) / max(8, $base[1] - $base[0] + 1));
    } elseif ($base[1] > 0) {
        $s = 1;
    } else {
        $s = max(1, (int)ceil($episode / 50));
    }

    $tried = [];
    for ($i = 0; $i < 8; $i++) {
        if ($s < 1) $s = 1;
        if (isset($tried[$s])) break;
        $tried[$s] = true;

        $html = toonstream_page('/series/' . $slug . '/season/' . $s . '/');
        if ($html === '') {
            // Overshot the last season — step back once (ep 220 estimates
            // season 5 on a 4-season show).
            $s--;
            continue;
        }
        $map = toonstream_page_epmap($html);
        if (isset($map[$episode]) && $map[$episode]['s'] >= 1) {
            $href = $map[$episode]['href'];
            $epHtml = toonstream_page($href);
            if ($epHtml !== '') return ['html' => $epHtml, 'path' => $href];
            break;
        }
        [$min, $max] = toonstream_ep_range($map);
        if ($max <= 0) break;                       // no regular episodes here
        if ($episode > $max) {
            $s += max(1, (int)ceil(($episode - $max) / max(8, $max - $min + 1)));
        } elseif ($episode < $min) {
            $s -= max(1, (int)ceil(($min - $episode) / max(8, $max - $min + 1)));
        } else {
            break;                                  // in range but unlisted → absent
        }
    }
    return [];
}

// ─── Episode page → server cards ──────────────────────────────────────

/**
 * Extraction priority for one embed host. The page's own card order still
 * breaks ties inside a rank (via toonstream_page_cards), but a proven
 * extractor is worth jumping the queue for.
 */
function toonstream_host_rank($url) {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (strpos($host, 'emturbovid') !== false)  return 0;  // data-hash straight in the HTML
    if (strpos($host, 'blakiteapi') !== false)   return 1;  // get.php JSON → rumble CDN URL
    if (strpos($host, 'rubystm') !== false)      return 2;  // POST /dl + packed JS → master.m3u8
    if (strpos($host, 'vidmoly') !== false)      return 3;
    if (strpos($host, 'as-cdn') !== false)       return 5;  // direct player path, but times out often
    if (strpos($host, 'filesforever') !== false) return 90; // obfuscated piliyerxnew.js — no extractor yet
    if (strpos($host, 'abyssplayer') !== false)  return 91; // iamcdn lite bundle — no extractor yet
    if (strpos($host, 'youtube') !== false || strpos($host, 'youtu.be') !== false) return 92;
    return 6;   // cloudy / strmup / vidstreaming … — generic body scan
}

/**
 * Every player card on an episode page: [['idx','name','url'], …].
 *
 * The site renders one <a href="#options-N">…<span class="server">NAME</span>
 * tab per server and a matching <div id="options-N"> holding its iframe
 * (active with src=, lazy ones with data-src= — both carry `src=`). Pages
 * without the options structure (movies, older layouts) fall back to the
 * plain iframe list, unnamed.
 */
function toonstream_page_cards($html) {
    $html = (string)$html;
    if ($html === '') return [];

    $names = [];
    if (preg_match_all('~<a\b[^>]*href="#options-(\d+)"[^>]*>(.*?)</a>~s', $html, $nm, PREG_SET_ORDER)) {
        foreach ($nm as $one) {
            if (preg_match('~<span\b[^>]*class="[^"]*server[^"]*"[^>]*>\s*(.*?)\s*</span>~is', $one[2], $sm)) {
                $names[$one[1]] = trim(html_entity_decode(strip_tags($sm[1])));
            } else {
                $txt = trim(preg_replace('/\s+/', ' ', strip_tags($one[2])));
                $names[$one[1]] = trim(preg_replace('~^Server\s*\d+\s*~i', '', $txt));
            }
        }
    }

    $cards = [];
    $seen  = [];
    if (preg_match_all('~<div\b[^>]*\bid="options-(\d+)"[^>]*>(.{0,700}?)<iframe\b[^>]*\bsrc="([^"]+)"~s', $html, $um, PREG_SET_ORDER)) {
        foreach ($um as $one) {
            $url = trim($one[3]);
            if (strpos($url, '//') === 0) $url = 'https:' . $url;
            if (!preg_match('#^https?://#i', $url) || isset($seen[$url])) continue;
            $seen[$url] = true;
            $cards[] = [
                'idx'  => (int)$one[1],
                'name' => (string)($names[$one[1]] ?? ''),
                'url'  => $url,
            ];
        }
    }
    if (!$cards && preg_match_all('/<iframe[^>]+src="(https?:\/\/[^"]+)"/i', $html, $im)) {
        foreach ($im[1] as $url) {
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            $cards[] = ['idx' => count($cards), 'name' => '', 'url' => $url];
        }
    }
    return $cards;
}

// ─── Embed → direct URL ───────────────────────────────────────────────

/**
 * Unpack a Dean-Edwards packed `eval(function(p,a,c,k,e,d){…}('…',A,C,
 * 'k0|k1|…'.split('|'),…))` payload — the shape rubystm hands back from
 * POST /dl — without a JS engine: brace-scan the function body, parse the
 * four packed arguments, then replay the packer's own decode loop
 * (descending, \b-token replacement in base A).
 * Returns the unpacked source, or '' when it is not the expected shape.
 */
function toonstream_packer_unpack($html) {
    $html = (string)$html;
    $pos = strpos($html, 'eval(function(p,a,c,k,e,d)');
    if ($pos === false) return '';
    $open = strpos($html, '{', $pos);
    if ($open === false) return '';

    // Scan the function body with quote awareness (its own braces included).
    $depth = 0; $in = null; $esc = false; $end = null;
    $len = strlen($html);
    for ($j = $open; $j < $len; $j++) {
        $ch = $html[$j];
        if ($in !== null) {
            if ($esc) { $esc = false; continue; }
            if ($ch === '\\') { $esc = true; continue; }
            if ($ch === $in) $in = null;
            continue;
        }
        if ($ch === '"' || $ch === "'") { $in = $ch; continue; }
        if ($ch === '{') $depth++;
        elseif ($ch === '}') { $depth--; if ($depth === 0) { $end = $j; break; } }
    }
    if ($end === null) return '';

    // The call's arguments run from after the body to </script>; strip the
    // eval() closing paren (one) and parse ('packed', A, C, 'keys'.split…).
    $sp = strpos($html, '</script>', $end);
    $args = $sp === false ? substr($html, $end + 1, 40000) : substr($html, $end + 1, $sp - ($end + 1));
    $args = rtrim($args);
    if (substr($args, -1) === ')') $args = substr($args, 0, -1);

    $n = strlen($args); $i = 0;
    while ($i < $n && ctype_space($args[$i])) $i++;
    if (($args[$i] ?? '') !== '(') return '';
    $i++;

    $parseStr = function ($s, &$i) {
        $n = strlen($s);
        if (($s[$i] ?? '') !== "'") return null;
        $i++; $out = '';
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $n) { $out .= $s[$i + 1]; $i += 2; continue; }
            if ($c === "'") { $i++; return $out; }
            $out .= $c; $i++;
        }
        return null;
    };
    $parseNum = function ($s, &$i) {
        $n = strlen($s);
        while ($i < $n && ctype_space($s[$i])) $i++;
        $j = $i;
        while ($j < $n && ctype_digit($s[$j])) $j++;
        if ($j === $i) return null;
        $v = (int)substr($s, $i, $j - $i);
        $i = $j;
        return $v;
    };

    $p = $parseStr($args, $i);
    if ($p === null || ($args[$i] ?? '') !== ',') return '';
    $i++;
    $base = $parseNum($args, $i);
    if ($base === null || $base < 2 || $base > 62 || ($args[$i] ?? '') !== ',') return '';
    $i++;
    $count = $parseNum($args, $i);
    if ($count === null || ($args[$i] ?? '') !== ',') return '';
    $i++;
    $keys = $parseStr($args, $i);
    if ($keys === null) return '';

    $keyArr = explode('|', (string)$keys);
    $digits = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $toBase = function ($num, $b) use ($digits) {
        if ($num === 0) return '0';
        $out = '';
        while ($num > 0) { $out = $digits[$num % $b] . $out; $num = (int)floor($num / $b); }
        return $out;
    };
    for ($c = $count - 1; $c >= 0; $c--) {
        if (!isset($keyArr[$c]) || $keyArr[$c] === '') continue;
        $token = $toBase($c, $base);
        $rep = $keyArr[$c];
        $p = preg_replace_callback('/\b' . preg_quote($token, '/') . '\b/',
            function ($mm) use ($rep) { return $rep; }, $p);
    }
    return $p;
}

/**
 * rubystm (the "Ruby" card) → master.m3u8 (+ English captions).
 * Its embed page posts op=embed&file_code={last path segment} to /dl, which
 * answers with the packed eval above; unpacked, jwplayer's setup() carries
 * sources[0].file (the signed HLS master) and any labelled .vtt tracks.
 */
function toonstream_rubystm_source($embedUrl, $timeout) {
    if (!preg_match('~rubystm\.com/e/([^./?#]+)~i', (string)$embedUrl, $m)) return null;
    $code = preg_replace('/\.html$/i', '', $m[1]);
    // Their player does pathname.split('-').pop() — keep the same rule.
    $parts = explode('-', $code);
    $code = (string)end($parts);
    if ($code === '') return null;

    [$hc, $body] = ts_http('https://rubystm.com/dl', [
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://rubystm.com',
        'Referer: ' . $embedUrl,
    ], $timeout, http_build_query([
        'op'        => 'embed',
        'file_code' => $code,
        'auto'      => '1',
        'referer'   => '',
    ]));
    if ($hc !== 200 || $body === '') return null;

    $unp = toonstream_packer_unpack($body);
    if ($unp === '') return null;
    if (preg_match('#file:"(https?:[^"]+\.m3u8[^"]*)"#i', $unp, $mm)) {
        $url = $mm[1];
    } elseif (preg_match('#file:"(https?:[^"]+)"#i', $unp, $mm)) {
        $url = $mm[1];
    } else {
        return null;
    }

    $subs = [];
    if (preg_match_all('#\{file:"(https?:[^"]+\.vtt)"([^}]*)\}#i', $unp, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) {
            if (preg_match('#kind:"thumbnails"#i', $t[2])) continue;   // preview sprite, not a caption
            // Their caption files frequently 404 upstream (verified live:
            // fresh extract → immediate GET = "Not Found"), and a dead link
            // only surfaces later as a CORS/failed fetch in the player.
            // Probe once here and ship only tracks that actually answer.
            if (!toonstream_sub_alive($t[1], $embedUrl)) continue;
            $label = '';
            if (preg_match('#label:"([^"]+)"#i', $t[2], $lm)) $label = $lm[1];
            $subs[] = [
                'url'      => $t[1],
                'language' => $label !== '' ? $label : 'English',
                'format'   => 'vtt',
                'default'  => false,
            ];
        }
    }
    return ['url' => $url, 'mode' => 'hls', 'subtitles' => $subs];
}

/** Does this caption URL really answer 200 right now? (see the caller). */
function toonstream_sub_alive($url, $referer) {
    [$code, $body] = ts_http($url, ['Referer: ' . (string)$referer], 5);
    return $code === 200 && is_string($body) && $body !== '';
}

/**
 * Open one embed page and pull its direct media URL out:
 *   rubystm   → POST /dl → packed eval → master.m3u8 (+ captions)
 *   emturbovid → <div id="video_player" data-hash="…x.m3u8">
 *   blakite    → /embed/{tmdbId}/{S}-{E} → get.php JSON (dataId, quality,
 *                format) → hugh.cdn.rumble.cloud video URL
 *   generic    → the first .m3u8 / .mp4 anywhere (JSON-escaped slashes
 *                included)
 * One JS-redirect hop (vidstreaming.xyz style) is followed before giving up.
 * Returns ['url' => …, 'mode' => 'hls'] or null.
 */
function toonstream_extract_embed($embedUrl, $referer, $timeout = null) {
    $timeout = $timeout ?? min(6, toonstream_timeout());
    $host = strtolower((string)(parse_url($embedUrl, PHP_URL_HOST) ?: ''));
    if (strpos($host, 'rubystm') !== false || strpos($host, 'streamruby') !== false) {
        return toonstream_rubystm_source($embedUrl, $timeout);
    }
    [$code, $body] = ts_http($embedUrl, ['Referer: ' . $referer], $timeout);
    if ($code !== 200 || $body === '') return null;

    $got = toonstream_extract_body($body, $embedUrl, $timeout);
    if ($got) return $got;

    // One JS-redirect hop: window.location.replace('…') (vidstreaming.xyz).
    if (preg_match('/window\.location\.replace\([\'"]([^\'"]+)[\'"]\)/i', $body, $m)) {
        $next = html_entity_decode($m[1]);
        if (strpos($next, '//') === 0) {
            $next = 'https:' . $next;
        } elseif (strpos($next, '/') === 0) {
            $h = parse_url($embedUrl) ?: [];
            $next = ($h['scheme'] ?? 'https') . '://' . ($h['host'] ?? '') . $next;
        }
        if (preg_match('#^https?://#i', $next)) {
            [$c2, $b2] = ts_http($next, ['Referer: ' . $embedUrl], $timeout);
            if ($c2 === 200 && $b2 !== '') $got = toonstream_extract_body($b2, $next, $timeout);
        }
    }
    return $got;
}

/** Direct-URL patterns inside one embed body (see toonstream_extract_embed). */
function toonstream_extract_body($body, $embedUrl, $timeout) {
    if (preg_match('/data-hash="(https?:\/\/[^"]+)"/i', (string)$body, $m)) {
        return ['url' => trim($m[1]), 'mode' => 'hls'];
    }
    $blak = toonstream_blakite_source($embedUrl, $timeout);
    if ($blak) return $blak;

    $flat = str_replace(['\\/', '\\"', '\\&'], ['/', '"', '&'], (string)$body);
    if (preg_match('#https?://[^\s"\'<>]+\.m3u8(\?[^\s"\'<>]*)?#i', $flat, $m)) {
        return ['url' => $m[0], 'mode' => 'hls'];
    }
    if (preg_match('#https?://[^\s"\'<>]+\.mp4(\?[^\s"\'<>]*)?#i', $flat, $m)) {
        return ['url' => $m[0], 'mode' => 'hls'];   // mode always 'hls': to our player "direct stream" — playHls sniffs mp4 vs m3u8 from the URL itself
    }
    return null;
}

/** blakite quality label → rumble CDN code (order matches their player.js). */
function toonstream_blakite_code($label) {
    $codes = ['240p' => 'oaa', '360p' => 'baa', '480p' => 'caa', '720p' => 'gaa', '1080p' => 'haa'];
    return $codes[strtolower(trim((string)$label))] ?? null;
}

/**
 * Blakite embed → playable URL on hugh.cdn.rumble.cloud. The embed URL
 * carries /embed/{tmdbId}/{S}-{E} (movies omit the episode); their own
 * player.js resolves dataId + quality the same way:
 *   MP4  → {base}{dataId}.{code}.mp4          (only the listed quality is
 *                                               guaranteed — others 404)
 *   HLS  → {base}{dataId}.{code}.tar?r_file=chunklist.m3u8&r_type=…
 *           + r_range from the ranges table
 */
function toonstream_blakite_source($embedUrl, $timeout) {
    if (!preg_match('#blakiteapi\.xyz/embed/([0-9]+)(?:/([0-9]+-[0-9]+))?#i', (string)$embedUrl, $m)) {
        return null;
    }
    $tmdb = $m[1];
    $ep   = $m[2] ?? '';
    $api  = 'https://blakiteapi.xyz/api/get.php?' . ($ep !== '' ? "id={$ep}&tmdbId={$tmdb}" : "tmdbId={$tmdb}");

    [$code, $body] = ts_http($api, ['Referer: https://blakiteapi.xyz/'], $timeout);
    if ($code !== 200 || $body === '') return null;
    $j = json_decode($body, true);
    $d = is_array($j) ? ($j['data'] ?? null) : null;
    if (!is_array($d) || empty($d['dataId'])) return null;

    $base    = 'https://hugh.cdn.rumble.cloud/video/';
    $dataId  = (string)$d['dataId'];
    $format  = strtoupper((string)($d['format'] ?? ''));
    $quality = (string)($d['quality'] ?? '');

    if ($format === 'M3U8') {
        // Ranges table: lines like "4096-8191 (480p)" — one per playable
        // quality; the site itself refuses the file without it.
        if (empty($d['ranges'])) return null;
        $map = [];
        $order = [];
        foreach (explode("\n", (string)$d['ranges']) as $line) {
            if (preg_match('/^(\d+-\d+)\s*\(([^)]+)\)/', trim($line), $mm)) {
                $label = trim($mm[2]);
                $map[$label] = $mm[1];
                $order[] = $label;
            }
        }
        $label = ($quality !== '' && isset($map[$quality])) ? $quality : (end($order) ?: '');
        $c = toonstream_blakite_code($label);
        if ($c === null || !isset($map[$label])) return null;
        return [
            'url'  => $base . $dataId . '.' . $c
                   . '.tar?r_file=chunklist.m3u8&r_type=application/vnd.apple.mpegurl&r_range=' . $map[$label],
            'mode' => 'hls',
        ];
    }

    // MP4: start from the quality the API reports (it is the one guaranteed
    // to exist), then probe the remaining known codes — each probe is a tiny
    // ranged GET that stops at the first 2xx.
    $candidates = [];
    if ($quality !== '' && ($c0 = toonstream_blakite_code($quality)) !== null) $candidates[] = $c0;
    foreach (['haa', 'gaa', 'caa', 'baa', 'oaa'] as $c) {
        if (!in_array($c, $candidates, true)) $candidates[] = $c;
    }
    foreach ($candidates as $c) {
        $url = $base . $dataId . '.' . $c . '.mp4';
        [$hc, ] = ts_http($url, [
            'Range: bytes=0-64',
            'Referer: https://blakiteapi.xyz/',
            'Origin: https://blakiteapi.xyz',
        ], min(4, max(3, (int)$timeout)));
        if ($hc >= 200 && $hc < 300) return ['url' => $url, 'mode' => 'hls'];
    }
    return null;
}

// ─── Resolve ──────────────────────────────────────────────────────────

/**
 * Full attempt for one anime + episode. Returns the same shape
 * stream_try_* wrappers expect: ok/url/lang/servers/message.
 *
 * Both cuts of the title are resolved in one pass — the requested side
 * first (it owns the primary), the other side behind it — and up to
 * TOONSTREAM_MAX_SERVERS cards are opened across them (host-rank order,
 * TOONSTREAM_BUDGET seconds wall-clock). Every server keeps its own
 * truthful lang + audio_lang ([DUB · Hindi], [Multi Audio]) so the watch
 * page can list them all instead of filtering by the SUB/DUB mode.
 */
function toonstream_resolve($info, $episode, $lang) {
    if (!toonstream_enabled()) {
        return ['ok' => false, 'message' => 'ToonStream is disabled.'];
    }
    $episode = max(1, (int)$episode);
    $lang = ($lang === 'dub') ? 'dub' : 'sub';

    // Requested cut first, the other one behind it (deduped by path — many
    // titles only exist once, and that entry serves both modes).
    $variants = [];
    foreach ([$lang, ($lang === 'dub') ? 'sub' : 'dub'] as $want) {
        $pick = toonstream_pick($info, $want);
        if (!$pick) continue;
        $dup = false;
        foreach ($variants as $v) {
            if ($v['pick']['path'] === $pick['path']) { $dup = true; break; }
        }
        if (!$dup) $variants[] = ['pick' => $pick, 'want' => $want];
    }
    if (!$variants) {
        return ['ok' => false, 'message' => 'No matching ToonStream entry for this title.'];
    }

    // Collect every server card, tagged with its variant's language.
    $cards   = [];
    $pageOk  = false;
    foreach ($variants as $vi => $v) {
        $page = toonstream_episode_page($v['pick'], $episode, $info);
        if (empty($page['html'])) continue;
        $pageOk = true;

        $elang = toonstream_entry_lang($v['pick']['slug'], $v['pick']['title']);
        $audio = $elang['name'];
        if ($audio === null && $elang['lang'] === 'any'
            && stripos($page['html'], 'Multi Audio') !== false) {
            $audio = 'Multi Audio';
        }
        $referer = (toonstream_bases()[0] ?? '') . $page['path'];
        foreach (toonstream_page_cards($page['html']) as $c) {
            $cards[] = [
                'url'        => $c['url'],
                'name'       => $c['name'],
                'referer'    => $referer,
                'variant'    => $vi,
                'order'      => count($cards),
                'idx'        => $c['idx'],
                'lang'       => $elang['lang'],
                'audio_lang' => $audio,
            ];
        }
    }
    if (!$pageOk) {
        return ['ok' => false, 'message' => 'ToonStream episode page not found.'];
    }
    if (!$cards) {
        return ['ok' => false, 'message' => 'ToonStream episode page held no servers.'];
    }

    // Requested variant first, then extraction reliability, then the page's
    // own card order (stable sort — PHP 8 keeps ties in source order).
    usort($cards, function ($a, $b) {
        if ($a['variant'] !== $b['variant']) return $a['variant'] - $b['variant'];
        $ra = toonstream_host_rank($a['url']);
        $rb = toonstream_host_rank($b['url']);
        if ($ra !== $rb) return $ra - $rb;
        return $a['order'] - $b['order'];
    });

    $budget  = defined('TOONSTREAM_BUDGET') ? max(6, (int)TOONSTREAM_BUDGET) : 12;
    $maxSrv  = defined('TOONSTREAM_MAX_SERVERS') ? max(1, (int)TOONSTREAM_MAX_SERVERS) : 5;
    $maxTry  = max(4, (int)(defined('TOONSTREAM_MAX_EMBEDS') ? TOONSTREAM_MAX_EMBEDS : 4));
    // Both cuts must show up in the chips, so each variant gets an equal
    // share of the server budget (2 cuts × 3, or all 5 for a lone cut).
    $perVar  = (int)ceil($maxSrv / max(1, count($variants)));
    $started = time();
    $tried   = 0;
    $servers = [];
    $perSeen = [];
    $perFail = [];
    $seen    = [];

    foreach ($cards as $card) {
        if (count($servers) >= $maxSrv) break;
        $v = $card['variant'];
        if (($perSeen[$v] ?? 0) >= $perVar) continue;
        // Diminishing returns: chasing the last slot is not worth seconds
        // of timeouts. A lone cut gets a wider leash (there is no second
        // cut waiting for budget), a cut sharing the sweep stops early —
        // one miss once it has two servers, so the other cut still gets
        // its share.
        $have = $perSeen[$v] ?? 0;
        if (count($variants) > 1) {
            $missLimit = $have >= 2 ? 1 : ($have >= 1 ? 2 : 4);
        } else {
            $missLimit = $have >= 4 ? 1 : ($have >= 2 ? 2 : ($have >= 1 ? 3 : 4));
        }
        if (($perFail[$v] ?? 0) >= $missLimit) continue;
        if ($tried >= $maxTry) break;
        $left = $budget - (time() - $started);
        if ($left <= 0) break;
        if (toonstream_host_rank($card['url']) >= 90) continue;   // no extractor for these
        if (isset($seen[$card['url']])) continue;

        $tried++;
        $got = toonstream_extract_embed($card['url'], $card['referer'], min(4, max(3, $left)));
        if (!$got || empty($got['url']) || isset($seen[$got['url']])) {
            $perFail[$v] = ($perFail[$v] ?? 0) + 1;
            continue;
        }
        $seen[$got['url']] = true;
        $perSeen[$v] = ($perSeen[$v] ?? 0) + 1;
        $perFail[$v] = 0;

        $host = strtolower((string)(parse_url($card['url'], PHP_URL_HOST) ?: ''));
        $hostish = preg_replace('/[^a-z0-9]+/', '-', trim($host, '.'));
        $servers[] = [
            'key'        => 'toonstream-' . ($hostish ?: 'srv') . '-' . $card['variant'] . '-' . $card['idx'],
            'label'      => 'ToonStream' . ($card['name'] !== '' ? ' ' . $card['name'] : ''),
            'lang'       => $card['lang'],
            'audio_lang' => $card['audio_lang'],
            'mode'       => 'hls',
            'url'        => $got['url'],
            'dataLink'   => null,
            'provider'   => 'toonstream',
            'subtitles'  => $got['subtitles'] ?? [],
            'primary'    => count($servers) === 0,
        ];
    }

    if (!$servers) {
        return ['ok' => false, 'message' => 'ToonStream embeds held no direct stream.'];
    }

    // CORS: the embed caption CDNs answer without an Access-Control-Allow-
    // Origin header, so a browser can only read them same-origin through
    // the signed subtitle proxy (stream.php always loads subtitles_api).
    if (function_exists('subtitle_sign')) {
        foreach (array_keys($servers) as $si) {
            $subs = $servers[$si]['subtitles'] ?? [];
            foreach (array_keys($subs) as $j) {
                $signed = subtitle_sign($subs[$j]['url'] ?? '');
                if ($signed !== null) $subs[$j]['url'] = $signed;
            }
            $servers[$si]['subtitles'] = $subs;
        }
    }

    return [
        'ok'         => true,
        'mode'       => 'hls',
        'url'        => $servers[0]['url'],
        'lang'       => $servers[0]['lang'],
        'audio_lang' => $servers[0]['audio_lang'] ?? null,
        'episode'    => $episode,
        'servers'    => $servers,
        'server_key' => $servers[0]['key'],
        'subtitles'  => $servers[0]['subtitles'] ?? [],
        'intro'      => null,
        'outro'      => null,
        'thumbnails' => null,
        'source'     => 'toonstream',
        'message'    => null,
    ];
}
