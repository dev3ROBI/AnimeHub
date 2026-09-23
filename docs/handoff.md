# AnimeHub — Handoff

> Purpose: any future session (or dev) can pick up exactly where work stopped.
> Last updated: 2026-09-24 (hover preview + shared watchlist modal + enhanced search & notifications).

## 1. Project snapshot

- **Stack:** vanilla PHP 8 (no framework), MySQL via PDO/mysqli, AniList (primary) + TMDB/Jikan (secondary) APIs, vanilla JS, hand-rolled CSS.
- **Hosting target:** InfinityFree + Cloudflare. Local dev: XAMPP, DB `animehub`, user `root`, no password.
- **Repo:** `https://github.com/dev3ROBI/AnimeHub.git`, branch `main`.

## 2. Completed work

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
