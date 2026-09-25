<?php
/**
 * MegaPlay CDN relay.
 *
 *   GET ./includes/megaplay_relay.php?p=…&e=…&s=…     (signed by the resolver)
 *
 * Streams one object from MegaPlay's media CDN with the `Referer:
 * https://megaplay.buzz/` the CDN demands — a browser on this origin can
 * never send that header itself. Playlists (.m3u8) are rewritten so every
 * child reference (variants, segments, EXT-X-KEY/EXT-X-MAP URIs) comes back
 * through this endpoint too; everything else is streamed through untouched
 * (Range included, so seeking works).
 *
 * Not an open proxy: p/e/s are HMAC-verified (mp_relay_unsign) and the
 * decoded target must sit on the fixed CDN host + path allowlist
 * (mp_cdn_*). Signature covers expiry (12h), so links do not live forever.
 */
include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/megaplay_relay_lib.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
header('X-Content-Type-Options: nosniff');

$target = mp_relay_unsign(
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

$isHead     = ($method === 'HEAD');
$isPlaylist = (bool)preg_match('#\.m3u8(?:$|\?)#i', (string)(parse_url($target, PHP_URL_PATH) ?: ''));

$up = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Referer: https://megaplay.buzz/',
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

$status  = 0;
$headers = [];
$started = false;
$buffer  = '';

$emit = function () use (&$started, &$status, &$headers) {
    if ($started) return;
    $started = true;
    http_response_code($status ?: 200);
    foreach ($headers as $h) header($h);
    header('X-Content-Type-Options: nosniff');
};

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_URL            => $target,
    CURLOPT_HTTPHEADER     => $up,
    CURLOPT_FOLLOWLOCATION => false,          // a redirect is an error here: never chase off-allowlist
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$headers, $isPlaylist) {
        $len = strlen($line);
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            $headers = [];
            return $len;
        }
        if (trim($line) === '') return $len;
        // Playlists get rewritten below, so their length/type/validators are
        // ours to set — only pass cache hints through. Media is untouched:
        // forward the validators so the browser can cache and resume.
        if ($isPlaylist) {
            if (preg_match('#^cache-control\s*:#i', $line)) $headers[] = trim($line);
            return $len;
        }
        if (preg_match('#^(content-type|content-length|content-range|accept-ranges|content-encoding|cache-control|last-modified|etag)\s*:#i', $line)) {
            $headers[] = trim($line);
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$status, $isPlaylist, &$emit) {
        if ($isPlaylist) { $buffer .= $data; return strlen($data); }
        if ($status >= 200 && $status < 300) {
            $emit();
            echo $data;
        }
        return strlen($data);                  // swallow error bodies (HTML 403 pages etc.)
    },
]);
if ($isPlaylist) {
    curl_setopt($ch, CURLOPT_ENCODING, '');    // decode compressed playlists before rewriting
}
if ($isHead) {
    curl_setopt($ch, CURLOPT_NOBODY, true);
}
curl_exec($ch);
curl_close($ch);

if ($status < 200 || $status >= 300) {
    if ($started) exit;                        // media error already swallowed; nothing sent
    $code = ($status >= 300 && $status < 400) ? 502 : ($status ?: 502);
    http_response_code($code);
    header('Content-Type: text/plain');
    echo 'upstream ' . ($status ?: 'error');
    exit;
}

if (!$isPlaylist) {
    if (!$started) $emit();                    // empty 2xx body
    exit;
}

// Playlist: rewrite every child URL back through this endpoint.
$rewritten = mp_relay_rewrite($buffer, $target);
http_response_code($status);
foreach ($headers as $h) header($h);
header('Content-Type: application/vnd.apple.mpegurl');
header('Content-Length: ' . strlen($rewritten));
echo $rewritten;

/**
 * Re-point every URI line and URI="…" attribute of an HLS playlist at this
 * endpoint. References that are not on the CDN allowlist stay untouched —
 * the browser simply cannot fetch them, which fails that one track instead
 * of leaking the relay as a general proxy.
 */
function mp_relay_rewrite(string $m3u8, string $baseUrl): string {
    $lines = preg_split('/\r\n|\n|\r/', $m3u8);
    foreach ($lines as $i => $line) {
        if ($line === '') continue;
        if ($line[0] === '#') {
            if (stripos($line, 'URI="') === false) continue;
            $lines[$i] = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($baseUrl) {
                $abs = mp_url_resolve($baseUrl, $m[1]);
                if ($abs === null) return $m[0];
                $signed = mp_relay_sign($abs);
                return $signed !== null ? 'URI="' . $signed . '"' : $m[0];
            }, $line);
            continue;
        }
        $abs = mp_url_resolve($baseUrl, $line);
        if ($abs === null) continue;
        $signed = mp_relay_sign($abs);
        if ($signed !== null) $lines[$i] = $signed;
    }
    return implode("\r\n", $lines);
}
