<?php
/**
 * 8Stream provider — native PHP, no Node service needed.
 *
 * The chain (verified against the live site, 2026-09):
 *
 *   1. a base site (allmovieland.*) serves `const AwsIndStreamDomain = '…'`
 *      → the current player hostname (it rotates)
 *   2. GET {player}/play/{imdb}
 *      → the page embeds a player config object literal, either
 *          let p3 = {"file":…,"key":…,"host":…}          (movie)
 *        or
 *          var pl = new HDVBPlayer({"file":…,"key":…})   (series)
 *   3. GET {player}{file}  with `X-Csrf-Token: {key}`
 *      → JSON: [ {language…}, … ]                       (movie)
 *        or     [ {season: "Season 1", folder:[ {episode, folder:[language…]} ]}, … ]
 *   4. GET {player}/playlist/{leaf.slice(1)}.txt  with the same token
 *      → a plain-text master m3u8 URL
 *
 * That last URL only answers with the player's own `Referer`/`Origin` and a
 * token that embeds the *requesting* IP — so a browser on our origin always
 * 404s it. Every URL handed to the client therefore goes through
 * includes/eightstream_relay.php (signed, header-attaching, playlist-rewriting).
 *
 * Nothing here trusts an upstream payload: hosts/paths are only signed for the
 * relay after the learn → fetch → resolve chain produced them, and the relay
 * re-validates on every request.
 */

include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/http.php';
include_once __DIR__ . '/eightstream_relay_lib.php';

// ─── Switches / config readers ────────────────────────────────────────

function eightstream_enabled() {
    return defined('EIGHTSTREAM_ENABLED') && EIGHTSTREAM_ENABLED;
}

/** Base sites whose HTML carries the current player hostname. */
function eightstream_bases() {
    $list = $GLOBALS['EIGHTSTREAM_BASE_URLS'] ?? [];
    return is_array($list) ? array_values($list) : [];
}

/** Player domains used directly / as extra candidates. */
function eightstream_direct_players() {
    $list = $GLOBALS['EIGHTSTREAM_PLAYER_URLS'] ?? [];
    return is_array($list) ? array_values($list) : [];
}

function eightstream_timeout() {
    return defined('EIGHTSTREAM_TIMEOUT') ? max(3, (int)EIGHTSTREAM_TIMEOUT) : 12;
}

// ─── Low-level HTTP ───────────────────────────────────────────────────

/**
 * GET a URL and return [httpCode, body, contentType, error]. Never throws.
 * Intentionally raw (not api_http): the player/CDN hosts are learned at
 * runtime, so the caller — not a fixed allowlist — decides what to fetch.
 */
function es_http($url, array $headers = [], $timeout = null) {
    $timeout = (int)max(3, $timeout ?? eightstream_timeout());
    $hdrs = [
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ];
    foreach ($headers as $k => $v) $hdrs[] = is_int($k) ? $v : ($k . ': ' . $v);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$code, is_string($body) ? $body : '', $err, $ct];
}

// ─── Step 1: learn the player hostname ────────────────────────────────

/**
 * Player domains to try, in order: every base that names one, then the
 * configured direct domains. Cached — the hostname changes rarely and a
 * cold call costs one request per base.
 */
function eightstream_player_domains() {
    $cacheKey = 'eightstream:players';
    $hit = api_cache_get($cacheKey);
    if (is_array($hit) && !empty($hit['domains'])) return $hit['domains'];

    $found = [];
    foreach (eightstream_bases() as $base) {
        [$code, $html] = es_http($base, ['Referer: https://google.com'], min(8, eightstream_timeout()));
        if ($code < 200 || $code >= 400 || $html === '') continue;
        if (preg_match("#const\\s+AwsIndStreamDomain\\s*=\\s*['\"]([^'\"]+)['\"]#", $html, $m)) {
            $domain = rtrim(trim($m[1]), '/');
            if (stripos($domain, 'http') !== 0) $domain = 'https://' . ltrim($domain, '/');
            if (es_domain_ok($domain)) $found[] = $domain;
        }
    }

    foreach (eightstream_direct_players() as $d) {
        $d = rtrim(trim((string)$d), '/');
        if ($d !== '' && stripos($d, 'http') !== 0) $d = 'https://' . ltrim($d, '/');
        if ($d !== '' && es_domain_ok($d)) $found[] = $d;
    }

    $found = array_values(array_unique($found));
    if ($found) api_cache_set($cacheKey, 'eightstream', ['domains' => $found], 3600);
    return $found;
}

