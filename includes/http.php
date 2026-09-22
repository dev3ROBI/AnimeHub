<?php
/**
 * Shared HTTP + cache primitives used by every API client.
 *
 * Keeps one cURL helper and one DB backed cache so anilist/jikan/reanime
 * don't each re-implement them.
 */

include_once __DIR__ . '/../config/config.php';

/**
 * Perform an HTTP request and decode a JSON response.
 *
 * @param string $url
 * @param array  $options  method, headers, body, timeout, connect_timeout,
 *                         raw (return raw string instead of decoded JSON)
 * @return array|string|null  null on any failure
 */
function api_http($url, array $options = []) {
    $method   = strtoupper($options['method'] ?? 'GET');
    $timeout  = (int)($options['timeout'] ?? 25);
    $connect  = (int)($options['connect_timeout'] ?? 10);
    $raw      = !empty($options['raw']);
    $body     = $options['body'] ?? null;
    $headers  = $options['headers'] ?? [];
    $label    = $options['label'] ?? 'api';

    if (!is_array($headers)) $headers = [];

    $baseHeaders = [
        'Accept: application/json, text/plain, */*',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ];
    foreach ($headers as $k => $v) {
        $baseHeaders[] = is_int($k) ? $v : ($k . ': ' . $v);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $baseHeaders);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        }
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        }
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[$label] cURL error ($url): $curlErr");
        return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[$label] HTTP $httpCode ($url)");
        return null;
    }
    if ($response === false || $response === '') {
        return null;
    }
    if ($raw) return $response;

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("[$label] JSON decode error ($url): " . json_last_error_msg());
        return null;
    }
    return $data;
}

// ─── Cache ─────────────────────────────────────────────────────────────

/** Set to false the first time the cache table turns out to be missing. */
$GLOBALS['__api_cache_available'] = null;

function api_cache_available() {
    global $pdo;
    if ($GLOBALS['__api_cache_available'] !== null) return $GLOBALS['__api_cache_available'];
    if (!$pdo) return $GLOBALS['__api_cache_available'] = false;
    try {
        $pdo->query("SELECT 1 FROM api_cache LIMIT 1");
        return $GLOBALS['__api_cache_available'] = true;
    } catch (Exception $e) {
        $GLOBALS['__api_cache_available'] = false;
        return false;
    }
}

function api_cache_get($key) {
    global $pdo;
    if (!$key || !$pdo || !api_cache_available()) return null;
    try {
        $stmt = $pdo->prepare("SELECT payload, expires_at FROM api_cache WHERE cache_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ((int)$row['expires_at'] < time()) return null;
        $data = json_decode($row['payload'] ?? 'null', true);
        return is_array($data) ? $data : null;
    } catch (Exception $e) {
        error_log('[api_cache] read error: ' . $e->getMessage());
        return null;
    }
}

function api_cache_set($key, $provider, $data, $ttl) {
    global $pdo;
    if (!$key || !$pdo || !is_array($data) || !api_cache_available()) return false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO api_cache (cache_key, provider, payload, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                provider   = VALUES(provider),
                payload    = VALUES(payload),
                expires_at = VALUES(expires_at),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            substr($key, 0, 191),
            substr((string)$provider, 0, 32),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            time() + max(1, (int)$ttl),
        ]);
        return true;
    } catch (Exception $e) {
        error_log('[api_cache] write error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Return a cached value or run $producer and cache its (array) result.
 * A null/false producer result is never cached.
 */
function api_cache_remember($key, $provider, $ttl, callable $producer) {
    $hit = api_cache_get($key);
    if ($hit !== null) return $hit;
    $fresh = $producer();
    if (is_array($fresh) && !empty($fresh)) {
        api_cache_set($key, $provider, $fresh, $ttl);
    }
    return $fresh;
}

/** Stable cache key builder. */
function api_cache_key($provider, $parts) {
    return $provider . ':' . md5(is_array($parts) ? json_encode($parts) : (string)$parts);
}
