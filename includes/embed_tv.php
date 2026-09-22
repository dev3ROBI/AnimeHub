<?php
/**
 * TV embed provider — multi-source fallback with season/episode.
 */

function tv_embed_resolve($tmdbId, $season, $episode) {
    $tmdbId  = (int)$tmdbId;
    $season  = (int)$season;
    $episode = (int)$episode;
    if ($tmdbId <= 0 || $season <= 0 || $episode <= 0) return null;

    $providers = $GLOBALS['TV_EMBED_PROVIDERS'] ?? [];
    if (!$providers) return null;

    foreach ($providers as $key => $p) {
        return [
            'key'   => $key,
            'label' => $p['label'] ?? $key,
            'mode'  => 'embed',
            'url'   => str_replace(
                ['{tmdb}', '{season}', '{episode}'],
                [$tmdbId, $season, $episode],
                $p['url']
            ),
        ];
    }
    return null;
}

function tv_embed_all($tmdbId, $season, $episode) {
    $tmdbId  = (int)$tmdbId;
    $season  = (int)$season;
    $episode = (int)$episode;
    if ($tmdbId <= 0) return [];

    $providers = $GLOBALS['TV_EMBED_PROVIDERS'] ?? [];
    $list = [];
    foreach ($providers as $key => $p) {
        $list[] = [
            'key'   => $key,
            'label' => $p['label'] ?? $key,
            'mode'  => 'embed',
            'url'   => str_replace(
                ['{tmdb}', '{season}', '{episode}'],
                [$tmdbId, $season, $episode],
                $p['url']
            ),
        ];
    }
    return $list;
}
