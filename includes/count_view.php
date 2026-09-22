<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    exit;
}

$user_id = $_SESSION['userID'];
$imdb_id = $_POST['imdb_id'] ?? '';

if (empty($imdb_id)) {
    http_response_code(400);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO views (user_id, imdb_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $imdb_id]);
} catch (Exception $e) {
    error_log("count_view error: " . $e->getMessage());
}
