<?php
/**
 * KitsuPlay central configuration.
 *
 * Every API client (anilist_api.php, jikan_api.php, reanime_api.php,
 * anikuro_api.php) includes this file first, so the constants below always
 * win. Each definition is guarded with `if (!defined(...))` so a caller can
 * override a value before including this file.
 *
 * Secrets (TMDB keys, DB passwords, …) belong in config/config.local.php,
 * which is gitignored and loaded here first — never put them in this file.
 */
$kp_local_config = __DIR__ . '/config.local.php';
if (is_file($kp_local_config)) {
    require_once $kp_local_config;
}
unset($kp_local_config);

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
//
// Real keys live in config/config.local.php (gitignored) or the server
// environment — the defaults here are empty so nothing secret is committed.
if (!defined('TMDB_ENABLED'))       define('TMDB_ENABLED', true);
// v3 api key (34 chars).
if (!defined('TMDB_API_KEY'))       define('TMDB_API_KEY', getenv('TMDB_API_KEY') ?: '');
// v4 read access token (optional). When both are set the bearer token wins.
if (!defined('TMDB_ACCESS_TOKEN'))  define('TMDB_ACCESS_TOKEN', getenv('TMDB_ACCESS_TOKEN') ?: '');
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

// ─── Embed-source resolver (Level 1) ───────────────────────────────────
// Turn a supported embed-server URL into a directly playable media URL
// (hls/mp4) that the ArtPlayer can run natively. Unsupported or blocked
// providers keep the existing iframe fallback — nothing is proxied.
// Resolver walks a provider's whole server list until one verifies, so the
// budget covers several attempts — the client waits RESOLVER_TIMEOUT + 4s.
if (!defined('RESOLVER_ENABLED'))  define('RESOLVER_ENABLED', true);
if (!defined('RESOLVER_TIMEOUT'))  define('RESOLVER_TIMEOUT', 12);   // seconds, per resolve()
if (!defined('RESOLVER_CACHE_TTL')) define('RESOLVER_CACHE_TTL', 60);
if (!defined('RESOLVER_MAX_ATTEMPTS')) define('RESOLVER_MAX_ATTEMPTS', 3); // Level-1 cap

/**
 * Hosts the resolver endpoint is allowed to touch (exact match, https only).
 * Anything not listed here is rejected before a single byte leaves the box,
 * so the endpoint can never be used as a general-purpose proxy.
 *
 * vidzen.fun + movish.to sit here because they are the JSON backends the
 * vidcore.org player itself queries (VidCore's resolver only ever calls the
 * provider's own APIs) — and the client mirrors this list to decide which
 * embed chips may attempt a resolve.
 *
 * zokoanime.video is NHD's own upstream for anime: its /stream/ani page
 * carries the decoded player config (the same m3u8 NHD's API returns) and is
 * how the Auto HD chip serves DUB — NHD's extraction API only ever answers
 * with the /sub sibling.
 *
 * megaplay.buzz is the embed page whose own player calls getSources; its
 * resolver decrypts the payload (the AES key ships in their e1-player.js)
 * and hands the client a signed URL on includes/megaplay_relay.php instead
 * of the raw CDN link — the media CDN only answers with a megaplay Referer,
 * which a browser on this origin can never send. The relay re-verifies the
 * HMAC + its own CDN host/path allowlist on every request.
 *
 * AniXo was probed and deliberately left out: its m3u8 relay answers only
 * with Referer: anixo.buzz, so a URL extracted here would 403 in the
 * browser — that chip keeps its iframe (same for the CF-blocked VidPlus
 * and the obfuscated VidSrc/VidFast/VidLink/2Embed mirrors).
 */
