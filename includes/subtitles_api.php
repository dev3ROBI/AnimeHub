<?php
/**
 * Online subtitles — captions for a title whose own source ships none.
 *
 * English first, then Chinese (see SUBTITLES_LANGS): a C-drama very often has
 * only a Chinese track, and a Chinese track the viewer cannot read is still
 * worse than none — so both are offered and the CC row switches between them.
 *
 * Chain (all server-side, no Node service):
 *
 *   1. GET {SUBTITLES_API_BASE}/subtitles/movie/{imdb}.json
 *      or  …/subtitles/series/{imdb}:{season}:{episode}.json
 *      → {"subtitles":[{"url":"https://subs5.strem.io/…","lang":"eng",…}, …]}
 *
 *      This is the OpenSubtitles v3 addon that Stremio itself uses. It stays
 *      keyless (Wyzie and the OpenSubtitles REST API both started demanding an
 *      API key), which is the whole reason it is the pick here.
 *
 *   2. The upstream file URL is never handed to the browser: it is signed into
 *      includes/subtitle_proxy.php (host gate + HMAC + expiry) so the relay
 *      re-validates it and can convert SRT → WebVTT on the way out. The
 *      subtitle CDN sends no CORS headers, so a direct browser fetch would
 *      fail even if the URL were allowed.
 *
 * Nothing here runs for a source that already carries captions — callers only
 * ask when their own subtitle list came back empty (see subtitles_attach).
 */

include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/http.php';

// ─── Switches / tunables ──────────────────────────────────────────────

function subtitles_enabled() {
    return defined('SUBTITLES_AUTO_ENABLED') && SUBTITLES_AUTO_ENABLED;
}

function subtitles_timeout() {
    return defined('SUBTITLES_TIMEOUT') ? max(3, (int)SUBTITLES_TIMEOUT) : 6;
}

function subtitles_cache_ttl() {
    return defined('SUBTITLES_CACHE_TTL') ? max(60, (int)SUBTITLES_CACHE_TTL) : 86400;
}

function subtitles_relay_ttl() {
    return defined('SUBTITLES_RELAY_TTL') ? max(300, (int)SUBTITLES_RELAY_TTL) : 21600;
}

