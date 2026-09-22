<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode([]);
    exit;
}

$user_id = $_SESSION['userID'];
$limit = min(10, max(1, intval($_GET['limit'] ?? 8)));

try {
    $stmt = $pdo->prepare("
        SELECT term, searched_at
        FROM search_history
        WHERE user_id = ?
        ORDER BY searched_at DESC
        LIMIT ?
    ");
    $stmt->execute([(int)$user_id, (int)$limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($rows as $row) {
        $history[] = [
            'term' => $row['term'],
            'time' => $row['searched_at'],
        ];
    }

    echo json_encode($history);
} catch (Exception $e) {
    echo json_encode([]);
}