/** Sanity gate for a learned/configured origin. */
function es_domain_ok($domain) {
    $p = parse_url($domain);
    if (!is_array($p) || empty($p['host'])) return false;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') return false;
    return !empty($p['host']);
}

// ─── Step 2: the player page → {file, key, host} ──────────────────────

/**
 * Extract the player config object literal out of a /play/ page.
 *
 * The object is the only `{"file":…}` literal on the page; strings are
 * balanced-scanned (it nests `rek`/ad objects), so a naive regex would not
 * survive. Returns the decoded array or null.
 */
function eightstream_parse_config($html) {
    $pos = strpos($html, '{"file":');
    if ($pos === false) {
        $at = strpos($html, '"file"');
        if ($at === false) return null;
        $pos = strrpos(substr($html, 0, $at), '{');
        if ($pos === false) return null;
    }

    $depth = 0; $inStr = false; $esc = false;
    $len = strlen($html);
    for ($i = $pos; $i < $len; $i++) {
        $ch = $html[$i];
        if ($inStr) {
            if ($esc) { $esc = false; continue; }
            if ($ch === '\\') { $esc = true; continue; }
            if ($ch === '"') $inStr = false;
            continue;
        }
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === '{') $depth++;
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $data = json_decode(substr($html, $pos, $i - $pos + 1), true);
                return is_array($data) ? $data : null;
            }
        }
    }
    return null;
}

/** Fetch + parse the player page for one IMDb id. */
function eightstream_page($player, $imdb) {
    $url = rtrim($player, '/') . '/play/' . rawurlencode($imdb);
    [$code, $html] = es_http($url, [
        'Origin: https://allmovieland.link',
        'Referer: https://google.com',
    ]);
    if ($code < 200 || $code >= 400 || $html === '') return null;

    $cfg = eightstream_parse_config($html);
    if (!is_array($cfg) || empty($cfg['file']) || empty($cfg['key'])) return null;
    return $cfg;
}

// ─── Step 3: the config's file → language/season tree ─────────────────

/**
 * Fetch the playlist tree behind the player config.
 * Returns ['items' => […], 'base' => playlist origin, 'cfg' => cfg] or null.
 */
function eightstream_tree($player, array $cfg) {
    $file = (string)$cfg['file'];
    $url  = stripos($file, 'http') === 0 ? $file : rtrim($player, '/') . $file;

    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) return null;

    [$code, $body] = es_http($url, [
        'X-Csrf-Token: ' . $cfg['key'],
        'Referer: https://google.com/',
        'Origin: https://' . ($cfg['host'] ?? $p['host']),
    ]);
    if ($code < 200 || $code >= 300 || $body === '') return null;

    $items = json_decode($body, true);
    if (!is_array($items)) return null;

    $base = strtolower((string)($p['scheme'] ?? 'https')) . '://' . $p['host']
          . (isset($p['port']) ? ':' . $p['port'] : '');

    return ['items' => $items, 'base' => $base, 'cfg' => $cfg];
}

// ─── Step 4: pick the requested language / season+episode leaf ────────

/** The real objects of a tree level (drops the empty [] sentinel rows). */
function eightstream_objects($items) {
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        if (empty($it['title']) && empty($it['file']) && empty($it['folder'])) continue;
        $out[] = $it;
    }
    return $out;
}

