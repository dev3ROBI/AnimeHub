<?php
/**
 * Batch title-logo lookup for the hero slider.
 *
 *   m[]=59193&t[]=Mushoku Tensei…&y[]=2026  →  {"enabled":true,"logos":{"0":"https://…"}}
 *   ids=21,1535                            →  mal-id only (handy for a quick test)
 *   ids=21&debug=1                          →  verbose per-title diagnostics
 *
 * Logos are keyed by request index, and the title is sent as well as the MAL
 * id because TMDB's myanimelist_id mapping does not exist for every
 * season-qualified entry — the endpoint then falls back to a title search.
 */
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/tmdb_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$debug = !empty($_GET['debug']);

// ─── Request → list of items ───────────────────────────────────────────
$items = [];

if (!empty($_GET['m']) || !empty($_GET['t'])) {
    $mals   = array_values((array)($_GET['m'] ?? []));
    $titles = array_values((array)($_GET['t'] ?? []));
    $years  = array_values((array)($_GET['y'] ?? []));
    $total  = max(count($mals), count($titles));

    for ($i = 0; $i < $total; $i++) {
        $items[] = [
            'mal_id' => (int)($mals[$i] ?? 0),
            'title'  => (string)($titles[$i] ?? ''),
            'year'   => (int)($years[$i] ?? 0),
        ];
    }
} else {
    $ids = array_map('intval', explode(',', (string)($_GET['ids'] ?? '')));
    foreach (array_slice(array_filter($ids, fn($id) => $id > 0), 0, 12) as $id) {
        $items[] = ['mal_id' => $id];
    }
}

$items = array_slice($items, 0, 12);

// ─── Credentials ───────────────────────────────────────────────────────
$overrideCount = count($GLOBALS['KITSUPLAY_TITLE_LOGOS'] ?? []);
$tmdbReady     = tmdb_enabled();

if (!$tmdbReady && $overrideCount === 0) {
    echo json_encode([
        'enabled' => false,
        'logos'   => new stdClass(),
        'reason'  => 'No TMDB credential and no KITSUPLAY_TITLE_LOGOS overrides set in config/config.php',
    ]);
    exit;
}

// ─── Lookups ───────────────────────────────────────────────────────────
if ($debug) {
    $results = [];
    foreach ($items as $item) {
        $results[] = tmdb_debug_logo($item['mal_id'], $item['title'], $item['year']);
    }

    echo json_encode([
        'enabled'      => true,
        'tmdb_enabled' => $tmdbReady,
        'credentials'  => [
            'api_key'      => TMDB_API_KEY !== '',
            'access_token' => TMDB_ACCESS_TOKEN !== '',
        ],
        'overrides'    => $overrideCount,
        'results'      => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$logos = [];
foreach ($items as $index => $item) {
    $url = anime_title_logo($item);
    if ($url) $logos[(string)$index] = $url;
}

echo json_encode([
    'enabled'     => true,
    'tmdb_enabled'=> $tmdbReady,
    'logos'       => $logos ?: new stdClass(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
