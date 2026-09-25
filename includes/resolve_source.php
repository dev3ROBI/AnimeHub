<?php
/**
 * AJAX endpoint: turn a supported embed-server URL into a directly playable
 * media URL (hls/mp4) for the ArtPlayer.
 *
 *   GET ./includes/resolve_source.php?url=https://nhdapi.com/movie/550
 *
 * Response:
 *   { ok:true,  result:{ success, type, url, provider, headers,
 *                        subtitles[], quality[], expires_at } }
 *   { ok:false, error:"blocked|unsupported|..." }
 *
 * Security: session required + RESOLVER_ALLOWLIST exact-match host gate, so
 * this can only ever talk to the handful of providers we ship a resolver for.
 * It is not a proxy — only the normalized result ever leaves this script.
 */
session_start();
// Short-lived result cache lives in api_cache. Only pull the DB in when the
// driver exists — without it the resolver still answers, just uncached
// (the player keeps its own per-page cache anyway).
if (class_exists('mysqli')) {
    include_once __DIR__ . '/db.php';
}
include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/resolver/resolver_manager.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function resolve_source_out(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['userID'])) {
    resolve_source_out(['ok' => false, 'error' => 'auth']);
}

if (!defined('RESOLVER_ENABLED') || !RESOLVER_ENABLED) {
    resolve_source_out(['ok' => false, 'error' => 'disabled']);
}

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '' || strlen($url) > 2000) {
    resolve_source_out(['ok' => false, 'error' => 'bad-request']);
}

$result = ResolverManager::resolve($url);

if (!empty($result['success'])) {
    resolve_source_out(['ok' => true, 'result' => $result]);
}

// Internal codes stay server-side; the client shows one generic line.
resolve_source_out(['ok' => false, 'error' => 'unresolved']);
