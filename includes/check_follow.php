<?php
/**
 * Check if the current user follows a specific anime.
 * GET: anime_slug
 * Returns: { following: bool, followers: int }
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['following' => false, 'followers' => 0]);
    exit;
}

include_once __DIR__ . '/db.php';

$user_id    = (int)$_SESSION['userID'];
$anime_slug = trim($_GET['anime_slug'] ?? '');

if ($anime_slug === '') {
    echo json_encode(['following' => false, 'followers' => 0]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE user_id = ? AND anime_slug = ? LIMIT 1");
    $stmt->execute([$user_id, $anime_slug]);
    $following = $stmt->fetch() !== false;

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE anime_slug = ?");
    $cnt->execute([$anime_slug]);
    $followers = (int)$cnt->fetchColumn();

    echo json_encode(['following' => $following, 'followers' => $followers]);
} catch (Exception $e) {
    error_log('[Follow] Check error: ' . $e->getMessage());
    echo json_encode(['following' => false, 'followers' => 0]);
}
