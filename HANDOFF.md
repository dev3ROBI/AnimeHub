# AnimeHub Session Handoff

Working dir: `D:\xampp\htdocs\AnimeHub` (PHP + MySQL, XAMPP).  
Verify: `php -l watch.php; php -l includes/anilist_api.php`  
CLI PHP has curl/PDO but NO mysqli — tests bootstrap PDO only.  
Test script: `C:\Users\iamro\AppData\Local\Temp\opencode\test_season_chain.php`  
Rate limit: AniList ~90 req/min (429s are normal).

## Completed

### Notifications / TMDB / Embeds / Filters / Homepage
- Notification system, TMDB API (`4343034868a20a38c503cc0d3be89ec0`), embed resolvers
- `movies.php` / `tv.php` filters; homepage Movies section removed
- Embed order: nhdapi → vidfast → vidlink → vidsrc-pm → vidsrc-to → vidsrc-cc → vidcore-org → 2embed-skin → 2embed-cc
- URL scheme: `tmdb:movie:{id}` / `tmdb:tv:{id}`

### Watch page design
- Hero: logo 560×148, title 34px, centered; mobile head -64px / logo 86px / title 24px
- Meta panel above cast (`.kp-meta-panel` + Details header)
- Related: width `calc(100% - 48px)`, cards forced `width:100%`, transparent show-container

### AniList season switcher (JUST COMPLETED)
- `anilist_season_chain()` in `includes/anilist_api.php` (~630+)
- SEQUEL/PREQUEL BFS, budget 10, max 32 nodes; formats TV/TV_SHORT/ONA only (drops SPECIAL/MOVIE/OVA e.g. Ryusui)
- **Split-cour grouping**: titles matching `/part|cour [2-9]/i` merge into previous season
  - Dr. Stone id 105333 → **4 seasons** (was 7): S1, S2 Stone Wars, S3 New World+Part2, S4 Science Future+Cour2+Cour3
- Orphans interleaved by air date (stable order → active tab correct after reload)
- Active flag on group containing current entry; tab URL = first member of group
- Members list carries `current` flag for episode offset
- Relation grid filters ALL member ids (parts don't appear in Related)
- Tabs UI: compact wrap chips (no horizontal scroll), inline CSS watch.php ~440, mobile watch_page_style.css ~1565

### Episode labels
- KP payload: `kpSeason`, `kpEpOffset` (watch.php ~1070)
- `$kp_season` init 0 at line 157; set to 1 for any anilist; overridden by active chain `season_num`
- Offset = sum of episodes of members before current in same season group
- `episodeElement()`: AniList → `S3E12` (season + offset); TMDB TV unchanged `S1 E12`; else `EP001`
- Search string uses `s{season}e{ep}`

### Full-season merge + episode list (this session)
- watch.php ~216-297: merges split parts; `dn` (display name) + `src` (source id) fields
- Cross-part episode clicks → `./watch.php?id={src}&ep={n}` (`data-episode = "src:n"`)
- `showNextEpisode` switches src; `updateGate` shows `meta.dn`
- One Piece id 21: episodes=0, aired=1179, list=1179, next 1180 — chip count = list count

### Layout / counts / locks / watch-time / rank (this session, ALL LINT-CLEAN)
- Layout: `.sidebar min-width:0/max-width:440px/overflow:hidden`, `.episode-scroll overscroll-behavior:contain; contain:paint`, `.kp-ep content-visibility:auto; contain-intrinsic-size:42px`, manual scrollTop centering, `#ep-search` 120ms debounce
- Counts: `$episodes_total = max($episodes_total, count($episodes_list))` (watch.php 297/302); anilist `max(episodes, aired_episodes)` (includes/anilist_api.php:651)
- Locks: post-merge block emits `lk` (locked flag) + `a` (air ts, `next_air_ts + (epNo-next_ep)*604800`); `episodeElement` adds `.kp-ep-locked` + fa-lock + `.kp-ep-air` date, click early-returns; CSS 1507-1514
- Watch time: `MIN_WATCH_SESSION_MS = 5000` (watch.php:1238); 10s zeroing + stale comment removed from includes/save_progress.php; heartbeats at line 53
- Rank: `watch_time_rank_ladder($user_id)` (includes/watch_time.php:291); profile.php ladder markup (186) + CSS (.kp-rank-* profile.css 716-815)

### Rank modal (this session, JS WIRED)
- `user/js/rank-modal.js` NEW: `__kpRankModalLoaded` guard, re-parents `#kp-rank-modal` to `<body>`, `resolve()` re-binds after profile-tab innerHTML re-fetch, delegated clicks (button/close/backdrop), Escape closes, restores focus
- Included at root `profile.php:343` (after avatar-picker.js:342)
- `user/js/avatar-picker.js` ~33: cleanup spares `node.id === 'kp-rank-modal'` (both use `.kp-avatar-modal`)
- Ladder tiers: newbie(0s) → weeb-king(2592000s), 10 tiers

### Verification status
- `php -l` PASS: watch.php, includes/watch_time.php, user/profile.php, includes/save_progress.php, includes/anilist_api.php, profile.php
- `node --check` OK (silent): user/js/rank-modal.js, user/js/avatar-picker.js
- Merge tests PASS: test_merge.php, test_merge_rev.php (temp dir)
- Browser verification NOT YET DONE

## Known notes
- Embed sites block localhost iframes (production only)
- CLI cannot test mysqli paths (needs CURLOPT_SSL_VERIFYPEER=false for AniList)
- AoT chain shows 4 seasons (S3+P2, Final+P2 merged); Final Season Part 3 may not be linked yet
- JJK "Kouhen" has no "Part 2" in title → separate season (possible false split)
- Both modals share `.kp-avatar-modal` on `<body>` — cleanup guard is load-bearing, don't remove
- CRITICAL: do NOT re-grep/re-read same sections in loops (agent bug, recurs 3x, ends in JSON parse crash)

## Next / potential
- BROWSER VERIFY (user must do): One Piece watch.php → chip=1179=list, ep1180 locked+date, layout intact w/ 1179 rows, watch-time ticks after 5s, profile → "View all ranks" opens modal (button/backdrop/Escape), close works after switching tabs
- AoT Final Season Part 3 linkage if user complains
- User communicates Banglish — wants premium responsive UI, vertical grids not horizontal scroll
