<?php
/**
 * Playback heartbeat.
 *
 * POST video_id         "{animeId}:{episode}" for catalogue rows, or a bare id
 *                       for legacy local movies
 *      last_position    resume point in seconds
 *      watched_seconds  seconds actually played since the last heartbeat
 *      duration         runtime in seconds, when the player knows it
 *
 * The position goes to video_progress and the played seconds go to watch_time,
 * so "how far in" and "how long watched" stay separate numbers.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/progress.php';

$user_id       = (int)$_SESSION['userID'];
$video_id      = trim((string)($_POST['video_id'] ?? ''));
$last_position = (float)($_POST['last_position'] ?? 0);
$watched       = (float)($_POST['watched_seconds'] ?? 0);
$duration      = (float)($_POST['duration'] ?? 0);

if ($video_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing video_id']);
    exit;
}

// "{animeId}:{episode}" — anything else is a legacy movie/special (episode 0).
$anime_id = $video_id;
$episode  = 0;
if (preg_match('/^(.*):(\d+)$/', $video_id, $match)) {
    $anime_id = $match[1];
    $episode  = (int)$match[2];
}

$positionSaved = false;
if ($last_position > 0) {
    $positionSaved = progress_save_raw($user_id, $video_id, $last_position);
}

$timeSaved = false;
if ($watched > 0 || $duration > 0) {
    $watched = max(0, (int)round($watched));
    $timeSaved = watch_time_add($user_id, $anime_id, $episode, $watched, $duration);
}

if (!$positionSaved && !$timeSaved) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nothing was saved']);
    exit;
}

echo json_encode([
    'success'          => true,
    'position_saved'   => $positionSaved,
    'watch_time_saved' => $timeSaved,
]);
