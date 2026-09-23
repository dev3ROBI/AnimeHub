# Mobile speed work — what changed and how to use it

Everything here targets one outcome: a phone on a slow link should paint the
first screen without waiting for ~100 KB of CSS, ~40 KB of JS and three font
requests, repeat visits should hit the browser cache instead of the network,
and the app stays installable/offline through a service worker.

## 1. What is now in place

| Area | Before | Now |
| --- | --- | --- |
| HTML transfer | 69.6 KB uncompressed (login page) | **14.0 KB gzip (−80%)** |
| Render-blocking CSS | `nav_style.css` (39 KB) + `home.css` (58 KB) both blocking | 1.94 KB gz critical CSS inlined; the rest loads async |
| CSS payload | 249 KB across 6 sheets | ~184 KB minified (−26%) before gzip |
| JS payload | 92 KB across 11 files | 60 KB minified (−35%) |
| Fonts | 3 render-blocking requests to fonts.googleapis.com/gstatic | **self-hosted** `fonts.min.css` (825 B) + 5 latin woff2 (~87 KB, `font-display: swap`, 2 preloaded) |
| Poster images | always the 460×690 / w500 file | `srcset` picks 230×345 / w342 on small cards; `width`/`height` reserve the box (CLS) |
| Repeat visit | every asset re-validated | `Cache-Control: immutable` (1 year) for CSS/JS, 1 month for fonts/images |
| HTML gzip | none (XAMPP has no mod_deflate) | `zlib` via `includes/performance.php` |
| Offline / install | none | root service worker (`sw.js`) + `manifest.json` + install banner |
| Scroll/touch handlers | default (main-thread) | `{ passive: true }` everywhere (hero swipe, card hover-dismiss, infinite scroll, sticky nav) |
| Paint work | whole grid repainted per hover | `contain: layout paint` per card, `content-visibility: auto` + `contain-intrinsic-size: auto …` on off-screen sections |

Measured on the local XAMPP with `curl` (see §6).

## 2. Files added

- **`includes/performance.php`** — the whole layer, included by `header.php`
  before the first byte of markup:
  - `kp_perf_start()` gzip for HTML (falls back to `ob_gzhandler`, then to
    `zlib.output_compression`, because XAMPP already buffers output).
  - `kp_asset('css'|'js', $file)` → `./assets/css/nav_style.min.css?v=<mtime>`
    when a fresh minified build exists, otherwise the source file. The mtime is
    the cache-buster, so a year-long `Cache-Control` is always safe.
  - `kp_base()` → `'./'` at the root, `'../'` in `/admin`, which also fixes the
    admin page previously requesting `/AnimeHub/admin/assets/...` (404).
  - `kp_img_attrs()` / `kp_img_srcset()` → `srcset`/`sizes`/`loading`/`decoding`/
    `fetchpriority`, plus intrinsic `width`/`height` from `kp_img_dims()`
    (AniList cover 460×690, AniList banner 1920×1080, TMDB poster w×1.5).
    Unknown URLs get **no** dimensions rather than a wrong aspect ratio;
    `['dims' => [w, h]]` overrides per call.
  - `kp_font_and_cdn_hints()` → the local `fonts.css` URL + CDN preconnects.
- **`assets/css/fonts.css`** — self-hosted @font-face for Poppins 400/600,
  Fira Code 400, Tangerine 400/700 (`font-display: swap`, latin subsets ~87 KB
  total). `header.php` preloads `poppins-400.woff2` + `tangerine-700.woff2`
  (the two faces the first screen paints with) and no longer links
  fonts.googleapis.com. `tools/fetch-fonts.ps1` re-downloads the woff2 files
  if Google rotates them.
- **`assets/css/critical.css`** — the above-the-fold subset (base, navbar, hero,
  card grid skeleton) plus the containment rules (`content-visibility` on
  off-screen sections, `contain: layout paint` on `.movie-card`). Inlined by
  `header.php`; the minified build is inlined when it exists.
- **`tools/minify.php`** — conservative minifier (comments + whitespace only,
  no line joining, so ASI cannot break). Targets: `assets/css`, `assets/js`,
  `user/js`, `assets/pwa`. See §4.
