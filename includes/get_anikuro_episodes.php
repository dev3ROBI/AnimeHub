<?php
include_once __DIR__ . '/anikuro_api.php';

header('Content-Type: application/json');

$session = trim($_GET['session'] ?? '');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

if (empty($session)) {
    echo json_encode(['error' => 'Missing session']);
    exit;
}

// Get episodes from Anikuro
$episodes = anikuro_episodes($session, $page, 'episode_asc');

if (empty($episodes)) {
    echo json_encode(['error' => 'Failed to load episodes']);
    exit;
}

echo json_encode($episodes);
