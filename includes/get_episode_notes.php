<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$video_id = trim($_GET['video_id'] ?? '');

if ($video_id === '') {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, timestamp_seconds, note, created_at
        FROM episode_notes
        WHERE user_id = ? AND video_id = ?
        ORDER BY timestamp_seconds ASC
    ");
    $stmt->execute([$user_id, $video_id]);
    $notes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notes[] = [
            'id'        => (int)$row['id'],
            'time'      => (float)$row['timestamp_seconds'],
            'time_fmt'  => gmdate('H:i:s', (int)$row['timestamp_seconds']),
            'note'      => $row['note'],
            'created'   => $row['created_at'],
        ];
    }
    echo json_encode($notes);
} catch (Exception $e) {
    echo json_encode([]);
}
