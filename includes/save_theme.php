<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['theme' => 'dark']);
    exit;
}

$user_id = $_SESSION['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $theme = in_array($_POST['theme'] ?? '', ['dark', 'light', 'auto']) ? $_POST['theme'] : 'dark';
    try {
        $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE User_ID = ?");
        $stmt->execute([$theme, (int)$user_id]);
        echo json_encode(['success' => true, 'theme' => $theme]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT theme_preference FROM users WHERE User_ID = ?");
    $stmt->execute([(int)$user_id]);
    $theme = $stmt->fetchColumn() ?: 'dark';
    echo json_encode(['theme' => $theme]);
} catch (Exception $e) {
    echo json_encode(['theme' => 'dark']);
}
