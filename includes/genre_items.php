<?php
/**
 * Card-fragment endpoint for the genre browsers.
 *
 * GET g=Action&page=2&sort=popular&limit=24
 * → {"html":"<div class=\"watch-item\">…","has_next":true,"page":2,"genre":"Action"}
 *
 * Used by the home "Browse by Genre" block and by the genre page's infinite
 * scroll, so both show exactly the markup the server would render.
 */
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$genre = catalog_normalize_genre($_GET['g'] ?? '');
$sorts = catalog_sort_options();
$sort  = isset($_GET['sort']) && array_key_exists($_GET['sort'], $sorts) ? $_GET['sort'] : 'popular';
$page  = max(1, min(500, (int)($_GET['page'] ?? 1)));
$limit = max(1, min(48, (int)($_GET['limit'] ?? 24)));

if ($genre === null) {
    echo json_encode(['html' => '', 'has_next' => false, 'page' => 1, 'genre' => null, 'count' => 0]);
    exit;
}

$result = catalog_by_genre($genre, $page, $limit, $sort);
$items  = $result['items'] ?? [];

$html = '';
foreach ($items as $item) {
    $html .= render_anime_card($item, ['show_ep_badge' => true]);
}

echo json_encode([
    'html'     => $html,
    'has_next' => !empty($result['has_next']),
    'page'     => (int)($result['page'] ?? $page),
    'genre'    => $genre,
    'count'    => count($items),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
