# AnimeHub — Handoff

> Purpose: any future session (or dev) can pick up exactly where work stopped.
> Last updated: 2026-09-26 (browse-page design pass, mobile settings panel, Top-10 cover gap, Chinese captions + Chinese-platform extractor scaffold, dubbed-audio defaults + aggregator slot).

## 1. Project snapshot

- **Stack:** vanilla PHP 8 (no framework), MySQL via PDO/mysqli, AniList (primary) + TMDB/Jikan (secondary) APIs, vanilla JS, hand-rolled CSS.
- **Hosting target:** InfinityFree + Cloudflare. Local dev: XAMPP, DB `animehub`, user `root`, no password.
- **Repo:** `https://github.com/dev3ROBI/AnimeHub.git`, branch `main`.

## 2. Completed work

### Follow-up: dub-first audio, id-aware embed templates, aggregator slot (IMPLEMENTED)

**Asked:** add `hindi-dub-api`, MX Player API, Netmirror aggregator, TMDB-Embed-API and CNCVerse as providers "for better dubbed".

**What survives contact with reality** (all checked live, 2026-09-26):
- **8Stream (`8StreamApi`) — already integrated** and it is the only source we have that ships dub audio at all: each language is a separate HLS stream. Nothing to add.
- **`hindi-dub-api`** — no such public API/project exists under that name. The conceptually closest documented thing is the *moviebox-internal-api* spec (m3u8 + an explicit "Hindi dub" audio selection) — i.e. a MovieBox scraper, not a callable public API.
- **MX Player API** — Amazon MX Player exposes **no public API**; playback is Widevine-protected and India-region-locked. Not integrable server-side.
- **TMDB-Embed-API (`Inside4ndroid`)** — real, but it is a **self-hosted Node app with an admin panel**. Usable only once *you* deploy it and hand over that host URL; it then behaves as one more iframe aggregator.
- **CNCVerse-Cloud-Stream-Extension** — an **Android CloudStream extension repo, not an HTTP API**. Its `master` branch contains only assets (the built plugins ship from the `builds` branch), so there is no endpoint to call. Using it means re-implementing its scrapers one by one in PHP (Netmirror, CastleTv, DoFlix, Einthusan, MLSBD, MovieBox, Moviezwap, Pikashow, PlayFyTv, PlayZTV, TamilDhool, TamilUltra) — each needs a live-verified flow, and most are Cloudflare/DNS-gated for a foreign datacentre IP.
- **Netmirror** — a streaming *site*, not an API; it is one of the things CNCVerse scrapes.

**Therefore — the parts that are real code:**

1. **Dub-first audio defaults** (`config/config.php` → `EIGHTSTREAM_AUDIO_PREF`, `includes/eightstream_api.php` → `eightstream_lang_preference()`). An unqualified request (`sub`/`dub`/none) previously always meant `['english','hindi']`. It now reads the comma-separated `EIGHTSTREAM_AUDIO_PREF` (default `English,Hindi,Bengali,Tamil,Telugu`), lowercased, with `english` appended as the guaranteed catch-all. A request that *names* a language still wins over the list. **Live-verified:** with `'Hindi,English,…'` set, `tt11737520` S1E2 (Hindi+English) resolves `lang=Hindi` while `tt1877830` (English/Bengali/Tamil/Telugu, no Hindi) still lands on `lang=English`.
2. **`{imdb}` in embed templates + skip-what-we-cannot-fill** (`includes/embed_url.php`, new; `includes/embed_movie.php`, `includes/embed_tv.php`). `embed_fill_url()` resolves `{tmdb} {imdb} {season} {episode}` and returns `null` — so the provider is *dropped for that request* — when a placeholder has no value, when `{tmdb}` is `0`, when an unknown `{token}` is left over, or when the result is not `http(s)`. Previously a `{imdb}` template would have rendered a literal `{imdb}` into a broken iframe. `movie_embed_all($tmdb, $imdb='')` / `tv_embed_all($tmdb,$season,$ep,$imdb='')` gained the trailing optional arg; `movie_embed_resolve()` / `tv_embed_resolve()` now delegate to the `*_all()` builders so the two can never disagree. Callers pass `$detail['imdb_id']` (`includes/get_movie_stream.php`, `includes/get_tv_stream.php`, and the server-side fallback in `watch.php`).
3. **Aggregator slot** (`config/config.php` → `$GLOBALS['EMBED_AGGREGATORS']`, `embed_merge_aggregators()` in `includes/embed_url.php`). Empty by default, so nothing changes until a URL is entered. Each entry takes `label`, `url` (movie template) and an optional `tv` template; `enabled => false` and `require_host` let a self-hosted instance stay dormant until it is actually deployed. Filled-in entries are spliced in **after `nhdapi`, before `vidfast`** — ahead of the generic mirrors because a dub-specialised aggregator is more likely to have the audio, but behind VidCore/NHD because those two are extractable by our own ArtPlayer. `{imdb}`-keyed entries drop out automatically on titles with no IMDb id.

**Verified:** `php -l` clean on all 8 touched files · probe confirmed template fill/skips, merge position (movie and TV, incl. the `tv` template override) and both audio-preference orders · live 8Stream resolves above · `minify → --check` fresh.

#### NetMirror scraper — investigated, NOT buildable (do not redo this)

A NetMirror scraper was explicitly requested and then rejected on evidence. Probes, 2026-09-26, browser UA + full navigation headers:

- **The official app page `netmirror.gg` (reached via `netmirror.app` 301) links to `https://net77.cc/home` as the web app.** That is the only authoritative pointer to the live domain.
- `net77.cc/verify2` → **403 Cloudflare managed challenge** (`Just a moment…`, `challenges.cloudflare.com`). `net77.cc/home` and `/movie/550` → **522** (CF could not reach origin; origin flapping). Neither a plain HTTP client nor a JS-fingerprint emulation passes a managed challenge — it needs a real browser and a Turnstile token.
- Every other guessable mirror is dead: `netmirror.xyz` → parked at `domains.atom.com`; `netmirror.one` → cPanel *Account Suspended*; `net52.cc`/`net11.cc` → 301 to net77.cc; and **`net33.cc` / `net44.cc` are parked domains serving a `router.parklogic.com` monetisation interstitial** (adblock test + fingerprint of timezone/GPU/`navigator.webdriver`, then a POST to the router). The base64 parameters on that page even name `"domainApex":"net33.cc", "tenant":"joe2"` — i.e. it is ParkLogic ad inventory, not NetMirror.
- Net effect: not one NetMirror page is fetchable, so the whole chain (movie page → `data-id` → player config → signed m3u8) can never be verified from our host. A scraper written blind would be dead code that breaks silently.

**If this is ever revisited**, the requirement is concrete: a mirror host that answers a plain `GET /movie/{tmdb}` **without** a Cloudflare managed challenge — either a mirror like that, or a proxy/VPS whose IP passes the challenge and can forward the player payload. The `EMBED_AGGREGATORS` slot (`config/config.php`) is the place that result goes in.

### Follow-up: mobile settings panel, Top-10 poster gap, Chinese captions (IMPLEMENTED)

**Asked:** in mobile view the player's settings panel was not responsive; the Top-10 rail on the home page left a gap under each cover; and (next in line) the site needs Chinese-platform extraction + working C-drama subtitles.

**1. ArtPlayer settings panel on a phone** (`assets/css/watch_page_style.css`). Two separate clamps, both from how ArtPlayer lays the panel out — `position:absolute`, anchored to `right`, width driven by content:
- **Width:** our rows carry an icon + label + a nowrap value (`Subtitle background … Default`), which on a phone is wider than the player, so the panel ran off the left edge and `.kp-player-shell { overflow:hidden }` cut it. Base rule now bounds `max-width: calc(100% - padding*2)`; on a phone the panel also pins **both** insets so it spans the player, and the *label* ellipsizes (never the value — that is the part that changes).
- **Height:** a 16:9 player on a 390px phone is ~220px tall, and ArtPlayer's mobile default is 180px of panel + a 38px control bar, so the top rows were clipped. `max-height: calc(100% - var(--art-control-height) - 8px)` fits it to the space above the bar; rows are 44px (thumb target) and the panel scrolls.
- The pop-up pickers (`.art-selector-list` under the CC/Server controls) are content-sized too and hang off a control near the right edge, so on a phone they get `max-width: min(calc(100vw - 24px), 320px)` — a percentage there would resolve against the tiny control box, hence viewport units.

