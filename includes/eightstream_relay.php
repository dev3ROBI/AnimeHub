<?php
/**
 * 8Stream media relay.
 *
 *   GET ./includes/eightstream_relay.php?p=…&r=…&e=…&s=…   (signed by the resolver)
 *
 * Streams one object from 8Stream's rotating media CDN with the provider
 * player's own `Referer`/`Origin` — headers a browser on our origin can never
 * send, and the URL token is bound to the requesting IP (which, server-side,
 * is us). Playlists (.m3u8) are rewritten so every child reference (variants,
 * segments, EXT-X-KEY/EXT-X-MAP URIs) comes back through this endpoint too;
 * everything else streams through untouched (Range included, so seeking works).
 *
 * Not an open proxy: p/r/e/s are HMAC-verified (es_relay_unsign), the decoded
 * target must sit on the public + media-path gate (es_cdn_url_ok), and each
 * redirect hop is re-checked the same way.
 */
include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/eightstream_relay_lib.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
header('X-Content-Type-Options: nosniff');

$signed = es_relay_unsign(
    (string)($_GET['p'] ?? ''),
    (string)($_GET['r'] ?? ''),
    (string)($_GET['e'] ?? ''),
    (string)($_GET['s'] ?? '')
);
if ($signed === null) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'forbidden';
    exit;
}

$target  = $signed['url'];
$referer = rtrim($signed['referer'], '/');
$isHead  = ($method === 'HEAD');

$isPlaylist = (bool)preg_match('#\.m3u8(?:$|\?)#i', (string)(parse_url($target, PHP_URL_PATH) ?: ''));
if ($isHead) $isPlaylist = false;   // HEAD has no body to rewrite — headers only

$up = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Referer: ' . $referer . '/',
    'Origin: ' . $referer,
    'Accept: */*',
];
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=\d*-\d*$/', $range)) {
    $up[] = 'Range: ' . $range;
}
if (!$isPlaylist) {
    // Media must stay byte-identical for Range/Content-Length passthrough.
    $up[] = 'Accept-Encoding: identity';
}

$status    = 0;
$location  = '';
$captured  = [];
$emitted   = false;
$buffer    = '';
$isList    = null;   // decided from the final response's Content-Type

$emit = function () use (&$emitted, &$status, &$captured) {
    if ($emitted) return;
    $emitted = true;
    http_response_code($status ?: 200);
    foreach ($captured as $h) header($h);
    header('X-Content-Type-Options: nosniff');
};

// Follow redirects by hand — every hop must re-pass the media gate, so the CDN
// can never bounce the relay onto an unrelated (or internal) host.
for ($hop = 0; $hop < 5; $hop++) {
    $status = 0; $location = ''; $captured = []; $isList = null;

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $target,
        CURLOPT_HTTPHEADER     => $up,
        CURLOPT_FOLLOWLOCATION => false,      // validated by this loop
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$location, &$captured, $isPlaylist) {
            $len = strlen($line);
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1]; $location = ''; $captured = [];
                return $len;
            }
            if (trim($line) === '') return $len;
            if (preg_match('#^location\s*:\s*(.+?)\s*$#i', $line, $m)) { $location = $m[1]; return $len; }
            // Playlists get rewritten below, so their length/type/validators are
            // ours to set — only pass cache hints through. Media is untouched.
            if ($isPlaylist) {
                if (preg_match('#^cache-control\s*:#i', $line)) $captured[] = trim($line);
                return $len;
            }
            if (preg_match('#^(content-type|content-length|content-range|accept-ranges|content-encoding|cache-control|last-modified|etag)\s*:#i', $line)) {
                $captured[] = trim($line);
            }
            return $len;
        },
        // The CDN serves its playlists as text/html, so "is this a playlist?"
        // is decided from the body (#EXTM3U) — a .m3u8 target is buffered whole
        // and the rest streams straight through.
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$status, &$emit, $isPlaylist) {
            if ($status < 200 || $status >= 300) return strlen($data);   // error bodies: swallow
            if ($isPlaylist) { $buffer .= $data; return strlen($data); }
            $emit();
            echo $data;
            return strlen($data);
        },
    ]);
    if ($isPlaylist) curl_setopt($ch, CURLOPT_ENCODING, '');   // decode before rewriting
    if ($isHead)    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    curl_close($ch);

    if ($status >= 300 && $status < 400 && $location !== '') {
        $next = mp_url_resolve($target, $location);
        if ($next === null || !es_cdn_url_ok($next)) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'redirect off-allowlist';
            exit;
        }
        $target = $next;
        continue;
    }
    break;
}

if ($status < 200 || $status >= 300) {
    if ($emitted) exit;
    $code = ($status >= 300 && $status < 400) ? 502 : ($status ?: 502);
    http_response_code($code);
    header('Content-Type: text/plain');
    echo 'upstream ' . ($status ?: 'error');
    exit;
}

// A .m3u8 target was buffered; treat it as a playlist only when its body
// really is one, otherwise fail (never leak a non-manifest body as a playlist).
$isList = $isPlaylist && str_starts_with(ltrim($buffer), '#EXTM3U');
if ($isPlaylist && !$isList) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'upstream not-a-playlist';
    exit;
}

if ($isList !== true) {
    if (!$emitted) $emit();
    exit;
}

$rewritten = es_relay_rewrite($buffer, $target, $referer);
http_response_code($status);
foreach ($captured as $h) header($h);
header('Content-Type: application/vnd.apple.mpegurl');
header('Content-Length: ' . strlen($rewritten));
echo $rewritten;

/**
 * Re-point every URI line and URI="…" attribute of an HLS playlist at this
 * endpoint (carrying the same referer). References that fail the media gate
 * stay untouched — the browser simply cannot fetch them, which fails that one
 * track instead of leaking the relay as a general proxy.
 */
function es_relay_rewrite(string $m3u8, string $baseUrl, string $referer): string {
    $lines = preg_split('/\r\n|\n|\r/', $m3u8);
    foreach ($lines as $i => $line) {
        if ($line === '') continue;
        if ($line[0] === '#') {
            if (stripos($line, 'URI="') === false) continue;
            $lines[$i] = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($baseUrl, $referer) {
                $abs = mp_url_resolve($baseUrl, $m[1]);
                if ($abs === null) return $m[0];
                $signed = es_relay_sign($abs, $referer);
                return $signed !== null ? 'URI="' . $signed . '"' : $m[0];
            }, $line);
            continue;
        }
        $abs = mp_url_resolve($baseUrl, $line);
        if ($abs === null) continue;
        $signed = es_relay_sign($abs, $referer);
        if ($signed !== null) $lines[$i] = $signed;
    }
    return implode("\r\n", $lines);
}
