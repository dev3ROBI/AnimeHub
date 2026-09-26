<?php
/**
 * Movie embed provider — multi-source fallback.
 *
 * Returns all configured embed URLs. The first one is the primary.
 * No health checks — the iframe handles loading, user can switch servers.
 *
 * Templates may use {tmdb} and {imdb}; a provider needing an id we do not
 * have is skipped rather than rendered broken (see includes/embed_url.php).
 */

include_once __DIR__ . '/embed_url.php';

function movie_embed_resolve($tmdbId, $imdb = '') {
    $tmdbId = (int)$tmdbId;
    if ($tmdbId <= 0) return null;

    $list = movie_embed_all($tmdbId, $imdb);
    return $list ? $list[0] : null;
}

function movie_embed_all($tmdbId, $imdb = '') {
    $tmdbId = (int)$tmdbId;
    if ($tmdbId <= 0) return [];

    $providers = $GLOBALS['MOVIE_EMBED_PROVIDERS'] ?? [];
    $vars = ['tmdb' => $tmdbId, 'imdb' => trim((string)$imdb)];

    $list = [];
    foreach ($providers as $key => $p) {
        $url = embed_fill_url($p['url'] ?? '', $vars);
        if ($url === null) continue;
        $list[] = [
            'key'   => $key,
            'label' => $p['label'] ?? $key,
            'mode'  => 'embed',
            'url'   => $url,
        ];
    }
    return $list;
}
