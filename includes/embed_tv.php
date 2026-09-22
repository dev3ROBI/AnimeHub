<?php
/**
 * TV embed provider — multi-source fallback with season/episode.
 */

include_once __DIR__ . '/http.php';

function tv_embed_resolve($tmdbId, $season, $episode) {
    $tmdbId  = (int)$tmdbId;
    $season  = (int)$season;
    $episode = (int)$episode;
    if ($tmdbId <= 0 || $season <= 0 || $episode <= 0) return null;

    $providers = $GLOBALS['TV_EMBED_PROVIDERS'] ?? [];
    if (!$providers) return null;

    $candidates = [];
    foreach ($providers as $key => $p) {
        $url = str_replace(
            ['{tmdb}', '{season}', '{episode}'],
            [$tmdbId, $season, $episode],
            $p['url']
        );
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

    return !empty($candidates) ? $candidates[0] : null;
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
            'url'   => str_replace(
                ['{tmdb}', '{season}', '{episode}'],
                [$tmdbId, $season, $episode],
                $p['url']
            ),
        ];
    }
    return $list;
}
