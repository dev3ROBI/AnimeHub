<?php
/**
 * TV embed provider — multi-source fallback with season/episode.
 *
 * Templates may use {tmdb}, {imdb}, {season} and {episode}; a provider needing
 * an id we do not have is skipped rather than rendered broken
 * (see includes/embed_url.php).
 */

include_once __DIR__ . '/embed_url.php';

function tv_embed_resolve($tmdbId, $season, $episode, $imdb = '') {
    $tmdbId  = (int)$tmdbId;
    $season  = (int)$season;
    $episode = (int)$episode;
    if ($tmdbId <= 0 || $season <= 0 || $episode <= 0) return null;

    $list = tv_embed_all($tmdbId, $season, $episode, $imdb);
    return $list ? $list[0] : null;
}

function tv_embed_all($tmdbId, $season, $episode, $imdb = '') {
    $tmdbId  = (int)$tmdbId;
    $season  = (int)$season;
    $episode = (int)$episode;
    if ($tmdbId <= 0) return [];

    $providers = $GLOBALS['TV_EMBED_PROVIDERS'] ?? [];
    $vars = [
        'tmdb'    => $tmdbId,
        'imdb'    => trim((string)$imdb),
        'season'  => $season,
        'episode' => $episode,
    ];

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
