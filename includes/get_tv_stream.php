<?php
/**
 * AJAX endpoint: resolve TV embed URL.
 * GET ?id=tmdb:tv:1399&season=1&ep=5  or  GET ?tmdb=1399&season=1&episode=5
 */
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/tmdb_movie_api.php';
include_once __DIR__ . '/embed_tv.php';
include_once __DIR__ . '/eightstream_api.php';
include_once __DIR__ . '/cn_extract_api.php';
include_once __DIR__ . '/flixhq_api.php';
include_once __DIR__ . '/subtitles_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$tmdbId = 0;
$season = 1;
$episode = 1;

if (isset($_GET['id'])) {
    $raw = trim($_GET['id']);
    if (preg_match('/^tmdb:tv:(\d+)$/', $raw, $m)) {
        $tmdbId = (int)$m[1];
    }
} elseif (isset($_GET['tmdb'])) {
    $tmdbId = (int)$_GET['tmdb'];
}

$season  = max(1, (int)($_GET['season'] ?? 1));
$episode = max(1, (int)($_GET['episode'] ?? $_GET['ep'] ?? 1));

if ($tmdbId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid TV ID']);
    exit;
}

$detail = tmdb_tv_detail($tmdbId);
$embed = tv_embed_resolve($tmdbId, $season, $episode, $detail['imdb_id'] ?? '');
$allServers = tv_embed_all($tmdbId, $season, $episode, $detail['imdb_id'] ?? '');

// Get season episode list
$seasonData = tmdb_tv_season($tmdbId, $season);

/*
 * Custom-player priority (same shape as the movie endpoint): 8Stream first
 * as a direct HLS for our own player, the external embeds behind it under
 * "More servers". A miss leaves the embed chain exactly as it was.
 */
$eight = null;
$imdb = trim((string)($detail['imdb_id'] ?? ''));
if (EIGHTSTREAM_ENABLED && $imdb !== '') {
    $eight = eightstream_source_entry($imdb, $season, $episode, 'sub');
}

/*
 * Chinese platforms second: a keyed extractor (includes/cn_extract_api.php)
 * hands back a playurl for our own player *plus* the platform's own CC track,
 * which is the one caption source a C-drama actually has. Inert until
 * CNEXTRACT_KEY is set, so this changes nothing by default.
 */
$cn = null;
if (function_exists('cnx_enabled') && cnx_enabled()) {
    $cnTitle = (string)($detail['title'] ?? $detail['name'] ?? '');
    $cnYear  = (int)($detail['year'] ?? 0);
    if ($cnYear <= 0 && !empty($detail['first_air_date'])) {
        $cnYear = (int)substr((string)$detail['first_air_date'], 0, 4);
    }
    $cn = cnx_source_entry($cnTitle, $cnYear);
}

$sources = [];
if ($eight) $sources[] = $eight;
if ($cn && !empty($cn['url'])) $sources[] = $cn;

/*
 * FlixHQ third (same as the movie endpoint): direct HLS for the requested
 * season/episode with the site's caption tracks — real chips for the
 * custom player, tagged [English].
 */
$flix = [];
if (flixhq_enabled()) {
    $flixTitle = (string)($detail['title'] ?? $detail['name'] ?? '');
    $flixYear  = (int)($detail['year'] ?? 0);
    if ($flixYear <= 0 && !empty($detail['first_air_date'])) {
        $flixYear = (int)substr((string)$detail['first_air_date'], 0, 4);
    }
    $flix = flixhq_source_entries($flixTitle, $flixYear, 'tv', $season, $episode);
}
foreach ($flix as $fe) $sources[] = $fe;

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
$primary = ($eight && !empty($eight['url'])) ? $eight
         : (($cn && !empty($cn['url'])) ? $cn
         : (!empty($flix[0]['url']) ? $flix[0] : null));

$response = [
    'ok'         => true,
    'mode'       => $primary ? 'hls' : 'embed',
    'url'        => $primary ? $primary['url'] : ($embed['url'] ?? ''),
    'server_key' => $primary ? $primary['key'] : ($embed['key'] ?? null),
    'lang'       => 'any',
    'sources'    => $sources,
    'subtitles'  => [],
    'tmdb_id'    => $tmdbId,
    'season'     => $season,
    'episode'    => $episode,
    'detail'     => $detail,
    'embed'      => $embed,
    'servers'    => $allServers,
    'episodes'   => $seasonData,
];

/*
 * Captions fallback (same rule as the movie endpoint): online English
 * subtitles are only signed onto a direct source, and only when that source
 * came back without a track of its own.
 */
subtitles_attach($response, $imdb, $season, $episode);

echo json_encode($response);