/** Preferred languages, ISO-639-2, in order. */
function subtitles_langs() {
    $raw = defined('SUBTITLES_LANGS') ? (string)SUBTITLES_LANGS : 'eng';
    $out = [];
    foreach (explode(',', $raw) as $l) {
        $l = subtitles_norm_lang($l);
        if ($l !== '' && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out ?: ['eng'];
}

function subtitles_api_base() {
    $base = defined('SUBTITLES_API_BASE') ? (string)SUBTITLES_API_BASE : 'https://opensubtitles-v3.strem.io';
    return rtrim($base !== '' ? $base : 'https://opensubtitles-v3.strem.io', '/');
}

function subtitles_max() {
    return defined('SUBTITLES_MAX') ? max(1, (int)SUBTITLES_MAX) : 3;
}

// ─── Language helpers ─────────────────────────────────────────────────

/** 'en' → 'eng', 'eng' → 'eng' (upstream mixes both). */
function subtitles_norm_lang($lang) {
    $lang = strtolower(trim((string)$lang));
    if ($lang === '') return '';
    static $map = [
        'en' => 'eng', 'bn' => 'ben', 'hi' => 'hin', 'ja' => 'jpn', 'ar' => 'ara',
        'es' => 'spa', 'fr' => 'fra', 'de' => 'deu', 'pt' => 'por', 'ru' => 'rus',
        'ko' => 'kor', 'zh' => 'zho', 'id' => 'ind', 'ta' => 'tam', 'te' => 'tel',
        'ur' => 'urd', 'tr' => 'tur', 'vi' => 'vie', 'th' => 'tha', 'it' => 'ita',
        'nl' => 'nld', 'pl' => 'pol', 'ml' => 'mal', 'kn' => 'kan', 'mr' => 'mar',
        // Bibliographic (639-2/B) → terminological (639-2/T): uploaders tag
        // either, and a track filed as "chi" would otherwise never match the
        // preferred "zho" — which is exactly the track a C-drama needs.
        'chi' => 'zho', 'ger' => 'deu', 'fre' => 'fra', 'dut' => 'nld',
        'cze' => 'ces', 'gre' => 'ell', 'rum' => 'ron', 'slo' => 'slk',
        'alb' => 'sqi', 'arm' => 'hye', 'geo' => 'kat', 'ice' => 'isl',
        'mac' => 'mkd', 'may' => 'msa', 'per' => 'fas', 'wel' => 'cym',
        'bur' => 'mya', 'tib' => 'bod',
    ];
    return $map[$lang] ?? substr($lang, 0, 3);
}

/** Display name for the caption row ("English", not "eng"). */
function subtitles_lang_name($code) {
    static $names = [
        'eng' => 'English', 'ben' => 'Bengali', 'hin' => 'Hindi', 'jpn' => 'Japanese',
        'ara' => 'Arabic', 'spa' => 'Spanish', 'fra' => 'French', 'deu' => 'German',
        'por' => 'Portuguese', 'rus' => 'Russian', 'kor' => 'Korean', 'zho' => 'Chinese',
        'ind' => 'Indonesian', 'tam' => 'Tamil', 'tel' => 'Telugu', 'urd' => 'Urdu',
        'tur' => 'Turkish', 'vie' => 'Vietnamese', 'tha' => 'Thai', 'ita' => 'Italian',
        'nld' => 'Dutch', 'pol' => 'Polish', 'mal' => 'Malayalam', 'kan' => 'Kannada',
        'mar' => 'Marathi',
    ];
    $code = subtitles_norm_lang($code);
    return $names[$code] ?? strtoupper($code);
}

// ─── Upstream search ──────────────────────────────────────────────────

/**
 * Raw subtitle rows for a title. Returns [] when the addon has nothing (or
 * cannot be reached) — the caller then simply plays without captions.
 *
 * Candidate shapes are tried most-specific-first: the requested episode, the
 * stream's own premiere numbering (anime rows are routinely filed under
 * season 1 whatever list they came from), then the movie entry.
 */
function subtitles_search($imdb, $season = 0, $episode = 0) {
    $imdb = trim((string)$imdb);
    if (!preg_match('/^tt\d{5,10}$/', $imdb)) return [];

    $season  = max(0, (int)$season);
    $episode = max(0, (int)$episode);
    $base    = subtitles_api_base();

    $paths = [];
    if ($season > 0 && $episode > 0) {
        $paths[] = '/subtitles/series/' . $imdb . ':' . $season . ':' . $episode . '.json';
        if ($season !== 1) $paths[] = '/subtitles/series/' . $imdb . ':1:' . $episode . '.json';
    } elseif ($season > 0) {
        $paths[] = '/subtitles/series/' . $imdb . ':1:1.json';
    }
    $paths[] = '/subtitles/movie/' . $imdb . '.json';

    // One shared budget for the whole walk, not per hop: this runs on the
    // playback path, so the viewer waits at most SUBTITLES_TIMEOUT for a
    // caption that is a bonus anyway (and the next click is cached).
    $deadline = microtime(true) + subtitles_timeout();
    foreach ($paths as $path) {
        $left = (int)floor($deadline - microtime(true));
        if ($left < 1) break;
        $data = api_http($base . $path, [
            'timeout' => min(subtitles_timeout(), $left),
            'label'   => 'subtitles',
        ]);
        $subs = $data['subtitles'] ?? null;
        if (is_array($subs) && $subs) return $subs;
    }
    return [];
}

/**
 * Reduce the raw rows to the few tracks the player should offer:
 * preferred languages first (best encoding, plain over hearing-impaired),
 * and only if those are absent, whatever the title actually has — so a Korean
 * drama still gets captions instead of an empty CC menu.
 */
function subtitles_pick($subs, array $langs) {
    $perLang = 2;
    $max     = subtitles_max();
    $buckets = [];
    $seen    = [];

    foreach ((array)$subs as $s) {
        if (!is_array($s)) continue;
        $url = (string)($s['url'] ?? '');
        if ($url === '' || isset($seen[$url])) continue;
        if (!subtitles_url_ok($url)) continue;
        $lang = subtitles_norm_lang($s['lang'] ?? $s['language'] ?? '');
        if ($lang === '') continue;
        $seen[$url] = true;
        $buckets[$lang][] = $s;
    }
    if (!$buckets) return [];

    // UTF-8 first (no re-encoding), plain captions before HI/forced tracks.
    $rank = function ($s) {
        $enc  = strtoupper((string)($s['SubEncoding'] ?? $s['encoding'] ?? ''));
        $name = strtolower((string)($s['subtitleFileName'] ?? $s['id'] ?? ''));
        $pen  = 0;
        if ($enc !== '' && strpos($enc, 'UTF-8') === false) $pen += 2;
        if (preg_match('/(^|[^a-z])(hi|sdh|cc|forced)([^a-z]|$)/', $name)) $pen += 1;
        return $pen;
    };

    $wanted = [];
    foreach ($langs as $l) {
        if (!empty($buckets[$l])) $wanted[] = $l;
    }
    if (!$wanted) {
        foreach (array_keys($buckets) as $l) {
            $wanted[] = $l;
            if (count($wanted) >= 2) break;
        }
    }

    // One track per preferred language, in order — English *and* Chinese for a
    // C-drama, not two English entries. The second file of the same language
    // is only worth a slot when it is the only language on offer (a broken
    // upload then still leaves something to switch to).
    $perLang = count($wanted) > 1 ? 1 : $perLang;

    $picked = [];
    foreach ($wanted as $lang) {
        if (count($picked) >= $max) break;
        $list = $buckets[$lang];
        usort($list, fn($a, $b) => $rank($a) <=> $rank($b));
        $taken = 0;
        foreach ($list as $s) {
            $picked[] = [
                'url'   => (string)$s['url'],
                'lang'  => $lang,
                'label' => subtitles_lang_name($lang),
            ];
            $taken++;
            // Inner loop only: the outer one decides whether another language
            // still fits inside SUBTITLES_MAX.
            if ($taken >= $perLang || count($picked) >= $max) break;
        }
    }
    return $picked;
}

/**
 * Ready-to-use caption entries for the player queue:
 *   [{url (signed proxy), language, lang, format, default}, …]
 *
 * The first entry is marked `default` so the player shows the English track
 * straight away — the whole point of the feature — and the CC row still lets
 * the viewer switch it off.
 */
function subtitles_for($imdb, $season = 0, $episode = 0) {
    if (!subtitles_enabled()) return [];
    $imdb = trim((string)$imdb);
    if (!preg_match('/^tt\d{5,10}$/', $imdb)) return [];

    $season  = max(0, (int)$season);
    $episode = max(0, (int)$episode);
    $langs   = subtitles_langs();
    $cacheKey = api_cache_key('subtitles', ['v1', $imdb, $season, $episode, implode(',', $langs), subtitles_max()]);

    $hit = api_cache_get($cacheKey);
    if (!is_array($hit) || !array_key_exists('subs', $hit)) {
        // Cache the miss too: on a busy page it stops one API round-trip per
        // episode click for titles OpenSubtitles simply does not have.
        $hit = ['subs' => subtitles_pick(subtitles_search($imdb, $season, $episode), $langs)];
        api_cache_set($cacheKey, 'subtitles', $hit, subtitles_cache_ttl());
    }

    $out = [];
    foreach (($hit['subs'] ?? []) as $row) {
        if (empty($row['url'])) continue;
        $signed = subtitle_sign($row['url']);   // signed fresh — the link expires
        if ($signed === null) continue;
        $out[] = [
            'url'      => $signed,
            'language' => $row['label'] ?? 'English',
            'lang'     => $row['lang'] ?? '',
            'format'   => 'vtt',
            'source'   => 'opensubtitles',
            'default'  => empty($out),
        ];
    }
    return $out;
}

/**
 * Give a payload online captions when its own source shipped none.
 *
 * Only direct (hls/mp4) payloads are touched: we are the ones driving that
 * video element, so the track really lands in the player. An embed payload's
 * captions belong to the foreign player and would never reach ours.
 *
 * @return int  number of captions attached
 */
function subtitles_attach(&$payload, $imdb, $season = 0, $episode = 0) {
    if (!is_array($payload) || empty($payload['ok']) || empty($payload['url'])) return 0;
    if (($payload['mode'] ?? 'hls') === 'embed') return 0;
    if (!empty($payload['subtitles'])) return 0;   // the source has its own

    $subs = subtitles_for($imdb, $season, $episode);
    if (!$subs) return 0;

    $payload['subtitles'] = $subs;
    $payload['subtitle_source'] = 'opensubtitles';

    // stream_build_sources() copies payload.subtitles onto the primary entry,
    // but this runs *after* the queue was built for the TMDB endpoints — keep
    // both paths true by stamping entry 0 directly.
    if (!empty($payload['sources'][0]) && empty($payload['sources'][0]['subtitles'])) {
        $payload['sources'][0]['subtitles'] = $subs;
    }
    return count($subs);
}

// ─── Signed proxy ─────────────────────────────────────────────────────

/**
 * Host gate. Everything these captions live on is the Stremio CDN, so the
 * allowlist is that one domain family — the proxy can never be pointed at an
 * arbitrary URL even with a forged signature.
 */
function subtitles_host_ok($host) {
    $host = strtolower(trim((string)$host));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) return false;
    // subs5.strem.io, subs7.strem.io, … — the whole family.
    if ($host === 'strem.io' || substr($host, -9) === '.strem.io') return true;
    // Bilibili's CC CDN (aisubtitle.hdslb.com), for the platform captions a
    // Chinese extractor hands us. It only answers with a bilibili Referer,
    // which subtitles_referer_for() supplies below.
    return ($host === 'hdslb.com' || substr($host, -10) === '.hdslb.com');
}

/**
 * Referer a caption host insists on, or null when it does not care.
 *
 * Bilibili's subtitle CDN 403s without one (the same gate the 8Stream relay
 * exists for) — the proxy is the only place that can add it, because the
 * browser must never be the one talking to that CDN.
 */
function subtitles_referer_for($url) {
    $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
    if ($host === 'hdslb.com' || substr($host, -10) === '.hdslb.com') return 'https://www.bilibili.com/';
    return null;
}

function subtitles_url_ok($url) {
    $p = @parse_url((string)$url);
    if (!is_array($p)) return false;
    if (($p['scheme'] ?? '') !== 'https') return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (isset($p['port']) && (int)$p['port'] !== 443) return false;
    return subtitles_host_ok($p['host'] ?? '');
}

function subtitles_b64($s) {
    return rtrim(strtr(base64_encode((string)$s), '+/', '-_'), '=');
}

function subtitles_unb64($s) {
    $b64 = strtr((string)$s, '-_', '+/');
    if (strlen($b64) % 4) $b64 .= str_repeat('=', 4 - strlen($b64) % 4);
    $out = base64_decode($b64, true);
    return ($out === false || $out === '') ? null : $out;
}

/** Signing secret — override with SUBTITLES_PROXY_KEY in config.local.php. */
function subtitle_proxy_key() {
    if (defined('SUBTITLES_PROXY_KEY') && (string)SUBTITLES_PROXY_KEY !== '') {
        return (string)SUBTITLES_PROXY_KEY;
    }
    // Fallback keeps the endpoint working without config.local.php; the host
    // gate above is the real protection.
    $parts = ['kp-subtitles'];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $c) {
        $parts[] = defined($c) ? (string)constant($c) : '';
    }
    return hash('sha256', implode('|', $parts));
}

