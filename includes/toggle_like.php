<?php
/**
 * Toggle a like for one title.
 *
 * POST imdb_id   the catalogue id used by watch.php ("anilist:21", a local id, …)
 * Returns { success, liked, likes }.
 *
 * The user id comes from the session only — never from the request body.
 */
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$imdb_id = trim((string)($_POST['imdb_id'] ?? ''));

if (!$user_id || $imdb_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

$check = $conn->prepare("SELECT * FROM likes WHERE user_id = ? AND imdb_id = ?");
$check->bind_param("is", $user_id, $imdb_id);
$check->execute();
$result = $check->get_result();

$liked = false;

if ($result->num_rows > 0) {
    $delete = $conn->prepare("DELETE FROM likes WHERE user_id = ? AND imdb_id = ?");
    $delete->bind_param("is", $user_id, $imdb_id);
    $delete->execute();
    $liked = false;
} else {
    $insert = $conn->prepare("INSERT INTO likes (user_id, imdb_id) VALUES (?, ?)");
    $insert->bind_param("is", $user_id, $imdb_id);
    $insert->execute();
    $liked = true;
}

$count = $conn->prepare("SELECT COUNT(*) AS total FROM likes WHERE imdb_id = ?");
$count->bind_param("s", $imdb_id);
$count->execute();
$countResult = $count->get_result()->fetch_assoc();
$likeCount = (int)($countResult['total'] ?? 0);

// `liked` lets the client set the icon from the server's answer instead of
// guessing, so a failed request can never leave the heart out of sync.
echo json_encode(['success' => true, 'liked' => $liked, 'likes' => $likeCount]);
