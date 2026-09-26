<?php
/**
 * Category rails for the browse pages — Movies, Series and Anime.
 *
 * One table defines every rail (title, icon, how to fetch it, where "View all"
 * goes), so all three pages stay in step and adding a row is a one-line change
 * in kp_category_rails(). The cards are the site's normal render_anime_card()
 * markup, so a rail looks exactly like every other section — two rows of the
 * ordinary poster card on a desktop (no sideways scrolling), one row you swipe
 * on a phone. See .kp-cat-rail in assets/css/home.css for the column ladder.
 *
 * Fetching is lazy. The first KP_RAIL_EAGER rails are resolved server-side;
 * the rest ship as skeletons and are filled by assets/js/category-rails.js
 * through includes/category_items.php once they scroll near the viewport. A
 * page carrying a dozen categories therefore costs one or two provider calls
 * on first paint instead of a dozen — which matters on the free host.
 */

include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/tmdb_movie_api.php';

/** Rails rendered with the page; the rest load as they scroll into view. */
if (!defined('KP_RAIL_EAGER'))   define('KP_RAIL_EAGER', 2);
/** Cards per rail — 12 fills the desktop grid exactly (2 rows of 6) and gives
 *  the phone rail a decent swipe. The grid hides anything past the row at each
 *  breakpoint, so a larger number here is safe too. */
if (!defined('KP_RAIL_LIMIT'))   define('KP_RAIL_LIMIT', 12);
/** Skeleton placeholders drawn inside a not-yet-loaded rail (same count, so
 *  the placeholder rows are exactly as tall as the loaded ones). */
if (!defined('KP_RAIL_SKELETON')) define('KP_RAIL_SKELETON', 12);

/**
 * Every rail, in display order, per page kind.
 *
 * Each entry:
 *   key    unique id (also the cache/endpoint key)
 *   title  heading
 *   icon   Font Awesome class, kept for the endpoint to echo back
 *   link   "View all" target, or '' for no link
 *   opts   TMDB /discover filters          (movie + tv rails)
 *   genre  AniList genre name, or ''       (anime rails)
 *   source non-genre anime row: popular | trending | airing | season
 *   sort   anime sort key (catalog_sort_options())
 */
