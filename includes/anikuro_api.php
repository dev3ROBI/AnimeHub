<?php
/**
 * Anikuro API Wrapper — PHP client for the Anikuro anime streaming API
 * Source: https://github.com/aor-rex/anikuro-api
 * Hosted: https://aor-rex-anikuro-api.hf.space
 */

if (!defined('ANIKURO_BASE_URL')) {
    // Change this to your hosted Anikuro API URL, or keep localhost for self-hosted
    define('ANIKURO_BASE_URL', 'http://localhost:7860');
}
if (!defined('ANIKURO_CACHE_TTL')) {
    define('ANIKURO_CACHE_TTL', 3600);
}

// ─── ID helpers (anikuro:{session}) ────────────────────────────────────
function anikuro_id_from_session($session) {
    return 'anikuro:' . (string)$session;
}

function anikuro_session_from_id($id) {
    if (strpos($id, 'anikuro:') !== 0) return null;
    return substr($id, 8);
}

// ─── HTTP helper ───────────────────────────────────────────────────────
function callAnikuroAPI($endpoint, $timeout = 30) {
    $baseUrl = rtrim(ANIKURO_BASE_URL, '/');
    $url = $baseUrl . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[Anikuro] cURL error ($endpoint): " . $curlErr);
        return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[Anikuro] HTTP $httpCode ($endpoint)");
        return null;
    }
    if (!$response) return null;

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("[Anikuro] JSON decode error ($endpoint): " . json_last_error_msg());
        return null;
    }
    return $data;
}

// ─── Search ────────────────────────────────────────────────────────────
function anikuro_search($query, $limit = 20) {
    $q = urlencode($query);
    $data = callAnikuroAPI("/api/anime/search?q=$q&page=1", 15);
    if (!is_array($data)) return null;

    $results = $data['data'] ?? [];
    // Normalize results
    $normalized = [];
    foreach ($results as $item) {
        $session = $item['session'] ?? '';
        if (empty($session)) continue;
        $normalized[] = [
            'session'   => $session,
            'title'     => $item['title'] ?? 'Unknown',
            'poster'    => $item['poster'] ?? '',
            'episodes'  => $item['episodes'] ?? 0,
            'score'     => $item['score'] ?? null,
            'type'      => $item['type'] ?? 'TV',
            'status'    => $item['status'] ?? '',
            'year'      => $item['year'] ?? null,
            'season'    => $item['season'] ?? '',
            'session'   => $session,
        ];
    }
    return ['results' => array_slice($normalized, 0, $limit)];
}

// ─── Currently Airing (home page) ─────────────────────────────────────
function anikuro_airing($page = 1) {
    $data = callAnikuroAPI("/api/anime/airing?page=" . intval($page), 15);
    if (!is_array($data)) return null;

    $items = $data['data'] ?? [];
    $normalized = [];
    foreach ($items as $item) {
        $session = $item['session'] ?? '';
        if (empty($session)) continue;
        $normalized[] = [
            'session'         => $session,
            'title'           => $item['title'] ?? 'Unknown',
            'poster'          => $item['image'] ?? '',
            'episode'         => $item['episode'] ?? null,
            'episode_number'  => $item['episode'] ?? null,
            'fansub'          => $item['fansub'] ?? '',
            'type'            => 'TV',
        ];
    }
    return $normalized;
}