/** Endpoint path, derived from the calling script (all callers live in includes/). */
function subtitles_proxy_endpoint() {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = $script !== '' ? rtrim(strtr(dirname($script), '\\', '/'), '/') : '';
    if ($dir === '.' || $dir === '') $dir = '';
    return $dir . '/subtitle_proxy.php';
}

/**
 * Sign an allowlisted subtitle URL into a proxy URL.
 *
 * $format tells the relay how to convert the upstream body (`bilijson` for
 * Bilibili's CC JSON). It is part of the HMAC, so it cannot be flipped by
 * editing the link; an empty format keeps the old signature shape, which is
 * what links already handed to a browser in the last few hours carry.
 */
function subtitle_sign($url, $format = '') {
    if (!subtitles_url_ok($url)) return null;
    $u = subtitles_b64($url);
    $e = time() + subtitles_relay_ttl();
    $f = preg_replace('/[^a-z0-9]/', '', strtolower((string)$format));
    $s = substr(hash_hmac('sha256', $u . '|' . $e . ($f !== '' ? '|' . $f : ''), subtitle_proxy_key()), 0, 32);
    return subtitles_proxy_endpoint() . '?u=' . $u . '&e=' . $e . '&s=' . $s . ($f !== '' ? '&f=' . $f : '');
}

