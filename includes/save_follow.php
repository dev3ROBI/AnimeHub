<?php
/**
 * Toggle follow/unfollow for an airing anime.
 * POST: anime_slug, anime_title (optional)
 * Returns: { success, following, followers }
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

include_once __DIR__ . '/db.php';

$user_id    = (int)$_SESSION['userID'];
$anime_slug = trim($_POST['anime_slug'] ?? '');
$anime_title = trim($_POST['anime_title'] ?? '');

if ($anime_slug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing anime_slug']);
    exit;
}

try {
    // Check if already following
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE user_id = ? AND anime_slug = ? LIMIT 1");
    $stmt->execute([$user_id, $anime_slug]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Unfollow
        $del = $pdo->prepare("DELETE FROM follows WHERE user_id = ? AND anime_slug = ?");
        $del->execute([$user_id, $anime_slug]);
        $following = false;
    } else {
        // Follow — store English title for notifications
        if ($anime_title === '') {
            // Try to fetch title from the anime data we have in session or default
            $anime_title = $anime_slug;
        }
        $ins = $pdo->prepare("INSERT INTO follows (user_id, anime_slug, anime_title) VALUES (?, ?, ?)");
        $ins->execute([$user_id, $anime_slug, $anime_title]);
        $following = true;
    }

    // Count total followers for this anime
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE anime_slug = ?");
    $cnt->execute([$anime_slug]);
    $followers = (int)$cnt->fetchColumn();

    echo json_encode(['success' => true, 'following' => $following, 'followers' => $followers]);
} catch (Exception $e) {
    error_log('[Follow] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
