<?php
/**
 * Generic media relay (CORS-dead provider CDNs).
 *
 *   GET ./includes/media_relay.php?p=…&e=…&s=…      (signed by the resolver)
 *
 * Streams one object from an allowlisted media host — following 302 hops
 * manually so every landing spot re-passes the host gate (VidZen answers
 * its /api/stream/… URLs with redirects onto *.workers.dev, none of which
 * send CORS headers, so the browser can never fetch them directly).
 *
 * Playlists (detected by Content-Type — these CDNs serve m3u8 bodies on
 * extensionless URLs) are rewritten so every child reference comes back
 * through this endpoint too; everything else streams through untouched
 * (Range included, so seeking works).
 *
 * Not an open proxy: p/e/s are HMAC-verified (mr_relay_unsign), every hop
 * must sit on the mr_media_* host allowlist, and the signature covers
 * expiry (6h), so links do not live forever.
 */
include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/media_relay_lib.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
header('X-Content-Type-Options: nosniff');

$target = mr_relay_unsign(
    (string)($_GET['p'] ?? ''),
    (string)($_GET['e'] ?? ''),
    (string)($_GET['s'] ?? '')
);
if ($target === null) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'forbidden';
    exit;
}

$isHead = ($method === 'HEAD');
$range  = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && !preg_match('/^bytes=\d*-\d*$/', $range)) $range = '';

$up = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept: */*',
    'Accept-Encoding: identity',               // media must stay byte-identical
];
if ($range !== '') $up[] = 'Range: ' . $range;

// dlproxy's AES key endpoint only redeems keys for its own player's origin
// (403 "origin not allowed" otherwise) — same idea as MegaPlay's referer
// gate: the relay speaks to the upstream as the player would.
if (strtolower((string)(parse_url($target, PHP_URL_HOST) ?: '')) === 'api.dlproxy.com') {
    $up[] = 'Origin: https://movish.to';
    $up[] = 'Referer: https://movish.to/';
}

$status    = 0;
$location  = '';
$ctype     = '';
$captured  = [];        // passthrough headers of the final 2xx response
$emitted   = false;     // response headers already sent to the browser
$buffer    = '';
$isList    = null;      // decided from the final response's Content-Type
$emit      = function () use (&$emitted, &$status, &$captured) {
    if ($emitted) return;
    $emitted = true;
    http_response_code($status ?: 200);
    foreach ($captured as $h) header($h);
    header('X-Content-Type-Options: nosniff');
};

// Follow redirects by hand — each hop must re-pass the host gate, so a
// provider can never bounce the relay onto an unrelated server.
for ($hop = 0; $hop < 5; $hop++) {
    $status   = 0;
    $location = '';
    $ctype    = '';
    $captured = [];

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $target,
        CURLOPT_HTTPHEADER     => $up,
        CURLOPT_FOLLOWLOCATION => false,        // validated by the loop above
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$location, &$ctype, &$captured) {
            $len = strlen($line);
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
                $status   = (int)$m[1];
                $location = '';
                $ctype    = '';
                $captured = [];
                return $len;
            }
            if (trim($line) === '') return $len;
            if (preg_match('#^location\s*:\s*(.+?)\s*$#i', $line, $m)) {
                $location = $m[1];
                return $len;
            }
            if (preg_match('#^content-type\s*:\s*(.+?)\s*$#i', $line, $m)) {
                $ctype = strtolower($m[1]);
                return $len;
            }
            // Playlists get rewritten below, so their length/type/validators
            // are ours to set — cache hints and content-type pass through
            // (the playlist branch overwrites type after the copy). Media is
            // untouched: forward the validators so the browser can cache.
            if (preg_match('#^(content-type|content-length|content-range|accept-ranges|cache-control|last-modified|etag)\s*:#i', $line)) {
                $captured[] = trim($line);
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$status, &$ctype, &$isList, &$emit) {
            if ($status < 200 || $status >= 300) return strlen($data);   // redirect/error bodies: swallow
            if ($isList === null) {
                $isList = (bool)preg_match('#mpegurl|^application/x-mpegurl#i', $ctype);
            }
            if ($isList) { $buffer .= $data; return strlen($data); }
            $emit();
            echo $data;
            return strlen($data);
        },
    ]);
    if ($isHead) curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    curl_close($ch);

    if ($status >= 300 && $status < 400 && $location !== '') {
        $next = mp_url_resolve($target, $location);
        if ($next === null || !mr_media_url_ok($next)) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'redirect off-allowlist';
            exit;
        }
        $target = $next;
        continue;
    }
    break;   // 2xx (or a terminal error)
}

if ($status < 200 || $status >= 300) {
    if ($emitted) exit;                       // media error already swallowed
    $code = ($status >= 300 && $status < 400) ? 502 : ($status ?: 502);
    http_response_code($code);
    header('Content-Type: text/plain');
    echo 'upstream ' . ($status ?: 'error');
    exit;
}

if ($isList !== true) {
    if (!$emitted) $emit();                   // empty 2xx body / HEAD headers
    exit;
}

// Playlist: rewrite every child URL back through this endpoint.
$rewritten = mr_relay_rewrite($buffer, $target);
http_response_code($status);
foreach ($captured as $h) header($h);
header('Content-Type: application/vnd.apple.mpegurl');
header('Content-Length: ' . strlen($rewritten));
echo $rewritten;

/**
 * Re-point every URI line and URI="…" attribute of an HLS playlist at this
 * endpoint. Children that are not on the media allowlist stay untouched —
 * the browser simply cannot fetch them, which fails that one track instead
 * of leaking the relay as a general proxy.
 */
function mr_relay_rewrite(string $m3u8, string $baseUrl): string {
    $lines = preg_split('/\r\n|\n|\r/', $m3u8);
    foreach ($lines as $i => $line) {
        if ($line === '') continue;
        if ($line[0] === '#') {
            if (stripos($line, 'URI="') === false) continue;
            $lines[$i] = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($baseUrl) {
                $abs = mp_url_resolve($baseUrl, $m[1]);
                if ($abs === null) return $m[0];
                $signed = mr_relay_sign($abs);
                return $signed !== null ? 'URI="' . $signed . '"' : $m[0];
            }, $line);
            continue;
        }
        $abs = mp_url_resolve($baseUrl, $line);
        if ($abs === null) continue;
        $signed = mr_relay_sign($abs);
        if ($signed !== null) $lines[$i] = $signed;
    }
    return implode("\r\n", $lines);
}