if (!isset($GLOBALS['RESOLVER_ALLOWLIST'])) {
    $GLOBALS['RESOLVER_ALLOWLIST'] = [
        'nhdapi.com'      => 'nhdapi',
        'vidcore.org'     => 'vidcore',
        'vidzen.fun'      => 'vidcore',
        'movish.to'       => 'vidcore',
        'zokoanime.video' => 'zokoanime',
        'megaplay.buzz'   => 'megaplay',
    ];
}

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
    // Megavid (megavid.buzz) removed — the host answers 404, the chip would
    // only ever render a broken iframe.
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

// ─── 8Stream provider (native PHP) ────────────────────────────────────
// A movie/series source keyed by IMDb id. Its player page ships a signed
// `file` + `key`; fetching them yields a rotating HLS host whose master
// playlist only answers with the player's own Referer/Origin *and* a token
// bound to the requesting IP — so a browser on our origin can never play it
// directly and the bytes are proxied back through
// includes/eightstream_relay.php (same arrangement as MegaPlay).
if (!defined('EIGHTSTREAM_ENABLED'))    define('EIGHTSTREAM_ENABLED', true);
if (!defined('EIGHTSTREAM_TIMEOUT'))    define('EIGHTSTREAM_TIMEOUT', 8);    // seconds per upstream hop
if (!defined('EIGHTSTREAM_CACHE_TTL'))  define('EIGHTSTREAM_CACHE_TTL', 1800); // resolved m3u8 (seconds)
if (!defined('EIGHTSTREAM_VERIFY'))     define('EIGHTSTREAM_VERIFY', true);   // Range-probe the media URL first

/**
 * Audio-track order when a request does not name a language (the normal
 * case: `sub`/`dub`/empty). 8Stream serves every audio as its *own* HLS
 * stream, so the first name here that exists wins — putting "Hindi" first
 * makes dubbed titles open in Hindi for the Indian audience, English stays
 * the fallback. A request that names a language still overrides this list.
 */
if (!defined('EIGHTSTREAM_AUDIO_PREF')) {
    define('EIGHTSTREAM_AUDIO_PREF', 'English,Hindi,Bengali,Tamil,Telugu');
}

/**
 * Base sites tried in order. Each one serves the rotating player hostname via
 * `const AwsIndStreamDomain = '…'`; the first that answers wins.
 */
if (!isset($GLOBALS['EIGHTSTREAM_BASE_URLS'])) {
    $GLOBALS['EIGHTSTREAM_BASE_URLS'] = [
        'https://allmovieland.link',
        'https://allmovieland.fun',
        'https://allmovieland.com',
    ];
}

/**
 * Player domains probed directly (and as extra candidates if every base is
 * down). Tried *after* whatever the bases hand back, so a live base wins.
 */
if (!isset($GLOBALS['EIGHTSTREAM_PLAYER_URLS'])) {
    $GLOBALS['EIGHTSTREAM_PLAYER_URLS'] = [
        'https://slast430did.com',
    ];
}

/**
 * Relay request headers. The media CDN demands the provider's own origin as
 * Referer/Origin; the relay attaches them server-side. Read from config.local.php
 * (gitignored) if you need to override the learned referer.
 */
if (!defined('EIGHTSTREAM_RELAY_TTL'))  define('EIGHTSTREAM_RELAY_TTL', 21600); // 6h

// ─── Online subtitles (auto English CC) ───────────────────────────────
// When a source ships no caption track of its own, the server looks the title
// up on the OpenSubtitles v3 addon Stremio itself uses
// (opensubtitles-v3.strem.io — still keyless, unlike Wyzie/OpenSubtitles REST)
// and hands our own player a *signed* URL on includes/subtitle_proxy.php. The
// proxy re-verifies the HMAC, fetches the file from the caption CDN and serves
// it as WebVTT, so nothing depends on that CDN's CORS headers.
//
// Nothing is fetched for a source that already carries captions; the settings
// below only govern the fallback.
if (!defined('SUBTITLES_AUTO_ENABLED')) define('SUBTITLES_AUTO_ENABLED', true);
if (!defined('SUBTITLES_TIMEOUT'))      define('SUBTITLES_TIMEOUT', 6);      // seconds per lookup
if (!defined('SUBTITLES_CACHE_TTL'))    define('SUBTITLES_CACHE_TTL', 86400); // one day (misses included)
if (!defined('SUBTITLES_RELAY_TTL'))    define('SUBTITLES_RELAY_TTL', 21600); // signed proxy link
if (!defined('SUBTITLES_MAX'))          define('SUBTITLES_MAX', 3);          // tracks offered in the CC row