/**
 * Verify a signed proxy URL back to the upstream URL.
 *
 * @return array|null  ['url' => …,'format' => …] — null when anything is off
 */
function subtitle_unsign($u, $e, $s, $f = '') {
    if ($u === '' || $e === '' || $s === '') return null;
    if (!preg_match('/^\d{1,20}$/', (string)$e)) return null;

    $exp = (int)$e;
    if ($exp < time() || $exp > time() + subtitles_relay_ttl() + 60) return null;

    $f    = preg_replace('/[^a-z0-9]/', '', strtolower((string)$f));
    $want = substr(hash_hmac('sha256', (string)$u . '|' . (string)$e . ($f !== '' ? '|' . $f : ''), subtitle_proxy_key()), 0, 32);
    if (!hash_equals($want, (string)$s)) return null;

    $url = subtitles_unb64($u);
    if ($url === null || !subtitles_url_ok($url)) return null;
    return ['url' => $url, 'format' => $f];
}

// ─── SRT → WebVTT ─────────────────────────────────────────────────────

/**
 * Caption files are user uploads, so the bytes are whatever the uploader had.
 * The addon's own URL claims UTF-8 ("subencoding-stremio-utf8"), and usually
 * is — but CJK tracks still arrive as GB18030/GBK/Big5/Shift-JIS often enough
 * that a C-drama's Chinese track can be nothing but mojibake without this.
 *
 * Order: a byte-order mark settles it, then UTF-8 validity (the cheap, exact
 * test), then mb_detect_encoding over the CJK suspects. A file that is already
 * clean UTF-8 is returned untouched.
 */