function kp_category_rails($kind) {
    $kind = strtolower(trim((string)$kind));

    if ($kind === 'movie') {
        return [
            ['key' => 'korean',    'title' => 'Korean Movies',    'icon' => 'fa-solid fa-heart',        'link' => './movies.php?lang=ko', 'opts' => ['with_original_language' => 'ko']],
            ['key' => 'chinese',   'title' => 'Chinese Movies',   'icon' => 'fa-solid fa-dragon',       'link' => './movies.php?lang=zh', 'opts' => ['with_original_language' => 'zh']],
            ['key' => 'japanese',  'title' => 'Japanese Movies',  'icon' => 'fa-solid fa-torii-gate',   'link' => './movies.php?lang=ja', 'opts' => ['with_original_language' => 'ja']],
            ['key' => 'hindi',     'title' => 'Hindi Movies',     'icon' => 'fa-solid fa-film',         'link' => './movies.php?lang=hi', 'opts' => ['with_original_language' => 'hi']],
            ['key' => 'turkish',   'title' => 'Turkish Movies',   'icon' => 'fa-solid fa-mosque',       'link' => './movies.php?lang=tr', 'opts' => ['with_original_language' => 'tr']],
            ['key' => 'animation', 'title' => 'Animation',        'icon' => 'fa-solid fa-wand-magic-sparkles', 'link' => './movies.php?genre=16', 'opts' => ['with_genres' => 16]],
            ['key' => 'action',    'title' => 'Action',           'icon' => 'fa-solid fa-explosion',    'link' => './movies.php?genre=28', 'opts' => ['with_genres' => 28]],
            ['key' => 'comedy',    'title' => 'Comedy',           'icon' => 'fa-solid fa-face-laugh',   'link' => './movies.php?genre=35', 'opts' => ['with_genres' => 35]],
            ['key' => 'romance',   'title' => 'Romance',          'icon' => 'fa-solid fa-heart-circle-plus', 'link' => './movies.php?genre=10749', 'opts' => ['with_genres' => 10749]],
            ['key' => 'horror',    'title' => 'Horror',           'icon' => 'fa-solid fa-ghost',        'link' => './movies.php?genre=27', 'opts' => ['with_genres' => 27]],
            ['key' => 'thriller',  'title' => 'Thriller',         'icon' => 'fa-solid fa-bolt',         'link' => './movies.php?genre=53', 'opts' => ['with_genres' => 53]],
            ['key' => 'crime',     'title' => 'Crime',            'icon' => 'fa-solid fa-handcuffs',    'link' => './movies.php?genre=80', 'opts' => ['with_genres' => 80]],
            ['key' => 'scifi',     'title' => 'Sci-Fi',           'icon' => 'fa-solid fa-rocket',       'link' => './movies.php?genre=878', 'opts' => ['with_genres' => 878]],
            ['key' => 'docs',      'title' => 'Documentary',      'icon' => 'fa-solid fa-book-open',    'link' => './movies.php?genre=99', 'opts' => ['with_genres' => 99]],
        ];
    }

    if ($kind === 'tv') {
        return [
            ['key' => 'kdrama',    'title' => 'K-Drama',          'icon' => 'fa-solid fa-heart',        'link' => './tv.php?lang=ko&genre=18', 'opts' => ['with_original_language' => 'ko', 'with_genres' => 18]],
            ['key' => 'cdrama',    'title' => 'C-Drama',          'icon' => 'fa-solid fa-dragon',       'link' => './tv.php?lang=zh&genre=18', 'opts' => ['with_original_language' => 'zh', 'with_genres' => 18]],
            ['key' => 'jdrama',    'title' => 'J-Drama',          'icon' => 'fa-solid fa-torii-gate',   'link' => './tv.php?lang=ja&genre=18', 'opts' => ['with_original_language' => 'ja', 'with_genres' => 18]],
            ['key' => 'turkish',   'title' => 'Turkish Drama',    'icon' => 'fa-solid fa-mosque',       'link' => './tv.php?lang=tr&genre=18', 'opts' => ['with_original_language' => 'tr', 'with_genres' => 18]],
            ['key' => 'thai',      'title' => 'Thai Drama',       'icon' => 'fa-solid fa-umbrella-beach', 'link' => './tv.php?lang=th&genre=18', 'opts' => ['with_original_language' => 'th', 'with_genres' => 18]],
            ['key' => 'filipino',  'title' => 'Filipino Drama',   'icon' => 'fa-solid fa-sun',          'link' => './tv.php?lang=tl&genre=18', 'opts' => ['with_original_language' => 'tl', 'with_genres' => 18]],
            ['key' => 'korean',    'title' => 'Korean Shows',     'icon' => 'fa-solid fa-tv',           'link' => './tv.php?lang=ko', 'opts' => ['with_original_language' => 'ko']],
            ['key' => 'anime',     'title' => 'Anime Series',     'icon' => 'fa-solid fa-wand-magic-sparkles', 'link' => './tv.php?lang=ja&genre=16', 'opts' => ['with_original_language' => 'ja', 'with_genres' => 16]],
            ['key' => 'crime',     'title' => 'Crime & Mystery',  'icon' => 'fa-solid fa-handcuffs',    'link' => './tv.php?genre=80', 'opts' => ['with_genres' => 80]],
            ['key' => 'scififan',  'title' => 'Sci-Fi & Fantasy', 'icon' => 'fa-solid fa-rocket',       'link' => './tv.php?genre=10765', 'opts' => ['with_genres' => 10765]],
            ['key' => 'action',    'title' => 'Action & Adventure', 'icon' => 'fa-solid fa-explosion',  'link' => './tv.php?genre=10759', 'opts' => ['with_genres' => 10759]],
            ['key' => 'soap',      'title' => 'Romance & Drama',  'icon' => 'fa-solid fa-heart-circle-plus', 'link' => './tv.php?genre=10766', 'opts' => ['with_genres' => 10766]],
            ['key' => 'comedy',    'title' => 'Comedy',           'icon' => 'fa-solid fa-face-laugh',   'link' => './tv.php?genre=35', 'opts' => ['with_genres' => 35]],
            ['key' => 'reality',   'title' => 'Reality',          'icon' => 'fa-solid fa-video',        'link' => './tv.php?genre=10764', 'opts' => ['with_genres' => 10764]],
            ['key' => 'docs',      'title' => 'Documentary',      'icon' => 'fa-solid fa-book-open',    'link' => './tv.php?genre=99', 'opts' => ['with_genres' => 99]],
        ];
    }

    // Anime (AniList, through the catalogue layer).
    return [
        ['key' => 'trending',  'title' => 'Trending Now',   'icon' => 'fa-solid fa-fire',          'link' => './genre.php?sort=trending', 'source' => 'trending'],
        ['key' => 'popular',   'title' => 'Most Popular',   'icon' => 'fa-solid fa-chart-line',    'link' => './genre.php?sort=popular',  'source' => 'popular'],
        ['key' => 'airing',    'title' => 'Airing Now',     'icon' => 'fa-solid fa-tower-broadcast', 'link' => './schedule.php',          'source' => 'airing'],
        ['key' => 'toprated',  'title' => 'Top Rated',      'icon' => 'fa-solid fa-star',          'link' => './genre.php?sort=score',    'source' => 'toprated'],
        ['key' => 'action',    'title' => 'Action',         'icon' => 'fa-solid fa-explosion',     'link' => './genre.php?g=Action',      'genre' => 'Action'],
        ['key' => 'adventure', 'title' => 'Adventure',      'icon' => 'fa-solid fa-compass',       'link' => './genre.php?g=Adventure',   'genre' => 'Adventure'],
        ['key' => 'comedy',    'title' => 'Comedy',         'icon' => 'fa-solid fa-face-laugh',    'link' => './genre.php?g=Comedy',      'genre' => 'Comedy'],
        ['key' => 'drama',     'title' => 'Drama',          'icon' => 'fa-solid fa-masks-theater', 'link' => './genre.php?g=Drama',       'genre' => 'Drama'],
        ['key' => 'fantasy',   'title' => 'Fantasy',        'icon' => 'fa-solid fa-dragon',        'link' => './genre.php?g=Fantasy',     'genre' => 'Fantasy'],
        ['key' => 'romance',   'title' => 'Romance',        'icon' => 'fa-solid fa-heart',         'link' => './genre.php?g=Romance',     'genre' => 'Romance'],
        ['key' => 'scifi',     'title' => 'Sci-Fi',         'icon' => 'fa-solid fa-rocket',        'link' => './genre.php?g=Sci-Fi',      'genre' => 'Sci-Fi'],
        ['key' => 'mystery',   'title' => 'Mystery',        'icon' => 'fa-solid fa-magnifying-glass', 'link' => './genre.php?g=Mystery',  'genre' => 'Mystery'],
        ['key' => 'slice',     'title' => 'Slice of Life',  'icon' => 'fa-solid fa-mug-hot',       'link' => './genre.php?g=Slice of Life', 'genre' => 'Slice of Life'],
        ['key' => 'supernat',  'title' => 'Supernatural',   'icon' => 'fa-solid fa-ghost',         'link' => './genre.php?g=Supernatural', 'genre' => 'Supernatural'],
    ];
}