/**
 * The language rows for one request: a movie's tree level is already the
 * language list; a series tree is drilled season → episode first.
 */
function eightstream_episode_langs($items, $season = 0, $episode = 0) {
    $objects = eightstream_objects($items);
    if (!$objects) return [];

    $isSeries = false;
    foreach ($objects as $it) {
        if (!empty($it['folder']) && is_array($it['folder'])) { $isSeries = true; break; }
    }
    if (!$isSeries) return $objects;   // movie: the level is the language list

    $wantedSeason = (int)$season;
    $seasonItem = null;
    if ($wantedSeason > 0) {
        foreach ($objects as $it) {
            if (eightstream_title_season($it['title'] ?? '') === $wantedSeason) { $seasonItem = $it; break; }
        }
    }
    if (!$seasonItem) $seasonItem = $objects[0];

    $episodes = eightstream_objects($seasonItem['folder'] ?? []);
    if (!$episodes) return [];

    $wantedEp = (int)$episode;
    $epItem = null;
    if ($wantedEp > 0) {
        foreach ($episodes as $ep) {
            $n = (int)($ep['episode'] ?? 0);
            if ($n === 0 && preg_match('/^\s*(\d+)/', (string)($ep['title'] ?? ''), $m)) $n = (int)$m[1];
            if ($n === $wantedEp) { $epItem = $ep; break; }
        }
    }
    if (!$epItem) {
        $idx = max(0, $wantedEp - 1);
        $epItem = $episodes[$idx] ?? $episodes[0];
    }

    return eightstream_objects($epItem['folder'] ?? []);
}

/**
 * Language titles available for one request, in provider order — every entry
 * is a separate audio stream (a selectable "Audio" row in the player).
 */
function eightstream_language_titles($items, $season = 0, $episode = 0) {
    $out = [];
    foreach (eightstream_episode_langs($items, $season, $episode) as $lang) {
        if (empty($lang['file'])) continue;
        $title = trim((string)($lang['title'] ?? ''));
        if ($title !== '') $out[] = $title;
    }
    return array_values(array_unique($out));
}

/** Titles a language pick prefers, best first. */
function eightstream_lang_preference($lang) {
    $lang = strtolower(trim((string)$lang));
    if ($lang !== '' && !in_array($lang, ['sub', 'dub', 'any'], true)) {
        return [$lang, 'english'];
    }
    /*
     * The provider's audio tracks (Hindi/English/Bengali/Tamil/Telugu). A
     * sub/dub/empty request means "no preference stated" — order comes from
     * EIGHTSTREAM_AUDIO_PREF so the audience can make dubbed audio the
     * default, with the last entry acting as the catch-all.
     */
    $pref = defined('EIGHTSTREAM_AUDIO_PREF') ? (string)EIGHTSTREAM_AUDIO_PREF : '';
    $list = [];
    foreach (explode(',', $pref) as $name) {
        $name = strtolower(trim($name));
        if ($name !== '') $list[] = $name;
    }
    if (!$list) $list = ['english', 'hindi'];
    if (!in_array('english', $list, true)) $list[] = 'english';
    return array_values(array_unique($list));
}

/** Choose one item (a leaf with `file`) from a language list. */
function eightstream_pick_language($list, $lang) {
    if (!is_array($list)) return null;
    $prefs = eightstream_lang_preference($lang);

    foreach ($prefs as $want) {
        foreach ($list as $item) {
            if (!is_array($item) || empty($item['file'])) continue;
            $title = strtolower((string)($item['title'] ?? ''));
            if ($title !== '' && strpos($title, $want) !== false) return $item;
        }
    }
    // Nothing matched the preference: first leaf that carries a file.
    foreach ($list as $item) {
        if (is_array($item) && !empty($item['file'])) return $item;
    }
    return null;
}

/** Season number from a title like "Season 2"; 0 when there is none. */
function eightstream_title_season($title) {
    return preg_match('/season\s*(\d+)/i', (string)$title, $m) ? (int)$m[1] : 0;
}

