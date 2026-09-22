<?php
/**
 * Movie embed provider — multi-source fallback.
 *
 * Tries each configured provider in order, health-checks the URL,
 * and returns the first working embed or the best candidate.
 */

include_once __DIR__ . '/http.php';

function movie_embed_resolve($tmdbId) {
    $tmdbId = (int)$tmdbId;
    if ($tmdbId <= 0) return null;

    $providers = $GLOBALS['MOVIE_EMBED_PROVIDERS'] ?? [];
    if (!$providers) return null;

    $candidates = [];
    foreach ($providers as $key => $p) {
        $url = str_replace('{tmdb}', $tmdbId, $p['url']);
        $candidates[] = [
            'key'   => $key,
            'label' => $p['label'] ?? $key,
            'url'   => $url,
        ];
    }

    // Try each provider with a quick HEAD check
    foreach ($candidates as $c) {
        $check = api_http($c['url'], ['timeout' => 8, 'method' => 'HEAD', 'label' => 'embed:' . $c['key']]);
        if (is_array($check) && ($check['status'] ?? 0) >= 200 && ($check['status'] ?? 0) < 400) {
            return $c;
        }
    }

    // Return first candidate as fallback even if health check failed
    return !empty($candidates) ? $candidates[0] : null;
}

function movie_embed_all($tmdbId) {
    $tmdbId = (int)$tmdbId;
    if ($tmdbId <= 0) return [];

    $providers = $GLOBALS['MOVIE_EMBED_PROVIDERS'] ?? [];
    $list = [];
    foreach ($providers as $key => $p) {
        $list[] = [
            'key'   => $key,
            'label' => $p['label'] ?? $key,
            'url'   => str_replace('{tmdb}', $tmdbId, $p['url']),
        ];
    }
    return $list;
}