/** Preference order, ISO-639-2 (comma separated). English first; the other
 *  languages are only used when the preferred one has nothing.
 *
 *  Chinese is listed too because C-dramas ship their own track far more often
 *  than an English one — with both in the list the CC row offers English *and*
 *  Chinese instead of silently settling for whichever exists. */
if (!defined('SUBTITLES_LANGS'))        define('SUBTITLES_LANGS', 'eng,zho');

/** Lookup base — point at a mirror if the addon ever moves. */
if (!defined('SUBTITLES_API_BASE'))     define('SUBTITLES_API_BASE', 'https://opensubtitles-v3.strem.io');

// ─── Chinese-platform extractor (third-party, keyed) ─────────────────
// iQIYI / Tencent-WeTV / Youku / MGTV / Sohu publish no stream API: their
// playurls are signed per request, mostly Widevine-protected and region-locked
// to CN, so there is nothing to scrape reliably from this server. The workable
// route is a keyed extractor service, which is what this block points at.
//
// The client (includes/cn_extract_api.php) speaks TikHub's documented Bilibili
// endpoints — the one Chinese pair that returns *both* a playurl and the
// platform's own CC tracks (`fetch_video_playurl` + `fetch_video_subtitle`).
// It stays completely inert while CNEXTRACT_KEY is empty, so a half-set-up
// install cannot break a page.
//
// Setup: put the key in config.local.php (never in git):
//     define('CNEXTRACT_KEY', '…');
// then flip CNEXTRACT_ENABLED to true.
if (!defined('CNEXTRACT_ENABLED'))   define('CNEXTRACT_ENABLED', false);
if (!defined('CNEXTRACT_KEY'))       define('CNEXTRACT_KEY', '');
if (!defined('CNEXTRACT_BASE_URL'))  define('CNEXTRACT_BASE_URL', 'https://api.tikhub.io');
if (!defined('CNEXTRACT_TIMEOUT'))   define('CNEXTRACT_TIMEOUT', 12);    // seconds per call
if (!defined('CNEXTRACT_CACHE_TTL')) define('CNEXTRACT_CACHE_TTL', 1800);// playurl(cached shorter below)
if (!defined('CNEXTRACT_RELAY_TTL')) define('CNEXTRACT_RELAY_TTL', 21600);// signed subtitle link

/** Preferred caption languages for platform CC, ISO-639-2, in order. Chinese
 *  first — it is the platform's own track and the one a C-drama needs most. */
if (!defined('CNEXTRACT_LANGS'))     define('CNEXTRACT_LANGS', 'zho,eng');

