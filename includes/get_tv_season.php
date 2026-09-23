<?php
/**
 * One TV season's episodes, for the watch page's season switcher.
 *
 *   get_tv_season.php?tmdb_id=1399&season=2
 *     -> { season: 2, episodes: [ { n, t, s, a, ax, ad, lk }, … ] }
 *
 * watch.php ships the season being watched inline, so a 20-season series does
 * not pay for 20 TMDB season calls on first paint; clicking another tab asks for
 * just that season here. Rows come from the shared cached tmdb_tv_season(), which
 * also tags unaired episodes (lk + a + ad), so the shape always matches
 * KP.episodes exactly and the client can swap them in as-is.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

// Same gate as get_tv_stream.php: the watch page is the only caller and it is
// already behind a login, so this must not become a free TMDB proxy.
if (!isset($_SESSION['userID'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// db.php gives the shared transport its $pdo — without it api_cache_get/set()
// silently no-op and every season tab click would hit TMDB again.
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/tmdb_movie_api.php';

$tmdbId = (int)($_GET['tmdb_id'] ?? 0);
$season = (int)($_GET['season'] ?? 0);

if ($tmdbId <= 0 || $season < 0 || $season > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid tmdb_id/season']);
    exit;
}

$rows = function_exists('tmdb_tv_season') ? tmdb_tv_season($tmdbId, $season) : null;

$episodes = [];
foreach (($rows ?: []) as $row) {
    $episodes[] = [
        'n'  => (int)($row['number'] ?? 0),
        't'  => (string)($row['title'] ?? ''),
        's'  => (int)($row['season'] ?? $season),
        'a'  => !empty($row['a']) ? (int)$row['a'] : null,
        'ax' => !empty($row['ax']),
        'ad' => !empty($row['ad']),
        'lk' => !empty($row['lk']),
    ];
}

echo json_encode([
    'season'   => $season,
    'episodes' => $episodes,
], JSON_UNESCAPED_UNICODE);
