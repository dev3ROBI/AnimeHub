<?php
/**
 * Signed subtitle relay.
 *
 *   GET ./includes/subtitle_proxy.php?u=…&e=…&s=…   (signed by subtitles_api.php)
 *
 * Fetches one caption file from the Stremio subtitle CDN — the only host
 * family subtitle_sign() will sign — converts SRT → WebVTT and serves it.
 *
 * Why a relay at all: that CDN sends no `Access-Control-Allow-Origin`, so the
 * player's own fetch from our origin is blocked by the browser. Routing it
 * through our origin also keeps the upstream URL out of the page, which means
 * the link can simply be re-signed when it goes stale.
 *
 * Not an open proxy: u/e/s are HMAC-verified, the decoded target must pass the
 * https + strem.io gate, and a redirect is only followed if its *effective*
 * URL still passes the same gate.
 */
include_once __DIR__ . '/subtitles_api.php';

$signed = subtitle_unsign(
    (string)($_GET['u'] ?? ''),
    (string)($_GET['e'] ?? ''),
    (string)($_GET['s'] ?? ''),
    (string)($_GET['f'] ?? '')
);
if ($signed === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$target = $signed['url'];
$format = $signed['format'];

// Some caption hosts only answer with a Referer (Bilibili's CC CDN); the
// browser can never send one for them, which is half of why this relay exists.
$referer = subtitles_referer_for($target);
$headers = [
    'Accept: */*',
    'Accept-Language: en-US,en;q=0.9',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
];
if ($referer !== null) {
    $headers[] = 'Referer: ' . $referer;
    $headers[] = 'Origin: ' . rtrim($referer, '/');
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => subtitles_timeout(),
    CURLOPT_CONNECTTIMEOUT => min(6, subtitles_timeout()),
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',   // upstream may gzip/br the body
    CURLOPT_HTTPHEADER     => $headers,
]);

$body   = curl_exec($ch);
$code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$final  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$error  = curl_error($ch);
curl_close($ch);

if ($error !== '' || $body === false || $body === '' || $code < 200 || $code >= 300
    || !subtitles_url_ok($final)) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unavailable';
    exit;
}

// A `f=bilijson` link carries Bilibili's CC JSON, everything else is an SRT
// or VTT file. A JSON body that will not convert is reported as unavailable
// rather than wrapped into a junk WebVTT.
if ($format === 'bilijson') {
    $vtt = subtitles_json_to_vtt($body);
} else {
    $vtt = subtitles_to_vtt($body);
}
if ($vtt === '') {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'empty';
    exit;
}

header('Content-Type: text/vtt; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
// A caption file for one title never changes: let the browser keep it and
// drop the repeat fetch entirely.
header('Cache-Control: public, max-age=86400');
echo $vtt;
