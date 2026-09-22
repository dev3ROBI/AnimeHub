<?php
/**
 * Resolve a playable source for one episode.
 *
 *   GET includes/get_stream.php?id=anilist:21&ep=1&lang=sub
 *   GET includes/get_stream.php?id=reanime:one-piece-xamk74&ep=1
 *
 * Responds with the payload from stream_resolve(); `mode` is either "hls"
 * (feed `url` to the HLS player) or "embed" (put `url` in an iframe).
 */
session_start();

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/stream.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$rawId   = trim($_GET['id'] ?? '');
$episode = isset($_GET['ep']) ? intval($_GET['ep']) : 1;
$lang    = $_GET['lang'] ?? 'sub';
$title   = trim($_GET['title'] ?? '');

if ($rawId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing id']);
    exit;
}

$info = catalog_info($rawId, $title !== '' ? $title : null);
if (!$info) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Anime not found', 'id' => $rawId]);
    exit;
}

$result = stream_resolve($info, $episode, $lang);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