/**
 * Walk the tree to the leaf `file` for one request.
 *
 * @param array $items    playlist tree from eightstream_tree()
 * @param int   $season   0 = auto / not a series
 * @param int   $episode  episode number (movies: ignored)
 * @param string $lang     'sub'|'dub'|a language name
 * @return array|null  ['file' => '~…', 'lang' => 'English', 'kind' => 'movie'|'tv']
 */
function eightstream_pick_leaf($items, $season = 0, $episode = 0, $lang = 'sub') {
    $langs = eightstream_episode_langs($items, $season, $episode);
    if (!$langs) return null;

    $leaf = eightstream_pick_language($langs, $lang);
    if (!$leaf || empty($leaf['file'])) return null;

    $isSeries = false;
    foreach (eightstream_objects($items) as $it) {
        if (!empty($it['folder']) && is_array($it['folder'])) { $isSeries = true; break; }
    }

    return [
        'file' => (string)$leaf['file'],
        'lang' => (string)($leaf['title'] ?? 'English'),
        'kind' => $isSeries ? 'tv' : 'movie',
    ];
}

// ─── Step 5: leaf → master m3u8 ───────────────────────────────────────

/**
 * Ask the player for the master m3u8 behind a leaf file. The response body is
 * a plain-text URL (or, occasionally, the playlist itself).
 */
function eightstream_leaf_stream($player, array $tree, $leafFile) {
    $leafFile = (string)$leafFile;
    $path = substr($leafFile, 1) . '.txt';
    $key = (string)($tree['cfg']['key'] ?? '');
    $host = (string)($tree['cfg']['host'] ?? '');

    $bases = array_values(array_unique(array_filter([$tree['base'] ?? null, rtrim($player, '/')])));

    foreach ($bases as $base) {
        $url = rtrim($base, '/') . '/playlist/' . $path;
        [$code, $body] = es_http($url, [
            'X-Csrf-Token: ' . $key,
            'Referer: https://google.com/',
            'Origin: https://' . $host,
        ]);
        if ($code < 200 || $code >= 300 || $body === '') continue;

        $body = trim($body);
        if (stripos($body, 'http') === 0 && !str_contains($body, ' ')) return $body;
        if (stripos($body, '#EXTM3U') === 0) return $body;   // already a playlist
    }
    return null;
}

/** Does the master playlist actually serve behind the required headers? */
function eightstream_probe($url, $referer) {
    if (!defined('EIGHTSTREAM_VERIFY') || !EIGHTSTREAM_VERIFY) return true;

    $origin = rtrim((string)$referer, '/');
    [$code, $body, , $ct] = es_http($url, [
        'Referer: ' . $origin . '/',
        'Origin: ' . $origin,
        'Range: bytes=0-2047',
    ], 8);

    if ($code < 200 || $code >= 300 || $body === '') return false;
    if (stripos($ct, 'application/json') !== false) return false;
    return str_starts_with($body, '#EXTM3U');
}

// ─── Orchestration ────────────────────────────────────────────────────

/**
 * Resolve one IMDb id (+ season/episode/lang) to a player-ready result.
 *
 * @return array ['ok'=>bool, 'url'=>relay url, 'raw'=>m3u8, 'lang'=>…,
 *                'referer'=>…, 'type'=>'hls', 'error'=>…]
 */
