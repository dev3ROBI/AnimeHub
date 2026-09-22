<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = $_SESSION['userID'];
$term = trim($_POST['term'] ?? '');

if ($term === '' || mb_strlen($term) < 2) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO search_history (user_id, term)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE searched_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([(int)$user_id, $term]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
