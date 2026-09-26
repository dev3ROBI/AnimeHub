<?php
/**
 * AJAX endpoint: resolve one 8Stream **audio language** to a relay URL.
 *
 *   GET ./includes/get_eightstream_audio.php?imdb=tt1877830&lang=Hindi
 *   GET ./includes/get_eightstream_audio.php?imdb=tt11737520&season=1&ep=2&lang=English
 *
 * 8Stream carries each language as its own HLS stream, so the player's Audio
 * row asks for the picked one on demand instead of paying for every language
 * during the first resolve.
 *
 * Response:
 *   { ok:true,  url:"…relay…", label:"Hindi", type:"hls" }
 *   { ok:false, error:"auth|bad-request|unresolved" }
 */
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/eightstream_api.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

if (!eightstream_enabled()) {
    echo json_encode(['ok' => false, 'error' => 'disabled']);
    exit;
}

$imdb   = trim((string)($_GET['imdb'] ?? ''));
$season = max(0, (int)($_GET['season'] ?? 0));
$ep     = max(0, (int)($_GET['ep'] ?? 0));
$lang   = trim((string)($_GET['lang'] ?? ''));

if (!preg_match('/^tt\d{5,10}$/', $imdb) || $lang === '' || strlen($lang) > 40) {
    echo json_encode(['ok' => false, 'error' => 'bad-request']);
    exit;
}

$res = eightstream_resolve($imdb, $season, $ep, $lang);
if (empty($res['ok']) || empty($res['url'])) {
    echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'unresolved']);
    exit;
}

echo json_encode([
    'ok'    => true,
    'url'   => $res['url'],
    'label' => $res['lang'] ?? $lang,
    'type'  => 'hls',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
