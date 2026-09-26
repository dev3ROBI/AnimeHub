<?php
/**
 * Chinese-platform stream extractor — third-party, keyed.
 *
 * Why a third party at all: none of iQIYI / Tencent-WeTV / Youku / MGTV / Sohu
 * expose a stream API. Their playurls are signed per request, mostly
 * Widevine-protected and region-locked to CN, so an in-house scraper would be
 * a weekly repair job with DRM'd titles unreachable at the end of it. A keyed
 * extractor service does that work from its own network, which is also what
 * gets around the region lock this server sits behind.
 *
 * This client is written against TikHub's Bilibili endpoints — the one pair in
 * that catalogue that is documented *and* returns the platform's own captions
 * alongside the stream:
 *
 *   GET /api/v1/bilibili/web/fetch_general_search?keyword=&order=&page=&page_size=
 *       → find the video (bvid)
 *   GET /api/v1/bilibili/web/fetch_one_video?bv_id=…            → cid / aid / title
 *   GET /api/v1/bilibili/web/fetch_video_playurl?bv_id=&cid=…   → the m3u8
 *   GET /api/v1/bilibili/web/fetch_video_subtitle?a_id=&c_id=…  → CC tracks (JSON)
 *
 * Response shapes are read defensively (see cnx_deep_*): these services move
 * keys around between versions, and a missing field should degrade to "no
 * source", never to a fatal. The captions the platform ships are converted to
 * WebVTT and served through the same signed relay the OpenSubtitles fallback
 * uses (includes/subtitle_proxy.php), because that CDN also needs a Referer.
 *
 * Everything here is inert while CNEXTRACT_KEY is empty — see config.php.
 */

include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/http.php';
include_once __DIR__ . '/subtitles_api.php';

// ─── Switches ─────────────────────────────────────────────────────────

function cnx_key() {
    return defined('CNEXTRACT_KEY') ? trim((string)CNEXTRACT_KEY) : '';
}

function cnx_enabled() {
    if (!defined('CNEXTRACT_ENABLED') || !CNEXTRACT_ENABLED) return false;
    return cnx_key() !== '';
}

function cnx_base() {
    $b = defined('CNEXTRACT_BASE_URL') ? (string)CNEXTRACT_BASE_URL : 'https://api.tikhub.io';
    return rtrim($b !== '' ? $b : 'https://api.tikhub.io', '/');
}

function cnx_timeout() {
    return defined('CNEXTRACT_TIMEOUT') ? max(4, (int)CNEXTRACT_TIMEOUT) : 12;
}

function cnx_cache_ttl() {
    return defined('CNEXTRACT_CACHE_TTL') ? max(60, (int)CNEXTRACT_CACHE_TTL) : 1800;
}

function cnx_relay_ttl() {
    return defined('CNEXTRACT_RELAY_TTL') ? max(300, (int)CNEXTRACT_RELAY_TTL) : 21600;
}