function eightstream_resolve($imdb, $season = 0, $episode = 0, $lang = 'sub') {
    $imdb = trim((string)$imdb);
    if (!preg_match('/^tt\d{5,10}$/', $imdb)) {
        return ['ok' => false, 'error' => 'bad-imdb'];
    }
    if (!eightstream_enabled()) {
        return ['ok' => false, 'error' => 'disabled'];
    }

    $season  = max(0, (int)$season);
    $episode = max(0, (int)$episode);

    $cacheKey = api_cache_key('eightstream', ['v2', $imdb, $season, $episode, strtolower((string)$lang)]);
    $hit = api_cache_get($cacheKey);
    if (is_array($hit) && !empty($hit['raw']) && !empty($hit['referer'])) {
        $signed = es_relay_sign($hit['raw'], $hit['referer']);
        if ($signed !== null) {
            return ['ok' => true, 'type' => 'hls', 'url' => $signed, 'raw' => $hit['raw'],
                    'referer' => $hit['referer'], 'lang' => $hit['lang'] ?? null,
                    'kind' => $hit['kind'] ?? null, 'languages' => $hit['languages'] ?? [], 'cached' => true];
        }
    }

    $players = eightstream_player_domains();
    if (!$players) return ['ok' => false, 'error' => 'no-player-domain'];

    // Wall-clock budget so a black-holing provider cannot stall the episode
    // resolve (the client is waiting with its own timer).
    $deadline = microtime(true) + 20;

    $lastError = 'not-found';
    foreach ($players as $player) {
        if (microtime(true) >= $deadline) { $lastError = 'budget'; break; }
        $cfg = eightstream_page($player, $imdb);
        if (!$cfg) { $lastError = 'page-failed'; continue; }

        $tree = eightstream_tree($player, $cfg);
        if (!$tree) { $lastError = 'tree-failed'; continue; }

        $leaf = eightstream_pick_leaf($tree['items'], $season, $episode, $lang);
        if (!$leaf || empty($leaf['file'])) { $lastError = 'no-leaf'; continue; }

        $m3u8 = eightstream_leaf_stream($player, $tree, $leaf['file']);
        if (!$m3u8) { $lastError = 'no-m3u8'; continue; }

        $referer = 'https://' . (string)($cfg['host'] ?? parse_url($m3u8, PHP_URL_HOST));
        if (!es_cdn_url_ok($m3u8)) { $lastError = 'cdn-not-allowlisted'; continue; }
        if (!eightstream_probe($m3u8, $referer)) { $lastError = 'verify-failed'; continue; }

        $languages = eightstream_language_titles($tree['items'], $season, $episode);

        $ttl = defined('EIGHTSTREAM_CACHE_TTL') ? (int)EIGHTSTREAM_CACHE_TTL : 1800;
        api_cache_set($cacheKey, 'eightstream', [
            'raw'       => $m3u8,
            'referer'   => $referer,
            'lang'      => $leaf['lang'] ?? null,
            'kind'      => $leaf['kind'] ?? null,
            'languages' => $languages,
        ], max(60, $ttl));

        $signed = es_relay_sign($m3u8, $referer);
        if ($signed === null) { $lastError = 'sign-failed'; continue; }

        return ['ok' => true, 'type' => 'hls', 'url' => $signed, 'raw' => $m3u8,
                'referer' => $referer, 'lang' => $leaf['lang'] ?? null,
                'kind' => $leaf['kind'] ?? null, 'languages' => $languages];
    }

    return ['ok' => false, 'error' => $lastError];
}

// ─── IMDb id for a catalogue item ─────────────────────────────────────

/**
 * The IMDb id 8Stream needs. TMDB movie/TV rows carry one already; anime rows
 * (AniList/Jikan/ReAnime) are cross-referenced through TMDB's MyAnimeList
 * mapping, then a title search — best effort, and null when TMDB is off/no key.
 */