function subtitles_to_utf8($text) {
    $text = (string)$text;
    if ($text === '') return '';

    if (substr($text, 0, 3) === "\xEF\xBB\xBF") return substr($text, 3);
    $bom = substr($text, 0, 2);
    if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
        // Big-endian/little-endian explicitly: mb_convert_encoding('UTF-16')
        // trusts the BOM only intermittently and can hand the bytes straight
        // back, which would post NULs to the browser.
        $from = ($bom === "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
        $out = @mb_convert_encoding(substr($text, 2), 'UTF-8', $from);
        return (is_string($out) && $out !== '') ? $out : $text;
    }

    // No BOM, but NUL bytes near the start: BOM-less UTF-16 (mbstring's own
    // 'UTF-16' output, and what some Windows editors write). A NUL byte cannot
    // occur in a UTF-8 caption file, so this cannot misfire. Byte 0 being NUL
    // means big-endian — an ASCII caption starts with '1'.
    if (strpos(substr($text, 0, 64), "\x00") !== false) {
        $from = (substr($text, 0, 1) === "\x00") ? 'UTF-16BE' : 'UTF-16LE';
        $out = @mb_convert_encoding($text, 'UTF-8', $from);
        if (is_string($out) && $out !== '' && @preg_match('//u', $out) === 1) return $out;
    }

    // `//u` is a UTF-8 validity check, not a search.
    if (@preg_match('//u', $text) === 1) return $text;

    if (function_exists('mb_detect_encoding')) {
        $enc = @mb_detect_encoding($text, ['GB18030', 'BIG-5', 'SJIS', 'EUC-KR', 'Windows-1252', 'ISO-8859-1'], true);
        if (is_string($enc) && $enc !== '') {
            $out = @mb_convert_encoding($text, 'UTF-8', $enc);
            if (is_string($out) && $out !== '') return $out;
        }
    }
    return $text;
}