**2. Top-10 cover gap** (`assets/css/home.css`). `critical.css` ships `img[width][height]{height:auto}` for content images and `kp_img_attrs()` does emit those attributes — two attribute selectors out-specify `.kp-top10-poster img`, so `height:100%` lost and any poster that was not exactly 2:3 left the frame's background showing below it. The image is now `position:absolute; inset:0` inside the 2:3 frame (same guard the browse cards got), which resolves its height from the insets and cannot be out-voted.

**3. Chinese captions** (`includes/subtitles_api.php`, `config/config.php`):
- `SUBTITLES_LANGS` default is now **`eng,zho`** — the CC row offers English *and* Chinese when OpenSubtitles has both, instead of one language plus a duplicate.
- `subtitles_pick()` had `break 2`, which exited the language loop after the first language: a second preferred language was never added. It is now one track per preferred language in order (up to `SUBTITLES_MAX`), with the second file of the same language used only when that language is the only one on offer (broken-upload insurance).
- `subtitles_norm_lang()` maps bibliographic codes to terminological ones (`chi→zho`, `ger→deu`, …) — a track tagged `chi` otherwise never matched the preferred `zho`, which is exactly the track a C-drama needs.
- `subtitles_to_utf8()` (new, called from `subtitles_to_vtt()`): BOM, then UTF-8 validity, then `mb_detect_encoding` over GB18030/Big5/SJIS/EUC-KR, plus a NUL-byte branch for BOM-less UTF-16. Verified with fixtures in UTF-8, GB18030, Big5 and UTF-16 — all four come out valid UTF-8 VTT with the Chinese text intact.

**Verified live (2026-09-26):** the caption proxy answers `HTTP 200 text/vtt; charset=utf-8` with real cue times. Auto-CC **does** reach C-dramas: TMDB-searched titles → `The Untamed` `tt10554898` (19 rows), `Hidden Love` `tt28076458` (15), `Love Between Fairy and Devil` `tt14922556` (17), `Nirvana in Fire` `tt5141800` (10, and it *does* have a Chinese track → row shows English + Chinese), `Word of Honor`, `Story of Kunning Palace`, `Lost You Forever`, `The Double`, `Joy of Life`, `Empresses in the Palace` — all ≥4 rows, all English.
> OpenSubtitles' Chinese coverage is thin (1 of those 10), which is the real argument for the platforms' own CC tracks.

**4. Chinese-platform extractor (scaffolded — waiting on a key).** The user picked a third-party extractor, so the research was: iQIYI / Tencent-WeTV / Youku / MGTV / Sohu expose **no** public m3u8 API (signed per request, mostly Widevine, region-locked to CN) and **no** service in the catalogues covers them — checked TikHub's full OpenAPI (1050 paths) and JustOneAPI's (319): TikHub has Bilibili only (87 endpoints, incl. `fetch_video_playurl` + `fetch_video_subtitle`), JustOneAPI has Bilibili/Youku metadata only. Bilibili via TikHub is therefore the one documented pair that returns a stream **and** the platform's own CC track.

What is now in place (`includes/cn_extract_api.php`, **new**):
- `cnx_search()` (search → bvid, titles scored, misses cached), `cnx_video_ids()` (bvid → cid/aid), `cnx_playurl()` (→ m3u8, else progressive `.mp4`; 15-minute cache because playurls expire), `cnx_subtitles()` (platform CC → signed relay tracks, preferred-language ordered), `cnx_resolve()` and `cnx_source_entry()` (the same entry shape 8Stream uses, so the queue and chips need no special case).
- Payloads are read defensively via `cnx_deep_first/strings/nodes` — these services move keys between versions and a missing field must degrade to "no source", never to a fatal.
- Wired as a second custom-player source in `includes/get_movie_stream.php`, `includes/get_tv_stream.php` and `includes/stream.php` (anime, attempt 1.6), behind `CNEXTRACT_ENABLED` **and** a non-empty key, so the default install is byte-for-byte unaffected.
- Caption plumbing: `subtitle_sign($url, $format)` now carries the conversion in the HMAC (`f=bilijson`), `subtitles_json_to_vtt()` turns Bilibili's `{body:[{from,to,content}]}` into WebVTT, the host gate accepts `*.hdslb.com`, and the proxy attaches the `Referer: https://www.bilibili.com/` that CDN demands (legacy no-`f` links still verify).

**To go live:** `define('CNEXTRACT_KEY', '…')` in `config/config.local.php` (gitignored) and `CNEXTRACT_ENABLED` → true. `CNEXTRACT_LANGS` (default `zho,eng`) decides which platform captions are preferred.

**Verified (no key yet, so nothing live was called):** `php -l` ✓ · the client returns null for every entry point while the key is empty (inert) ✓ · CC JSON → VTT with correct cue timing and UTF-8 Chinese ✓ · signed link round-trips, a tampered `f` is rejected, legacy links still verify ✓ · `*.hdslb.com` passes the gate while `hdslb.com.evil.tld` does not ✓.
**Still to verify with the key:** the real response shapes (the deep readers are a first pass — the first keyed run should dump one raw payload and tighten them), and Bilibili's region-lock behaviour for a non-CN server.

### Browse-page design pass: even card grid, filter badges, quiet player chrome (IMPLEMENTED)

**Asked:** the Movies / Series / Anime pages still looked bad, upgrade the filter design (badge-like on desktop, select-like on mobile, options styled too), captions stayed on screen after switching subtitles off, the watermark should use the logo font at a lower opacity, no hover text on the player's menu icon, and the next-episode button icon removed.

**1. Cards and rails (what "the design looks bad" actually was).** In `assets/css/home.css`:
- the poster is now **pinned inside its 2:3 box** (`.movie-card .thumb-wrapper img { position:absolute; inset:0; object-fit:cover; object-position:center top }`). In flow, an image could still claim its own intrinsic height back on a square/wide poster and that is what ragged-nized a row.
- every card in a row is now one height: `.show-item-con` / `.kp-cat-rail` use `align-items: stretch`, `.watch-item` is the flex wrapper (`.watch-item > .kp-card-link` fills it, `.kp-card-info` flexes, `.kp-card-meta` pins to the bottom).
- `.kp-card-title` is clamped to **two lines with `min-height: 2.6em`**, so a long title neither pushes the meta line down nor leaves the neighbour looking shorter.
- rail chrome: `.kp-rail` is a soft gradient card (`#2d2d33 → #212127`, radius 14, real shadow) and "View all" is a chip at the end of the heading instead of a stray link.

**2. Filters — badges on a desktop, selects on a phone.** `.kp-filter-bar` keeps one markup (all three pages feed it `<select>`s):
- a filter sitting on its "All …" default is a **quiet grey pill**; the moment it carries a value `select:has(option:checked:not([value=""]))` fills it with the brand colour (+ soft shadow). One glance at the row now says which filters are on.
- `color-scheme: dark` on the select asks the engine for a **dark native picker list** — the only lever that styles `<option>` on Android/Safari — and the desktop list is themed explicitly (`option` background `#1b1b22`, `option:checked` pink).
- on a phone they stay real full-width selects in a two-column grid (native picker under the thumb), with a small "Filters" caption above the row; `[data-theme="light"]` flips `color-scheme` back.

