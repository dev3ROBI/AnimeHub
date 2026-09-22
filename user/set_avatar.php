<?php
/**
 * Save the avatar a user picked from the anime avatar gallery.
 *
 *   POST avatar=ch127691    -> store that gallery entry
 *   POST avatar=            -> reset to the default
 *   GET                     -> return the current avatar + the full gallery
 *
 * Values are validated against the gallery, so only known anime avatars can be
 * stored — there is no upload path and no arbitrary URL.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/../includes/db.php';
include_once __DIR__ . '/../includes/avatars.php';

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['userID'];

// ─── Current state + gallery (used to render the picker) ───────────────
function current_avatar_value($conn, $user_id) {
    $stmt = $conn->prepare("SELECT User_Avatar FROM users WHERE User_ID = ?");
    if (!$stmt) return '';
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (string)($row['User_Avatar'] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $value = current_avatar_value($conn, $user_id);
    echo json_encode([
        'ok'      => true,
        'avatar'  => $value,
        'url'     => avatar_resolve($value),
        'meta'    => avatar_meta($value),
        'default' => avatar_default(),
        'gallery' => array_values(avatar_gallery()),
    ]);
    exit;
}

// ─── Save ─────────────────────────────────────────────────────────────
$avatar = isset($_POST['avatar']) ? trim((string)$_POST['avatar']) : '';

if ($avatar !== '' && !avatar_is_valid($avatar)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'অবতারটি গ্যালারিতে নেই।']);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET User_Avatar = ? WHERE User_ID = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database error']);
    exit;
}
$stmt->bind_param("si", $avatar, $user_id);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'অবতার সেভ করা যায়নি।']);
    exit;
}

echo json_encode([
    'ok'     => true,
    'avatar' => $avatar,
    'url'    => avatar_resolve($avatar),
    'meta'   => avatar_meta($avatar),
]);