/** One rail definition by key (used by the lazy endpoint). */
function kp_category_rail($kind, $key) {
    foreach (kp_category_rails($kind) as $rail) {
        if ($rail['key'] === $key) return $rail;
    }
    return null;
}

/**
 * Top-rated anime across every genre.
 *
 * anilist_by_genre() always demands a genre, and "top rated" is a *sort* rather
 * than a genre, so this asks AniList through the same helper the rest of the
 * catalogue uses — anilist_query() caches the answer like any other list.
 */
function kp_rail_top_rated($limit = null) {
    $limit = $limit === null ? KP_RAIL_LIMIT : max(1, (int)$limit);
    if (!function_exists('anilist_query') || !defined('ANILIST_MEDIA_FIELDS')) return [];

    $fields = ANILIST_MEDIA_FIELDS;
    $data = anilist_query("
        query (\$page: Int, \$perPage: Int, \$sort: [MediaSort]) {
          Page(page: \$page, perPage: \$perPage) {
            media(type: ANIME, isAdult: false, sort: \$sort) {
              $fields
            }
          }
        }
    ", ['page' => 1, 'perPage' => min(50, $limit), 'sort' => ['SCORE_DESC']]);

    $out = [];
    foreach (($data['Page']['media'] ?? []) as $m) {
        $n = anilist_normalize($m);
        if ($n) $out[] = $n;
    }
    return $out;
}

/**
 * Resolve a rail's cards.
 *
 * TMDB rails go through /discover (cached by tmdb_movie_api for an hour);
 * anime rails go through the catalogue layer, which already fans out to
 * AniList → ReAnime → Jikan. A failed rail returns [] and renders as an
 * empty notice rather than breaking the page.
 */
function kp_category_items(array $rail, $limit = null) {
    $limit = $limit === null ? KP_RAIL_LIMIT : max(1, (int)$limit);
    $kind  = $rail['kind'] ?? 'movie';

    if ($kind === 'anime') {
        $genre = $rail['genre'] ?? '';
        if ($genre !== '') {
            $res = catalog_by_genre($genre, 1, $limit, $rail['sort'] ?? 'popular');
            return $res['items'] ?? [];
        }
        switch ($rail['source'] ?? 'popular') {
            case 'trending': return catalog_trending($limit);
            case 'airing':   return catalog_airing($limit);
            case 'toprated': return kp_rail_top_rated($limit);
            case 'season':   return function_exists('anilist_season') ? anilist_season(null, null, $limit) : [];
            default:         return function_exists('anilist_popular') ? anilist_popular(1, $limit) : [];
        }
    }

    $opts = $rail['opts'] ?? [];
    $opts['language'] = 'en-US';
    $items = ($kind === 'movie')
        ? tmdb_movie_discover(1, $opts)
        : tmdb_tv_discover(1, $opts);

    return is_array($items) ? array_slice($items, 0, $limit) : [];
}

/** Card markup for a rail (empty state included). */
function kp_rail_cards(array $items, $loaded = true) {
    if (!$loaded) {
        // Skeletons hold the rail's height so lazy loading cannot shift the
        // page around it.
        $out = '';
        for ($i = 0; $i < KP_RAIL_SKELETON; $i++) {
            $out .= '<span class="kp-rail-skel" aria-hidden="true"></span>';
        }
        return $out;
    }

    $out = '';
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) continue;
        $out .= render_anime_card($item, ['show_ep_badge' => false]);
    }
    return $out !== ''
        ? $out
        : '<p class="kp-rail-empty"><i class="fa-solid fa-ghost"></i> No titles here yet.</p>';
}

