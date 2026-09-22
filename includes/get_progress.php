<?php
/**
 * Saved playback positions.
 *
 *   get_progress.php?video_id=anilist:21:5   -> { position: 123.4 }
 *   get_progress.php?anime_id=anilist:21     -> { progress: { "5": 123.4, ... } }
 *
 * Keys are "{provider}:{remoteId}:{episode}", written by save_progress.php.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

include_once 'db.php';

$user_id  = $_SESSION['userID'];
$video_id = trim((string)($_GET['video_id'] ?? ''));
$anime_id = trim((string)($_GET['anime_id'] ?? ''));

if ($video_id === '' && $anime_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing video_id or anime_id']);
    exit;
}

// ─── One anime, every episode ──────────────────────────────────────────
if ($anime_id !== '') {
    // LIKE wildcards in the id would widen the match, so escape them.
    $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $anime_id) . ':%';

    $stmt = $conn->prepare(
        "SELECT video_id, last_position
           FROM video_progress
          WHERE user_id = ? AND video_id LIKE ? ESCAPE '\\\\'"
    );
    $stmt->bind_param("is", $user_id, $prefix);
    $stmt->execute();
    $result = $stmt->get_result();

    $progress = [];
    $offset = strlen($anime_id) + 1;
    while ($row = $result->fetch_assoc()) {
        $ep = substr((string)$row['video_id'], $offset);
        if ($ep === '' || strpos($ep, ':') !== false) continue; // not an episode key
        $progress[(string)(int)$ep] = floatval($row['last_position']);
    }

    echo json_encode(['progress' => $progress]);
    exit;
}

// ─── Single episode ────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT last_position FROM video_progress WHERE user_id = ? AND video_id = ?");
$stmt->bind_param("is", $user_id, $video_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['position' => floatval($row['last_position'])]);
} else {
    echo json_encode(['position' => 0]);
}
