<?php
/**
 * KitsuPlay central configuration.
 *
 * Every API client (anilist_api.php, jikan_api.php, reanime_api.php,
 * anikuro_api.php) includes this file first, so the constants below always
 * win. Each definition is guarded with `if (!defined(...))` so a caller can
 * override a value before including this file.
 */

// ─── Database ──────────────────────────────────────────────────────────
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'animehub');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// ─── Provider switches ─────────────────────────────────────────────────
// AniList needs no key and no self-hosted server, so it is the primary.
if (!defined('ANILIST_ENABLED'))  define('ANILIST_ENABLED', true);
if (!defined('JIKAN_ENABLED'))    define('JIKAN_ENABLED', true);
if (!defined('REANIME_ENABLED'))  define('REANIME_ENABLED', true);
// Anikuro points at a localhost server that is usually not running.
if (!defined('ANIKURO_ENABLED'))  define('ANIKURO_ENABLED', false);

// ─── TMDB (optional — title logos) ─────────────────────────────────────
// AniList has no title-logo artwork, so the hero slider asks TMDB for the
// transparent logo PNG/SVG. Without a key (or with TMDB_DISABLED) the slider
// simply keeps its styled text title — nothing else changes.
// Free key: https://www.themoviedb.org/settings/api
if (!defined('TMDB_ENABLED'))       define('TMDB_ENABLED', true);
// v3 api key (34 chars). Paste it here — not inside includes/tmdb_api.php.
if (!defined('TMDB_API_KEY'))       define('TMDB_API_KEY', '4343034868a20a38c503cc0d3be89ec0');
// v4 read access token (optional). When both are set the bearer token wins.
if (!defined('TMDB_ACCESS_TOKEN'))  define('TMDB_ACCESS_TOKEN', 'eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiI0MzQzMDM0ODY4YTIwYTM4YzUwM2NjMGQzYmU4OWVjMCIsIm5iZiI6MTc5MDAwNjc2OS4wNjYsInN1YiI6IjZhYjE1NWYxZWYyZDJjMDA2N2Y2Njg5NiIsInNjb3BlcyI6WyJhcGlfcmVhZCJdLCJ2ZXJzaW9uIjoxfQ.311fzT2nMQr6tUATSJlIMtmt_yZ3JrTO0fKhv6ZYbS4');
if (!defined('TMDB_BASE_URL'))      define('TMDB_BASE_URL', 'https://api.themoviedb.org/3');
if (!defined('TMDB_IMAGE_BASE'))    define('TMDB_IMAGE_BASE', 'https://image.tmdb.org/t/p/w500');
if (!defined('CACHE_TTL_LOGO'))     define('CACHE_TTL_LOGO', 604800); // 7 days

/**
 * Hand-picked title logos, used before (or instead of) TMDB.
 *
 * Keys:  'mal:{malId}'  or  'title:{lowercase title}'
 * Value: any image URL — TMDB's CDN works: https://image.tmdb.org/t/p/w500/{file}.png
 *
 * Only needed when TMDB has no logo (or no API key is set).
 */
if (!isset($GLOBALS['KITSUPLAY_TITLE_LOGOS'])) {
    $GLOBALS['KITSUPLAY_TITLE_LOGOS'] = [
        // 'mal:59193' => 'https://image.tmdb.org/t/p/w500/jJhNy9fEX6I7y4nCOdcMTs6pBDk.png',
    ];
}

// ─── Metadata base URLs ────────────────────────────────────────────────
if (!defined('ANILIST_BASE_URL')) define('ANILIST_BASE_URL', 'https://graphql.anilist.co');
if (!defined('JIKAN_BASE_URL'))   define('JIKAN_BASE_URL', 'https://api.jikan.moe/v4');
// Public reanime API. Metadata routes work; /watch, /servers and /stream are
// auth gated upstream, which is why streaming uses the scraper below.
if (!defined('REANIME_BASE_URL')) define('REANIME_BASE_URL', 'https://api.reanime.to/api/v1');
// Self-hosted scraper from ReAnime.to-API/ (uvicorn reanime:app --port 8000).
if (!defined('REANIME_SCRAPER_URL')) define('REANIME_SCRAPER_URL', 'http://localhost:8000');
if (!defined('ANIKURO_BASE_URL')) define('ANIKURO_BASE_URL', 'http://localhost:7860');

