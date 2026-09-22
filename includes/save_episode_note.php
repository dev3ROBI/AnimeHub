<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$video_id = trim($_POST['video_id'] ?? '');
$timestamp = floatval($_POST['timestamp'] ?? 0);
$note = trim($_POST['note'] ?? '');

if ($video_id === '' || $note === '') {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Keep one note a sane length — the input is client-capped too, this is the
// server-side guarantee.
if (strlen($note) > 500) {
    $note = substr($note, 0, 500);
}
if ($timestamp < 0) $timestamp = 0;

try {
    $stmt = $pdo->prepare("
        INSERT INTO episode_notes (user_id, video_id, timestamp_seconds, note)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $video_id, $timestamp, $note]);
    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Exception $e) {
    // Swallowing this made a missing table look like a successful save.
    error_log('[note] save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save the note']);
}
