<?php
session_start();
include_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = (int)$_SESSION['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get preferences
    try {
        $stmt = $pdo->prepare("SELECT episode_alerts, follow_alerts, system_alerts, sound_enabled, toast_enabled FROM notification_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode(['success' => true, 'settings' => $row]);
        } else {
            echo json_encode(['success' => true, 'settings' => [
                'episode_alerts' => 1, 'follow_alerts' => 1, 'system_alerts' => 1,
                'sound_enabled' => 0, 'toast_enabled' => 1,
            ]]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update preferences
    $input = json_decode(file_get_contents('php://input'), true);
    $episode = isset($input['episode_alerts']) ? (int)$input['episode_alerts'] : 1;
    $follow  = isset($input['follow_alerts'])  ? (int)$input['follow_alerts']  : 1;
    $system  = isset($input['system_alerts'])  ? (int)$input['system_alerts']  : 1;
    $sound   = isset($input['sound_enabled'])  ? (int)$input['sound_enabled']  : 0;
    $toast   = isset($input['toast_enabled'])  ? (int)$input['toast_enabled']  : 1;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notification_settings (user_id, episode_alerts, follow_alerts, system_alerts, sound_enabled, toast_enabled)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                episode_alerts = VALUES(episode_alerts),
                follow_alerts = VALUES(follow_alerts),
                system_alerts = VALUES(system_alerts),
                sound_enabled = VALUES(sound_enabled),
                toast_enabled = VALUES(toast_enabled)
        ");
        $stmt->execute([$user_id, $episode, $follow, $system, $sound, $toast]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