// ─── Catalog preference order ──────────────────────────────────────────
// First provider that answers wins. Used by includes/catalog.php.
if (!defined('CATALOG_ORDER')) define('CATALOG_ORDER', 'anilist,reanime,jikan');

// ─── Cache TTLs (seconds) ──────────────────────────────────────────────
if (!defined('CACHE_TTL_LIST'))    define('CACHE_TTL_LIST', 900);      // home / trending / search
if (!defined('CACHE_TTL_INFO'))    define('CACHE_TTL_INFO', 21600);    // 6h  anime detail
if (!defined('CACHE_TTL_SCHEDULE')) define('CACHE_TTL_SCHEDULE', 3600);
if (!defined('CACHE_TTL_STREAM'))  define('CACHE_TTL_STREAM', 120);    // stream tokens rotate
if (!defined('CACHE_TTL_HEALTH'))  define('CACHE_TTL_HEALTH', 60);

// ─── TMDB catalog cache (movies / TV) ────────────────────────────────
if (!defined('CACHE_TTL_TMDB_LIST'))  define('CACHE_TTL_TMDB_LIST', 3600);  // 1h
if (!defined('CACHE_TTL_TMDB_INFO'))  define('CACHE_TTL_TMDB_INFO', 21600); // 6h

// Keep the legacy constants the old wrappers used.
if (!defined('REANIME_CACHE_TTL')) define('REANIME_CACHE_TTL', CACHE_TTL_INFO);
if (!defined('ANIKURO_CACHE_TTL')) define('ANIKURO_CACHE_TTL', CACHE_TTL_INFO);

// ─── Stream resolver ───────────────────────────────────────────────────
// Try the self-hosted scraper first (real m3u8 + subtitles), then fall back
// to embed players.
if (!defined('STREAM_TRY_SCRAPER')) define('STREAM_TRY_SCRAPER', true);
if (!defined('STREAM_TRY_EMBEDS'))  define('STREAM_TRY_EMBEDS', true);
if (!defined('STREAM_SCRAPER_TIMEOUT')) define('STREAM_SCRAPER_TIMEOUT', 45);

/**
 * Embed players used as the streaming fallback, in priority order.
 *
 * Placeholders available in `url`:
 *   {anilist}  AniList media id
 *   {mal}      MyAnimeList id
 *   {ep}       episode number
 *   {lang}     "sub" or "dub"
 *
 * Add, remove or reorder entries here — nothing else needs to change.
 *
 * MegaPlay is first because it is the only one verified to actually serve a
 * player (it returns a JW Player page with data-id/data-realid). The others
 * are kept as fallbacks so playback survives an outage.
 *
 * `primary` marks the provider used for the automatic first pick; the rest
 * still show up as switchable server chips in the player.
 */
$GLOBALS['KITSUPLAY_EMBED_PROVIDERS'] = [
    'megaplay' => [
        'label'   => 'MegaPlay',
        'url'     => 'https://megaplay.buzz/stream/ani/{anilist}/{ep}/{lang}',
        'lang'    => true,
        'primary' => true,
    ],
    'anixo' => [
        'label'   => 'AniXo',
        'url'     => 'https://anixo.buzz/embed/ani/{anilist}/{ep}/{lang}',
        'lang'    => true,
    ],
    'vidplus' => [
        'label'   => 'VidPlus',
        'url'     => 'https://player.vidplus.to/embed/anime/{anilist}/{ep}?dub={dub}',
        'lang'    => true,
    ],
    'megavid' => [
        'label'   => 'Megavid',
        'url'     => 'https://megavid.buzz/embed/ani/{anilist}/{ep}/{lang}',
        'lang'    => true,
    ],
    'anilink' => [
        'label'   => 'AniLink',
        'url'     => 'https://anilink.cc/watch/{anilist}/{ep}?variant={lang}',
        'lang'    => true,
    ],

    /**
     * Flixcloud.
     *
     * Flixcloud links are per-token, not per-anime, so there is no id pattern
     * to build a URL from — its servers arrive through the self-hosted scraper
     * (ReAnime.to-API) and includes/stream.php names them "Flixcloud"
     * automatically. This entry only exists so a direct embed pattern can be
     * dropped in later: fill `url` in and the chip appears on every episode
     * like the providers above. While `url` is empty nothing is rendered.
     */
    'flixcloud' => [
        'label'   => 'Flixcloud',
        'url'     => '',
        'lang'    => true,
    ],
];

if (!function_exists('embed_providers')) {
    function embed_providers(): array {
        return $GLOBALS['KITSUPLAY_EMBED_PROVIDERS'] ?? [];
    }
}