- **`.htaccess`** — static caching through `mod_headers` (loaded in XAMPP),
  plus `mod_deflate`/`mod_expires`/`mod_brotli` blocks that activate
  automatically if you enable those modules. The deny-all-JSON rule exempts
  `manifest.json`, `sw.js` is pinned to `no-cache, must-revalidate`, and the
  manifest gets `max-age=3600`.

## 2b. PWA package

| File | Role |
| --- | --- |
| `manifest.json` (app root) | installability; relative `start_url`/`scope`/icons, so it works at `/` **and** `/AnimeHub/` |
| `sw.js` (app root) | scope = whole app by location, no `Service-Worker-Allowed` header needed; tolerant per-URL shell precache (a single 404 skips that entry instead of bricking the install) |
| `assets/icons/` | `icon-192.png`, `icon-512.png`, `apple-touch-icon.png`, `favicon-48.png` (ffmpeg-generated — no GD/Imagick on this box) |
| `assets/pwa/offline.html` | fully self-contained branded fallback (inline CSS, no external requests) |
| `assets/pwa/install-prompt.js` + `.css` | `beforeinstallprompt` banner; the script resolves its base URL from `document.currentScript` so it works from `/admin` too, and the banner CSS is injected **only** when a prompt actually fires (0 bytes otherwise) |

SW strategies (nothing video-shaped is ever intercepted — no range/HLS
corruption):

- **navigations** → network-first, runtime-cached, `offline.html` fallback;
- **same-origin static + AniList/TMDB artwork** → cache-first with
  `ignoreSearch` (asset URLs carry `?v=<mtime>`; content rotation = bump
  `VERSION` in `sw.js`, which purges both caches on activate);
- **same-origin `.php` APIs** → network-first with exact-URL fallback;
- **everything else** (cdnjs, unpkg, jQuery…) → untouched passthrough.

Registration lives in `includes/footer.php` on `load` via `kp_base()sw.js`.
Bump `VERSION` whenever precached files change.

## 2c. Client-side speed rules

- **Passive listeners** everywhere (`{ passive: true }` on scroll / wheel /
  touchstart / touchend): `hero-slider.js` swipe, `card-preview.js`
  scroll-dismiss, `genre-scroll.js` infinite-scroll fallback, sticky-nav
  snippet. `user/js/*` has no scroll/touch listeners at all.
- **CSS containment**: `content-visibility: auto` + `contain-intrinsic-size:
  auto N` (remembers the last rendered size, so scrollbars don't jump) on
  show/notice/sidebar sections; `contain: layout paint` on `.movie-card`;
  `will-change: transform` only on the active hero slide.
- **Image dimensions**: `kp_img_dims()` → AniList cover 460×690, AniList
  banner 1920×1080 (the hero LCP), TMDB poster w×1.5; unknown URLs get no
  `width`/`height` rather than a wrong aspect ratio.

## 3. Files removed

- `assets/css/admin.css` — referenced by nothing (the admin page loads the
  shared `header.php`).
- `assets/images/logo.png` — 69 KB, referenced by nothing.
- The `<style>` block at the end of `includes/footer.php` moved into
  `assets/css/nav_style.css` (a style block that late forced a second style
  recalculation on every page).

`git checkout -- <path>` restores any of them.

## 4. After editing CSS or JS

```powershell
php tools/minify.php          # rebuild the .min.* files
php tools/minify.php --check  # list stale builds (safe, changes nothing)
php tools/minify.php --clean  # delete every generated build
```

`kp_asset()` only serves a `.min.*` file while it is at least as new as its
source, so a forgotten rebuild degrades to the original file — never to a stale
one.

## 5. Optional: turn on Apache compression for static files

`.htaccess` already compresses nothing until the modules exist. In
`D:\xampp\apache\conf\httpd.conf` uncomment:

```
LoadModule deflate_module modules/mod_deflate.so
LoadModule expires_module modules/mod_expires.so
LoadModule filter_module  modules/mod_filter.so
```

Restart Apache. CSS/JS/SVG/JSON then arrive gzipped (Brotli too, if
`mod_brotli` is available). PHP keeps compressing the HTML, so nothing regresses
if you skip this.

## 6. How to verify

```powershell
# HTML gzip + security/caching headers
curl.exe -s -D - -o NUL -H "Accept-Encoding: gzip" http://localhost/AnimeHub/authentication.php

# an asset should be immutable for a year; the SW must revalidate; manifest 1h
curl.exe -s -D - -o NUL http://localhost/AnimeHub/assets/css/nav_style.min.css
curl.exe -s -D - -o NUL http://localhost/AnimeHub/sw.js
curl.exe -s -D - -o NUL http://localhost/AnimeHub/manifest.json        # was 403 until the JSON rule learned the exception

# PWA bits + self-hosted fonts all answer 200
foreach ($u in 'manifest.json','sw.js','assets/pwa/offline.html','assets/pwa/install-prompt.js','assets/css/fonts.css','assets/fonts/poppins-400.woff2','assets/icons/icon-512.png') {
  curl.exe -s -o NUL -w "$u %{http_code}`n" "http://localhost/AnimeHub/$u"
}