/**
 * The addon hands out SRT (via `…/en/download/subencoding-stremio-utf8/…`).
 * ArtPlayer can read either, but WebVTT has no "comma vs dot" ambiguity, so
 * the conversion happens once here instead of in every browser.
 */
/** 3.5 → "00:00:03.500" for a WebVTT cue line. */
function subtitles_vtt_time($seconds) {
    $seconds = max(0.0, (float)$seconds);
    $h = (int)floor($seconds / 3600);
    $m = (int)floor(($seconds - $h * 3600) / 60);
    $s = $seconds - $h * 3600 - $m * 60;
    return sprintf('%02d:%02d:%06.3f', $h, $m, $s);
}

/**
 * Bilibili's CC tracks are JSON, not a subtitle file:
 *   {"body":[{"from":1.23,"to":4.5,"content":"…"}, …]}
 * (a bare array of those objects also shows up). Converted here so the browser
 * only ever receives WebVTT.
 */
function subtitles_json_to_vtt($text) {
    $data = json_decode((string)$text, true);
    if (!is_array($data)) return '';

    $lines = $data['body'] ?? $data['subtitles'] ?? $data;
    if (!is_array($lines)) return '';

    $out = [];
    $n   = 0;
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        $from = $line['from'] ?? $line['start'] ?? $line['startTime'] ?? null;
        $to   = $line['to']   ?? $line['end']   ?? $line['endTime']   ?? null;
        $txt  = trim((string)($line['content'] ?? $line['text'] ?? ''));
        if ($from === null || $to === null || $txt === '') continue;
        $n++;
        $out[] = $n . "\n" . subtitles_vtt_time($from) . ' --> ' . subtitles_vtt_time($to) . "\n" . $txt;
    }
    return $out ? ("WEBVTT\n\n" . implode("\n\n", $out) . "\n") : '';
}

function subtitles_to_vtt($text) {
    $text = subtitles_to_utf8($text);
    if ($text === '') return '';

    // A stray BOM (leading, or repeated by the encoder) renders as a junk
    // glyph inside the first cue, and CRLF breaks cue parsing — both go first.
    $text = str_replace(["\xEF\xBB\xBF", "\r\n", "\r"], ['', "\n", "\n"], $text);

    if (stripos(ltrim($text), 'WEBVTT') === 0) return $text;   // already VTT

    // 00:00:01,000 --> 00:00:04,000  (also the rare MM:SS,mmm form)
    $text = preg_replace('/(\d{1,2}:\d{2}:\d{2}),(\d{1,3})/', '$1.$2', $text);
    $text = preg_replace('/(?<!\d)(\d{1,2}:\d{2}),(\d{1,3})(?=\s*-->)/', '00:$1.$2', $text);

    return "WEBVTT\n\n" . ltrim($text);
}
