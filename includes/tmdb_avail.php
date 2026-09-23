<?php
/**
 * Real availability for one TMDB id — used by the hover preview card.
 *
 * TMDB's list endpoints (trending / discover / search) omit `status`,
 * `next_episode_to_air` and `last_episode_to_air`, so list rows cannot say
 * whether a show is airing or finished. This endpoint does the full detail
 * lookup (tmdb_tv_detail / tmdb_movie_detail, both DB-cached) and returns
 * just the availability fields the hover card needs:
 *
 *   { st: "RELEASING", ae: 19, na: { e: 5, ts: 1790200800 }, e: 2 }
 *
 * Cached at the DB layer by the detail calls, so repeated hovers cost
 * nothing and the first hover for one title is a single provider request.
 */
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/tmdb_movie_api.php';

header('Content-Type: application/json; charset=utf-8');

$raw = trim((string)($_GET['id'] ?? ''));
$parsed = catalog_parse_id($raw);
if (!$parsed || $parsed['provider'] !== 'tmdb') {
    http_response_code(400);
    echo json_encode(['error' => 'tmdb id required (tmdb:movie:N / tmdb:tv:N)']);
    exit;
}

$remote = (string)$parsed['id'];
$item = null;
if (preg_match('/^tv:(\d+)$/', $remote, $m)) {
    $item = tmdb_tv_detail((int)$m[1]);
} elseif (preg_match('/^movie:(\d+)$/', $remote, $m)) {
    $item = tmdb_movie_detail((int)$m[1]);
}

if (!is_array($item)) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$out = ['st' => (string)($item['status'] ?? '')];

$ae = (int)($item['aired_episodes'] ?? 0);
if ($ae > 0) $out['ae'] = $ae;

if (!empty($item['next_airing']['airingAt'])) {
    $ts = (int)$item['next_airing']['airingAt'];
    if ($ts > time()) {
        $out['na'] = [
            'e'  => (int)($item['next_airing']['episode'] ?? 0),
            'ts' => $ts,
        ];
    }
}

// TMDB TV rows count seasons in `episodes` — pass the season count back so
// the hover card can render "2S · 19 EP" once the real data has landed.
if (($item['content_type'] ?? '') === 'tv') {
    $seasons = (int)($item['seasons_count'] ?? $item['episodes'] ?? 0);
    if ($seasons > 0) $out['e'] = $seasons;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