**3. Player chrome (`watch.php`, `assets/css/watch_page_style.css`):**
- **Turning subtitles off now clears the painted cue.** `mode = 'disabled'` only stops the *next* cuechange, so the line already on screen stayed until the video advanced. `applySub()` now also sets `art.subtitle.show = false` (ArtPlayer's stylesheet shows `.art-subtitle` only while `.art-subtitle-show` is set) and empties `art.template.$subtitle`; re-showing the same file calls `st.update()` so the cue returns at once instead of waiting for the next one.
- **No hover bubbles anywhere.** ArtPlayer prints one for anything carrying a `tooltip` — including its own gear and fullscreen buttons (`Show Setting`). `kpKillHints()` strips the `hint--*` class (its stylesheet is `[class*=hint--][aria-label]:after`), keeping the `aria-label` so the buttons stay labelled for a screen reader. A debounced `MutationObserver` catches the chrome ArtPlayer builds later (the settings panel renders on demand). The helper is exported as `window.kpWatchHints` for the legacy player script, which runs outside the main IIFE. Settings **rows keep their right-hand value text** — that is a separate `<span>`, so Server / Quality / Subtitles still show what is selected.
- **The extra bar icon is gone:** `fullscreenWeb` was removed from the options (the arrow-in-a-box button — the real fullscreen button next to it covers the same need).
- **The bottom-bar CC button now lights up** while a caption track is active: `Controls` has no `.get()`, controls are plain properties (`art.controls['kp-cc']`), so `setCcOn()` had been a silent no-op.
- the unused `KPIcons.next` entry was dropped.

**4. Watermark.** It is now the navbar wordmark itself — the same `'Tangerine'` script and pink glow as `.logo span` — at `opacity: .45` (24px/`.4` under 480px) instead of a Poppins pill in a black box, which read like a UI badge sitting on the video.

**Files:** `assets/css/home.css` (+min — card grid, rail chrome, filter bar), `assets/css/watch_page_style.css` (+min — watermark), `watch.php` (`applySub`, `kpKillHints`/`kpWatchHints`, `setCcOn`, `fullscreenWeb`, `KPIcons`).

**Verified:** `php -l` ✓ · all three inline `watch.php` scripts extracted + `node --check` ✓ (also `category-rails.js`) · minify rebuilt + `--check` fresh ✓ · scratch probes removed.
**Not verified:** an actual click-through in a browser (subtitle Off, CC light, hover with no bubble) — the behaviour above is read from ArtPlayer v5.4.0's own source (`Component.show` toggles `art-subtitle-show`; `.art-video-player.art-subtitle-show .art-subtitle{display:flex}`) rather than from a live session.

### Player: 8Stream audio languages + CC + native ArtPlayer design (IMPLEMENTED)

**Asked:** add more servers where our custom player can extract a playable link (the recently added 8Stream included), enhance the player UI, make CC + audio language always available, and bring the player back to the *original ArtPlayer* look (per its docs).

**1. Audio language row.** 8Stream carries each audio language as its own HLS stream, so they are not hls.js tracks. `includes/eightstream_api.php` now returns the language list (`eightstream_language_titles`, `eightstream_episode_langs`) and each source entry carries `audio:[{label,lang,imdb,season,ep}]` + `audio_lang`. The player shows an **Audio** setting row; picking one calls the new `includes/get_eightstream_audio.php` (session-gated) and re-plays in place, preserving the playhead. Verified live: `tt1877830` → `[English, Bengali, Tamil, Telugu]`, `tt11737520` S1E2 → `[Hindi, English]`, and switching returns a **different** master m3u8.

**2. CC always on the bar.** When a source has no caption track the CC control still renders (it notifies "No subtitles for this source") instead of vanishing; when tracks exist it behaves as before. The Subtitles row keeps its `icon`.

**3. Original ArtPlayer design.** Removed the custom "designed pass" CSS overrides (control-bar gradient, button pills/hover, progress gradient, settings/volume panel styling) so ArtPlayer v5's **own injected stylesheet** renders — the brand colour comes from the `theme` option only. Our own additions (CC, Next, setting-row icons) now use inline SVG in the ArtPlayer house style (22px, `currentColor`, 2px round strokes) instead of FontAwesome `<i>` icons, so they blend with the built-ins.

**4. Server list.** The in-player **Server** row keeps listing every source our own player can actually take over (`isCustomPlayer()` — 8Stream HLS + resolver-backed embeds), which is the criterion requested; iframe-only providers stay in the chips outside. (Each entry was relabelled "Auto HD", which hid 8Stream — see the newest section above.)

**Files:** `includes/eightstream_api.php` (languages + `audio` on entries), `includes/get_eightstream_audio.php` (new), `includes/stream.php` (`audio`/`audio_lang` through the queue), `includes/get_movie_stream.php` + `includes/get_tv_stream.php` (entry already carried whole), `watch.php` (`KPIcons`, Audio row, `switchStreamAudio`, CC-always), `assets/css/watch_page_style.css` (+min).
Verified: `php -l` ✓ · inline scripts extracted + `node --check` ✓ · minify rebuilt + `--check` fresh ✓ · per-language resolve returns distinct streams ✓.

### 8Stream provider — movies / TV / anime in our own player (IMPLEMENTED)

**What was asked:** add 8StreamApi (an HLS source) as another provider for movies/anime/TV, play it in our own ArtPlayer, give our player 100% priority, and — when nothing plays — show "our server doesn't have this title, click More servers".

**How it resolves (verified live 2026-09-26):**
1. a base site (allmovieland.*) serves `const AwsIndStreamDomain = '…'` → the rotating player host (`slast430did.com` currently);
2. `GET {player}/play/{imdb}` → the page embeds a config object (`let p3 = {"file":…,"key":…}` for movies, `var pl = new HDVBPlayer({…})` for series);
3. `GET {player}{file}` with `X-Csrf-Token: {key}` → languages (movie) or seasons→episodes→languages (series);
4. `GET {player}/playlist/{leaf.slice(1)}.txt` with the token → the **master m3u8 URL**.

**The catch (why a relay is mandatory):** that m3u8 only answers with the provider's own `Referer`/`Origin` (`https://1xcinema.net/`), and its token embeds the *requesting* IP (`:…:103.x.x.x:`). A browser on our origin can send neither — a bare GET 404s. So the URL handed to the client is always a **signed relay URL** (`includes/eightstream_relay.php`) that attaches the headers server-side and rewrites every child playlist/segment back through itself. Verified end-to-end: master → variant (664 KB) → segment `206 video/mp2t` (`47 40 11` TS sync).

**Files:**
| File | Change |
|---|---|
| `config/config.php` | `EIGHTSTREAM_*` switches; `$GLOBALS['EIGHTSTREAM_BASE_URLS']` (allmovieland.link/.fun/.com) + `EIGHTSTREAM_PLAYER_URLS` (slast430did.com) — tried in order |
| `includes/eightstream_api.php` | **new** — player-domain discovery (cached), page/tree/leaf resolution, m3u8 probe, `eightstream_imdb_for()` (TMDB cross-lookup for anime, cached), `eightstream_source_entry()` |
| `includes/eightstream_relay_lib.php` | **new** — HMAC sign/verify + public-host/media-path gate |
| `includes/eightstream_relay.php` | **new** — attaches Referer/Origin, Range passthrough, playlist rewrite |
| `includes/stream.php` | anime: `stream_try_eightstream()` added (after the ReAnime scraper, before embeds) — hls → our player |
| `includes/get_movie_stream.php`, `includes/get_tv_stream.php` | prepend the 8Stream hls source into a `sources` queue, embeds behind it |
| `watch.php` | `KP_NO_SOURCE_MSG` ("এই টাইটেলটি আমাদের সার্ভারে এখনো নেই — …More servers…"), `iframeFallback()` now returns false while Auto HD is active (no silent iframe drop) |

**Priority model:** the source queue is `8Stream hls` → external embeds. `isCustomPlayer()` already treats a plain `hls` entry as ours, so Auto HD plays it in the ArtPlayer and the embeds move under **More servers**. When every custom source fails, Auto HD stops on `KP_NO_SOURCE_MSG` instead of auto-loading a foreign iframe.

**Anime ordering:** the ReAnime scraper stays first (purpose-built anime source: sub/dub + captions) and 8Stream follows it; on a host without the self-hosted scraper the health check fails fast, so 8Stream becomes the de-facto primary. Move the `stream_try_eightstream()` block above the scraper in `stream_resolve()` to make 8Stream first. **To disable:** `EIGHTSTREAM_ENABLED false`.

### Movies / Series category rails + new Anime page + player polish (IMPLEMENTED)

**Asked:** enhance the TV and movie pages with categories (K-drama, C-drama and plenty more), one row on mobile and two on desktop, build a matching anime page, drop the icon-name tooltip in our player, and fix the watermark that was missing on many videos.

**1. Category rails.** `includes/category_rails.php` (new) is the single table of every category: 15 for TV (K-Drama, C-Drama, J-Drama, Turkish, Thai, Filipino, Korean Shows, Anime Series, Crime & Mystery, Sci-Fi & Fantasy, Action & Adventure, Romance & Drama, Comedy, Reality, Documentary), 14 for movies (Korean/Chinese/Japanese/Hindi/Turkish, Animation, Action, Comedy, Romance, Horror, Thriller, Crime, Sci-Fi, Documentary) and 14 for anime (Trending, Popular, Airing Now, Top Rated + ten genres). Adding a row is one array entry in `kp_category_rails()`.
- Cards are the site's own `render_anime_card()`, so a rail looks like every other section rather than a second design language.
- **Lazy by default.** The first `KP_RAIL_EAGER` rails (2 — one on the anime page, where AniList answers slower) are resolved with the page; the rest ship as eight shimmer skeletons and are filled by `assets/js/category-rails.js` through `includes/category_items.php` as they come near the viewport — one request at a time, and a failed rail keeps its skeletons as tap-to-retry instead of collapsing and shifting the page. A dozen categories therefore cost one or two provider calls on first paint instead of a dozen.
- TMDB rails go through `tmdb_movie_discover()` / `tmdb_tv_discover()` (already cached for an hour); anime rails through the catalogue layer (AniList → ReAnime → Jikan). *Top Rated* uses the new `kp_rail_top_rated()` — a `MediaSort: SCORE_DESC` query — because `anilist_by_genre()` always demands a genre.
- **Two rows on a desktop, no sideways scrolling; one swipe row on a phone.** `.kp-cat-rail` is a plain grid whose column count is pinned per step — 6 columns (default), 5 (`<=1100px`), 4 (`<=900px`) — with the surplus cards hidden by `> *:nth-child(n + …)`, so a rail always shows exactly two rows and a short result set keeps the same card size as its neighbours (the column count never depends on how many cards came back). At `<=768px` it switches to a `flex` swipe row with fixed-width cards, the same pattern as the Top 10 rail. `.kp-rail-skel` is 12 shimmer cards sized like a real card (poster + info) so lazy loading cannot shift the page.
- `KP_RAIL_LIMIT` is 12 (2 rows of 6) and `KP_RAIL_SKELETON` matches it, so the placeholder rows are exactly as tall as the loaded ones.
- **Search and filters now share one card** on all three pages — two stacked boxes above the rails was noise.

**2. New `anime.php`.** Same shape as `movies.php` / `tv.php` — notice, search + filters in one card, rails, then one paginated grid (`anilist_popular` / `catalog_by_genre` / `catalog_search`). Linked from the drawer right after Movies / Series.

**3. Player polish.** Two fixes in `watch.php`:
- The server chips no longer carry a `title` tooltip (the name that appeared on hover); the primary chip is still visually distinct through `.server-chip-primary`.
- **Watermark.** It was hidden unless the state was exactly `playing`, so any re-resolve or audio switch on a slow source left playback with no watermark. `setState()` now hides it only for the gate and the error card, and it starts visible when mounted, so it is on screen whenever our own player owns the screen. It sits at `z-index: 60` (above the video and ArtPlayer's layers, below its control bar), has a stronger contrast pill and scales down under 480px. A foreign iframe still has none — `destroyPlayers()` takes the watermark down together with the player.

**Files:** `includes/category_rails.php` (**new** — table, fetcher, renderer), `includes/category_items.php` (**new** — lazy JSON endpoint), `assets/js/category-rails.js` (+min) (**new**), `assets/css/home.css` (+min — `.kp-rails` / `.kp-rail` / `.kp-cat-rail` / skeleton), `movies.php` + `tv.php` (rails between the filters and the full grid), `anime.php` (**new**), `includes/header.php` (Anime drawer link + the new script), `watch.php` + `assets/css/watch_page_style.css` (chip tooltip, watermark).

**Verified:** all three pages rendered with a faked session — 14 / 15 / 14 rails, correct eager-vs-lazy split, no notices; every rail resolved live (12–18 cards each, e.g. Korean Movies → *Colony*, K-Drama → *The Scandal*, anime Action → *Attack on Titan*); `php -l` ✓ · `node --check` incl. the new script ✓ · minify rebuilt + `--check` fresh ✓.

### Server list shows 8Stream + Font Awesome player icons + auto English subtitles (IMPLEMENTED)

**Asked:** 8Stream was not showing up in the server list, the player icons looked bad, and CC should fetch an English subtitle online whenever a source carries no real one.

**1. "8Stream is missing" was a label bug, not a wiring bug.** `sources[0]` really is the 8Stream HLS entry — probed live: Breaking Bad (`tmdb:tv:1396`) and MobLand (`tmdb:tv:247718`, IMDb `tt31510819`) both resolve to a signed relay URL with `audio = English|Hindi`. But `serverBaseLabel()` relabelled *every* entry our own player can take over as the generic **"Auto HD"**, so the primary chip hid the provider name and the viewer only saw VidCore/NHD behind "More servers". An extracted source is now listed under its own provider name (`8Stream`, `VidCore`, `NHD`, `NHD`/`zokoanime`, `MegaPlay`, `Anikuro`, `ReAnime` — see `KP_PROVIDER_NAMES`), and `"Auto HD"` survives only as the fallback for an entry that carries no provider name. Each chip also gets a `title` ("Auto HD — plays in our own player" / "External player").

> Not everything is fixable: **Business Proposal** (`tmdb:tv:154825`, IMDb `tt14819828`) genuinely 404s on `GET {player}/play/{imdb}` — 8Stream does not carry that title (allmovieland's own library gap). Its entry is therefore correctly absent and the queue falls through to VidCore/NHD; the existing `KP_NO_SOURCE_MSG` already covers the all-failed case. Verified against `tt1877830` / `tt0903747` / `tt11737520` / `tt31510819`, which all resolve fine.

**2. Player icons.** The hand-drawn inline SVGs (`KPI_SVG` + `KPIcons`) were replaced with the site's Font Awesome set — the chips row already speaks `fas` — so the player chrome matches the rest of KitePlay instead of shipping a second icon language next to ArtPlayer's built-in SVGs: `fa-server`, `fa-volume-high`, `fa-closed-captioning`, `fa-gauge-high`, `fa-forward-step`. New `.kp-ic` CSS sizes each glyph into the same 22px slot the built-ins occupy (20px/15px in the settings panel, 19px in the control bar).

**3. Auto English subtitles (no real CC → fetch one online).** New `includes/subtitles_api.php`:
- lookup via the **keyless** OpenSubtitles v3 addon Stremio itself uses — `{base}/subtitles/movie/{imdb}.json` and `…/series/{imdb}:{season}:{episode}.json` (Wyzie and the OpenSubtitles REST API now both demand an API key, hence this one);
- candidates are tried most-specific-first (requested episode → season 1 of the same episode, because anime rows are routinely filed there → the movie entry);
- `subtitles_pick()` keeps the preferred languages (`SUBTITLES_LANGS`, default `eng`), ranks UTF-8 and non-HI/SDH files first, and only falls back to whatever the title has when the preferred language is absent — so a Korean drama still gets captions instead of an empty CC menu;
- results (misses included) are cached in `api_cache` for `SUBTITLES_CACHE_TTL` (1 day), so an episode click costs one API round trip per title at most;
- upstream URLs are never shipped to the browser: each one is HMAC-signed into `includes/subtitle_proxy.php`, which re-verifies the signature, re-checks the `*.strem.io` host gate (redirects re-checked on the *effective* URL), fetches the file and serves it as **WebVTT** (SRT → VTT conversion, BOM + CRLF normalised). The relay is needed because that CDN sends no `Access-Control-Allow-Origin`.

The captions are attached by `subtitles_attach($payload, $imdb, $season, $episode)`, which is a no-op unless the payload is a **direct** (hls/mp4) source that came back with an empty `subtitles` list — an embed payload's captions belong to the foreign player. The first track is marked `default`, so English subtitles simply appear; the CC row and its Off/on switch behave exactly as they did for provider captions.

**4. Switching server inside the player never dismisses ArtPlayer.** `useServer()` used to treat *any* non-primary pick as an explicit server choice, so choosing a server in the in-player **Server** menu turned "Auto HD" off and a failed extraction dropped straight to that server's iframe (ArtPlayer gone, foreign player on screen). The in-player row now calls `useServer(s, {fromPlayer: true})`, which keeps `autoHdMode` on — the pick is an ask for that server, not for a different kind of player — and reopens that entry's attempt budget (`delete resolverTried[url]`, `resolverSpent = 0`) so the server the viewer picked is genuinely extracted again instead of being carried past as "already tried" (which is what used to hand the screen to its iframe). A failed extraction therefore walks on inside our player. The iframe remains reachable exactly where it was intended: a chip in the row *outside* the player (**More servers**), where the click unmistakably means "open this embed".

**Files:**
| File | Change |
|---|---|
| `includes/subtitles_api.php` | **new** — search / pick / sign / SRT→VTT |
| `includes/subtitle_proxy.php` | **new** — signed, host-gated caption relay |
| `includes/get_movie_stream.php`, `includes/get_tv_stream.php` | build the response array, then `subtitles_attach($response, $imdb, [season, episode])` |
| `includes/stream.php` | `stream_attach_sources()` → `stream_attach_subtitles()` (anime: IMDb via the existing `eightstream_imdb_for()` cross-lookup, season via `eightstream_season_for()`) |
| `config/config.php` | `SUBTITLES_*` block (`AUTO_ENABLED`, `TIMEOUT`, `CACHE_TTL`, `RELAY_TTL`, `MAX`, `LANGS`, `API_BASE`) |
| `watch.php` | `KPIcons` → Font Awesome, `KP_PROVIDER_NAMES` + `serverBaseLabel()` rework, chip `title`, `useServer(server, {fromPlayer})` |
| `assets/css/watch_page_style.css` (+min) | `.kp-ic` sizing (replaces the `.kp-ctl svg` rule) |

**Verified live (2026-09-26):** all three paths return English tracks — movie (`tt1877830`, The Batman), TV (`tt0903747` S1E1, `tt14819828` S1E1, `tt11737520` S1E2) and anime (`anilist:5114` → `tt1355642`); `subtitles_attach()` stamps both the payload and `sources[0]`; the proxy answers `HTTP 200 text/vtt` with real cue text (65 KB, `00:00:47.546 --> 00:00:49.966`). Endpoint-level check: Breaking Bad returns `sources[0] = 8Stream` (2 captions) followed by the embed chain, Business Proposal returns embeds only and no captions.

**To disable:** `SUBTITLES_AUTO_ENABLED false` (nothing is fetched, the CC button behaves exactly as before).
Verified: `php -l` ✓ · inline scripts extracted + `node --check` ✓ · minify rebuilt + `--check` fresh ✓.

### Hover preview + shared watchlist modal + enhanced search & notifications (IMPLEMENTED)

**1. Hover preview card (`card-preview.js` + `kp_preview_payload()` + `home.css`)**
- "Watch Now" CTA removed — the panel is pure details; the whole card is still the link.
- New availability strip: release-status pill (Released / Airing Now / Upcoming / Cancelled / Hiatus, provider-status mapped in `statusInfo()`) + real count ("1179 EP", "1179 / 1179 EP" airing vs total, "38S · 1179 EP" TMDB TV, "Movie").
- Payload gains `ae` (aired episodes actually OUT), `ct` (content_type movie/tv), `na` (next airing `{e, ts}` only while ts > now); TMDB TV swaps `ae` to `total_episodes`.
- "Episode N in **2d 4h**" line under the strip, rendered as a `.kp-cd[data-release]` chip so `countdown.js` ticks it and flips it to green "Airing now" (scan re-armed via `kpCountdownScan()`). `window.kpCountdownFmt` is the shared JS formatter (matches `kp_time_left()`).
- CSS: `.kp-preview-avail/-count/-next` + `.kp-status-upcoming/-off`; old `.kp-preview-foot/-cta` deleted.

**2. Watchlist + button on every page (`includes/watchlist_modal.php`, new)**
- The modal + `openCardWatchlist/saveCardWatchlist/removeCardWatchlist` JS moved out of index.php into `includes/watchlist_modal.php`, included by `includes/footer.php` (guarded by `$kp_watchlist_modal_done`) — every page with cards now has the same add-to-list flow (verified: index/genre/schedule/movies/tv/profile/watch).
- Guests get a `kpToast` "Log in to add titles…" (login-redirect toast) instead of a silent 403. Login state rides on `<body data-user="1|0">` (header.php).
- Card `+` buttons now sync: after save the button on the affected card flips to a green tick (`.kp-card-add-btn.is-saved`), after remove back to `+`.

**3. Enhanced search (`includes/search.php` + header.php UI + nav_style.css)**
- Backend: `?kind=all|anime|movie|tv` filter (`search_kind_of()` classifies content_type → tmdb movie/tv → format); richer rows `{title, kind, imdb_id, source, poster, year, rating, episodes, status}` with normalized status (Airing/Upcoming/Released).
- UI: filter chip row (All/Anime/Movies/TV — switching re-runs the query), status pill + kind badge per row, kind+EP in the meta line, empty state names the active filter.
- Keyboard: ↑/↓ move the highlight, Enter opens the highlighted (or first) row, plain Enter with no results saves the term to history. Aborted fetches properly race-guarded (`searchAbort`).
- History rows restyled (classes instead of inline styles: `.kp-history-term/-del/-clear`).

**4. Notifications (`check_notifications.php` + header.php + nav_style.css)**
- **TV follows now actually alert**: `follows` accept `tmdb:tv:N` slugs (regex `^tmdb:tv:\d+$`), and watchlist rows marked Watching track via `follow` (so follow_alerts=0 silences them); TMDB sweep backfills missing `follows.anime_title` from the detail call.
- UI: type filter tabs (All / Episodes / System), Load-more button (15/page, uses the endpoint's existing `pages`), per-filter empty states, poll only re-renders while the panel is closed; `unread` no longer double-counted when clicking rows.

Files: `includes/functions.php`, `includes/header.php`, `includes/footer.php`, `includes/watchlist_modal.php` (new), `includes/search.php`, `includes/check_notifications.php`, `index.php` (modal removed), `assets/js/card-preview.js`, `assets/css/home.css`, `assets/css/nav_style.css`, `sw.js` (VERSION `v5`→`v6`).

### Session follow-up 3: premium sidebar (trending ranks + Upcoming day tabs + My Pulse) (IMPLEMENTED)

1. **Top Trending premium look** — `render_trend_item()` (`includes/functions.php`) now emits an air-status pill (`kp-trend-status`: pulsing green Airing / amber Upcoming / quiet Released, unknown stays silent), a next-episode strip (`kp-trend-next`: "EP 5 · 2d 4h" from `next_airing`), and an `is-top` class for ranks 1–3. CSS (`home.css`): gradient medals (gold/silver/bronze `-webkit-background-clip:text`) on the top-3 ranks, glowing red left edge on podium rows, glass card rows with red hover glow + lift, banner zoom on hover, gradient play button reveal.
2. **Upcoming day tabs** — `kp_group_upcoming()` + `render_upcoming_item()` (`functions.php`) split the upcoming fetch into TODAY (rest of today + 12h grace) / NEXT (tomorrow…7d) / LATER (beyond/undated), each sorted by air time (undated last). index.php renders 3 panes + TODAY/NEXT/LATER tabs (default tab = first non-empty group); `home-sections.js` toggles panes (pure class toggle, zero network, stacks without JS). Rows show "EP 4 · Fri, 25 Sep" + live countdown chip; empty groups get a friendly `kp-up-empty` note.
3. **Second side panel: My Pulse** — index.php sidebar now has a third card under Upcoming: Continue Watching (top 3 from `progress_recent_anime`, poster + "S2 · EP 4" + gradient progress bar + %) with graceful empty state, plus a 2×2 Shortcuts grid (Schedule / Watchlist / Alerts / Settings).

Files: `includes/functions.php`, `index.php`, `assets/js/home-sections.js`, `assets/css/home.css`, `sw.js` (VERSION → `v9`). Verified: `php -l` ✓, `node --check` ✓, minify rebuilt ✓, server-render check — 10 trend rows (3 podium, 10 status pills, 4 next-EP strips), 3 up-panes (active = first non-empty), pulse card + empty state ✓.

### Session follow-up: dropdown fix + TMDB airing status + preview anchor (IMPLEMENTED)

1. **Navbar dropdowns dead** — the notification-JS rewrite had left a duplicated `.then()` block after the tab-handler code, so inline script 2 threw `Unexpected token '}'` on every page: no search/notification/user popup opened. The dead block (header.php ~1177–1246) is removed; all 4 inline scripts parse (`new Function` check).
2. **TMDB TV showed "Released" while episodes are Coming Soon** — `tmdb_apply_availability()` (new, `tmdb_movie_api.php`) now derives real availability from air dates inside both normalizers: `next_episode_to_air` future → RELEASING/NOT_YET_RELEASED + `next_airing` (feeds the hover "Episode N in 2d 4h" chip), `first_air_date` future → NOT_YET_RELEASED, TMDB status Canceled/Ended → FINISHED; `aired_episodes` counts seasons only up to the last aired one. List rows (trending/discover) carry no status/next-ep keys, so the hover card ALSO derives: `na.ts > now` → "Airing Now" regardless of the status string (card-preview.js `statusInfo`). Cached `detail:v4` payloads predate this — the derived fields compute at normalize time so only stale list caches matter (list TTL is short).
3. **Which card is the preview for?** — `place()` now tags the panel with a side class (`kp-from-right/left/bottom`), CSS draws a rotated-square arrow pointing at the source card (`--kp-arrow-top` follows the card's midline), and the source card gets `.kp-preview-source` (accent ring + glow + lift) while its preview is open.

Files: `includes/header.php`, `includes/tmdb_movie_api.php`, `assets/js/card-preview.js`, `assets/css/home.css`, `sw.js` (VERSION → `v7`).

### Session follow-up 2: TMDB list rows resolve real status on hover (IMPLEMENTED)

The deeper fix for "list page says Released while episodes are Coming Soon": TMDB's
list/search/discover endpoints carry NO `status`, `next_episode_to_air` or
`last_episode_to_air` keys, so a list row can never know if a show is airing.
Instead of guessing:

1. **PHP** — rows where status is unknowable get `tvq: 1` + `st: ''` (and
   `aired_episodes: 0`) in `tmdb_apply_availability()`. `kp_preview_payload()`
   forwards `tvq` into the card's data-kp JSON.
2. **JS** — `statusInfo()` renders a neutral spinning "Details…" badge for
   `tvq` rows instead of "Released"; `availCount()` hides the misleading EP count.
3. **On first hover** — `resolveStatus()` fetches
   `includes/tmdb_avail.php?id=…` (new; wraps the DB-cached
   `tmdb_tv_detail`/`tmdb_movie_detail`), patches the card's `data-kp` live and
   re-renders the open panel — so "Airing Now / Episode 2 in 1d 4h" fills in,
   and every later hover of that card is instant from the patched payload.
4. Detail cache keys bumped (`detail:v4`→`v5` tv, `detail:v2`→`v3` movie) so old
   pre-derivation payloads don't serve stale status through the endpoint.
   **NOTE:** list caches (`trending/popular/search/…`) cache the *normalized*
   items — entries built before the tvq fix keep their guessed status until
   their TTL expires (list TTLs are short) or the rows are cleared from
   `api_cache`.

Verified: tv.php now ships 20 `tvq:1` rows, 0 guessed "FINISHED";
`tmdb_avail.php?id=tmdb:tv:247718` → `{"st":"RELEASING","ae":20,"na":{"e":2,"ts":…},"e":2}`;
bad id → 400.

Files: `includes/tmdb_movie_api.php`, `includes/tmdb_avail.php` (new),
`includes/functions.php`, `assets/js/card-preview.js`, `assets/css/home.css`, `sw.js` (→ `v8`).
Verified: `php -l` all touched files ✓ · `node --check` (src + min) ✓ · minify 20 built + `--check` fresh ✓ · live curl: search kind=movie filters correctly, `data-kp` payloads carry `ae/ct/na`, wl-modal present on all 7 page types with `+buttons`, notifications `?type=` + `pages` work, movie page shows `ct:"movie"` ✓ · 0 PHP warnings.

### Performance / PWA (previous sessions — details in `docs/performance.md`)
- DB indexes, response caching (`api_cache_*` in `includes/http.php`), image lazy-loading + `kp_img_attrs`.
- Service worker `sw.js`: shell + runtime caches, offline page, **VERSION currently `v3`** (bump on any precached asset change).
- Fonts self-hosted in `assets/fonts/` via `fonts.css`.
- `tools/minify.php` builds `.min` variants; `kp_asset()` serves the min build when fresh. **Run `php tools/minify.php && php tools/minify.php --check` after touching any JS/CSS.**

### Locked upcoming episodes + arrival dates (this session — IMPLEMENTED)
What users see:
1. **Full episode list** — all planned episodes render (e.g. 12/12), not just aired ones.
2. **Locked rows** — unaired episodes get `.kp-ep-locked` (lock icon, not clickable, muted).
3. **Arrival date** — exact "Oct 5 · 20:30" from AniList `airingSchedule(notYetAired:true)`; weekly estimates show "≈ Oct 12" (italic + tooltip).
4. **Live countdown chip** `.kp-cd` — ticks every second, turns green "Airing now" at zero and **auto-unlocks the row live** via `kp:released` event + toast.
5. **"Coming Soon · N scheduled" divider** above the first locked row (hidden while episode search is active).
6. **Click locked row** → toast with arrival date/time.
7. **Details "Next EP"** → date · time + countdown chip.
8. **Homepage Upcoming sidebar** → "Arrives Fri, 05 Sep" + chip (`.kp-side-air`); "Premieres <date>" when no schedule yet.

Files touched:
| File | Change |
|---|---|
| `includes/anilist_api.php` | aired semantics fix (§4), `anilist_card_episode()` null pre-premiere, new `anilist_upcoming_airing($id)` → `airingSchedule(notYetAired:true)` map ep→unix ts (cached `CACHE_TTL_SCHEDULE`) |
| `includes/functions.php` | new `kp_time_left($ts)` + `kp_countdown_chip($ts)` after `kp_e` |
| `watch.php` | lock block fires for aired==0 too; exact schedule + startDate(JST) premiere fallback + weekly estimate; `ax` flag in KP JSON; arrival text + chip + locked-click toast; `kp:released` auto-unlock listener; Coming Soon divider; search hides divider; Next EP time + chip |
| `index.php` | Upcoming sidebar arrival row + chip |
| `assets/js/countdown.js` | **new** generic ticker (contract in file header) |
| `includes/header.php` | loads `countdown.js` (defer) |
| `assets/css/home.css` | `.kp-cd`, `.kp-side-air`, ≤640px hides side arrival |
| `assets/css/watch_page_style.css` | `.kp-ep-coming`, `.kp-ep-air-est`, ≤640px hides date (chip stays) |
| `sw.js` | precache `countdown.min.js`, VERSION `v1`→`v2` |
| `docs/handoff.md` | this file |

### Footer redesign + install confirmation modal (IMPLEMENTED)

**Footer** (single source `includes/footer.php` → every page gets it):
1. Desktop: 4-column grid — brand (logo + tagline + socials), Explore, Account, Data & Support (credit pills: AniList / TMDB). Phone: brand centred across both columns, then Explore + Account side by side (2-col), support full width → no more one long left-aligned list.
2. **Install CTA strip** (`.footer-cta`) between the grid and the bottom bar: icon + copy + `#kp-footer-install`. Full-width button ≤760px, so the CTA is never buried under the credit text.
3. Bottom bar: `© year · Made with ♥` + **back-to-top** (`#kp-back-top`), now a labelled pill on phones instead of hidden.
4. Panels: `#101018`, gradient hairline on the top edge (`.site-footer::before`), violet halo `.footer-glow`, gradient tick before each column label (`.footer-col h4::before`), link lists are `<ul class="footer-links">`.
5. Breakpoints: `≤1024px` support column → own row · `≤760px` phone layout · reduced-motion guard.

**Install flow** (`assets/pwa/install-prompt.js` + `.css`):
1. `window.kpConfirmInstall()` opens a custom confirmation modal (`.kp-install-overlay` / `.kp-install-modal`): badge, 3 benefit rows, **Not now** / **Install**; Esc + backdrop close, focus trap, focus restore, `body.kp-install-noscroll` scroll lock, z-index 10001 (above the 10000 banner).
2. Confirming runs the native prompt; with no `beforeinstallprompt` (iOS Safari / desktop Safari) the same dialog swaps to numbered **per-platform manual steps** (iOS Share → Add to Home Screen, Chrome ⋮ → Install app, else browser menu) instead of dead-ending.
3. Both the footer button and the banner's Install button go through that modal. `window.kpRequestInstall()` (native prompt only, returns false when unavailable) is unchanged for other callers; footer falls back to `kpToast` guidance only if the script never loaded.
4. **Bug fixed:** the asset-base regex assumed `install-prompt.js`, but `kp_asset()` usually serves `install-prompt.min.js?v=<mtime>` — BASE then kept the whole src and the injected stylesheet 404'd (footer banner rendered unstyled). Now `/install-prompt(\.min)?\.js.*$/i` strips file + query, with a `document.scripts` fallback when `document.currentScript` is null.
5. `deferred` is captured even after a permanent banner dismissal, so the footer CTA can still use the native prompt.
6. **The sheet is linked by `includes/footer.php`, not injected at click time.** Injecting it meant the dialog could paint before its own CSS applied, so it appeared as unstyled text at the bottom of the document (no overlay, no card). The tag uses the same lazy pattern as the header sheets (`data-kp-lazy` + `media="print"` + onload swap, retried by header.php's guard), so it is applied long before anyone clicks; `ensureCss()` still injects it on pages with no footer.
7. Belt and braces: banner and dialog are built with inline `display: none` and revealed only once the sheet is *really* live. `sheetApplied()` checks that a `kp-install-*` rule actually reached `link.sheet` — Chrome still creates an EMPTY sheet for a same-origin URL it refuses to apply (403/404 body, wrong MIME), so `link.sheet` alone would open the dialog at zero rules. If the sheet never arrives (error event, or a 1.5s cap) the dialog stays hidden and `guidanceToast()` shows the browser-menu path through `kpToast`. `appinstalled` now also toasts a confirmation.

Files: `includes/footer.php`, `assets/css/nav_style.css` ("Site footer" section), `assets/pwa/install-prompt.css`, `assets/pwa/install-prompt.js`, `sw.js` VERSION `v3`→`v4`→`v5` (nav_style.min.css + install-prompt.* are precached).
Verified: `php -l` ✓ · `node --check` (src + min) ✓ · minify 20 built + `--check` fresh ✓ · headless-Chrome probe: modal opens with its own CSS (z-index 10001, radius 20px, scroll lock on), confirm swaps to 3 platform steps, Esc closes, works on both the `.js` and `.min.js?v=` builds; footer grid measured 4-col at 1440px, 3-col + full-width support row at 1000/800px, 2-col with a centred brand + full-width install button at 390px ✓ · click-through on the real page: sheet linked + applied before the click, dialog styled in the same tick, Install → 3 manual steps (headless has no native prompt), × and "Not now" both close and release the scroll lock ✓ · cold cache: hidden on click, styled ~100 ms later ✓ · refused sheet (403): stays hidden, falls back to a toast ✓ · 0 PHP warnings on 3 pages ✓.

### Stability pass — stale assets + squashed posters (IMPLEMENTED)

The complaint was "some reloads render the design broken / watchlist thumbnails look wrong". Three separate causes, all fixed:

**1. Posters squashed to a 690px sliver (the watchlist/continue-watching thumbnails).**
`kp_img_attrs()` emits `width="460" height="690"` so the box can be reserved before the artwork lands, and those attributes are *presentational hints* — a `height` hint beats `aspect-ratio`. Every rule shaped `.x img { width: …; aspect-ratio: 2 / 3 }` (no `height`) therefore rendered at the attribute height: measured in-browser, a watchlist poster was **159×690** instead of 159×239, i.e. a hopelessly zoomed crop. Fix is one line in `critical.css` (inlined, so it beats every later sheet):
```css
img[width][height] { height: auto; }
```
A rule that genuinely wants a fixed height still wins (later sheet / higher specificity). Affected: `.kp-wl-card-poster`, `.kp-wl-thumb img`, `.kp-cw-thumb img`, `.kp-cw-hero-poster`, `.kp-preview-thumb`, `.kp-gate-poster`, `.kp-avatar-choice img`. Verified after: 155×232 @1920px and 219×328 @390px.

**2. Stale CSS/JS served under a fresh URL.**
- `sw.js` matched static assets with `ignoreSearch: true`, so a rebuilt file kept arriving as the pre-edit one for every new `?v=` (new markup + old sheet = broken layout). It is now cache-first keyed by the **exact** URL — a changed file is a changed URL, hence a miss and a fresh fetch — with the `ignoreSearch` match used only as an offline fallback (`Response.error()` when even that misses). `install-prompt.css` / `install-prompt.min.js` are now precached too, and VERSION is `v4` → **`v5`** (bump on any change to sw.js or a precached asset).
- `kp_asset()` versions with `?v=<mtime>-<size>`: Windows/FAT `filemtime()` has 1-second resolution, so two edits in the same second produced the *same* `?v=` for different bytes.

**3. An async sheet that fails to load left the page unstyled forever.**
`nav_style.css` / `home.css` load as `media="print"` and are switched on in `onload`; a failed or stalled request left `media="print"` with no recovery (this is what "reload and the footer loses its CSS" looked like). `includes/header.php` now tags them `data-kp-lazy`, the swap sets `data-kp-ready`, and a tiny guard retries anything still unmarked — forcing `media="all"` and appending one cache-busted copy (`kp-css-retry=1`) — on `onerror`, at `load + 150ms`, and at `3s`. One retry per sheet, so it cannot loop.

Files: `assets/css/critical.css` (+min), `includes/header.php`, `includes/performance.php`, `sw.js`.
Verified (headless Chrome): poster frame 2:3 at 1920/1400/390px · good lazy sheet → ready + applied, 404 sheet → retried exactly once then ready, real page → 2/2 ready with **0** spurious retries · footer still 2-col @390px · `php -l` ✓ · `node --check` (sw.js, install-prompt.min.js) ✓ · minify fresh ✓.

### TMDB TV episode list = the AniList sidebar (IMPLEMENTED)

The complaint was "the anime page shows seasons / episodes / upcoming episodes like *this* — give my TMDB TV series the same". TMDB TV reached the same markup but with placeholder data, so it now feeds the shared renderer real rows.

**What was missing.** `tmdb_tv_detail()` only knows the per-season summary, so the sidebar listed name-less `S1 E1` rows with no air dates and printed "Seasons (18) + 18 episodes in total" instead of an episode count. Nothing was lock-aware, so a currently-airing show offered no "Coming Soon" block at all.

**1. Real rows for the season being watched** — `tmdb_tv_season($id, $n)` (`includes/tmdb_movie_api.php`) reads `/tv/{id}/season/{n}` (name, still, air date, runtime, overview) and `watch.php` swaps those rows over the placeholders for just that season, so a 38-season show still makes one season call instead of 38. The list itself is unchanged: same `episodeElement()`, same `.kp-ep-*` markup, same `kp-ep-coming` divider, same countdown chips — the design was already shared, only the data was missing.
**2. Locks with real dates** — `tmdb_tv_lock_state()` tags unaired episodes `lk` + `a` + `ad` (date-level, TMDB knows the broadcast *day* only), mirroring the AniList fields; the UI prints "Sep 27" without the useless 12:00 AM and derives the `.kp-cd` chip from `a`. It is applied **after** the cache read, so an episode that airs while the season sits cached unlocks by itself instead of waiting out the TTL.
   * `watch.php`'s generic (AniList-shaped, weekly-estimate) lock loop is now skipped for TMDB TV: it compares a *series-wide* `aired_episodes` against per-season episode numbers, which would mis-lock later seasons.
   * An announced episode with **no air date** cannot be locked (nothing to show), so it stays listed as a normal row.
**3. Season tabs + heading** — tabs keep the anime wording (aired count while a season is mid-run, "Season 2 · 1179 eps") and the sidebar gained the same `Episodes (n)` heading the anime page has (`#kp-episodes-heading`), kept in sync on every switch. `toggle` on a tab now shows the `kp-ep-loading` state, fetches **`includes/get_tv_season.php?tmdb_id=&season=`** (same row shape, already lock-tagged, JSON, 400 on bad input) and auto-plays the season's first episode. The endpoint uses the `get_tv_stream.php` gate — `session_start()` + 403 without `userID`, so it is not a free TMDB proxy — and includes `db.php` so `api_cache_*` really caches (0.6s cold → 0.01s warm).
**4. Bare links open the season being aired** — home cards / search / shares carry no `?season=`, so `watch.php` now opens the season of `last_episode_to_air` (the same place the AniList sidebar opens) instead of always season 1. Continue-watching and notification links pass `?season=` and still win.
**5. Cache keys bumped** — `tmdb_tv_detail()` `detail:v3`→`v4` (placeholder titles are now `''`; a leftover `S1 E1` title printed as a junk `· S1 E1` suffix once real names arrived) and `tmdb_tv_season()` → `season:v2` (rows gained runtime/overview).

Files: `includes/tmdb_movie_api.php` (`tmdb_tv_season`, new `tmdb_tv_lock_state`, detail key), `includes/get_tv_season.php` (new), `watch.php` (season upgrade + heading + tabs/switch JS).
Verified (headless Chrome on the real page, both providers): TMDB airing show → `Episodes (10)`, tabs `Season 1 10 eps | Season 2 1 eps*`, `Coming Soon · 9 scheduled`, locked row `S2E2 · Song 2 · Sep 25 · 1d 2h`, season switch → `Episodes (10)` all playable with the loading state in between ✓ · bare link lands on the aired season (The Simpsons → Season 37 active, 38 tabs) ✓ · no locked-without-a-date rows anywhere ✓ · computed styles identical to the anime list — `.kp-ep-coming` dashed pink uppercase 11px, locked row opacity `.55`, active tab `#ff2e63` + 14px glow — at 390px and 1400px ✓ · anime page unchanged: `Episodes (1202)`, 1202 rows, 23 locked, chips `3d 18h / 10d 18h` ✓ · `php -l` ✓ · endpoint 200 JSON / 400 on bad input ✓ · 0 PHP warnings ✓.

## 3. Lock pipeline

```
AniList Media (nextAiringEpisode, episodes, status, startDate)
        │ anilist_normalize()
        ▼
aired_episodes = episodes actually OUT (next-1 / 0 / total)
        │
watch.php: aired < total → rows number>aired get lk=1, a=ts, ax=0|1
        │  ts priority: airingSchedule exact → nextAiring ± weekly → startDate(JST)+7d
        ▼
KP.episodes JSON → episodeElement() renders lock + arrival + .kp-cd chip
        │
countdown.js ticks → at 0 fires kp:released → row unlocks + toast
```
- PHP first-paint text from `kp_time_left()` — **must stay in step with `fmt()` in countdown.js**.
- No DB migration needed: release dates come from the API (local uploads are instant-release; a `release_date` column only matters for future scheduled local uploads — deferred).

## 4. Gotchas / decisions (READ BEFORE "FIXING" aired_episodes)

- **`aired_episodes` semantics changed**: now `next-1` when `nextAiringEpisode` exists, `0` for NOT_YET_RELEASED, else `total`. The old value (`total` always) made the lock condition `aired < total` **never fire** for AniList. Consumers checked: catalog, jikan, reanime, tmdb, export_mal, get_continue_watching, watch-list, watch.php resume, anilist season chain, functions.php cards/hero — all treat `aired` as "content out so far", which the new value satisfies better (cards previously showed the total as current EP).
- `anilist_card_episode()` returns `null` pre-premiere → cards show `?` instead of phantom EP1/EP12.
- Merged split-cour rows: exact schedule only applied when row `src` is empty or equals current id; other members keep the weekly estimate (AniList schedule is per-media).
- AniList cache TTLs live in `includes/http.php` (`CACHE_TTL_*`); airing schedule uses `CACHE_TTL_SCHEDULE`.

## 5. Environment quirks

- `php` on PATH = `D:\Program Files\php-8.4.11\php.exe` — **no mysqli**, fine for `php -l`/minify. XAMPP PHP = `D:\xampp\php\php.exe` — **has mysqli/pdo_mysql**, use it for DB/page probes.
- PowerShell: `Set-Content -Encoding UTF8` writes a BOM (corrupts JSON bodies) — use `[System.IO.File]::WriteAllText($path, $text)`; prefer the editor tool for file writes.
- `users` table has **no `username` column** (a probe once fatal'd on it) — run `SHOW COLUMNS` before guessing columns.
- Naming (conflict-checked): `kp-cd`, `kp_time_left`, `kpCountdownScan`, `kp-ep-coming`, `kp-side-air`, `kp:released`.

## 6. Verification checklist (run after changes)

```
php -l watch.php && php -l index.php && php -l includes/anilist_api.php && php -l includes/functions.php
php tools/minify.php && php tools/minify.php --check
node --check assets/js/countdown.js && node --check assets/js/countdown.min.js
```
Live checks: `watch.php?id=anilist:21` (chip in Next EP; locked rows if total known) · a NOT_YET title → all rows locked with arrival · homepage Upcoming shows `.kp-side-air`.

## 7. UI/UX roadmap (NOT yet implemented — page-by-page plan)

**P0 globals:** mobile bottom nav (Home/Search/Schedule/Watchlist/Profile; hidden on player) · skeleton rollout (CSS exists: `.kp-ep-skeleton`, home skeleton blocks) · card hover glow `translateY(-4px)` + border (tokens: `--primary #7c3aed`, `--accent #ec4899`, radius 12/8px) · ~~back-to-top~~ **done** (footer button) · **dark/light toggle UI** (backend exists: `settings.php` + `save_theme.php`, no header toggle yet) · ~~footer + install banner design~~ **done** (see §2).
**P1 home:** hero polish (autoplay 7s exists) · consistent section headers · wire empty-state CSS everywhere.
**P2 details/player:** sticky mini bar, up-next, skip/next buttons, ambient glow.
**P3 profile/stats/notifications/search/watchlist:** charts, bell popup (backend exists), mega-menu, 404 page, newsletter.
Already present — don't rebuild: hero autoplay, continue-watching, `kpToast`, achievements backend, schedule page w/ air times, `.kp-card-time`, `.kp-side-go`, offline page in sw.js.

## 8. Next steps

1. P0 items one at a time (minify + lint + curl after each).
2. Optional later: local `episodes.release_date` + admin cron (original SQL proposal) only if scheduled local uploads become a thing.
3. Consider `date.timezone = Asia/Dhaka` — Next EP / sidebar `date()` renders in server tz (pre-existing).
