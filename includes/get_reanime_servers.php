<?php
/**
 * Streaming servers for one episode (self-hosted scraper).
 *
 *   GET includes/get_reanime_servers.php?slug=one-piece-xamk74&episode=1&anilist_id=21
 *
 * Returns { sub: [...], dub: [...], anilist_id, ... }.
 */
include_once __DIR__ . '/reanime_api.php';

header('Content-Type: application/json');

$slug = trim($_GET['slug'] ?? '');
$episode = isset($_GET['episode']) ? intval($_GET['episode']) : 0;
$anilist_id = isset($_GET['anilist_id']) && intval($_GET['anilist_id']) > 0
    ? intval($_GET['anilist_id'])
    : null;

if ($slug === '' || $episode <= 0) {
    http_response_code(400);
    echo json_encode(['sub' => [], 'dub' => [], 'error' => 'Missing slug or episode']);
    exit;
}

$data = reanime_servers($slug, $episode, $anilist_id);

if (!$data) {
    echo json_encode([
        'sub' => [],
        'dub' => [],
        'error' => reanime_scraper_health()
            ? 'Scraper returned no servers for this episode.'
            : 'Scraper offline — start it with: cd ReAnime.to-API && uvicorn reanime:app --port 8000',
    ]);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