/** Preferred platform-caption languages, in order. */
function cnx_langs() {
    $raw = defined('CNEXTRACT_LANGS') ? (string)CNEXTRACT_LANGS : 'zho,eng';
    $out = [];
    foreach (explode(',', $raw) as $l) {
        $l = subtitles_norm_lang($l);
        if ($l !== '' && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out ?: ['zho', 'eng'];
}

/** One authenticated GET against the extractor service. */
function cnx_get($path, array $query = []) {
    if (!cnx_enabled()) return null;
    $url = cnx_base() . $path . ($query ? ('?' . http_build_query($query)) : '');
    $res = api_http($url, [
        'timeout' => cnx_timeout(),
        'label'   => 'cn_extract',
        'headers' => ['Authorization' => 'Bearer ' . cnx_key()],
    ]);
    return is_array($res) ? $res : null;
}

// ─── Defensive readers ────────────────────────────────────────────────
// The service reshapes its payloads between versions, so values are found by
// walking the tree instead of by trusting a path.

/** Every string in the tree that matches $regexp (in document order). */
function cnx_deep_strings($node, $regexp, $limit = 20) {
    $out = [];
    $walk = function ($n) use (&$walk, &$out, $regexp, $limit) {
        if (count($out) >= $limit) return;
        if (is_string($n)) {
            if (preg_match($regexp, $n)) $out[] = $n;
            return;
        }
        if (!is_array($n)) return;
        foreach ($n as $v) {
            if (is_array($v) || is_string($v)) $walk($v);
            if (count($out) >= $limit) return;
        }
    };
    $walk($node);
    return $out;
}

/** First scalar found under any of $keys (case/underscore insensitive). */
function cnx_deep_first($node, array $keys) {
    $want = array_map(fn($k) => strtolower(str_replace('_', '', $k)), $keys);
    $found = null;
    $walk = function ($n) use (&$walk, &$found, $want) {
        if ($found !== null || !is_array($n)) return;
        foreach ($n as $k => $v) {
            if (is_array($v)) continue;
            $key = strtolower(str_replace('_', '', (string)$k));
            if (in_array($key, $want, true) && $v !== null && $v !== '' && !is_array($v)) {
                $found = $v;
                return;
            }
        }
        foreach ($n as $v) {
            if (is_array($v)) $walk($v);
            if ($found !== null) return;
        }
    };
    $walk($node);
    return $found;
}

/** Every node in the tree that carries any of $keys (i.e. the result objects). */
function cnx_deep_nodes($node, array $keys) {
    $want = array_map(fn($k) => strtolower(str_replace('_', '', $k)), $keys);
    $out  = [];
    $walk = function ($n) use (&$walk, &$out, $want) {
        if (!is_array($n)) return;
        $hit = false;
        foreach ($n as $k => $v) {
            if (is_array($v)) continue;
            if (in_array(strtolower(str_replace('_', '', (string)$k)), $want, true)) { $hit = true; break; }
        }
        if ($hit) $out[] = $n;
        foreach ($n as $v) {
            if (is_array($v)) $walk($v);
        }
    };
    $walk($node);
    return $out;
}

/** A localised title out of one result node, for scoring. */
function cnx_node_title(array $node) {
    $title = (string)cnx_deep_first($node, ['title', 'name', 'video_title', 'show_title']);
    if ($title === '') return '';
    // Search results often wrap the real title in <em> highlight tags.
    return trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Loose title match: everything alphanumeric, lowercased. */
function cnx_norm_title($s) {
    $s = strtolower((string)$s);
    $s = preg_replace('/[^a-z0-9\p{Han}\p{Hiragana}\p{Katakana}]+/u', '', $s);
    return $s === null ? '' : $s;
}

// ─── Search / resolve ─────────────────────────────────────────────────

/**
 * Find the Bilibili entry for a title.
 *
 * Scored on the title (and the year when the result carries one) — a search
 * for "Hidden Love" also returns trailers and reactions, so the best-scoring
 * node wins rather than the first one.
 *
 * @param string $title   catalogue title
 * @param int    $year    release year, 0 when unknown
 * @return array|null     ['bvid'=>…, 'cid'=>…, 'aid'=>…, 'title'=>…]
 */
function cnx_search($title, $year = 0) {
    $title = trim((string)$title);
    if ($title === '' || !cnx_enabled()) return null;

    $key = api_cache_key('cn_extract', ['search', strtolower($title), (int)$year]);
    $hit = api_cache_get($key);
    if (is_array($hit) && array_key_exists('item', $hit)) return $hit['item'] ?: null;

    $data = cnx_get('/api/v1/bilibili/web/fetch_general_search', [
        'keyword'   => $title,
        'order'     => 'totalrank',
        'page'      => 1,
        'page_size' => 10,
    ]);

    $best = null;
    $bestScore = -1;
    $wantTitle = cnx_norm_title($title);
    foreach (cnx_deep_nodes((array)$data, ['bvid', 'bv_id', 'bvid_str']) as $node) {
        $bvid = (string)cnx_deep_first($node, ['bvid', 'bv_id', 'bvid_str']);
        if ($bvid === '' || !preg_match('/^BV[0-9A-Za-z]{8,12}$/', $bvid)) continue;

        $score = 0;
        $nodeTitle = cnx_norm_title(cnx_node_title($node));
        if ($nodeTitle !== '' && $wantTitle !== '') {
            if ($nodeTitle === $wantTitle)                                  $score += 6;
            elseif (strpos($nodeTitle, $wantTitle) !== false)               $score += 4;
            elseif (strpos($wantTitle, $nodeTitle) !== false)               $score += 3;
        }
        $pub = (int)cnx_deep_first($node, ['pubdate', 'pubtime', 'pub_time', 'year', 'release_date']);
        if ($year > 0 && $pub > 0) {
            $pubYear = $pub > 100000000 ? (int)date('Y', $pub) : ($pub < 1000 ? 0 : $pub);
            if ($pubYear === (int)$year) $score += 3;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'bvid'  => $bvid,
                'cid'   => (string)cnx_deep_first($node, ['cid', 'c_id']),
                'aid'   => (string)cnx_deep_first($node, ['aid', 'a_id', 'avid']),
                'title' => cnx_node_title($node) ?: $title,
            ];
        }
    }

    // No caption-worthy match at all is a miss worth remembering (same as the
    // OpenSubtitles fallback: misses are cached so a page never re-asks).
    api_cache_set($key, 'cn_extract', ['item' => $best], $best ? 21600 : 3600);
    return $best;
}

/** cid/aid for a video, when the search hit did not carry them. */
function cnx_video_ids($bvid) {
    $bvid = trim((string)$bvid);
    if ($bvid === '' || !cnx_enabled()) return null;

    $key = api_cache_key('cn_extract', ['video', $bvid]);
    $hit = api_cache_get($key);
    if (is_array($hit) && array_key_exists('ids', $hit)) return $hit['ids'] ?: null;

    $data = cnx_get('/api/v1/bilibili/web/fetch_one_video', ['bv_id' => $bvid]);
    $ids  = [
        'cid'   => (string)cnx_deep_first((array)$data, ['cid', 'c_id']),
        'aid'   => (string)cnx_deep_first((array)$data, ['aid', 'a_id', 'avid']),
        'title' => (string)cnx_deep_first((array)$data, ['title', 'name']),
    ];
    $ids = array_filter($ids, fn($v) => $v !== '');
    api_cache_set($key, 'cn_extract', ['ids' => $ids], 21600);
    return $ids ?: null;
}

/**
 * Playable URL for one video: the HLS master when the service offers one
 * (that is what our player wants), else the progressive mp4 from `durl`.
 *
 * Playurls expire quickly at the platform's end, so this is cached for a
 * fraction of the usual TTL.
 *
 * @return array|null  ['url'=>…, 'type'=>'m3u8'|'mp4']
 */
function cnx_playurl($bvid, $cid) {
    $bvid = trim((string)$bvid);
    $cid  = trim((string)$cid);
    if ($bvid === '' || $cid === '' || !cnx_enabled()) return null;

    $key = api_cache_key('cn_extract', ['playurl', $bvid, $cid]);
    $hit = api_cache_get($key);
    if (is_array($hit) && array_key_exists('play', $hit)) return $hit['play'] ?: null;

    $data = cnx_get('/api/v1/bilibili/web/fetch_video_playurl', ['bv_id' => $bvid, 'cid' => $cid]);

    $play = null;
    foreach (cnx_deep_strings((array)$data, '~^https?://[^\s"]+\.m3u8~i', 5) as $u) {
        $play = ['url' => $u, 'type' => 'm3u8'];
        break;
    }
    if (!$play) {
        // Progressive fallback: durl[].url / backup_url[] — an .mp4 the
        // player can run directly.
        foreach (cnx_deep_strings((array)$data, '~^https?://[^\s"]+\.(mp4|flv)~i', 5) as $u) {
            $play = ['url' => $u, 'type' => 'mp4'];
            break;
        }
    }

    api_cache_set($key, 'cn_extract', ['play' => $play], $play ? 900 : 120);
    return $play;
}

/**
 * The platform's own CC tracks, as signed relay URLs our player can load.
 *
 * Bilibili serves them as JSON (`{body:[{from,to,content}]}`); the proxy
 * converts that to WebVTT on the way out, which is why the format rides along
 * in the signed link.
 *
 * @return array  [{url, language, lang, format, source}, …]
 */
function cnx_subtitles($aid, $cid, $bvid = '') {
    $aid = trim((string)$aid);
    $cid = trim((string)$cid);
    if ($cid === '' || ($aid === '' && $bvid === '') || !cnx_enabled()) return [];

    $key = api_cache_key('cn_extract', ['subs', $aid, $cid, $bvid]);
    $hit = api_cache_get($key);
    if (is_array($hit) && array_key_exists('tracks', $hit)) return $hit['tracks'] ?: [];

    // The subtitle endpoint wants the numeric ids; a bvid-only hit needs the
    // aid looked up first.
    if ($aid === '' && $bvid !== '') {
        $ids = cnx_video_ids($bvid);
        $aid = (string)($ids['aid'] ?? '');
    }
    if ($aid === '') return [];

    $data   = cnx_get('/api/v1/bilibili/web/fetch_video_subtitle', ['a_id' => $aid, 'c_id' => $cid]);
    $wanted = cnx_langs();
    $tracks = [];
    $seen   = [];

    foreach (cnx_deep_nodes((array)$data, ['subtitle_url', 'subtitleUrl', 'url']) as $node) {
        $url = (string)cnx_deep_first($node, ['subtitle_url', 'subtitleUrl', 'url']);
        if ($url === '' || isset($seen[$url])) continue;
        if (stripos($url, 'hdslb.com') === false && stripos($url, '/bfs/') === false) continue;
        if (!subtitles_url_ok($url)) continue;
        $seen[$url] = true;

        $lang  = subtitles_norm_lang(cnx_deep_first($node, ['lan', 'lang', 'language', 'lang_doc']) ?: '');
        if ($lang === '') $lang = $wanted[0];

        $signed = subtitle_sign($url, 'bilijson');
        if ($signed === null) continue;

        $tracks[] = [
            'url'      => $signed,
            'language' => subtitles_lang_name($lang),
            'lang'     => $lang,
            'format'   => 'vtt',
            'source'   => 'bilibili',
        ];
    }

    // Preferred languages first, in order; everything else keeps service order.
    usort($tracks, function ($a, $b) use ($wanted) {
        $ia = array_search($a['lang'], $wanted, true);
        $ib = array_search($b['lang'], $wanted, true);
        $ia = ($ia === false) ? 99 : $ia;
        $ib = ($ib === false) ? 99 : $ib;
        return $ia <=> $ib;
    });

    $tracks = array_slice($tracks, 0, max(1, subtitles_max()));
    foreach ($tracks as $i => $t) $tracks[$i]['default'] = ($i === 0);

    api_cache_set($key, 'cn_extract', ['tracks' => $tracks], $tracks ? subtitles_cache_ttl() : 3600);
    return $tracks;
}

/**
 * Full resolve: title → playable source + the platform's captions.
 *
 * @return array|null  [
 *   'url' => signed/plain media URL, 'type' => 'm3u8'|'mp4',
 *   'subtitles' => [...], 'title' => matched title, 'bvid','cid','aid'
 * ]
 */
function cnx_resolve($title, $year = 0) {
    if (!cnx_enabled()) return null;

    $hit = cnx_search($title, $year);
    if (!$hit || empty($hit['bvid'])) return null;

    $ids = cnx_video_ids($hit['bvid']) ?: [];
    $cid = (string)($hit['cid'] ?? ($ids['cid'] ?? ''));
    $aid = (string)($hit['aid'] ?? ($ids['aid'] ?? ''));
    if ($cid === '') return null;

    $play = cnx_playurl($hit['bvid'], $cid);
    if (!$play || empty($play['url'])) return null;

    return [
        'url'       => $play['url'],
        'type'      => $play['type'],
        'subtitles' => cnx_subtitles($aid, $cid, $hit['bvid']),
        'title'     => (string)($hit['title'] ?? $title),
        'bvid'      => $hit['bvid'],
        'cid'       => $cid,
        'aid'       => $aid,
    ];
}

/**
 * Source entry for the player queue — the same shape eightstream_source_entry()
 * produces, so the client and the chips row need no special case.
 *
 * @param string $title    catalogue title
 * @param int    $year     release year, 0 when unknown
 * @param string $label    chip label (iQIYI / WeTV / Bilibili …)
 */
function cnx_source_entry($title, $year = 0, $label = 'Bilibili') {
    if (!cnx_enabled()) return null;

    $res = cnx_resolve($title, $year);
    if (!$res || empty($res['url'])) return null;

    return [
        'key'        => 'cn_extract',
        'label'      => $label,
        'type'       => $res['type'],
        'mode'       => 'hls',
        'url'        => $res['url'],
        'provider'   => 'cn_extract',
        'lang'       => 'any',
        'dataLink'   => null,
        'headers'    => null,
        'expires_at' => null,
        'subtitles'  => array_map(function ($s) {
            return [
                'url'      => $s['url'],
                'language' => $s['language'],
                'lang'     => $s['lang'],
                'format'   => 'vtt',
                'source'   => 'bilibili',
                'default'  => !empty($s['default']),
            ];
        }, (array)($res['subtitles'] ?? [])),
        'intro'      => null,
        'outro'      => null,
        'audio'      => [],
        'audio_lang' => null,
        'cn_id'      => ['bvid' => $res['bvid'] ?? null, 'cid' => $res['cid'] ?? null],
    ];
}
