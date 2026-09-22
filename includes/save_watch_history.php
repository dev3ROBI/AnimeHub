<?php
session_start();
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/reanime_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['userID'];
$anime_slug = trim($_POST['anime_slug'] ?? '');
$episode_number = isset($_POST['episode_number']) ? intval($_POST['episode_number']) : 0;

if (empty($anime_slug) || $episode_number <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

$result = reanime_save_watch_history($user_id, $anime_slug, $episode_number);
echo json_encode(['success' => (bool)$result]);
