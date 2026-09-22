<?php
include_once __DIR__ . '/anikuro_api.php';

header('Content-Type: application/json');

$session = trim($_GET['session'] ?? '');
$episode_session = trim($_GET['episode_session'] ?? '');

if (empty($session) || empty($episode_session)) {
    echo json_encode(['sources' => [], 'error' => 'Missing session or episode_session']);
    exit;
}

// Get streaming links from Anikuro
$data = anikuro_stream($session, $episode_session, false);

if (!$data || empty($data['sources'])) {
    echo json_encode(['sources' => [], 'error' => 'No streaming sources available']);
    exit;
}

// Return sources (m3u8 links with resolution info)
echo json_encode([
    'sources' => $data['sources'] ?? [],
    'episode' => $data['episode'] ?? null,
    'anime_title' => $data['anime_title'] ?? null,
]);