# every minified JS file must still parse
Get-ChildItem -Recurse -Filter *.min.js | ForEach-Object { node --check $_.FullName }
```

In Chrome DevTools → Application: manifest should show no errors, the service
worker should reach **activated**, and the Lighthouse PWA checks should pass.
(In-page screenshot of a poster card should show `width="460" height="690"`.)

## 7. Deploying to InfinityFree + Cloudflare

- **Upload as-is.** `.htaccess` rules are inert without `mod_headers`/
  `mod_deflate` — harmless; Cloudflare replaces both.
- **Cloudflare dashboard:**
  - *Speed → Optimization → Content Optimization* → **Brotli ON** (InfinityFree
    has no Brotli of its own);
  - *Speed → Image Optimization* → **Polish** (and Avifify if offered) turns on
    automatic WebP/AVIF — needed because the host has **no GD/Imagick**, so
    nothing can convert images server-side;
  - DNS record for your domain set to **proxied (orange cloud)** — that also
    upgrades the connection to HTTP/2/3 + edge caching.
- **HTTPS:** Cloudflare's Universal SSL covers the origin; the SW/manifest need
  HTTPS (or `localhost`) — do not point the domain at InfinityFree unproxied,
  their free tier is HTTP-only on the bare server.
- **Cron/caching:** InfinityFree's own file cache is aggressive; since every
  asset URL carries `?v=<mtime>` that is safe. PHP pages must stay
  `private, no-cache` (already in `.htaccess`).
- `zlib.output_compression` in `performance.php` still gives gzipped HTML even
  if the proxy's compression is disabled — the two stack harmlessly.
- After any CSS/JS edit: `php tools/minify.php` locally, then upload the
  `.min.*` files together with their sources (or upload everything).

## 8. Known follow-ups (not done here)

- Font Awesome's 100 KB sheet is still render-blocking from cdnjs.
  Self-hosting a subset (only the icons actually used) is the next big win.
- Cards built in JavaScript (`continue-watching.js`, `stats.js`) only got
  `decoding="async"`; their posters have no `srcset` because the variant logic
  lives in PHP. A small shared JS helper duplicating `kp_img_variants()` would
  close that gap.
- HLS (`hls.js`) is loaded on every watch page even when the source is an MP4.
- `install-prompt.js` fires on Chrome/Edge/Android only by design; iOS Safari
  has no `beforeinstallprompt` (the `apple-touch-icon` + share-sheet
  "Add to Home Screen" covers it).
- Provider helpers that nothing calls any more (safe to delete, left in place
  because they are part of a client's public surface):
  `anikuro_id_from_session`, `anikuro_airing`, `catalog_is_api_id`,
  `catalog_airing`, `jikan_mal_from_id`, `progress_save`,
  `reanime_latest_aired`, `reanime_stream`, `reanime_get_cache`,
  `reanime_set_cache`, `reanime_slug_from_id`,
  `reanime_anilist_episode_count`, `tmdb_movie_now_playing`,
  `tmdb_movie_upcoming`, `tmdb_movie_top_rated`, `tmdb_tv_airing_today`,
  `tmdb_tv_on_the_air`, `tmdb_tv_top_rated`, `tmdb_movie_by_genre`,
  `tmdb_tv_by_genre`.
