# Mobile speed work — what changed and how to use it

Everything here targets one outcome: a phone on a slow link should paint the
first screen without waiting for ~100 KB of CSS, ~40 KB of JS and three font
requests, and repeat visits should hit the browser cache instead of the network.

## 1. What is now in place

| Area | Before | Now |
| --- | --- | --- |
| HTML transfer | 69.6 KB uncompressed (login page) | **14.0 KB gzip (−80%)** |
| Render-blocking CSS | `nav_style.css` (39 KB) + `home.css` (58 KB) both blocking | 1.94 KB gz critical CSS inlined; the rest loads async |
| CSS payload | 249 KB across 6 sheets | 180 KB minified (−28%) before gzip |
| JS payload | 92 KB across 11 files | 60 KB minified (−35%) |
| Fonts | 3 Google Fonts requests | 1 request, `display=swap` |
| Poster images | always the 460×690 / w500 file | `srcset` picks 230×345 / w342 on small cards |
| Repeat visit | every asset re-validated | `Cache-Control: immutable` (1 year) for CSS/JS |
| HTML gzip | none (XAMPP has no mod_deflate) | `zlib` via `includes/performance.php` |

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
    `fetchpriority` for AniList (230w, 460w) and TMDB (w185…w780) artwork.
  - `kp_font_and_cdn_hints()` → one fonts URL + the CDN preconnects.
- **`assets/css/critical.css`** — the above-the-fold subset (base, navbar, hero,
  card grid skeleton). Inlined by `header.php`; the minified build is inlined
  when it exists.
- **`tools/minify.php`** — conservative minifier (comments + whitespace only,
  no line joining, so ASI cannot break). See §4.
- **`.htaccess`** — static caching through `mod_headers` (loaded in XAMPP),
  plus `mod_deflate`/`mod_expires`/`mod_brotli` blocks that activate
  automatically if you enable those modules.

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

# an asset should be immutable for a year
curl.exe -s -D - -o NUL http://localhost/AnimeHub/assets/css/nav_style.min.css

# every minified JS file must still parse
Get-ChildItem -Recurse -Filter *.min.js | ForEach-Object { node --check $_.FullName }
```

## 7. Known follow-ups (not done here)

- `assets/js` cards built in JavaScript (`continue-watching.js`, `stats.js`)
  only got `decoding="async"`; their posters have no `srcset` because the
  variant logic lives in PHP. A small shared JS helper duplicating
  `kp_img_variants()` would close that gap.
- Font Awesome's 100 KB sheet is still render-blocking. Self-hosting a subset
  (only the icons actually used) is the next big win.
- HLS (`hls.js`) is loaded on every watch page even when the source is an MP4.
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