function eightstream_imdb_for($info) {
    if (!is_array($info)) return null;

    $imdb = (string)($info['imdb_id'] ?? '');
    if (preg_match('/^tt\d{5,10}$/', $imdb)) return $imdb;

    // The cross-lookup costs a couple of TMDB round trips — remember it.
    $cacheKey = api_cache_key('eightstream', ['imdb', $info['provider'] ?? '', $info['id'] ?? '', $info['title'] ?? '']);
    $hit = api_cache_get($cacheKey);
    if (is_array($hit) && array_key_exists('imdb', $hit)) {
        return $hit['imdb'] ?: null;
    }

    if (!function_exists('tmdb_get')) {
        include_once __DIR__ . '/tmdb_api.php';
    }
    if (!function_exists('tmdb_enabled') || !tmdb_enabled()) return null;
    if (!function_exists('tmdb_tv_id')) {
        include_once __DIR__ . '/tmdb_movie_api.php';
    }

    $title = trim((string)($info['title'] ?? ''));
    $year  = $info['year'] ?? ($info['aired'] ?? null);
    $year  = $year ? (int)substr((string)$year, 0, 4) : null;
    $malId = (int)($info['mal_id'] ?? 0);

    // TV/anime first — TMDB maps MAL ids exactly, so that id is trustworthy.
    if (function_exists('tmdb_tv_id')) {
        $tvId = tmdb_tv_id($malId ?: null, $title, $year);
        if ($tvId) {
            $ext = tmdb_get('/tv/' . (int)$tvId . '/external_ids');
            if (!empty($ext['imdb_id']) && preg_match('/^tt\d+$/', (string)$ext['imdb_id'])) {
                api_cache_set($cacheKey, 'eightstream', ['imdb' => (string)$ext['imdb_id']], 7 * 86400);
                return (string)$ext['imdb_id'];
            }
        }
    }

    // Movie fallback by title (+year).
    if ($title !== '') {
        $query = ['query' => $title, 'include_adult' => 'false'];
        if ($year) $query['year'] = $year;
        $res = tmdb_get('/search/movie', $query);
        $mid = $res['results'][0]['id'] ?? null;
        if (!$mid && $year) {
            $res = tmdb_get('/search/movie', ['query' => $title, 'include_adult' => 'false']);
            $mid = $res['results'][0]['id'] ?? null;
        }
        if ($mid) {
            $ext = tmdb_get('/movie/' . (int)$mid . '/external_ids');
            if (!empty($ext['imdb_id']) && preg_match('/^tt\d+$/', (string)$ext['imdb_id'])) {
                api_cache_set($cacheKey, 'eightstream', ['imdb' => (string)$ext['imdb_id']], 7 * 86400);
                return (string)$ext['imdb_id'];
            }
        }
    }

    // Cache the miss too (shorter) so a title with no IMDb mapping is not
    // re-searched on every episode.
    api_cache_set($cacheKey, 'eightstream', ['imdb' => ''], 3600);
    return null;
}

/** Season number to use for a series item (AniList titles often name it). */
function eightstream_season_for($info) {
    $title = (string)($info['title'] ?? '');
    $n = eightstream_title_season($title);
    return $n > 0 ? $n : 1;
}

/**
 * A ready-to-play source entry for the source queues (hls → our own player).
 * Returns null when 8Stream has nothing for the title.
 */
function eightstream_source_entry($imdb, $season = 0, $episode = 0, $lang = 'sub', $key = 'eightstream', $label = '8Stream') {
    $res = eightstream_resolve($imdb, $season, $episode, $lang);
    if (empty($res['ok']) || empty($res['url'])) return null;

    // Every audio language the provider offers, each self-describing so the
    // client can ask for just the one the viewer picks (get_eightstream_audio).
    $audio = [];
    foreach (($res['languages'] ?? []) as $title) {
        $audio[] = [
            'label'  => (string)$title,
            'lang'   => (string)$title,
            'imdb'   => $imdb,
            'season' => (int)$season,
            'ep'     => (int)$episode,
        ];
    }

    return [
        'key'       => $key,
        'label'     => $label,
        'type'      => 'hls',
        'mode'      => 'hls',
        'url'       => $res['url'],
        'provider'  => 'eightstream',
        'lang'      => 'any',
        'audio'     => $audio,
        'audio_lang'=> $res['lang'] ?? null,
        'dataLink'  => null,
        'headers'   => null,
        'expires_at'=> null,
        'subtitles' => [],
        'intro'     => null,
        'outro'     => null,
    ];
}
