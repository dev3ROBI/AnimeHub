<?php
/**
 * Export watchlist as MyAnimeList-compatible XML.
 *
 * Generates an XML file that MAL's import tool can read.
 * Maps internal statuses to MAL's my_status values.
 */
session_start();

include __DIR__ . '/db.php';
include_once __DIR__ . '/catalog.php';

if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    exit('Not logged in');
}

$userID = (int)$_SESSION['userID'];

// MAL status mapping: our status -> MAL my_status integer
$STATUS_MAP = [
    'watching'    => 1,  // Watching
    'completed'   => 2,  // Completed
    'on_hold'     => 3,  // On Hold
    'dropped'     => 4,  // Dropped
    'watch_later' => 6,  // Plan to Watch
];

try {
    $stmt = $pdo->prepare("SELECT imdb_id, status FROM watchlist WHERE user_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$userID]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('[MAL export] ' . $e->getMessage());
    $entries = [];
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="animehub_watchlist_export.xml"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<myanimelist>' . "\n";

foreach ($entries as $entry) {
    $rawId = (string)($entry['imdb_id'] ?? '');
    if ($rawId === '') continue;

    $status = (string)($entry['status'] ?? 'watch_later');
    $malStatus = $STATUS_MAP[$status] ?? 6;

    // Try to resolve anime info for better XML output
    $title = '';
    $totalEps = 0;
    $score = 0;

    $parsed = catalog_parse_id($rawId);
    $provider = $parsed['provider'] ?? 'local';

    if (in_array($provider, ['anilist', 'reanime', 'jikan', 'anikuro'], true)) {
        $info = catalog_info($rawId);
        if ($info) {
            $title = (string)($info['title'] ?? '');
            $totalEps = (int)max($info['episodes'] ?? 0, $info['aired_episodes'] ?? 0);
            $scoreVal = $info['score'] ?? null;
            if ($scoreVal !== null && is_numeric($scoreVal)) {
                $score = (int)round((float)$scoreVal);
            }
        }
    } else {
        // Local lookup
        try {
            $s = $pdo->prepare("SELECT name AS title, imdb_rating FROM movies WHERE imdb_id = ? LIMIT 1");
            $s->execute([$rawId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $s = $pdo->prepare("SELECT title, imdb_rating FROM shows WHERE imdb_id = ? LIMIT 1");
                $s->execute([$rawId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
            }
            if ($row) {
                $title = (string)($row['title'] ?? '');
                if (!empty($row['imdb_rating']) && is_numeric($row['imdb_rating'])) {
                    $score = (int)round((float)$row['imdb_rating'] * 2); // Convert /10 to /10 scale
                }
            }
        } catch (Throwable $e) {}
    }

    if ($title === '') $title = $rawId;

    // Get progress (episodes watched)
    $watchedEps = 0;
    try {
        $q = $pdo->prepare("SELECT COUNT(DISTINCT episode_number) FROM watch_history WHERE user_id = ? AND anime_slug = ?");
        $q->execute([$userID, $rawId]);
        $watchedEps = (int)$q->fetchColumn();
    } catch (Throwable $e) {}

    echo "<anime>\n";
    echo "  <series_title>" . htmlspecialchars($title) . "</series_title>\n";
    echo "  <series_type>TV</series_type>\n";
    echo "  <series_episodes>" . $totalEps . "</series_episodes>\n";
    echo "  <my_status>" . $malStatus . "</my_status>\n";
    echo "  <my_watched_episodes>" . $watchedEps . "</my_watched_episodes>\n";
    if ($score > 0 && $score <= 10) {
        echo "  <my_score>" . $score . "</my_score>\n";
    }
    echo "  <update_on_import>1</update_on_import>\n";
    echo "</anime>\n";
}

echo "</myanimelist>\n";
