<?php
/**
 * Remove a title from Continue Watching.
 *
 *   POST video_id=anilist:21          -> clear the whole title
 *   POST video_id=anilist:21&episode=5 -> clear just that episode
 *
 * Continue Watching is fed by `watch_history` (which episode was opened) and
 * `video_progress` (the exact seconds), so both are cleared here — leaving
 * either one behind would keep the row on the page.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}

include_once 'db.php';
include_once 'progress.php';

$user_id  = (int)$_SESSION['userID'];
$video_id = trim((string)($_POST['video_id'] ?? ''));
$episode  = isset($_POST['episode']) && $_POST['episode'] !== '' ? (int)$_POST['episode'] : null;

if ($video_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing video_id']);
    exit;
}

try {
    if ($episode !== null) {
        progress_clear($user_id, $video_id, $episode);
    } else {
        // LIKE wildcards in the title id would widen the match, so escape them.
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $video_id) . ':%';
        $stmt = $pdo->prepare("DELETE FROM video_progress WHERE user_id = ? AND video_id LIKE ? ESCAPE '\\\\'");
        $stmt->execute([$user_id, $prefix]);
    }

    $stmt = $pdo->prepare("DELETE FROM watch_history WHERE user_id = ? AND anime_slug = ?");
    $stmt->execute([$user_id, $video_id]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[clear_progress] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database error']);
}
