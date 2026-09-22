<?php
/**
 * AJAX endpoint: resolve TV embed URL.
 * GET ?id=tmdb:tv:1399&season=1&ep=5  or  GET ?tmdb=1399&season=1&episode=5
 */
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/tmdb_movie_api.php';
include_once __DIR__ . '/embed_tv.php';

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
$embed = tv_embed_resolve($tmdbId, $season, $episode);
$allServers = tv_embed_all($tmdbId, $season, $episode);

// Get season episode list
$seasonData = tmdb_tv_season($tmdbId, $season);

echo json_encode([
    'ok'       => true,
    'mode'     => 'embed',
    'url'      => $embed['url'] ?? '',
    'tmdb_id'  => $tmdbId,
    'season'   => $season,
    'episode'  => $episode,
    'detail'   => $detail,
    'embed'    => $embed,
    'servers'  => $allServers,
    'episodes' => $seasonData,
]);
