<?php
/**
 * AJAX endpoint: resolve movie embed URL.
 * GET ?id=tmdb:movie:550  or  GET ?tmdb=550
 */
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/tmdb_movie_api.php';
include_once __DIR__ . '/embed_movie.php';

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

// Get movie detail for metadata
$detail = tmdb_movie_detail($tmdbId);

// Resolve embed
$embed = movie_embed_resolve($tmdbId);
$allServers = movie_embed_all($tmdbId);

echo json_encode([
    'ok'      => true,
    'mode'    => 'embed',
    'url'     => $embed['url'] ?? '',
    'tmdb_id' => $tmdbId,
    'detail'  => $detail,
    'embed'   => $embed,
    'servers' => $allServers,
]);
