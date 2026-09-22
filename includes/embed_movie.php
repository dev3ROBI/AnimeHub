<?php
/**
 * Movie embed provider — multi-source fallback.
 *
 * Returns all configured embed URLs. The first one is the primary.
 * No health checks — the iframe handles loading, user can switch servers.
 */

function movie_embed_resolve($tmdbId) {
    $tmdbId = (int)$tmdbId;
    if ($tmdbId <= 0) return null;

    $providers = $GLOBALS['MOVIE_EMBED_PROVIDERS'] ?? [];
    if (!$providers) return null;

    foreach ($providers as $key => $p) {
        return [
            'key'   => $key,
            'label' => $p['label'] ?? $key,
            'mode'  => 'embed',
            'url'   => str_replace('{tmdb}', $tmdbId, $p['url']),
        ];
    }
    return null;
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
            'mode'  => 'embed',
            'url'   => str_replace('{tmdb}', $tmdbId, $p['url']),
        ];
    }
    return $list;
}
