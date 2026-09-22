<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$input = json_decode(file_get_contents('php://input'), true);

if (!empty($input['id'])) {
    // Delete single notification
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$input['id'], $user_id]);
    echo json_encode(['success' => true]);
} elseif (!empty($input['ids']) && is_array($input['ids'])) {
    // Delete multiple
    $ids = array_map('intval', $input['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$user_id]);
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?");
    $stmt->execute($params);
    echo json_encode(['success' => true]);
} elseif (!empty($input['clear_all'])) {
    // Clear all notifications for user
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->execute([$user_id]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No notification ID provided']);
}