if (!function_exists('catalog_order')) {
    function catalog_order(): array {
        $order = array_filter(array_map('trim', explode(',', CATALOG_ORDER)));
        $enabled = [
            'anilist' => ANILIST_ENABLED,
            'reanime' => REANIME_ENABLED,
            'jikan'   => JIKAN_ENABLED,
            'anikuro' => ANIKURO_ENABLED,
        ];
        $out = [];
        foreach ($order as $p) {
            if ($enabled[$p] ?? true) $out[] = $p;
        }
        return $out ?: ['anilist'];
    }
}

// ─── Movie embed providers (fallback chain) ──────────────────────────
if (!isset($GLOBALS['MOVIE_EMBED_PROVIDERS'])) {
    $GLOBALS['MOVIE_EMBED_PROVIDERS'] = [
        // === Priority: NHD first ===
        'nhdapi'       => ['label' => 'NHD',        'url' => 'https://nhdapi.com/movie/{tmdb}'],
        // === Then VidFast/VidLink ===
        'vidfast'      => ['label' => 'VidFast',    'url' => 'https://vidfast.pro/movie/{tmdb}?autoPlay=true'],
        'vidlink'      => ['label' => 'VidLink',    'url' => 'https://vidlink.pro/movie/{tmdb}'],
        // === Then VidSrc ===
        'vidsrc-pm'    => ['label' => 'VidSrc (pm)','url' => 'https://vidsrc.pm/embed/movie/{tmdb}'],
        // Current VidSrc mirrors — same pair the TV list uses (see the comment there).
        'vidsrc-buzz'  => ['label' => 'VidSrc (buzz)','url' => 'https://vidsrc.buzz/embed/movie/{tmdb}'],
        'videm'        => ['label' => 'Videm',        'url' => 'https://videm.xyz/embed/movie/{tmdb}'],
        // === Fallback ===
        'vidcore-org'  => ['label' => 'VidCore',    'url' => 'https://vidcore.org/embed/movie/{tmdb}'],
        '2embed-skin'  => ['label' => '2Embed',     'url' => 'https://www.2embed.skin/embed/{tmdb}'],
        '2embed-cc'    => ['label' => '2Embed (cc)','url' => 'https://www.2embed.cc/embed/{tmdb}'],
    ];
}

// ─── TV embed providers (fallback chain) ─────────────────────────────
if (!isset($GLOBALS['TV_EMBED_PROVIDERS'])) {
    $GLOBALS['TV_EMBED_PROVIDERS'] = [
        // === Priority: NHD first ===
        'nhdapi'       => ['label' => 'NHD',        'url' => 'https://nhdapi.com/tv/{tmdb}/{season}/{episode}'],
        // === Then VidFast/VidLink ===
        'vidfast'      => ['label' => 'VidFast',    'url' => 'https://vidfast.pro/tv/{tmdb}/{season}/{episode}?autoPlay=true'],
        'vidlink'      => ['label' => 'VidLink',    'url' => 'https://vidlink.pro/tv/{tmdb}/{season}/{episode}'],
        // === Then VidSrc ===
        'vidsrc-pm'    => ['label' => 'VidSrc (pm)','url' => 'https://vidsrc.pm/embed/tv/{tmdb}/{season}/{episode}'],
        /*
         * Current VidSrc mirrors. Both answer with the resolved episode in
         * their SSR payload, so they are the reliable pair when a show's
         * season/episode map is missing from the older mirrors.
         * (2Embed's own server list for a TV episode offers exactly these.)
         */
        'vidsrc-buzz'  => ['label' => 'VidSrc (buzz)','url' => 'https://vidsrc.buzz/embed/tv/{tmdb}/{season}/{episode}'],
        'videm'        => ['label' => 'Videm',        'url' => 'https://videm.xyz/embed/tv/{tmdb}/{season}/{episode}'],
        // === Fallback ===
        'vidcore-org'  => ['label' => 'VidCore',    'url' => 'https://vidcore.org/embed/tv/{tmdb}/{season}/{episode}'],
        '2embed-skin'  => ['label' => '2Embed',     'url' => 'https://www.2embed.skin/embedtv/{tmdb}&s={season}&e={episode}'],
        '2embed-cc'    => ['label' => '2Embed (cc)','url' => 'https://www.2embed.cc/embedtv/{tmdb}&s={season}&e={episode}'],
    ];
}
