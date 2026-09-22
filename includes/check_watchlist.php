<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include 'db.php';

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => true, 'status' => null]);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$imdb_id = trim((string)($_GET['imdb_id'] ?? ''));

if ($imdb_id === '') {
    echo json_encode(['success' => true, 'status' => null]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status FROM watchlist WHERE user_id = ? AND imdb_id = ? LIMIT 1");
    $stmt->execute([$user_id, $imdb_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'status' => $row ? $row['status'] : null]);
} catch (Throwable $e) {
    echo json_encode(['success' => true, 'status' => null]);
}
