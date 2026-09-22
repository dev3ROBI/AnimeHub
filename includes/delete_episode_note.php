<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$note_id = intval($_POST['note_id'] ?? 0);

if ($note_id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM episode_notes WHERE id = ? AND user_id = ?");
    $stmt->execute([$note_id, $user_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
