<?php
/**
 * Episode problem report.
 *
 * POST anime_slug   "{provider}:{remoteId}" (or a local id)
 *      episode      episode number, 0 for movies
 *      reason       one of kp_report_reasons()
 *      details      free text, optional (truncated to 500 chars)
 *      source       server/provider the user was watching, optional
 *
 * Returns { success, id } or { success:false, message }.
 *
 * The table is created on first write — mirrors includes/watch_time.php — so a
 * fresh install works without running sql/watch_time_and_reports.sql.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/db.php';

/** Allowed reasons, keyed by the value the client sends. */
function kp_report_reasons() {
    return [
        'video_not_playing' => 'Video will not play',
        'wrong_episode'     => 'Wrong episode',
        'audio_desync'      => 'Audio/video out of sync',
        'no_subtitles'      => 'Subtitles missing',
        'wrong_anime'       => 'Wrong anime',
        'buffering'         => 'Constant buffering',
        'other'             => 'Other',
    ];
}

function report_ensure_table() {
    global $pdo;
    if (!$pdo) return false;

    static $ready = null;
    if ($ready !== null) return $ready;

    try {
        $pdo->query('SELECT 1 FROM reports LIMIT 1');
        return $ready = true;
    } catch (Exception $e) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS reports (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    user_id     INT NOT NULL,
                    anime_slug  VARCHAR(191) NOT NULL,
                    episode     INT NOT NULL DEFAULT 0,
                    reason      VARCHAR(64) NOT NULL DEFAULT 'other',
                    details     TEXT NULL,
                    source      VARCHAR(64) NULL,
                    status      VARCHAR(16) NOT NULL DEFAULT 'open',
                    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_reports_user (user_id, created_at),
                    KEY idx_reports_anime (anime_slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            return $ready = true;
        } catch (Exception $e2) {
            error_log('[report] table unavailable: ' . $e2->getMessage());
            return $ready = false;
        }
    }
}

if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id    = (int)$_SESSION['userID'];
$anime_slug = trim((string)($_POST['anime_slug'] ?? ''));
$episode    = max(0, (int)($_POST['episode'] ?? 0));
$reason     = trim((string)($_POST['reason'] ?? 'other'));
$details    = trim((string)($_POST['details'] ?? ''));
$source     = trim((string)($_POST['source'] ?? ''));

if ($anime_slug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing anime_slug']);
    exit;
}

if (!array_key_exists($reason, kp_report_reasons())) {
    $reason = 'other';
}

// Free text is user input: bound to TEXT, but keep it short and single-line-ish.
if (strlen($details) > 500) {
    $details = substr($details, 0, 500);
}
if (strlen($source) > 64) {
    $source = substr($source, 0, 64);
}

if (!report_ensure_table()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Report storage unavailable']);
    exit;
}

try {
    // One open report per user + episode: a second click updates it instead of
    // filling the table with duplicates.
    $stmt = $pdo->prepare("
        SELECT id FROM reports
        WHERE user_id = ? AND anime_slug = ? AND episode = ? AND status = 'open'
        LIMIT 1
    ");
    $stmt->execute([$user_id, $anime_slug, $episode]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE reports
               SET reason = ?, details = ?, source = ?, created_at = CURRENT_TIMESTAMP
             WHERE id = ?
        ");
        $upd->execute([$reason, $details !== '' ? $details : null, $source ?: null, (int)$existing]);

        echo json_encode(['success' => true, 'id' => (int)$existing, 'updated' => true]);
        exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO reports (user_id, anime_slug, episode, reason, details, source)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $user_id,
        $anime_slug,
        $episode,
        $reason,
        $details !== '' ? $details : null,
        $source ?: null,
    ]);

    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Exception $e) {
    error_log('[report] save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the report']);
}