// ─── Anime Info ────────────────────────────────────────────────────────
function anikuro_info($session) {
    if (empty($session)) return null;

    // Check cache
    $cached = anikuro_get_cache($session);
    if (is_array($cached) && !empty($cached['__from_cache'])) {
        return $cached;
    }

    $data = callAnikuroAPI("/api/anime/" . rawurlencode($session), 30);
    if (!is_array($data)) return $cached ?: null;

    // Normalize
    $normalized = [
        'session'       => $session,
        'title'         => $data['title'] ?? 'Unknown',
        'poster'        => $data['image'] ?? '',
        'description'   => $data['synopsis'] ?? '',
        'type'          => $data['type'] ?? 'TV',
        'episodes'      => $data['episodes'] ?? 0,
        'status'        => $data['status'] ?? '',
        'duration'      => $data['duration'] ?? '',
        'aired'         => $data['aired'] ?? '',
        'season'        => $data['season'] ?? '',
        'studio'        => $data['studio'] ?? '',
        'genres'        => $data['genre'] ?? [],
        'themes'        => $data['themes'] ?? [],
        'score'         => null,
        'rating'        => null,
        'anilist_id'    => null,
        'mal_id'        => null,
        'relations'     => $data['relations'] ?? [],
        'recommendations' => $data['recommendations'] ?? [],
        'episodes_list' => [],  // Will be populated separately
    ];

    // Extract IDs
    $ids = $data['ids'] ?? [];
    if (!empty($ids['anilist'])) $normalized['anilist_id'] = intval($ids['anilist']);
    if (!empty($ids['mal'])) $normalized['mal_id'] = $ids['mal'];

    // Score
    if (!empty($data['score']) && is_numeric($data['score'])) {
        $score = floatval($data['score']);
        if ($score > 10) $score = $score / 10.0;
        $normalized['score'] = number_format($score, 1);
        $normalized['rating'] = $normalized['score'];
    }

    // Cache it
    anikuro_set_cache($session, $normalized);

    return $normalized;
}

// ─── Streaming Links ───────────────────────────────────────────────────
function anikuro_stream($session, $episode_session, $include_downloads = false) {
    if (empty($session) || empty($episode_session)) return null;
    $dl = $include_downloads ? 'true' : 'false';
    $data = callAnikuroAPI("/api/anime/" . rawurlencode($session) . "/" . rawurlencode($episode_session) . "?downloads=$dl", 30);
    return is_array($data) ? $data : null;
}

// ─── Episode List (via releases endpoint) ──────────────────────────────
function anikuro_episodes($session, $page = 1, $sort = 'episode_desc') {
    if (empty($session)) return null;
    $data = callAnikuroAPI("/api/anime/" . rawurlencode($session) . "/releases?sort=$sort&page=" . intval($page), 15);
    if (!is_array($data)) return null;

    $episodes = $data['data'] ?? $data;
    if (!is_array($episodes)) return null;

    $normalized = [];
    foreach ($episodes as $ep) {
        $epSession = $ep['session'] ?? '';
        if (empty($epSession)) continue;
        $normalized[] = [
            'number'    => $ep['episode'] ?? $ep['number'] ?? 0,
            'title'     => $ep['title'] ?? '',
            'session'   => $epSession,
            'fansub'    => $ep['fansub'] ?? '',
            'image'     => $ep['snapshot'] ?? $ep['image'] ?? '',
        ];
    }
    return $normalized;
}

// ─── Cache helpers ─────────────────────────────────────────────────────
function anikuro_get_cache($session) {
    global $pdo;
    if (empty($session) || !$pdo) return null;
    try {
        $stmt = $pdo->prepare("SELECT session, title, data_json, updated_at FROM anikuro_cache WHERE session = ? LIMIT 1");
        $stmt->execute([$session]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $updated = strtotime($row['updated_at']);
        if (time() - $updated > ANIKURO_CACHE_TTL) return null;
        $data = json_decode($row['data_json'] ?? '{}', true);
        if (!is_array($data)) $data = [];
        $data['__from_cache'] = true;
        return $data;
    } catch (Exception $e) {
        error_log("[Anikuro] Cache read error: " . $e->getMessage());
        return null;
    }
}

function anikuro_set_cache($session, $data) {
    global $pdo;
    if (empty($session) || !$pdo || !is_array($data)) return false;
    try {
        $title = $data['title'] ?? null;
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("
            INSERT INTO anikuro_cache (session, title, data_json)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                data_json = VALUES(data_json),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$session, $title, $dataJson]);
        return true;
    } catch (Exception $e) {
        error_log("[Anikuro] Cache write error: " . $e->getMessage());
        return false;
    }
}