// ─── ToonStream (Hindi / Indian dubs, plus the sub cuts) ──────────────
// Title-keyed scrape of ToonStream's Next.js site: JSON search
// (/search/all?q=) → /series|movies/{slug} → /episode/{slug}-{S}x{E}/ →
// the episode page's server cards (Ruby / blakite / emturbovid / …) opened
// until direct HLS/mp4 URLs fall out (no DRM, no login). Language markers
// in the slug ("hindi", "tamil", "muse", "sony-yay" …) tag each server with
// its audio ([DUB · Hindi] / [Multi Audio]); BOTH cuts of the title resolve
// in one pass so the watch page can list every server with its language.
//
// Mirrors rotate often (.dad, .day, .in, .shop …); the base list is tried
// in order, exactly like the 8Stream one below.
if (!defined('TOONSTREAM_ENABLED'))    define('TOONSTREAM_ENABLED', true);
if (!defined('TOONSTREAM_TIMEOUT'))    define('TOONSTREAM_TIMEOUT', 6);      // seconds per upstream hop
if (!defined('TOONSTREAM_PAGE_TTL'))   define('TOONSTREAM_PAGE_TTL', 300);   // episode/series page (seconds)
if (!defined('TOONSTREAM_SEARCH_TTL')) define('TOONSTREAM_SEARCH_TTL', 21600);
if (!defined('TOONSTREAM_MAX_EMBEDS')) define('TOONSTREAM_MAX_EMBEDS', 7);   // embed attempts across both cuts
if (!defined('TOONSTREAM_MAX_SERVERS')) define('TOONSTREAM_MAX_SERVERS', 5); // direct servers kept per episode
if (!defined('TOONSTREAM_BUDGET'))     define('TOONSTREAM_BUDGET', 12);      // seconds for the whole resolve
if (!isset($GLOBALS['TOONSTREAM_BASE_URLS'])) {
    // Mirrors rotate — 2026-09 landscape: .us is the only one serving the
    // real Next.js site (/series/… + /search/all JSON); .vip and .dad 301
    // to it; .day is dead, .in parked, .shop runs a different site gen
    // (/watch/{slug}-episode-{N}/) our parsers don't speak.
    $GLOBALS['TOONSTREAM_BASE_URLS'] = [
        'https://toonstream.us',
        'https://toonstream.vip',
        'https://toonstream.dad',
    ];
}

// ─── FlixHQ (flixhq.vc) — servers for movies & TV ─────────────────────
// Title-keyed scrape for the CUSTOM player: /search?keyword= → a
// /watch-movie|/watch-series/{slug} page → its data-token → POST
// /ajax/ajax.php (players= for movies, players_show= for episodes) →
// the server list (FlixHQ / Vidmoly / Videasy) → the embed page's
// plaintext sources config → direct HLS + the subget= caption JSON
// (multi-language .vtt tracks). Language: English audio on every
// server (chips read [English]); no dub concept on this site.
if (!defined('FLIXHQ_ENABLED'))    define('FLIXHQ_ENABLED', true);
if (!defined('FLIXHQ_TIMEOUT'))    define('FLIXHQ_TIMEOUT', 10);     // seconds per upstream hop
if (!defined('FLIXHQ_PAGE_TTL'))   define('FLIXHQ_PAGE_TTL', 300);   // watch/episode page (seconds)
if (!defined('FLIXHQ_SEARCH_TTL')) define('FLIXHQ_SEARCH_TTL', 21600);
if (!defined('FLIXHQ_BUDGET'))     define('FLIXHQ_BUDGET', 12);      // seconds for the whole lookup
if (!isset($GLOBALS['FLIXHQ_BASE_URLS'])) {
    $GLOBALS['FLIXHQ_BASE_URLS'] = [
        'https://flixhq.vc',
    ];
}

// ─── Movie embed providers (fallback chain) ──────────────────────────
if (!isset($GLOBALS['MOVIE_EMBED_PROVIDERS'])) {
    $GLOBALS['MOVIE_EMBED_PROVIDERS'] = [
        /*
         * Priority: VidCore first. Its backends (vidzen.fun / movish.to)
         * answer in ~1.5s and have not missed once, while nhdapi's edge
         * regularly burns 10s+ on timeouts before failing — putting VidCore
         * first is what makes Auto HD start playing quickly. nhdapi stays
         * as the second custom-player source (chip label "NHD": the first
         * custom chip is always relabelled "Auto HD").
         */
        'vidcore-org'  => ['label' => 'VidCore',    'url' => 'https://vidcore.org/embed/movie/{tmdb}'],
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
        '2embed-skin'  => ['label' => '2Embed',     'url' => 'https://www.2embed.skin/embed/{tmdb}'],
        '2embed-cc'    => ['label' => '2Embed (cc)','url' => 'https://www.2embed.cc/embed/{tmdb}'],
    ];
}

