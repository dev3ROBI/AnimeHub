<?php
session_start();
if (!isset($_SESSION['userID'])) {
    header("Location: authentication.php");
    exit();
}

include 'includes/db.php';
include_once 'includes/functions.php';
include_once 'includes/tmdb_movie_api.php';
include 'includes/header.php';

$home = catalog_home(20);
$airing   = $home['airing']   ?? [];
$trending = $home['trending'] ?? [];
$popular  = $home['popular']  ?? [];
$provider = $home['provider'];
$hasData  = ($airing || $trending || $popular);

// ── TMDB Movies & TV ───────────────────────────────────────────────────
$tmdbMoviesTrending = tmdb_movie_trending(1);
$tmdbTvTrending     = tmdb_tv_trending(1);
$tmdbMoviesPopular  = tmdb_movie_popular(1);
$tmdbTvPopular      = tmdb_tv_popular(1);
$hasTmdbData = (!empty($tmdbMoviesTrending) || !empty($tmdbTvTrending));

// Recently Watched
include_once 'includes/progress.php';
$recentlyWatched = [];
$rw_rows = progress_recent_anime($_SESSION['userID'], 8);
$watchTimeMap = watch_time_anime_map($_SESSION['userID']);
foreach ($rw_rows as $rw) {
    $slug = $rw['anime_slug'] ?? '';
    if ($slug === '') continue;
    $info = catalog_info($slug);
    if (!$info) continue;

    // TMDB TV stores a season-aware number (see progress_episode_key()), so
    // split it back into season + episode for the label and the link. The
    // stored value stays the handle for every lookup below.
    $cwEpisode = (int)($rw['episode_number'] ?? 1);
    $cwSplit   = progress_split_episode_key($slug, $cwEpisode);
    $info['episode'] = $cwSplit['episode'];
    if ($cwSplit['season'] > 0) $info['season_number'] = $cwSplit['season'];

    // The saved playback position lives in video_progress (keyed by anime +
    // episode) and the runtime comes from the catalogue row, so both are
    // resolved here instead of being read from the history row.
    $cw = progress_card_seconds($_SESSION['userID'], $slug, $cwEpisode, $info);
    // An embed player cannot report a position, so the tracked seconds are the
    // only real evidence of what was watched — show the furthest of the two.
    $info['last_position'] = $cw['progress'];
    $info['duration'] = $cw['duration'];
    $info['watched_seconds'] = $cw['watched'];
    $info['watch_percent'] = $cw['percent'];
    // How much of this series the user has actually been through.
    $info['watched_total'] = (int)($watchTimeMap[$slug]['seconds'] ?? 0);
    $info['watched_episodes'] = (int)($watchTimeMap[$slug]['episodes'] ?? 0);

    $recentlyWatched[] = $info;
}

// Latest episodes (airing + trending merged, deduplicated)
$latestEps = [];
$seenLatest = [];
foreach (array_merge($airing, $trending) as $ep) {
    $id = $ep['id'] ?? null;
    if (empty($id) || isset($seenLatest[$id])) continue;
    $seenLatest[$id] = true;
    $latestEps[] = $ep;
}

// New on Site (popular items not in latest)
$newOnSite = [];
$seenNew = [];
foreach (array_merge($popular, $trending) as $item) {
    $id = $item['id'] ?? null;
    if (empty($id) || isset($seenNew[$id]) || isset($seenLatest[$id])) continue;
    $seenNew[$id] = true;
    $newOnSite[] = $item;
}

// Upcoming — dedicated NOT_YET_RELEASED fetch first (popular/trending lists
// rarely contain unreleased titles, which left this sidebar empty), then any
// airing-with-future-ep items from those lists as filler.
$upcoming = [];
if (function_exists('anilist_upcoming_media')) {
    $upcoming = anilist_upcoming_media(12);
}
$seenUp = [];
foreach ($upcoming as $upIt) {
    if (!empty($upIt['id'])) $seenUp[$upIt['id']] = true;
}
foreach (array_merge($popular, $trending) as $item) {
    if (count($upcoming) >= 12) break;
    $id = $item['id'] ?? null;
    if (empty($id) || isset($seenUp[$id])) continue;
    $aired = $item['aired_episodes'] ?? 0;
    $isUp = (!$aired || $aired <= 0)
        || ($item['status'] ?? '') === 'NOT_YET_RELEASED'
        || (int)($item['next_airing']['airingAt'] ?? 0) > time();
    if ($isUp) {
        $seenUp[$id] = true;
        $upcoming[] = $item;
    }
}

