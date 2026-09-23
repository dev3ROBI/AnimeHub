<?php
/**
 * KitsuPlay front-end performance layer.
 *
 * Four jobs, all shared by every page through includes/header.php:
 *
 *   1. Output compression. XAMPP ships without mod_deflate loaded, so the gzip
 *      for HTML is done in PHP (zlib is always present). When Apache's
 *      mod_deflate *is* enabled the .htaccess handles static files and this
 *      layer keeps going for the HTML — the two stack harmlessly.
 *   2. Asset URLs. Minified first, source second, with the file's mtime as the
 *      cache-buster. Editing a .css/.js changes its URL, so a long
 *      Cache-Control from .htaccess is always safe.
 *   3. Responsive images. AnimeHub posters come from AniList (230w / 460w) and
 *      TMDB (w185 … w780). kp_img_attrs() turns one poster URL into a srcset
 *      so phones stop downloading 460px artwork for a 175px card.
 *   4. Hints that keep the first paint early: preconnect + preload emitters.
 *
 * Nothing here is required for correctness: if the file is missing, or PHP has
 * no zlib, every page still renders — just less efficiently.
 */

if (!defined('KP_PERF_LOADED')) {
    define('KP_PERF_LOADED', true);

    /**
     * Root-relative prefix for this page ('./' at the root, '../' in /admin).
     *
     * header.php is shared by pages at different depths, so './assets/...'
     * used to 404 on admin pages. Deriving the depth from the running script
     * fixes that and keeps every asset path identical to before at the root.
     */
    if (!function_exists('kp_base')) {
        function kp_base() {
            static $base = null;
            if ($base !== null) return $base;

            $base = './';

            $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
            $script = $script !== '' ? realpath($script) : false;
            $root   = realpath(__DIR__ . '/..');

            if ($script && $root) {
                $script = str_replace('\\', '/', $script);
                $root   = str_replace('\\', '/', $root);

                if (strpos($script, $root . '/') === 0) {
                    $depth = substr_count(substr($script, strlen($root) + 1), '/');
                    if ($depth > 0) $base = str_repeat('../', $depth);
                }
            }

            return $base;
        }
    }

    /**
     * Start PHP-side compression for the HTML response.
     *
     * Must run before any output. Two routes, because XAMPP ships with
     * output_buffering=4096 — a buffer that already exists (and is not a
     * compression handler) makes ob_gzhandler useless:
     *
     *   • zlib.output_compression handles output_buffering for us.
     *   • ob_gzhandler covers the case where nothing is buffering yet.
     */
    if (!function_exists('kp_perf_start')) {
        function kp_perf_start() {
            if (PHP_SAPI === 'cli' || headers_sent()) return;
            if (defined('KP_PERF_SKIP_GZIP') && KP_PERF_SKIP_GZIP) return;
            if (!extension_loaded('zlib')) return;

            $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            if ($accept === '' || stripos($accept, 'gzip') === false) return;

            $zlib = (string)ini_get('zlib.output_compression');
            if ($zlib === '' || $zlib === '0' || strtolower($zlib) === 'off') {
                if (ob_get_level() > 0) {
                    // An outer buffer exists (output_buffering, a session
                    // handler, …) — let zlib do the compressing instead.
                    @ini_set('zlib.output_compression', 'On');
                    @ini_set('zlib.output_compression_level', '6');
                } elseif (function_exists('ob_gzhandler')) {
                    @ob_start('ob_gzhandler');
                }
            }

            // Caches must key on the encoding or they hand gzip to a client
            // that asked for plain text.
            @header('Vary: Accept-Encoding', false);
        }
    }

    /**
     * Pick the file to serve for a logical asset: 'foo.min.css' when it exists
     * and is not older than 'foo.css', otherwise the source file.
     *
     * Returns a path relative to the app root, or '' when the asset is missing.
     */
    if (!function_exists('kp_asset_pick')) {
        function kp_asset_pick($type, $file) {
            $type = $type === 'css' ? 'css' : 'js';
            $file = ltrim(str_replace('\\', '/', $file), '/');

            // Files under assets/ can be named bare ('nav_style.css'); anything
            // else is passed with its folder ('user/js/stats.js').
            $rel = strpos($file, '/') === false ? "assets/{$type}/{$file}" : $file;
            $dir = dirname(__DIR__);
            $src = $dir . '/' . $rel;
            if (!is_file($src)) return '';

            $min     = preg_replace('/\.(css|js)$/i', '.min.$1', $rel);
            $minPath = $dir . '/' . $min;

            if ($min !== $rel && is_file($minPath) && filemtime($minPath) >= filemtime($src)) {
                return $min;
            }
            return $rel;
        }
    }

    /**
     * Public URL for an asset, cache-busted with the served file's mtime.
     *
     *   <link href="<?= kp_asset('css', 'nav_style.css') ?>">
     */
    if (!function_exists('kp_asset')) {
        function kp_asset($type, $file) {
            $rel = kp_asset_pick($type, $file);
            if ($rel === '') return kp_base() . ltrim(str_replace('\\', '/', $file), '/');

            $path = dirname(__DIR__) . '/' . $rel;

            // mtime + byte size, not mtime alone: Windows (and FAT) only
            // resolve filemtime() to the second, so two edits inside the same
            // second produced the SAME ?v= for different bytes — the browser
            // and the service worker then kept serving the previous file under
            // the new URL. The size is free and changes with the content.
            $ver = is_file($path) ? filemtime($path) . '-' . filesize($path) : '1';

            return kp_base() . $rel . '?v=' . $ver;
        }
    }

    /** Inline an asset's contents (used for the above-the-fold CSS block). */
    if (!function_exists('kp_inline_css')) {
        function kp_inline_css($file) {
            // Prefer the minified build so the inlined bytes are as small as
            // possible; fall back to the source when there is no build yet.
            $rel = kp_asset_pick('css', $file);
            if ($rel === '') return '';

            $path = dirname(__DIR__) . '/' . $rel;
            if (!is_file($path)) return '';

            $css = (string)file_get_contents($path);
            // Never let a stylesheet close the <style> element early.
            return str_ireplace('</style', '<\/style', $css);
        }
    }

    /**
     * Image size variants for the two poster CDNs the catalogue uses.
     *
     * AniList serves 230x345 (medium) and 460x690 (large) — verified against the
     * live CDN; 'extraLarge' 404s. TMDB takes the width in the path. Anything
     * else returns [] and the tag is left as a plain src.
     *
     * @return array<int, array{0:string,1:int}>  [url, intrinsic width] pairs
     */
    if (!function_exists('kp_img_variants')) {
        function kp_img_variants($url) {
            if (!is_string($url) || $url === '') return [];

            // AniList: .../anilistcdn/media/anime/cover/large/bx21-hash.jpg
            if (preg_match('~^(https?://s4\.anilist\.co/file/anilistcdn/media/anime/cover/)(medium|large)(/.+)$~i', $url, $m)) {
                $widths = ['medium' => 230, 'large' => 460];
                $out = [];
                foreach ($widths as $dir => $w) {
                    $out[] = [$m[1] . $dir . $m[3], $w];
                }
                return $out;
            }

            // TMDB: https://image.tmdb.org/t/p/w500/abc.jpg
            if (preg_match('~^(https?://image\.tmdb\.org/t/p/)([a-z0-9_]+)(/.+)$~i', $url, $m)) {
                $widths = ['w185' => 185, 'w342' => 342, 'w500' => 500, 'w780' => 780];
                $out = [];
                foreach ($widths as $size => $w) {
                    $out[] = [$m[1] . $size . $m[3], $w];
                }
                return $out;
            }

            return [];
        }
    }

    /**
     * srcset + sizes for one image URL ('' when the CDN has no variants).
     *
     * $opts['sizes'] overrides the default poster-card layout description:
     * the card grid is minmax(155px, 1fr), so a phone shows roughly 45vw per
     * poster and a desktop card is ~190px wide.
     */
    if (!function_exists('kp_img_srcset')) {
        function kp_img_srcset($url, array $opts = []) {
            $variants = kp_img_variants($url);
            if (count($variants) < 2) return '';

            $sizes = $opts['sizes'] ?? '(max-width: 480px) 45vw, (max-width: 900px) 30vw, 190px';

            $pairs = [];
            foreach ($variants as $v) {
                $pairs[] = htmlspecialchars($v[0], ENT_QUOTES, 'UTF-8') . ' ' . (int)$v[1] . 'w';
            }

            return ' srcset="' . implode(', ', $pairs) . '"'
                 . ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '"';
        }
    }

    /**
     * Decoding / loading / fetchpriority attributes for an <img>.
     *
     *   kp_img_attrs($item['poster'])                  // lazy + async decode
     *   kp_img_attrs($url, ['priority' => true])       // eager, fetchpriority=high
     *   kp_img_attrs($url, ['eager' => true])          // eager, normal priority
     */
    if (!function_exists('kp_img_attrs')) {
        function kp_img_attrs($url, array $opts = []) {
            $priority = !empty($opts['priority']);
            $eager    = $priority || !empty($opts['eager']);

            $attrs = kp_img_srcset($url, $opts);

            /*
             * Intrinsic width/height so the browser can reserve the box before
             * bytes arrive (CLS). Only emitted when the CDN is one whose
             * geometry we know: AniList covers and TMDB posters are 2:3, so
             * the largest variant's width drives the height. $opts['dims']
             * overrides for odd cases (['dims' => [1920, 1080]]); an unknown
             * URL emits nothing rather than a wrong aspect.
             */
            $dims = $opts['dims'] ?? null;
            if ($dims === null && function_exists('kp_img_dims')) {
                $dims = kp_img_dims($url);
            }
            if (is_array($dims) && ($dims[0] ?? 0) > 0 && ($dims[1] ?? 0) > 0) {
                $attrs .= ' width="' . (int)$dims[0] . '" height="' . (int)$dims[1] . '"';
            }

            if ($priority) {
                $attrs .= ' fetchpriority="high"';
            } elseif (!empty($opts['fetchpriority'])) {
                $attrs .= ' fetchpriority="' . htmlspecialchars($opts['fetchpriority'], ENT_QUOTES, 'UTF-8') . '"';
            }

            if (!$eager) $attrs .= ' loading="lazy"';

            if (!isset($opts['decoding']) || $opts['decoding'] !== false) {
                $dec = ($opts['decoding'] ?? '') ?: 'async';
                $attrs .= ' decoding="' . htmlspecialchars($dec, ENT_QUOTES, 'UTF-8') . '"';
            }

            return $attrs;
        }
    }

    /**
     * Intrinsic [width, height] for the artwork CDNs whose geometry is fixed:
     * AniList covers and TMDB posters are 2:3 (verified 230×345 / 460×690 and
     * w185×278). Returns null for anything else (backdrops, banners, uploads)
     * so callers never reserve a wrong-shaped box.
     */
    if (!function_exists('kp_img_dims')) {
        function kp_img_dims($url) {
            if (!is_string($url) || $url === '') return null;

            if (preg_match('~^https?://s4\\.anilist\\.co/file/anilistcdn/media/anime/cover/~i', $url)) {
                return [460, 690];
            }
            // AniList documents every banner as 1920×1080 — the hero LCP image.
            if (preg_match('~^https?://s4\\.anilist\\.co/file/anilistcdn/media/anime/banner/~i', $url)) {
                return [1920, 1080];
            }
            // The URL names the served width; height follows the 2:3 poster box.
            if (preg_match('~^https?://image\\.tmdb\\.org/t/p/(w185|w342|w500|w780)/~i', $url, $m)) {
                $w = ['w185' => 185, 'w342' => 342, 'w500' => 500, 'w780' => 780][strtolower($m[1])];
                return [$w, (int)round($w * 1.5)];
            }
            return null;
        }
    }

    /**
     * The self-hosted fonts stylesheet (assets/css/fonts.css — one local file
     * covering Poppins 400/600, Fira Code 400, Tangerine) plus the hosts worth
     * a preconnect before the artwork starts. Self-hosting removes the
     * render-blocking round trip to fonts.googleapis.com/fonts.gstatic.com
     * entirely — the fonts are same-origin and already long-cacheable.
     *
     * @return array{0:string,1:array<int,string>}  [fonts URL, preconnect hosts]
     */
    if (!function_exists('kp_font_and_cdn_hints')) {
        function kp_font_and_cdn_hints() {
            $fonts = kp_asset('css', 'fonts.css');

            $hosts = [
                'https://s4.anilist.co',    // posters / banners
                'https://image.tmdb.org',   // TMDB artwork
                'https://cdnjs.cloudflare.com', // Font Awesome (head)
            ];

            return [$fonts, $hosts];
        }
    }

    // Kick off compression the moment the layer is loaded: header.php includes
    // this before its first byte of markup, which is the only safe moment.
    kp_perf_start();
}
