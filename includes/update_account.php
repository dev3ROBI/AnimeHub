<?php
/**
 * Account self-service for the signed-in user.
 *
 *   POST {action:"name", name:"..."}
 *   POST {action:"password", current_password, new_password, confirm_password}
 *
 * The profile page promises that a name or password can be changed here, so
 * the two actions live together: same session check, same JSON shape, and one
 * place that has to reason about what the `users` row may accept.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/db.php';

function kp_account_out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['userID'])) {
    kp_account_out(['ok' => false, 'message' => 'Not logged in'], 403);
}

$user_id = (int)$_SESSION['userID'];

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$action = (string)($input['action'] ?? '');

try {
    $stmt = $pdo->prepare("SELECT User_Name, User_Password FROM users WHERE User_ID = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $user = null;
}

if (!$user) {
    kp_account_out(['ok' => false, 'message' => 'Account not found'], 404);
}

// ─── Display name ──────────────────────────────────────────────────────
if ($action === 'name') {
    $name = trim((string)($input['name'] ?? ''));
    $len  = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

    if ($len < 3 || $len > 40) {
        kp_account_out(['ok' => false, 'message' => 'Name must be 3–40 characters.']);
    }
    if (!preg_match('/^[\p{L}\p{N} ._-]+$/u', $name)) {
        kp_account_out(['ok' => false, 'message' => 'Only letters, numbers, spaces, dot, dash and underscore.']);
    }

    // Sign-in accepts either the name or the email, so two accounts sharing a
    // name would make logging in ambiguous.
    try {
        $dup = $pdo->prepare("SELECT User_ID FROM users WHERE User_Name = ? AND User_ID <> ? LIMIT 1");
        $dup->execute([$name, $user_id]);
        if ($dup->fetchColumn()) {
            kp_account_out(['ok' => false, 'message' => 'That name is already taken.']);
        }

        $upd = $pdo->prepare("UPDATE users SET User_Name = ? WHERE User_ID = ?");
        $upd->execute([$name, $user_id]);
    } catch (Throwable $e) {
        error_log('[account] name update failed: ' . $e->getMessage());
        kp_account_out(['ok' => false, 'message' => 'Could not save the new name.'], 500);
    }

    $_SESSION['userName'] = $name;
    if (isset($_COOKIE['userName'])) {
        setcookie('userName', $name, time() + 3 * 86400, '/');
    }

    kp_account_out(['ok' => true, 'name' => $name]);
}

// ─── Password ──────────────────────────────────────────────────────────
if ($action === 'password') {
    $current = (string)($input['current_password'] ?? '');
    $new     = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        kp_account_out(['ok' => false, 'message' => 'Fill in every password field.']);
    }
    if (!password_verify($current, (string)$user['User_Password'])) {
        kp_account_out(['ok' => false, 'message' => 'Your current password is not correct.']);
    }
    if (strlen($new) < 8) {
        kp_account_out(['ok' => false, 'message' => 'Use at least 8 characters.']);
    }
    if ($new !== $confirm) {
        kp_account_out(['ok' => false, 'message' => 'The new passwords do not match.']);
    }
    if (hash_equals((string)$user['User_Password'], (string)$new) || $new === $current) {
        kp_account_out(['ok' => false, 'message' => 'The new password must be different.']);
    }

    try {
        $upd = $pdo->prepare("UPDATE users SET User_Password = ? WHERE User_ID = ?");
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
    } catch (Throwable $e) {
        error_log('[account] password update failed: ' . $e->getMessage());
        kp_account_out(['ok' => false, 'message' => 'Could not save the new password.'], 500);
    }

    kp_account_out(['ok' => true]);
}

kp_account_out(['ok' => false, 'message' => 'Unknown action.'], 400);