// Top trending for sidebar
$topTrending = array_slice($trending, 0, 10);

// Hero slider pool (anime + movies + TV)
$sliderPool = [];
$seenIds = [];
foreach (array_merge($trending, $airing, $tmdbMoviesTrending, $tmdbTvTrending) as $candidate) {
    $id = $candidate['id'] ?? null;
    if (empty($id) || isset($seenIds[$id])) continue;
    $seenIds[$id] = true;
    $sliderPool[] = $candidate;
}
usort($sliderPool, fn($a, $b) => (int)empty($b['banner']) <=> (int)empty($a['banner']));
$slides = array_slice($sliderPool, 0, 10);

// ── Derived extras ─────────────────────────────────────────────────────
// Quick stats and the Top 10 are computed from the rows above, so these
// sections cost no additional provider requests.
$allItems = [];
foreach (array_merge($popular, $trending, $airing) as $item) {
    $id = $item['id'] ?? null;
    if (empty($id) || isset($allItems[$id])) continue;
    $allItems[$id] = $item;
}

$bestScore = 0.0;
$episodeTotal = 0;
foreach ($allItems as $item) {
    $bestScore = max($bestScore, (float)($item['score'] ?? 0));
    $episodeTotal += (int)($item['aired_episodes'] ?? 0);
}

$episodeTotalText = $episodeTotal >= 1000
    ? rtrim(rtrim(number_format($episodeTotal / 1000, 1), '0'), '.') . 'k'
    : (string)$episodeTotal;

$top10 = array_values($allItems);
usort($top10, fn($a, $b) => (float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0));
$top10 = array_slice($top10, 0, 10);

$genreChips = array_slice(catalog_genres(), 0, 30);

// Genre browser: one genre is picked for us and its first page is rendered
// server-side, so the block is useful before any JavaScript runs.
$homeGenre      = $genreChips[0] ?? null;
$homeGenreItems = [];
$homeGenreUrl   = './genre.php';
if ($homeGenre !== null) {
    $genreResult    = catalog_by_genre($homeGenre, 1, 12);
    $homeGenreItems = $genreResult['items'] ?? [];
    $homeGenreUrl   = './genre.php?' . http_build_query(['g' => $homeGenre]);
}

$quickStats = [
    ['icon' => 'fa-solid fa-star', 'num' => $bestScore > 0 ? number_format($bestScore, 1) : '—', 'label' => 'Top Rated', 'href' => './genre.php?sort=score'],
    ['icon' => 'fa-solid fa-tower-broadcast', 'num' => count($airing), 'label' => 'Airing Now', 'href' => './schedule.php'],
    ['icon' => 'fa-solid fa-clapperboard', 'num' => $episodeTotalText, 'label' => 'Episodes Aired', 'href' => '#latest-episodes'],
    ['icon' => 'fa-solid fa-tags', 'num' => count(catalog_genres()), 'label' => 'Genres', 'href' => './genre.php'],
];
?>

