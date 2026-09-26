<?php
/**
 * Card-fragment endpoint for the lazy category rails.
 *
 * GET kind=movie|tv|anime&key=kdrama&limit=18
 * → {"ok":true,"key":"kdrama","title":"K-Drama","count":18,"html":"<div class=\"watch-item\">…"}
 *
 * assets/js/category-rails.js calls this once per rail as it scrolls near the
 * viewport, so a browse page with a dozen categories pays for one rail at a
 * time instead of a dozen provider calls on first paint. The markup is exactly
 * what the page would have rendered server-side.
 */
session_start();

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/category_rails.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$kind  = strtolower(trim((string)($_GET['kind'] ?? '')));
$key   = trim((string)($_GET['key'] ?? ''));
$limit = max(1, min(48, (int)($_GET['limit'] ?? KP_RAIL_LIMIT)));

$rail = kp_category_rail($kind, $key);
if ($rail === null) {
    echo json_encode(['ok' => false, 'error' => 'Unknown rail']);
    exit;
}
$rail['kind'] = $kind;

$items = kp_category_items($rail, $limit);

$html = '';
foreach ($items as $item) {
    if (!is_array($item) || empty($item['id'])) continue;
    $html .= render_anime_card($item, ['show_ep_badge' => false]);
}

echo json_encode([
    'ok'    => true,
    'key'   => $rail['key'],
    'kind'  => $kind,
    'title' => $rail['title'],
    'count' => count($items),
    'html'  => $html,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
