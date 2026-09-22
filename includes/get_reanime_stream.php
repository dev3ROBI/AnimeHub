<?php
include_once __DIR__ . '/reanime_api.php';

header('Content-Type: application/json');

$link = trim($_GET['link'] ?? '');

if (empty($link)) {
    echo json_encode(['error' => 'Missing link']);
    exit;
}

$data = reanime_stream_from_link($link);
if (!$data) {
    echo json_encode(['error' => 'Failed to decrypt stream']);
    exit;
}

echo json_encode($data);
