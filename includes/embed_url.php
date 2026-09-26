<?php
/**
 * Embed-URL templating shared by the movie and TV embed lists.
 *
 * Provider templates may use {tmdb}, {imdb}, {season} and {episode}. A
 * template that needs a value we do not have for this request (an aggregator
 * keyed on {imdb} for a title whose IMDb id is unknown) is *skipped* instead
 * of being rendered with a literal "{imdb}" in the URL — that used to be a
 * broken iframe the viewer had to click past.
 */

if (!function_exists('embed_fill_url')) {
    /**
     * @param string $template provider URL with {placeholders}
     * @param array  $vars     e.g. ['tmdb'=>550,'imdb'=>'tt0137523','season'=>1,'episode'=>2]
     * @return string|null     null = provider cannot serve this request
     */
    function embed_fill_url($template, array $vars) {
        $template = trim((string)$template);
        if ($template === '') return null;

        $map = [
            '{tmdb}'    => $vars['tmdb']    ?? '',
            '{imdb}'    => $vars['imdb']    ?? '',
            '{season}'  => $vars['season']  ?? '',
            '{episode}' => $vars['episode'] ?? '',
        ];

        foreach ($map as $tag => $value) {
            if (strpos($template, $tag) === false) continue;
            $value = trim((string)$value);
            if ($value === '') return null;                 // placeholder with nothing to fill it
            if ($tag === '{tmdb}' && (int)$value <= 0) return null;
        }

        $url = str_replace(array_keys($map), array_values($map), $template);

        // Any leftover {token} means the provider expects something we do not
        // pass at all — do not emit a half-built URL.
        if (preg_match('/\{[a-z0-9_]+\}/i', $url)) return null;

        return preg_match('#^https?://#i', $url) ? $url : null;
    }
}

if (!function_exists('embed_merge_aggregators')) {
    /**
     * Insert globally configured aggregator providers into an embed list.
     *
     * They land right after the custom-player entries (the block before
     * $insert_before) because an aggregator that actually carries the dubbed
     * audio we want should beat the generic embed mirrors, but must never
     * outrank a source our own ArtPlayer can extract directly.
     *
     * @param array  $providers     existing ['key' => ['label'=>…,'url'=>…]]
     * @param array  $aggs          slug => ['label'=>…, 'url'=>…, 'enabled'=>bool]
     * @param string $insert_before key to insert in front of (missing = append)
     * @return array
     */
    function embed_merge_aggregators(array $providers, array $aggs, $insert_before) {
        $extra = [];
        foreach ($aggs as $slug => $a) {
            if (!is_array($a)) continue;
            if (array_key_exists('enabled', $a) && !$a['enabled']) continue;
            $url = trim((string)($a['url'] ?? ''));
            if ($url === '' || strpos($url, 'http') !== 0) continue;
            /* A self-hosted instance that is not deployed yet would otherwise
             * become a dead chip in every viewer's server list. */
            if (!empty($a['require_host']) && stripos($url, (string)$a['require_host']) === false) continue;
            $extra[(string)$slug] = ['label' => (string)($a['label'] ?? $slug), 'url' => $url];
        }
        if (!$extra) return $providers;

        $out  = [];
        $done = false;
        foreach ($providers as $key => $p) {
            if (!$done && $key === $insert_before) {
                foreach ($extra as $k => $v) $out[$k] = $v;
                $done = true;
            }
            $out[$key] = $p;
        }
        if (!$done) {
            foreach ($extra as $k => $v) $out[$k] = $v;
        }
        return $out;
    }
}
