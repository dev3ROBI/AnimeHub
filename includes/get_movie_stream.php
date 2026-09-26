<?php
/**
 * AJAX endpoint: resolve movie embed URL.
 * GET ?id=tmdb:movie:550  or  GET ?tmdb=550
 */
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/tmdb_movie_api.php';
include_once __DIR__ . '/embed_movie.php';
include_once __DIR__ . '/eightstream_api.php';
include_once __DIR__ . '/cn_extract_api.php';
include_once __DIR__ . '/subtitles_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$tmdbId = 0;

if (isset($_GET['id'])) {
    $raw = trim($_GET['id']);
    if (preg_match('/^tmdb:movie:(\d+)$/', $raw, $m)) {
        $tmdbId = (int)$m[1];
    }
} elseif (isset($_GET['tmdb'])) {
    $tmdbId = (int)$_GET['tmdb'];
}

if ($tmdbId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid movie ID']);
    exit;
}

// Get movie detail for metadata (also carries the IMDb id 8Stream needs)
$detail = tmdb_movie_detail($tmdbId);

// Resolve embed
$embed = movie_embed_resolve($tmdbId, $detail['imdb_id'] ?? '');
$allServers = movie_embed_all($tmdbId, $detail['imdb_id'] ?? '');

/*
 * Custom-player priority: 8Stream first (a direct HLS the ArtPlayer runs
 * through includes/eightstream_relay.php), the external embeds behind it as
 * the "More servers" fallback. Nothing changes when 8Stream is off, has no
 * IMDb id, or misses the title — the embed chain is untouched.
 */
$eight = null;
$imdb = trim((string)($detail['imdb_id'] ?? ''));
if (EIGHTSTREAM_ENABLED && $imdb !== '') {
    $eight = eightstream_source_entry($imdb, 0, 0, 'sub');
}

/*
 * Chinese platforms second: a keyed extractor (includes/cn_extract_api.php)
 * returns a playurl for our own player along with the platform's own CC track.
 * Inert until CNEXTRACT_KEY is set (see config.php).
 */
$cn = null;
if (function_exists('cnx_enabled') && cnx_enabled()) {
    $cnTitle = (string)($detail['title'] ?? '');
    $cnYear  = (int)($detail['year'] ?? 0);
    if ($cnYear <= 0 && !empty($detail['release_date'])) {
        $cnYear = (int)substr((string)$detail['release_date'], 0, 4);
    }
    $cn = cnx_source_entry($cnTitle, $cnYear);
}

$sources = [];
if ($eight) $sources[] = $eight;
if ($cn && !empty($cn['url'])) $sources[] = $cn;
foreach ($allServers as $srv) {
    if (empty($srv['url'])) continue;
    $sources[] = [
        'key'       => $srv['key'] ?? null,
        'label'     => $srv['label'] ?? null,
        'type'      => 'embed',
        'mode'      => 'embed',
        'url'       => $srv['url'],
        'provider'  => 'embed',
        'lang'      => 'any',
        'dataLink'  => null,
        'headers'   => null,
        'expires_at'=> null,
        'subtitles' => [],
        'intro'     => null,
        'outro'     => null,
    ];
}

// Whichever custom source took the top slot is what the player starts on.
$primary = ($eight && !empty($eight['url'])) ? $eight : (($cn && !empty($cn['url'])) ? $cn : null);

$response = [
    'ok'         => true,
    'mode'       => $primary ? 'hls' : 'embed',
    'url'        => $primary ? $primary['url'] : ($embed['url'] ?? ''),
    'server_key' => $primary ? $primary['key'] : ($embed['key'] ?? null),
    'lang'       => 'any',
    'sources'    => $sources,
    'subtitles'  => [],
    'tmdb_id'    => $tmdbId,
    'detail'     => $detail,
    'embed'      => $embed,
    'servers'    => $allServers,
];

/*
 * Captions fallback: when the direct source (the one our own player runs) has
 * no caption track of its own, English subtitles are looked up online and
 * signed onto it. An embed-only queue is left untouched — those captions
 * belong to the foreign player and would never reach ours.
 */
subtitles_attach($response, $imdb, 0, 0);

echo json_encode($response);
