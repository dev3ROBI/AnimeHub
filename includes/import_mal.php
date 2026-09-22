<?php
/**
 * Import watchlist from MyAnimeList XML export.
 *
 * Accepts a MAL XML file upload, parses <anime> entries, resolves each title
 * via AniList search, and inserts into the watchlist table.
 *
 * POST: multipart/form-data with 'mal_file' field
 * Returns JSON: { success: bool, imported: int, message: string }
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include __DIR__ . '/db.php';
include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/anilist_api.php';

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$userID = (int)$_SESSION['userID'];

// MAL status mapping: MAL my_status integer -> our status
$STATUS_MAP = [
    1 => 'watching',     // Watching
    2 => 'completed',    // Completed
    3 => 'on_hold',      // On Hold
    4 => 'dropped',      // Dropped
    6 => 'watch_later',  // Plan to Watch
    7 => 'watch_later',  // Re-watching -> map to watch_later
];

if (!isset($_FILES['mal_file']) || $_FILES['mal_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$tmpFile = $_FILES['mal_file']['tmp_name'];
$xmlContent = file_get_contents($tmpFile);

if ($xmlContent === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to read uploaded file']);
    exit;
}

// Suppress XML parsing warnings for malformed files
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlContent);
if ($xml === false) {
    $errors = libxml_get_errors();
    libxml_clear_errors();
    $errorMsg = !empty($errors) ? trim($errors[0]->message) : 'Invalid XML file';
    echo json_encode(['success' => false, 'message' => 'Invalid MAL XML: ' . $errorMsg]);
    exit;
}

$animes = $xml->anime;
if (empty($animes)) {
    echo json_encode(['success' => false, 'message' => 'No anime entries found in the XML']);
    exit;
}

$imported = 0;
$errors = 0;

foreach ($animes as $anime) {
    $title = trim((string)($anime->series_title ?? ''));
    if ($title === '') {
        $errors++;
        continue;
    }

    $malStatus = (int)($anime->my_status ?? 6);
    $ourStatus = $STATUS_MAP[$malStatus] ?? 'watch_later';
    $watchedEps = (int)($anime->my_watched_episodes ?? 0);

    // Try to resolve the anime via AniList search
    $resolved = false;

    // Try AniList search first
    try {
        $results = anilist_search($title, 5);
        if (!empty($results)) {
            $best = $results[0];
            $anilistId = $best['anilist_id'] ?? $best['id'] ?? null;
            if ($anilistId) {
                $animeId = 'anilist:' . $anilistId;

                // Check for duplicate
                $check = $pdo->prepare("SELECT id FROM watchlist WHERE user_id = ? AND imdb_id = ? LIMIT 1");
                $check->execute([$userID, $animeId]);
                if (!$check->fetch()) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO watchlist (user_id, imdb_id, status) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP"
                    );
                    $stmt->execute([$userID, $animeId, $ourStatus]);
                    $imported++;
                }
                $resolved = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[MAL import] AniList search failed for "' . $title . '": ' . $e->getMessage());
    }

    // If AniList failed, try Jikan search
    if (!$resolved) {
        try {
            include_once __DIR__ . '/jikan_api.php';
            $jikanResults = jikan_search($title, 1, 5);
            if (!empty($jikanResults)) {
                $best = $jikanResults[0];
                $malId = $best['mal_id'] ?? $best['id'] ?? null;
                if ($malId) {
                    $animeId = 'jikan:' . $malId;

                    $check = $pdo->prepare("SELECT id FROM watchlist WHERE user_id = ? AND imdb_id = ? LIMIT 1");
                    $check->execute([$userID, $animeId]);
                    if (!$check->fetch()) {
                        $stmt = $pdo->prepare(
                            "INSERT INTO watchlist (user_id, imdb_id, status) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP"
                        );
                        $stmt->execute([$userID, $animeId, $ourStatus]);
                        $imported++;
                    }
                    $resolved = true;
                }
            }
        } catch (Throwable $e) {
            error_log('[MAL import] Jikan search failed for "' . $title . '": ' . $e->getMessage());
        }
    }

    if (!$resolved) {
        $errors++;
    }

    // Rate limit: ~1 req/sec to be safe with APIs
    usleep(300000);
}

echo json_encode([
    'success'  => $imported > 0,
    'imported' => $imported,
    'errors'   => $errors,
    'message'  => $imported > 0
        ? "Successfully imported {$imported} anime" . ($errors > 0 ? " ({$errors} could not be resolved)" : '')
        : 'No anime could be resolved from the import file',
]);
