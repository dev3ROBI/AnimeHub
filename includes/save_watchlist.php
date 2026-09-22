<?php
/**
 * Add / update / remove a watch list entry.
 *
 *   POST imdb_id=anilist:21&status=watching   -> upsert
 *   POST imdb_id=anilist:21&action=remove     -> delete
 *
 * The session user is authoritative; a `user_id` posted by the client is
 * accepted for backwards compatibility but ignored.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include 'db.php';

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['userID'];
$imdb_id = trim((string)($_POST['imdb_id'] ?? ''));
$action  = trim((string)($_POST['action'] ?? 'set'));
$status  = trim((string)($_POST['status'] ?? ''));

$ALLOWED = ['watching', 'on_hold', 'watch_later', 'completed', 'dropped'];

if ($imdb_id === '' || mb_strlen($imdb_id) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid imdb_id']);
    exit;
}

try {
    if ($action === 'remove') {
        $stmt = $pdo->prepare("DELETE FROM watchlist WHERE user_id = ? AND imdb_id = ?");
        $stmt->execute([$user_id, $imdb_id]);
        echo json_encode(['success' => true, 'removed' => $stmt->rowCount() > 0]);
        exit;
    }

    if (!in_array($status, $ALLOWED, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown status']);
        exit;
    }

    // One statement instead of select-then-insert/update.
    $stmt = $pdo->prepare(
        "INSERT INTO watchlist (user_id, imdb_id, status)
              VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$user_id, $imdb_id, $status]);

    echo json_encode(['success' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('[watchlist] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
