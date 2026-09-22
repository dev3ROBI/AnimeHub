<?php
/**
 * Episode list for any catalogue id.
 *
 *   GET includes/get_reanime_episodes.php?slug=one-piece-xamk74
 *   GET includes/get_reanime_episodes.php?id=anilist:21
 *
 * `slug` is kept for backwards compatibility with the old ReAnime-only call.
 */
include_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$slug = trim($_GET['slug'] ?? '');
$id   = trim($_GET['id'] ?? '');

if ($id === '' && $slug !== '') {
    $id = reanime_id_from_slug($slug);
}

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing slug or id']);
    exit;
}

$episodes = catalog_episodes($id);

if (empty($episodes)) {
    http_response_code(404);
    echo json_encode([]);
    exit;
}

echo json_encode(array_values($episodes), JSON_UNESCAPED_UNICODE);