// ─── TV embed providers (fallback chain) ─────────────────────────────
if (!isset($GLOBALS['TV_EMBED_PROVIDERS'])) {
    $GLOBALS['TV_EMBED_PROVIDERS'] = [
        // VidCore first for the same reason as the movie list (see there).
        'vidcore-org'  => ['label' => 'VidCore',    'url' => 'https://vidcore.org/embed/tv/{tmdb}/{season}/{episode}'],
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
        '2embed-skin'  => ['label' => '2Embed',     'url' => 'https://www.2embed.skin/embedtv/{tmdb}&s={season}&e={episode}'],
        '2embed-cc'    => ['label' => '2Embed (cc)','url' => 'https://www.2embed.cc/embedtv/{tmdb}&s={season}&e={episode}'],
    ];
}

// ─── Dubbed-content aggregators (optional, off until a URL is filled in) ─
//
// A slot for an *aggregator you run or trust* that carries the dubbed audio
// the mainstream mirrors lack (Hindi/Tamil/Telugu). Two things it is not:
//
//  * Unlike VidCore/NHD these are plain iframes, so they show up under
//    "More servers" — they can never outrank a source our own ArtPlayer
//    extracts (8Stream / the Chinese extractor).
//  * A public aggregator needs an instance URL. A self-hosted one
//    (e.g. Inside4ndroid's TMDB-Embed-API, which is Node + an admin panel)
//    only works once *you* have deployed it and put that host here.
//
// Fill in `url` and the entry joins both movie and TV lists automatically,
// right after the custom-player pair. `tv` overrides the TV template when the
// aggregator uses a different path. Templates accept {tmdb} {imdb} {season}
// {episode} — an entry whose template needs an id we do not have for a title
// (say {imdb} on a title with no IMDb id) is skipped for that title instead of
// loading a broken frame.
if (!isset($GLOBALS['EMBED_AGGREGATORS'])) {
    $GLOBALS['EMBED_AGGREGATORS'] = [
        /*
         * Self-hosted example — leave the URL empty until your instance is up:
         *
         * 'tmdb-embed-api' => [
         *     'label' => 'Dubbed',
         *     'url'   => 'https://YOUR-HOST/embed/movie/{tmdb}',
         *     'tv'    => 'https://YOUR-HOST/embed/tv/{tmdb}/{season}/{episode}',
         * ],
         *
         * A dub-first mirror that keys on IMDb instead of TMDB:
         *
         * 'dub-mirror' => [
         *     'label' => 'Hindi Dub',
         *     'url'   => 'https://your-dub-host/embed/{imdb}',
         *     'tv'    => 'https://your-dub-host/embed/{imdb}/{season}/{episode}',
         * ],
         */
    ];
}

// Merge the aggregators in (a no-op while the block above is empty).
if ($GLOBALS['EMBED_AGGREGATORS'] && !empty($GLOBALS['MOVIE_EMBED_PROVIDERS'])) {
    include_once __DIR__ . '/../includes/embed_url.php';

    $movieAggs = [];
    $tvAggs    = [];
    foreach ($GLOBALS['EMBED_AGGREGATORS'] as $slug => $agg) {
        if (!is_array($agg)) continue;
        $movieAggs[$slug] = $agg;
        if (!empty($agg['tv'])) {
            $agg['url'] = $agg['tv'];
            unset($agg['tv']);
        }
        $tvAggs[$slug] = $agg;
    }

    // → behind VidCore/NHD (our own player), ahead of the generic mirrors.
    $GLOBALS['MOVIE_EMBED_PROVIDERS'] = embed_merge_aggregators($GLOBALS['MOVIE_EMBED_PROVIDERS'], $movieAggs, 'vidfast');
    $GLOBALS['TV_EMBED_PROVIDERS']    = embed_merge_aggregators($GLOBALS['TV_EMBED_PROVIDERS'], $tvAggs, 'vidfast');
}
