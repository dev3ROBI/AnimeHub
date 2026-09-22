<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

error_log('[settings] === SAVE REQUEST ===');
error_log('[settings] POST: ' . json_encode($_POST));
error_log('[settings] SESSION userID: ' . var_export($_SESSION['userID'] ?? null, true));

include 'db.php';

if (!isset($pdo)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No PDO connection']);
    exit;
}

$user_id = (int)($_SESSION['userID'] ?? 0);
if ($user_id <= 0) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$key = trim((string)($_POST['key'] ?? ''));
$value = isset($_POST['value']) ? (int)$_POST['value'] : 0;

$ALLOWED_KEYS = ['sticky_navbar', 'autoplay', 'show_ratings'];

if (!in_array($key, $ALLOWED_KEYS, true)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid key: ' . $key]);
    exit;
}

$value = $value ? 1 : 0;
error_log('[settings] key=' . $key . ' value=' . $value . ' user=' . $user_id);

try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        sticky_navbar TINYINT(1) NOT NULL DEFAULT 0,
        autoplay TINYINT(1) NOT NULL DEFAULT 1,
        show_ratings TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure row exists
    $check = $pdo->prepare("SELECT user_id FROM user_settings WHERE user_id = ?");
    $check->execute([$user_id]);
    if (!$check->fetch()) {
        $ins = $pdo->prepare("INSERT INTO user_settings (user_id) VALUES (?)");
        $ins->execute([$user_id]);
        error_log('[settings] Created row for user ' . $user_id);
    }

    // Update
    $stmt = $pdo->prepare("UPDATE user_settings SET {$key} = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
    $stmt->execute([$value, $user_id]);
    $affected = $stmt->rowCount();
    error_log('[settings] Affected rows: ' . $affected);

    // Verify read-back
    $verify = $pdo->prepare("SELECT {$key} FROM user_settings WHERE user_id = ?");
    $verify->execute([$user_id]);
    $row = $verify->fetch(PDO::FETCH_ASSOC);
    $saved = $row ? (int)$row[$key] : 'NOT_FOUND';
    error_log('[settings] Read-back: ' . $saved);

    ob_end_clean();

    if ($saved === $value) {
        echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Read-back mismatch', 'expected' => $value, 'got' => $saved]);
    }
} catch (Throwable $e) {
    ob_end_clean();
    error_log('[settings] EXCEPTION: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