<div class="home-layout">

    <!-- ==================== MAIN CONTENT ==================== -->
    <div class="home-main">

        <?php if (!$hasData): ?>
            <?= render_notice(
                'No data available from any provider. Check config or try again later.',
                'fa-solid fa-triangle-exclamation'
            ) ?>
        <?php endif; ?>

        <!-- Hero Slider (TOP) -->
        <?= render_hero_slider($slides, 'Trending Now') ?>

        <?php if ($hasData): ?>
        <!-- Quick Stats -->
        <div class="kp-quick-stats">
            <?php foreach ($quickStats as $stat): ?>
                <a class="kp-qstat" href="<?= kp_e($stat['href']) ?>">
                    <span class="kp-qstat-icon"><i class="<?= kp_e($stat['icon']) ?>"></i></span>
                    <div class="kp-qstat-info">
                        <span class="kp-qstat-num"><?= kp_e((string)$stat['num']) ?></span>
                        <span class="kp-qstat-label"><?= kp_e($stat['label']) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Top 10 This Week -->
        <?php if (!empty($top10)): ?>
        <div class="kp-section kp-top10-section">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>Top 10 This Week</h2>
                <a href="./genre.php?sort=score" class="kp-view-all">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="kp-top10-scroll">
                <?php foreach ($top10 as $i => $item): ?>
                    <?php
                    $t10Title  = kp_e($item['title'] ?? 'Unknown');
                    $t10Poster = $item['poster'] ?: './uploads/thumbnails/default.png';
                    $t10Score  = $item['score'] ?? null;
                    ?>
                    <a class="kp-top10-card" href="<?= kp_e(kp_watch_url($item)) ?>">
                        <span class="kp-top10-rank"><?= $i + 1 ?></span>
                        <div class="kp-top10-poster">
                            <img src="<?= kp_e($t10Poster) ?>" alt="<?= $t10Title ?>"<?= kp_img_attrs($t10Poster, ['sizes' => '128px']) ?> onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
                            <span class="kp-top10-play"><i class="fas fa-play"></i></span>
                        </div>
                        <div class="kp-top10-info">
                            <span class="kp-top10-title"><?= $t10Title ?></span>
                            <?php if (!empty($t10Score)): ?>
                                <span class="kp-top10-score"><i class="fas fa-star"></i> <?= kp_e($t10Score) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($recentlyWatched)): ?>
        <!-- Continue Watching -->
        <div class="kp-section">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>Continue Watching</h2>
                <a href="profile.php?tab=continue-watching" class="kp-view-all">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="kp-anime-grid">
                <?php foreach (array_slice($recentlyWatched, 0, 6) as $rw): ?>
                    <?php
                    $cwTitle = kp_e($rw['title'] ?? 'Unknown');
                    $cwPoster = $rw['poster'] ?: './uploads/thumbnails/default.png';
                    $cwEp = $rw['episode'] ?? 1;
                    $cwLink = kp_watch_url($rw, $cwEp, $rw['season_number'] ?? null);
                    $cwPos = (int)($rw['last_position'] ?? 0);
                    $cwDur = (int)($rw['duration'] ?? 1440);
                    if ($cwDur <= 0) $cwDur = 1440;
                    $cwPos = min($cwPos, $cwDur);
                    $cwPct = (int)($rw['watch_percent'] ?? ($cwDur > 0 ? round($cwPos / $cwDur * 100) : 0));
                    $cwPct = max(0, min(100, $cwPct));
                    $cwWatchedTotal = (int)($rw['watched_total'] ?? 0);
                    $cwWatchedEps   = (int)($rw['watched_episodes'] ?? 0);
                    $cwEpTotal = $rw['episodes'] ?? null;
                    $cwEpLabel = !empty($rw['season_number'])
                        ? 'S' . (int)$rw['season_number'] . ' E' . (int)$cwEp
                        : (string)(int)$cwEp;
                    ?>
                    <div class="watch-item" data-kp="">
                        <a href="<?= $cwLink ?>" class="kp-card-link" style="text-decoration:none;">
                            <div class="movie-card">
                                <div class="thumb-wrapper">
                                    <img src="<?= kp_e($cwPoster) ?>" alt="<?= $cwTitle ?>"<?= kp_img_attrs($cwPoster, ['sizes' => '30vw']) ?> onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
                                    <div class="kp-card-rating-badge"></div>
                                    <div class="kp-card-hover-overlay">
                                        <button type="button" class="kp-card-play-btn" aria-label="Play"><i class="fas fa-play"></i></button>
                                    </div>
                                    <div class="kp-card-ep-bar">
                                        <span><i class="fas fa-closed-captioning"></i> <?= kp_e($cwEpLabel) ?></span>
                                        <span><i class="fas fa-layer-group"></i> <?= $cwEpTotal ?: '?' ?></span>
                                    </div>
                                </div>
                                <div class="kp-card-info">
                                    <p class="kp-card-title"><?= $cwTitle ?></p>
                                    <div class="kp-cw-progress-inline">
                                        <div class="kp-cw-bar"><div class="kp-cw-fill" style="width:<?= $cwPct ?>%"></div></div>
                                        <span class="kp-cw-pct"><?= $cwPct ?>%</span>
                                    </div>
                                    <p class="kp-card-meta">
                                        <?= kp_e(progress_format_time($cwPos)) ?> / <?= kp_e(progress_format_time($cwDur)) ?>
                                    </p>
                                    <?php if ($cwWatchedEps > 0 || $cwWatchedTotal > 0): ?>
                                    <p class="kp-card-meta kp-card-meta-sub">
                                        <i class="fas fa-clock"></i>
                                        <?= (int)$cwWatchedEps ?> ep · <?= kp_e(progress_format_time($cwWatchedTotal)) ?> watched
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($tmdbMoviesTrending)): ?>
        <!-- Trending Movies -->
        <div class="kp-section">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>Trending Movies</h2>
                <a href="./movies.php?section=trending" class="kp-view-all">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="kp-anime-grid">
                <?php foreach (array_slice($tmdbMoviesTrending, 0, 12) as $movie): ?>
                    <?= render_anime_card($movie) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($tmdbTvTrending)): ?>
        <!-- Trending TV Series -->
        <div class="kp-section">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>Trending TV Series</h2>
                <a href="./tv.php?section=trending" class="kp-view-all">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="kp-anime-grid">
                <?php foreach (array_slice($tmdbTvTrending, 0, 12) as $show): ?>
                    <?= render_anime_card($show) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Latest Episodes -->
        <div class="kp-section" id="latest-episodes">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>Latest Episodes</h2>
                <a href="./schedule.php" class="kp-view-all">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="kp-anime-grid">
                <?php foreach (array_slice($latestEps, 0, 12) as $ep): ?>
                    <?= render_anime_card($ep) ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- New on Site -->
        <?php if (!empty($newOnSite)): ?>
        <div class="kp-section">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>New on Site</h2>
            </div>
            <div class="kp-anime-grid">
                <?php foreach (array_slice($newOnSite, 0, 12) as $item): ?>
                    <?= render_anime_card($item) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Browse by Genre — pick a chip, a few titles load in place -->
        <?php if (!empty($genreChips)): ?>
        <div class="kp-section kp-genre-browse" id="kpGenreBrowse"
             data-genre="<?= kp_e((string)$homeGenre) ?>" data-sort="popular" data-limit="12">
            <div class="kp-section-head">
                <h2><span class="kp-head-bar"></span>Browse by Genre</h2>
                <a href="<?= kp_e($homeGenreUrl) ?>" class="kp-view-all" id="kpGenreViewAll" data-base="./genre.php">
                    View all <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="kp-genre-nav" id="kpGenreNav">
                <?php foreach ($genreChips as $genre): ?>
                    <a class="kp-genre-chip<?= $genre === $homeGenre ? ' active' : '' ?>"
                       data-genre="<?= kp_e($genre) ?>"
                       href="./genre.php?<?= kp_e(http_build_query(['g' => $genre])) ?>"><?= kp_e($genre) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="kp-anime-grid kp-genre-grid" id="kpGenreGrid">
                <?php if ($homeGenreItems): ?>
                    <?php foreach ($homeGenreItems as $item): ?>
                        <?= render_anime_card($item, ['show_ep_badge' => true]) ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="kp-genre-empty"><i class="fa-solid fa-ghost"></i> No titles for this genre yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ==================== SIDEBAR ==================== -->
    <div class="home-sidebar">
        <!-- Top Trending -->
        <div class="kp-sidebar-card">
            <div class="kp-sidebar-head">
                <h3>Top Trending</h3>
                <div class="kp-trend-tabs" id="trendTabs">
                    <button class="kp-trend-tab active" data-period="day">DAY</button>
                    <button class="kp-trend-tab" data-period="week">WEEK</button>
                    <button class="kp-trend-tab" data-period="month">MONTH</button>
                </div>
            </div>
            <div class="kp-trend-list" id="kpTrendList" data-period="day">
                <?php foreach ($topTrending as $i => $item): ?>
                    <?= render_trend_item($item, $i + 1) ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Upcoming — day-grouped (Today / Next / Later), scannable like a mini schedule -->
        <?php if (!empty($upcoming)): ?>
        <?php
        $upGroups = kp_group_upcoming($upcoming);
        // Default tab: the first group that actually has rows — an empty
        // "TODAY" pane is a bad first impression.
        $upDefault = 'today';
        foreach ($upGroups as $upKey => $upRows) {
            if ($upRows) { $upDefault = $upKey; break; }
        }
        ?>
        <div class="kp-sidebar-card">
            <div class="kp-sidebar-head">
                <h3>Upcoming</h3>
                <div class="kp-trend-tabs kp-up-tabs" id="upTabs">
                    <button class="kp-trend-tab<?= $upDefault === 'today' ? ' active' : '' ?>" data-up="today">TODAY</button>
                    <button class="kp-trend-tab<?= $upDefault === 'next' ? ' active' : '' ?>" data-up="next">NEXT</button>
                    <button class="kp-trend-tab<?= $upDefault === 'later' ? ' active' : '' ?>" data-up="later">LATER</button>
                </div>
            </div>
            <?php foreach ($upGroups as $groupKey => $groupItems): ?>
                <div class="kp-side-list kp-up-pane<?= $groupKey === $upDefault ? ' active' : '' ?>" data-up-pane="<?= kp_e($groupKey) ?>">
                    <?php if (!$groupItems): ?>
                        <p class="kp-up-empty"><i class="fa-solid fa-cloud-moon"></i> Nothing airing <?= kp_e($groupKey === 'today' ? 'today' : ($groupKey === 'next' ? 'this week' : 'in this range yet')) ?> — check back soon.</p>
                    <?php endif; ?>
                    <?php foreach (array_slice($groupItems, 0, 7) as $item): ?>
                        <?= render_upcoming_item($item) ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- My Pulse — continue where you left off + one-tap shortcuts -->
        <div class="kp-sidebar-card kp-pulse-card">
            <div class="kp-sidebar-head">
                <h3><i class="fas fa-satellite-dish kp-pulse-ico"></i> My Pulse</h3>
            </div>
            <?php if ($recentlyWatched): ?>
            <div class="kp-pulse-label">Continue Watching</div>
            <div class="kp-pulse-list">
                <?php foreach (array_slice($recentlyWatched, 0, 3) as $rw):
                    $rwEp = (int)($rw['episode'] ?? 1);
                    $rwSeason = (int)($rw['season_number'] ?? 0);
                    $rwSub = $rwSeason > 0 ? 'S' . $rwSeason . ' · EP ' . $rwEp : 'EP ' . $rwEp;
                    $rwPct = max(2, min(100, (int)round((float)($rw['watch_percent'] ?? 0))));
                    $rwPoster = $rw['poster'] ?: './uploads/thumbnails/default.png';
                ?>
                <a class="kp-pulse-item" href="<?= kp_e(kp_watch_url($rw, $rwEp, $rwSeason ?: null)) ?>">
                    <img class="kp-pulse-thumb" src="<?= kp_e($rwPoster) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
                    <div class="kp-pulse-info">
                        <span class="kp-pulse-title"><?= kp_e($rw['title'] ?? 'Unknown') ?></span>
                        <span class="kp-pulse-sub"><?= kp_e($rwSub) ?></span>
                        <div class="kp-pulse-bar"><span style="width: <?= $rwPct ?>%"></span></div>
                    </div>
                    <span class="kp-pulse-pct"><?= $rwPct ?>%</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="kp-up-empty"><i class="fa-solid fa-play"></i> Start watching anything — it will show up here.</p>
            <?php endif; ?>
            <div class="kp-pulse-label">Shortcuts</div>
            <div class="kp-pulse-links">
                <a href="./schedule.php"><i class="fas fa-calendar-days"></i> Schedule</a>
                <a href="./profile.php?tab=watch-list"><i class="fas fa-heart"></i> Watchlist</a>
                <a href="./profile.php?tab=notification"><i class="fas fa-bell"></i> Alerts</a>
                <a href="./profile.php?tab=settings"><i class="fas fa-sliders"></i> Settings</a>
            </div>
        </div>
    </div>

</div>

<?php include 'includes/footer.php'; ?>
