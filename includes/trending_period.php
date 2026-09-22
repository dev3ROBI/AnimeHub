<?php
/**
 * Sidebar trending list per period.
 *
 * GET period=day|week|month&limit=10
 * → {"period":"week","html":"<a class=\"kp-trend-item\">…","count":10}
 *
 * The rows are rendered server-side so the swapped-in markup stays identical
 * to the list the page ships with.
 */
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$period = strtolower(trim((string)($_GET['period'] ?? 'day')));
if (!in_array($period, ['day', 'week', 'month'], true)) $period = 'day';

$limit = max(3, min(20, (int)($_GET['limit'] ?? 10)));
$items = catalog_trending_period($period, $limit);

$html  = '';
$shown = 0;
foreach ($items as $item) {
    $shown++;
    $html .= render_trend_item($item, $shown);
}

if ($html === '') {
    $html = '<p class="kp-trend-empty"><i class="fa-solid fa-ghost"></i> Nothing to show right now</p>';
}

echo json_encode([
    'period' => $period,
    'html'   => $html,
    'count'  => $shown,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
