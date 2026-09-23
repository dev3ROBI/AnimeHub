# AnimeHub — Handoff

> Purpose: any future session (or dev) can pick up exactly where work stopped.
> Last updated: 2026-09-23 (locked upcoming episodes + arrival dates session).

## 1. Project snapshot

- **Stack:** vanilla PHP 8 (no framework), MySQL via PDO/mysqli, AniList (primary) + TMDB/Jikan (secondary) APIs, vanilla JS, hand-rolled CSS.
- **Hosting target:** InfinityFree + Cloudflare. Local dev: XAMPP, DB `animehub`, user `root`, no password.
- **Repo:** `https://github.com/dev3ROBI/AnimeHub.git`, branch `main`.

## 2. Completed work

### Performance / PWA (previous sessions — details in `docs/performance.md`)
- DB indexes, response caching (`api_cache_*` in `includes/http.php`), image lazy-loading + `kp_img_attrs`.
- Service worker `sw.js`: shell + runtime caches, offline page, **VERSION currently `v2`** (bump on any precached asset change).
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

**P0 globals:** mobile bottom nav (Home/Search/Schedule/Watchlist/Profile; hidden on player) · skeleton rollout (CSS exists: `.kp-ep-skeleton`, home skeleton blocks) · card hover glow `translateY(-4px)` + border (tokens: `--primary #7c3aed`, `--accent #ec4899`, radius 12/8px) · back-to-top · **dark/light toggle UI** (backend exists: `settings.php` + `save_theme.php`, no header toggle yet).
**P1 home:** hero polish (autoplay 7s exists) · consistent section headers · wire empty-state CSS everywhere.
**P2 details/player:** sticky mini bar, up-next, skip/next buttons, ambient glow.
**P3 profile/stats/notifications/search/watchlist:** charts, bell popup (backend exists), mega-menu, 404 page, newsletter.
Already present — don't rebuild: hero autoplay, continue-watching, `kpToast`, achievements backend, schedule page w/ air times, `.kp-card-time`, `.kp-side-go`, offline page in sw.js.

## 8. Next steps

1. P0 items one at a time (minify + lint + curl after each).
2. Optional later: local `episodes.release_date` + admin cron (original SQL proposal) only if scheduled local uploads become a thing.
3. Consider `date.timezone = Asia/Dhaka` — Next EP / sidebar `date()` renders in server tz (pre-existing).