/** One rail section (heading + the horizontal rail itself). */
function kp_render_rail(array $rail, array $items, $loaded = true, $kind = 'movie') {
    $link = (string)($rail['link'] ?? '');
    $html = '<section class="kp-section kp-rail"'
          . ' data-rail="' . kp_e($rail['key']) . '"'
          . ' data-rail-kind="' . kp_e($kind) . '"'
          . ($loaded ? '' : ' data-rail-lazy="1"') . '>';

    $html .= '<div class="kp-section-head">'
           . '<h2><span class="kp-head-bar"></span>' . kp_e($rail['title']) . '</h2>'
           . ($link !== ''
                ? '<a class="kp-view-all" href="' . kp_e($link) . '">View all <i class="fas fa-arrow-right"></i></a>'
                : '')
           . '</div>';

    $html .= '<div class="kp-cat-rail">' . kp_rail_cards($items, $loaded) . '</div>';
    return $html . '</section>';
}

/**
 * All rails for a page kind.
 *
 * @param string $kind  'movie' | 'tv' | 'anime'
 * @param int    $eager rails to resolve now (-1 = every rail, for pages that
 *                      want everything server-rendered)
 */
function kp_render_category_rails($kind, $eager = null) {
    $rails = kp_category_rails($kind);
    if (!$rails) return '';

    $eager = ($eager === null) ? KP_RAIL_EAGER : (int)$eager;

    $html = '<div class="kp-rails">';
    foreach ($rails as $i => $rail) {
        $rail['kind'] = $kind;
        $withItems = ($eager < 0 || $i < $eager);
        $html .= kp_render_rail(
            $rail,
            $withItems ? kp_category_items($rail) : [],
            $withItems,
            $kind
        );
    }
    return $html . '</div>';
}
