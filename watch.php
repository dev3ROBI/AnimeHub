<?php
session_start();

if (!isset($_SESSION['userID'])) {
    header("Location: ./authentication.php");
    exit();
}

include_once './includes/db.php';
include_once './includes/functions.php';
include_once './includes/stream.php';
include_once './includes/progress.php';

if (!isset($_GET['id']) || trim($_GET['id']) === '') {
    include_once './includes/header.php';
    echo '<div class="kp-empty"><h2><i class="fas fa-circle-question"></i> Show or Movie not found</h2>'
       . '<p>URL-এ কোনো <code>id</code> দেওয়া হয়নি।</p>'
       . '<a class="kp-primary" href="./index.php"><i class="fas fa-home"></i> Back to home</a></div>';
    include_once './includes/footer.php';
    exit;
}

$raw_id   = trim((string)$_GET['id']);
$parsed   = catalog_parse_id($raw_id);
$provider = $parsed['provider'] ?? 'legacy';
$is_api   = in_array($provider, ['anilist', 'reanime', 'jikan', 'anikuro'], true);

$start_episode  = isset($_GET['ep']) ? max(1, intval($_GET['ep'])) : 1;
$requested_lang = (strtolower($_GET['lang'] ?? 'sub') === 'dub') ? 'dub' : 'sub';

$user_id     = $_SESSION['userID'] ?? null;
$anime_data  = null;
$legacy_data = null;
$is_movie    = false;
// Runtime of one episode, in seconds — the denominator for watch progress.
$episode_runtime_seconds = 0;
$seasons_result = null;
$episodes_list  = [];
$season_id   = isset($_GET['season']) ? intval($_GET['season']) : null;
$episode_id  = isset($_GET['episode']) ? intval($_GET['episode']) : null;
$video_url   = null;

if ($is_api) {
    if ($provider === 'anikuro') {
        $session = anikuro_session_from_id($raw_id);
        $fetched = $session ? anikuro_info($session) : null;
        if ($fetched) {
            $fetched['provider']    = 'anikuro';
            $fetched['provider_id'] = $session;
            $fetched['id']          = $raw_id;
            $anime_data = catalog_fill_item($fetched, 'anikuro');
        }
    } else {
        $anime_data = catalog_info($raw_id);
    }

    if ($anime_data) {
        $episodes_list = $anime_data['episodes_list'] ?? [];
        if (!$episodes_list) {
            $episodes_list = catalog_synthetic_episodes($anime_data);
        }
    }
} else {
    // ─── Legacy locally-stored movie / show ───────────────────────────
    $imdb_id = $raw_id;
    $stmt = $conn->prepare("SELECT * FROM movies WHERE imdb_id = ?");
    $stmt->bind_param("s", $imdb_id);
    $stmt->execute();
    $movie_result = $stmt->get_result();

    if ($movie_result->num_rows > 0) {
        $legacy_data = $movie_result->fetch_assoc();
        $is_movie = true;
    } else {
        $stmt = $conn->prepare("SELECT * FROM shows WHERE imdb_id = ?");
        $stmt->bind_param("s", $imdb_id);
        $stmt->execute();
        $show_result = $stmt->get_result();

        if ($show_result->num_rows === 0) {
            include_once './includes/header.php';
            echo '<div class="kp-empty"><h2><i class="fas fa-circle-question"></i> Movie or Show not found</h2>'
               . '<p>লোকাল ডেটাবেসে এই আইডি নেই।</p>'
               . '<a class="kp-primary" href="./index.php"><i class="fas fa-home"></i> Back to home</a></div>';
            include_once './includes/footer.php';
            exit;
        }

        $legacy_data = $show_result->fetch_assoc();
        $is_movie = false;
        $show_id = $legacy_data['id'];
        $seasons_sql = "SELECT * FROM seasons WHERE show_id = ? ORDER BY season_number ASC";
        $stmt = $conn->prepare($seasons_sql);
        $stmt->bind_param("i", $show_id);
        $stmt->execute();
        $seasons_result = $stmt->get_result();
    }

    $poster_url = $legacy_data['imdb_poster'] ?? '';
    $video_url = $is_movie ? ($legacy_data['video_url'] ?? null) : null;

    if (!$is_movie && $season_id && $episode_id) {
        $episode_sql = "SELECT * FROM episodes WHERE id = ? AND season_id = ?";
        $stmt = $conn->prepare($episode_sql);
        $stmt->bind_param("ii", $episode_id, $season_id);
        $stmt->execute();
        $episode_result = $stmt->get_result();
        if ($episode_result->num_rows > 0) {
            $ep = $episode_result->fetch_assoc();
            $video_url = $ep['video_url'] ?? null;
            if (!empty($ep['poster'])) $poster_url = $ep['poster'];
        }
    }
}

// ─── Metadata / stats ──────────────────────────────────────────────────
$relations = $recommendations = $external_links = [];
$banner_url = '';
$next_airing = null;

if ($is_api && $anime_data) {
    $episode_runtime_seconds = progress_item_runtime_seconds($anime_data);
    $display_title  = $anime_data['title'] ?: 'Unknown Anime';
    $poster_url     = $anime_data['poster'] ?: './uploads/thumbnails/default.png';
    $banner_url     = $anime_data['banner'] ?? '';
    $plot           = $anime_data['description'] ?? '';
    $rating         = $anime_data['score'] ?: ($anime_data['rating'] ?: 'N/A');
    $genre          = !empty($anime_data['genres']) ? implode(', ', $anime_data['genres']) : 'N/A';
    $release_date   = $anime_data['aired'] ?: ($anime_data['year'] ?: 'N/A');
    $runtime        = $anime_data['duration'] ?: 'N/A';
    $director       = $anime_data['studio'] ?: 'N/A';
    $actors         = 'N/A';
    $writer         = 'N/A';
    $language       = 'JP';
    $country        = 'Japan';
    $episodes_total = (int)($anime_data['episodes'] ?: $anime_data['aired_episodes'] ?: 0);
    $anilist_id     = $anime_data['anilist_id'] ?? null;
    $mal_id         = $anime_data['mal_id'] ?? null;
    $relations      = $anime_data['relations'] ?? [];
    $recommendations = $anime_data['recommendations'] ?? [];
    $external_links = $anime_data['external_links'] ?? [];
    $next_airing    = $anime_data['next_airing'] ?? null;
    $anime_status   = $anime_data['status'] ?? '';
    $anime_season   = $anime_data['season'] ?? '';
} else {
    $anime_status = '';
    $anime_season = '';
    $imdb_id = $raw_id;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_views FROM views WHERE imdb_id = ?");
    $stmt->execute([$imdb_id]);
    $total_views = $stmt->fetch()['total_views'] ?? 0;

    $display_title  = $legacy_data['name'] ?? $legacy_data['title'] ?? 'Unknown';
    $plot           = $legacy_data['plot'] ?? '';
    $rating         = $legacy_data['imdb_rating'] ?? 'N/A';
    $genre          = $legacy_data['genre'] ?? 'N/A';
    $release_date   = $legacy_data['release_date'] ?? 'N/A';
    $runtime        = $legacy_data['runtime'] ?? 'N/A';
    $actors         = $legacy_data['actors'] ?? 'N/A';
    $director       = $legacy_data['director'] ?? 'N/A';
    $writer         = $legacy_data['writer'] ?? 'N/A';
    $language       = $legacy_data['language'] ?? 'N/A';
    $country        = $legacy_data['country'] ?? 'N/A';
    $episodes_total = 0;
    $anilist_id     = null;
}

$plot = is_string($plot) ? strip_tags($plot) : '';

if ($is_api) {
    $total_views = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) AS c FROM watch_history WHERE anime_slug = ?");
        $stmt->execute([$raw_id]);
        $total_views = (int)($stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        $total_views = 0;
    }
}

$liked = false;
$likeCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM likes WHERE imdb_id = ?");
    $stmt->bind_param("s", $raw_id);
    $stmt->execute();
    $likeCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($user_id) {
        $check = $conn->prepare("SELECT 1 FROM likes WHERE user_id = ? AND imdb_id = ?");
        $check->bind_param("is", $user_id, $raw_id);
        $check->execute();
        $liked = $check->get_result()->num_rows > 0;
    }
} catch (Exception $e) {
}

$watchlist_status = null;
if ($user_id) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM watchlist WHERE imdb_id = ? AND user_id = ?");
        $stmt->execute([$raw_id, $user_id]);
        $watchlist_status = $stmt->fetch()['status'] ?? null;
    } catch (Exception $e) {
    }
}

$is_following = false;
if ($user_id && $is_api) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM follows WHERE user_id = ? AND anime_slug = ? LIMIT 1");
        $stmt->execute([$user_id, $raw_id]);
        $is_following = $stmt->fetch() !== false;
    } catch (Exception $e) {
    }
}

// ─── Resume state ──────────────────────────────────────────────────────
// Episode-level resume comes from watch_history and works for every provider
// (including embeds); exact seconds come from video_progress and only apply
// to the HLS player we control.
$resume = null;
if ($is_api && $anime_data && $user_id) {
    $resumeTotal = (int)($anime_data['episodes'] ?: $anime_data['aired_episodes'] ?: 0);
    $catalogSlug = $raw_id;
    $resume = progress_last_episode($user_id, $catalogSlug, $resumeTotal);
}

include_once './includes/header.php';
?>

<?php if ($is_api && !$anime_data): ?>
    <div class="kp-empty">
        <h2><i class="fas fa-exclamation-triangle"></i> Anime not found</h2>
        <p>সব ক্যাটালগ প্রোভাইডার <code style="background:rgba(255,46,99,.14); color:#ffb3c7; padding:1px 6px; border-radius:4px;"><?= kp_e($raw_id) ?></code> লোড করতে পারেনি।</p>
        <p style="color:#aaa; font-size:0.9em;">কিছুক্ষণ পরে আবার চেষ্টা করুন — প্রোভাইডার rate limit করতে পারে।</p>
        <a class="kp-primary" href="javascript:location.reload()"><i class="fas fa-redo"></i> Retry</a>
        <a href="./index.php"><i class="fas fa-home"></i> Home</a>
    </div>
<?php else: ?>

<div class="watch-wrapper">
    <div class="video-box">
        <div id="anime-player-container" class="kp-player-shell">
            <div id="artplayer"></div>
            <div id="embedplayer" style="display:none;">
                <iframe id="embed-frame" src="about:blank" allowfullscreen frameborder="0"
                        referrerpolicy="origin"
                        allow="autoplay; fullscreen; encrypted-media; picture-in-picture"></iframe>
            </div>

            <?php if ($is_api): ?>
            <!-- Poster gate: nothing heavy loads until the user presses play. -->
            <div id="kp-gate" class="kp-gate">
                <img id="kp-gate-bg" class="kp-gate-bg" src="" alt="" aria-hidden="true">
                <div class="kp-gate-shade"></div>
                <div class="kp-gate-body">
                    <img id="kp-gate-poster" class="kp-gate-poster" src="" alt="">
                    <div class="kp-gate-copy">
                        <span class="kp-gate-tag" id="kp-gate-source"><?= kp_e($provider) ?></span>
                        <h3 class="kp-gate-ep" id="kp-gate-ep">Episode <?= (int)$start_episode ?></h3>
                        <p class="kp-gate-title" id="kp-gate-title"><?= kp_e($display_title) ?></p>
                        <div class="kp-gate-actions">
                            <button type="button" class="kp-gate-play" id="kp-gate-play">
                                <i class="fa-solid fa-play"></i>
                                <span id="kp-gate-play-label">Play Episode <?= (int)$start_episode ?></span>
                            </button>
                        </div>
                        <p class="kp-gate-note" id="kp-gate-note"></p>
                    </div>
                </div>
            </div>

            <!-- Skeleton while the stream resolves. -->
            <div id="kp-player-loading" class="kp-player-loading" style="display:none;">
                <div class="kp-skeleton"></div>
                <p><i class="fas fa-spinner fa-spin"></i> <span id="kp-loading-text">সোর্স খোঁজা হচ্ছে…</span></p>
            </div>

            <!-- Themed failure state with retry. -->
            <div id="kp-player-error" class="kp-player-error" style="display:none;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <h3>স্ট্রিম লোড হয়নি</h3>
                <p id="kp-error-text"></p>
                <button type="button" id="kp-error-retry"><i class="fas fa-redo"></i> আবার চেষ্টা করুন</button>
            </div>

            <!-- Auto-play next episode overlay -->
            <div id="kp-next-overlay" class="kp-next-overlay" style="display:none;">
                <div class="kp-next-box">
                    <div class="kp-next-info">
                        <span class="kp-next-label">NEXT EPISODE</span>
                        <span class="kp-next-title" id="kp-next-title">Episode 2</span>
                    </div>
                    <div class="kp-next-count" id="kp-next-count">5</div>
                    <div class="kp-next-actions">
                        <button type="button" class="kp-next-play" id="kp-next-play">
                            <i class="fa-solid fa-play"></i> Play Now
                        </button>
                        <button type="button" class="kp-next-cancel" id="kp-next-cancel">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </button>
                    </div>
                    <div class="kp-next-progress">
                        <div class="kp-next-progress-bar" id="kp-next-progress-bar"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="player-status"></div>
        </div>

        <?php if ($is_api): ?>
        <div class="anikuro-controls">
            <div id="lang-toggle"></div>
            <div id="server-chips"></div>
        </div>
        <style>
            .anikuro-controls {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                padding: 10px 12px;
                position: relative;
                z-index: 50;
                overflow: visible;
            }

            #lang-toggle,
            #server-chips {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
                align-items: center;
                position: relative;
                overflow: visible;
            }
        </style>
        <?php endif; ?>
    </div>

    <div class="sidebar">
        <div class="movie-title">
            <i class="fa-solid fa-circle-play fa-beat-fade"></i>
            <span><?= kp_e($display_title) ?></span>
        </div>



        <?php if ($is_api): ?>
            <h4>Episodes<?= $episodes_total ? ' (' . (int)$episodes_total . ')' : '' ?></h4>
            <?php if (!empty($episodes_list)): ?>
                <input type="text" id="ep-search" placeholder="Filter episode…" style="margin-bottom:6px;">
            <?php endif; ?>
            <div class="episode-scroll">
                <div class="episodes" id="anikuro-episode-container">
                    <!-- Loading skeleton — replaced by buildEpisodeList() -->
                    <div class="kp-ep-skeleton" aria-hidden="true">
                        <?php for ($kpSkel = 0; $kpSkel < 6; $kpSkel++): ?>
                            <span class="kp-skel-row"></span>
                        <?php endfor; ?>
                    </div>
                    <p class="kp-ep-loading" role="status"><i class="fas fa-spinner fa-spin"></i> এপিসোড লোড হচ্ছে…</p>
                </div>
            </div>

            <!-- Episode Notes -->
            <?php if ($is_api && $user_id): ?>
            <div class="kp-notes">
                <div class="kp-notes-head">
                    <i class="fas fa-bookmark"></i>
                    <h4>My Notes</h4>
                    <span class="kp-notes-hint">প্লে করার সময়েই টাইমস্ট্যাম্প বসবে</span>
                </div>
                <div id="notes-list" class="kp-notes-list">
                    <p class="kp-note-state"><i class="fas fa-spinner fa-spin"></i> নোট লোড হচ্ছে…</p>
                </div>
                <div class="kp-note-form">
                    <input type="text" id="note-input" maxlength="280" placeholder="Add a note at current time…">
                    <button id="note-save-btn" type="button" aria-label="Save note">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        <?php elseif (!$is_movie && $seasons_result): ?>
            <h4>Seasons</h4>
            <div class="seasons">
                <?php while ($season = $seasons_result->fetch_assoc()): ?>
                    <div class="season <?= ($season['id'] == $season_id) ? 'active-play' : '' ?>" data-season-id="<?= $season['id'] ?>">
                        S<?= str_pad($season['season_number'], 2, '0', STR_PAD_LEFT) ?>
                    </div>
                <?php endwhile; ?>
            </div>

            <h4>Episodes</h4>
            <div class="episode-scroll">
                <div class="episodes" id="episode-container"></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="vdo-rprt-add-list-con">
        <div class="stats-con-and-watchlist">
            <div class="status-item">
                <i class="fas fa-eye"></i>
                <span id="viewCount"><?= number_format($total_views) ?></span>
            </div>

            <div class="status-item like" id="likeButton" data-imdbid="<?= kp_e($raw_id) ?>">
                <i id="likeIcon" class="fa fa-heart <?= $liked ? 'liked' : '' ?>"></i>
                <span id="likeCount"><?= $likeCount ?></span>
            </div>

            <?php if ($is_api && in_array($anime_status, ['RELEASING', 'NOT_YET_RELEASED'], true)): ?>
            <div class="status-item follow <?= $is_following ? 'is-following' : '' ?>" id="followButton" data-slug="<?= kp_e($raw_id) ?>" data-title="<?= kp_e($display_title) ?>">
                <i id="followIcon" class="fas fa-bell <?= $is_following ? 'liked' : '' ?>"></i>
                <span id="followLabel"><?= $is_following ? 'Following' : 'Follow' ?></span>
            </div>
            <?php endif; ?>

            <div class="custom-dropdown-watchlist">
                <div class="dropdown-toggle">
                    <i class="fas fa-list"></i>
                    <?php
                    $wlLabels = [
                        'watching' => 'Watching',
                        'watch_later' => 'Planning',
                        'completed' => 'Completed',
                        'on_hold' => 'On Hold',
                        'dropped' => 'Dropped',
                    ];
                    echo $watchlist_status ? ($wlLabels[$watchlist_status] ?? ucfirst(str_replace('_', ' ', $watchlist_status))) : 'Add to Watchlist';
                    ?>
                </div>
                <ul class="dropdown-menu">
                    <li data-value="watching"><i class="fas fa-play-circle"></i> Watching</li>
                    <li data-value="watch_later"><i class="fas fa-bookmark"></i> Planning</li>
                    <li data-value="completed"><i class="fas fa-check-circle"></i> Completed</li>
                    <li data-value="on_hold"><i class="fas fa-pause-circle"></i> On Hold</li>
                    <li data-value="dropped"><i class="fas fa-times-circle"></i> Dropped</li>
                </ul>
            </div>
        </div>

        <button type="button" class="report-option" id="kp-report-open" aria-haspopup="dialog">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span class="kp-report-label">Report</span>
        </button>
    </div>

    <!-- Report problem modal -->
    <div class="kp-report-modal" id="kp-report-modal" aria-hidden="true">
        <div class="kp-report-backdrop" data-kp-report-close></div>
        <div class="kp-report-dialog" role="dialog" aria-modal="true" aria-labelledby="kp-report-title">
            <div class="kp-report-head">
                <h3 id="kp-report-title"><i class="fas fa-triangle-exclamation"></i> সমস্যা রিপোর্ট করুন</h3>
                <button type="button" class="kp-report-close" data-kp-report-close aria-label="Close">&times;</button>
            </div>

            <label class="kp-report-field">
                <span>সমস্যা কী?</span>
                <select id="kp-report-reason">
                    <option value="video_not_playing">ভিডিও প্লে হচ্ছে না</option>
                    <option value="wrong_episode">ভুল এপিসোড</option>
                    <option value="audio_desync">অডিও/ভিডিও সিঙ্ক নেই</option>
                    <option value="no_subtitles">সাবটাইটেল নেই</option>
                    <option value="wrong_anime">ভুল অ্যানিমে</option>
                    <option value="buffering">বারবার বাফার হচ্ছে</option>
                    <option value="other">অন্য কিছু</option>
                </select>
            </label>

            <label class="kp-report-field">
                <span>বিস্তারিত (ঐচ্ছিক)</span>
                <textarea id="kp-report-details" rows="3" maxlength="500" placeholder="যা দেখছেন সেটা লিখুন…"></textarea>
            </label>

            <div class="kp-report-meta">
                <span><i class="fas fa-film"></i> EP <?= (int)$start_episode ?></span>
                <span id="kp-report-source"><i class="fas fa-server"></i> <?= kp_e($provider === 'legacy' ? 'local' : $provider) ?></span>
            </div>

            <div class="kp-report-actions">
                <button type="button" class="kp-report-cancel" data-kp-report-close>বাতিল</button>
                <button type="button" class="kp-report-submit" id="kp-report-submit"><i class="fas fa-paper-plane"></i> পাঠান</button>
            </div>
        </div>
    </div>

    <div class="movie-details">
        <?php if (!empty($banner_url)): ?>
            <div class="kp-banner">
                <img src="<?= kp_e($banner_url) ?>" alt="" loading="lazy">
            </div>
        <?php endif; ?>

        <div class="movie-title">
            <i class="fas fa-film"></i>
            <span><?= kp_e($display_title) ?></span>
            <span class="kp-badge"><?= kp_e($provider === 'legacy' ? 'local' : $provider) ?></span>
        </div>
        <div class="movie-description">
            <p><?= $plot !== '' ? nl2br(kp_e($plot)) : 'No description available.' ?></p>
        </div>

        <ul class="kp-meta-grid">
            <li><i class="fas fa-star"></i><strong>Rating:</strong><span class="kp-val"><?= kp_e($rating) ?></span></li>
            <li><i class="fas fa-theater-masks"></i><strong>Genres:</strong><span class="kp-val"><?= kp_e($genre) ?></span></li>
            <li><i class="fas fa-calendar-alt"></i><strong>Released:</strong><span class="kp-val"><?= kp_e($release_date) ?></span></li>
            <li><i class="fas fa-clock"></i><strong>Duration:</strong><span class="kp-val"><?= kp_e($runtime) ?></span></li>
            <li><i class="fas fa-video"></i><strong>Studio:</strong><span class="kp-val"><?= kp_e($director) ?></span></li>
            <li><i class="fas fa-language"></i><strong>Language:</strong><span class="kp-val"><?= kp_e($language) ?></span></li>
            <li><i class="fas fa-globe-asia"></i><strong>Country:</strong><span class="kp-val"><?= kp_e($country) ?></span></li>
            <li><i class="fas fa-database"></i><strong>Source:</strong><span class="kp-val"><?= kp_e(ucfirst($provider)) ?></span></li>
            <?php if (!empty($anilist_id)): ?>
                <li><i class="fas fa-link"></i><strong>AniList:</strong>
                    <a class="kp-val" href="https://anilist.co/anime/<?= (int)$anilist_id ?>" target="_blank" rel="noopener" style="color:#ff2e63; text-decoration:none;">#<?= (int)$anilist_id ?></a>
                </li>
            <?php endif; ?>
            <?php if (!empty($mal_id)): ?>
                <li><i class="fas fa-link"></i><strong>MAL:</strong>
                    <a class="kp-val" href="https://myanimelist.net/anime/<?= (int)$mal_id ?>" target="_blank" rel="noopener" style="color:#ff2e63; text-decoration:none;">#<?= (int)$mal_id ?></a>
                </li>
            <?php endif; ?>
            <?php if ($next_airing && !empty($next_airing['episode'])): ?>
                <li><i class="fas fa-tower-broadcast"></i><strong>Next EP:</strong>
                    <span class="kp-val"><?= (int)$next_airing['episode'] ?><?= !empty($next_airing['airingAt']) ? ' · ' . kp_e(date('D, d M', (int)$next_airing['airingAt'])) : '' ?></span>
                </li>
            <?php endif; ?>
        </ul>

        <?php if (!empty($external_links)): ?>
            <div style="margin-top:16px;">
                <strong style="color:#ddd;"><i class="fas fa-up-right-from-square"></i> Official / Streaming</strong>
                <div class="kp-links">
                    <?php foreach (array_slice($external_links, 0, 10) as $link): ?>
                        <a href="<?= kp_e($link['url']) ?>" target="_blank" rel="noopener">
                            <?= kp_e($link['site'] ?: 'Link') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($relations)): ?>
        <?= render_card_row('Related Anime', 'fa-solid fa-diagram-project', array_slice($relations, 0, 12), ['show_ep_badge' => false]) ?>
    <?php endif; ?>

    <?php if (!empty($recommendations)): ?>
        <?= render_card_row('You Might Also Like', 'fa-solid fa-thumbs-up', array_slice($recommendations, 0, 12), ['show_ep_badge' => false]) ?>
    <?php endif; ?>
</div>

<?php if ($is_api): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"></script>
<script>
    (function () {
        const KP = {
            id: '<?= addslashes($raw_id) ?>',
            catalogId: '<?= addslashes($raw_id) ?>',
            anilistId: <?= !empty($anilist_id) ? (int)$anilist_id : 'null' ?>,
            title: '<?= addslashes($display_title) ?>',
            poster: '<?= addslashes($poster_url) ?>',
            banner: '<?= addslashes($banner_url) ?>',
            provider: '<?= kp_e($provider) ?>',
            total: <?= (int)$episodes_total ?>,
            duration: <?= (int)$episode_runtime_seconds ?>,
            episode: <?= (int)$start_episode ?>,
            lang: '<?= $requested_lang ?>',
            userId: <?= $user_id ? (int)$user_id : 'null' ?>,
            resume: <?= json_encode($resume ?: null, JSON_UNESCAPED_UNICODE) ?>,
            episodes: <?= json_encode(
                array_map(fn($e) => ['n' => (int)$e['number'], 't' => (string)($e['title'] ?? '')], $episodes_list),
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            ) ?>
        };

        const statusEl   = document.getElementById('player-status');
        const chipsEl    = document.getElementById('server-chips');
        const langEl     = document.getElementById('lang-toggle');
        const embedBox   = document.getElementById('embedplayer');
        const embedFrame = document.getElementById('embed-frame');
        const artBox     = document.getElementById('artplayer');

        const gateEl        = document.getElementById('kp-gate');
        const gateBg        = document.getElementById('kp-gate-bg');
        const gatePoster    = document.getElementById('kp-gate-poster');
        const gateSource    = document.getElementById('kp-gate-source');
        const gateEpEl      = document.getElementById('kp-gate-ep');
        const gateTitleEl   = document.getElementById('kp-gate-title');
        const gatePlay      = document.getElementById('kp-gate-play');
        const gatePlayLabel = document.getElementById('kp-gate-play-label');
        const gateNote      = document.getElementById('kp-gate-note');
        const loadingEl     = document.getElementById('kp-player-loading');
        const loadingText   = document.getElementById('kp-loading-text');
        const errorEl       = document.getElementById('kp-player-error');
        const errorText     = document.getElementById('kp-error-text');
        const errorRetry    = document.getElementById('kp-error-retry');

        const nextOverlay   = document.getElementById('kp-next-overlay');
        const nextTitle     = document.getElementById('kp-next-title');
        const nextCount     = document.getElementById('kp-next-count');
        const nextPlayBtn   = document.getElementById('kp-next-play');
        const nextCancelBtn = document.getElementById('kp-next-cancel');
        const nextBar       = document.getElementById('kp-next-progress-bar');

        let art = null;
        let currentEp = KP.episode;
        let currentLang = KP.lang;
        let currentMode = KP.lang;     // 'sub' or 'dub'
        let lastServers = [];
        let payload = null;          // last resolved stream payload
        let wantResume = true;       // seek to saved progress on next play
        let progressTimer = null;
        let lastSavedAt = 0;
        const resumeCache = {};

        // ─── Watch-time clock ────────────────────────────────────────
        // Embed players are cross-origin: their playhead is unreadable, so
        // "how long was this actually playing" is measured here and reported
        // as watched_seconds. That is the only watch-time evidence an embed can
        // ever give, and it is what the stats page and the cards show.
        let playerActive = false;     // is a player attached right now?
        let playing = false;          // is playback currently running?
        let playStartedAt = 0;        // wall-clock ms when the current run began
        let playAccum = 0;            // seconds of finished runs for this episode
        let sentPlaySeconds = 0;      // ...up to the last heartbeat sent
        let basePosition = 0;         // where this episode resumed from
        let episodeRuntime = Number(KP.duration) > 0 ? Number(KP.duration) : 1440;

        // ─── User activity tracking ──────────────────────────────────
        // Detects if the user is actively watching (mouse/keyboard/touch)
        // to prevent counting idle time as watch time.
        let lastUserActivity = Date.now();
        const IDLE_TIMEOUT_MS = 30000; // 30s of no activity = idle
        const MIN_WATCH_SESSION_MS = 10000; // 10s minimum before counting
        let sessionStartTime = 0;

        function markUserActive() {
            lastUserActivity = Date.now();
        }
        ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(evt => {
            document.addEventListener(evt, markUserActive, { passive: true });
        });
        function isUserActive() {
            return (Date.now() - lastUserActivity) < IDLE_TIMEOUT_MS;
        }

        if (KP.resume && KP.resume.episode) {
            resumeCache[KP.resume.episode] = KP.resume.position || 0;
        }

        function formatNumber(num) {
            if (num >= 1e6) return (num / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
            if (num >= 1e3) return (num / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
            return String(num);
        }

        function formatClock(sec) {
            sec = Math.max(0, Math.round(sec));
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            const pad = (n) => String(n).padStart(2, '0');
            return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
        }

        function showStatus(html, isError) {
            if (!statusEl) return;
            statusEl.style.display = 'block';
            statusEl.style.color = isError ? '#ff6b6b' : '#ccc';
            statusEl.innerHTML = html;
        }
        function hideStatus() { if (statusEl) statusEl.style.display = 'none'; }

        function destroyPlayers() {
            // The clock stops with the player, but the seconds already played
            // stay accumulated — switching servers must not lose them.
            pausePlayClock();
            playerActive = false;
            if (art) { try { art.destroy(); } catch (e) {} art = null; }
            if (embedFrame) { embedFrame.onload = null; embedFrame.src = 'about:blank'; }
            if (embedBox) embedBox.style.display = 'none';
            if (artBox) artBox.style.display = 'block';
            stopProgressLoop();
            hideNextEpisode();
        }

        // ─── Player state machine ───────────────────────────────────
        // gate    : poster + play button, nothing loaded yet
        // loading : skeleton while resolving / starting playback
        // playing : a player is attached
        // error   : themed failure with a retry button
        function setState(state, opts) {
            opts = opts || {};
            const toggle = (el, on) => { if (el) el.style.display = on ? '' : 'none'; };

            toggle(gateEl, state === 'gate');
            toggle(loadingEl, state === 'loading');
            toggle(errorEl, state === 'error');

            if (state === 'error' && errorText) {
                errorText.textContent = opts.message || 'অজানা সমস্যা হয়েছে।';
            }
            if (state === 'loading' && loadingText) {
                loadingText.textContent = opts.message || 'সোর্স খোঁজা হচ্ছে…';
            }
            if (state !== 'gate') hideStatus();
        }

        // ─── Progress (resume + save) ───────────────────────────────
        function progressVideoId(ep) { return KP.id + ':' + ep; }

        function loadProgress(ep) {
            if (!KP.userId) return Promise.resolve(0);
            if (typeof resumeCache[ep] === 'number') return Promise.resolve(resumeCache[ep]);
            return fetch('./includes/get_progress.php?video_id=' + encodeURIComponent(progressVideoId(ep)))
                .then((r) => r.json())
                .then((d) => {
                    const pos = (d && typeof d.position === 'number') ? d.position : 0;
                    resumeCache[ep] = pos;
                    return pos;
                })
                .catch(() => 0);
        }

        // ─── Watch clock ────────────────────────────────────────────
        /** Seconds of real playback for the current episode (this page load). */
        function livePlaySeconds() {
            return playAccum + (playing ? (Date.now() - playStartedAt) / 1000 : 0);
        }
        function beginPlayClock() {
            if (playing) return;
            playing = true;
            playStartedAt = Date.now();
            if (sessionStartTime === 0) sessionStartTime = Date.now();
        }
        function pausePlayClock() {
            if (!playing) return;
            playAccum += (Date.now() - playStartedAt) / 1000;
            playing = false;
            playStartedAt = 0;
        }
        /** Start a fresh clock, resuming from `base` seconds into the episode. */
        function resetWatchClock(base) {
            pausePlayClock();
            playAccum = 0;
            sentPlaySeconds = 0;
            basePosition = Math.max(0, Number(base) || 0);
            sessionStartTime = 0; // Reset session tracking for new episode
        }
        /**
         * How far into the episode the user is.
         *
         * HLS gives the real playhead; an embed cannot, so the moved clock is
         * the estimate — capped at the runtime so it can never exceed it.
         */
        function currentEpisodePosition() {
            if (art && !isNaN(art.currentTime) && art.currentTime > 0) return art.currentTime;
            return Math.min(episodeRuntime, basePosition + livePlaySeconds());
        }

        /**
         * Send one heartbeat: the resume position plus the seconds played since
         * the previous one (the server accumulates those separately, so
         * rewinding never rewrites history).
         *
         * Activity-based tracking: only counts time when user is actively
         * watching (mouse/keyboard/touch activity within IDLE_TIMEOUT_MS).
         * Minimum session threshold: first 10s are not counted.
         */
        function saveProgress(ep, force) {
            if (!KP.userId || !playerActive) return;

            const position = currentEpisodePosition();
            let delta = Math.round(livePlaySeconds() - sentPlaySeconds);
            if (delta < 0) delta = 0;
            if (delta > 600) delta = 600;   // a heartbeat is minutes, not hours

            // Anti-idle: don't count time when user is idle
            if (!force && !isUserActive() && delta > 0) {
                // User is idle — don't add to watch time, but still save position
                sentPlaySeconds += delta;
                lastSavedAt = position;
                resumeCache[ep] = position;
                // Send position-only heartbeat (watched_seconds=0)
                const bodyPos = 'video_id=' + encodeURIComponent(progressVideoId(ep))
                           + '&last_position=' + encodeURIComponent(position.toFixed(2))
                           + '&watched_seconds=0'
                           + '&duration=' + encodeURIComponent(Math.round(episodeRuntime));
                fetch('./includes/save_progress.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: bodyPos
                }).catch(() => {});
                return;
            }

            // Minimum session threshold: don't count first 10s
            if (!force && sessionStartTime > 0) {
                const sessionMs = Date.now() - sessionStartTime;
                if (sessionMs < MIN_WATCH_SESSION_MS && delta > 0) {
                    delta = 0; // Don't count yet
                }
            }

            if (position <= 0 && delta <= 0) return;
            if (!force && delta < 1 && Math.abs(position - lastSavedAt) < 5) return;

            sentPlaySeconds += delta;
            lastSavedAt = position;
            resumeCache[ep] = position;
            markEpisodeProgress(ep, position);

            const body = 'video_id=' + encodeURIComponent(progressVideoId(ep))
                       + '&last_position=' + encodeURIComponent(position.toFixed(2))
                       + '&watched_seconds=' + encodeURIComponent(delta)
                       + '&duration=' + encodeURIComponent(Math.round(episodeRuntime));

            if (force && navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(
                        './includes/save_progress.php',
                        new Blob([body], { type: 'application/x-www-form-urlencoded' })
                    );
                    return;
                } catch (e) { /* fall through to fetch */ }
            }

            fetch('./includes/save_progress.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body,
                keepalive: true
            }).catch(() => {});
        }

        // Ticks while a player is attached — embeds included, which is why this
        // no longer waits for `art` to exist.
        function startProgressLoop(ep) {
            stopProgressLoop();
            progressTimer = setInterval(function () {
                if (document.visibilityState !== 'visible') return;
                saveProgress(ep, false);
            }, 5000);
        }
        function stopProgressLoop() {
            if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
        }

        // Always persist the final position, even if the tab is closing.
        function flushProgress() {
            pausePlayClock();
            saveProgress(currentEp, true);
        }
        window.addEventListener('pagehide', flushProgress);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                // Credit what was played before the tab went away, then stop the
                // clock so background time is never counted as watch time.
                flushProgress();
                return;
            }
            // Back in view: update activity timestamp so idle detection resets
            markUserActive();
            // HLS resumes on its own ('video:play' restarts the clock);
            // an embed has no events to rely on, so assume it resumed.
            if (playerActive && (!art || !art.paused)) beginPlayClock();
        });

        // ─── Auto-play next episode ──────────────────────────────────
        let nextTimer = null;
        let nextCancelled = false;

        function showNextEpisode() {
            var nextEp = currentEp + 1;
            var hasMore = !KP.total || nextEp <= KP.total;
            if (!hasMore || !nextOverlay) return;

            var meta = (KP.episodes || []).find(function (e) { return e.n === nextEp; });
            var title = meta && meta.t ? meta.t : ('Episode ' + nextEp);

            if (nextTitle) nextTitle.textContent = title;

            nextOverlay.style.display = 'flex';
            nextCancelled = false;

            var remaining = 5;
            if (nextCount) nextCount.textContent = remaining;
            if (nextBar) nextBar.style.transition = 'none';

            // Build URL for next episode
            var url = new URL(window.location.href);
            url.searchParams.set('ep', nextEp);
            url.searchParams.delete('lang');
            var nextUrl = url.toString();

            nextPlayBtn.onclick = function () {
                clearInterval(nextTimer);
                window.location.href = nextUrl;
            };
            nextCancelBtn.onclick = function () {
                nextCancelled = true;
                clearInterval(nextTimer);
                nextOverlay.style.display = 'none';
            };

            // Start countdown
            nextTimer = setInterval(function () {
                remaining--;
                if (nextCount) nextCount.textContent = remaining;
                if (remaining <= 0) {
                    clearInterval(nextTimer);
                    window.location.href = nextUrl;
                }
            }, 1000);

            // Animate progress bar
            requestAnimationFrame(function () {
                if (nextBar) {
                    nextBar.style.transition = 'none';
                    nextBar.style.width = '100%';
                    requestAnimationFrame(function () {
                        nextBar.style.transition = 'width 5s linear';
                        nextBar.style.width = '0%';
                    });
                }
            });
        }

        function hideNextEpisode() {
            if (nextOverlay) nextOverlay.style.display = 'none';
            if (nextTimer) { clearInterval(nextTimer); nextTimer = null; }
        }

        // ─── HLS playback via Artplayer ─────────────────────────────
        function playHls(url, subtitles, intro, outro, resumeAt) {
            hideStatus();
            destroyPlayers();

            if (!artBox) return;
            artBox.style.display = 'block';
            setState('loading', { message: 'প্লেয়ার শুরু হচ্ছে…' });

            art = new Artplayer({
                container: '#artplayer',
                url: url,
                type: 'm3u8',
                volume: 0.6,
                autoplay: true,
                fullscreen: true,
                fullscreenWeb: true,
                playbackRate: true,
                aspectRatio: true,
                mutex: true,
                backdrop: true,
                playsInline: true,
                theme: '#ff2e63',
                setting: true,
                flip: true,
                miniProgressBar: true,
                whitelist: ['*'],
                customType: {
                    m3u8: function (video, src) {
                        if (window.Hls && Hls.isSupported()) {
                            const hls = new Hls({ enableWorker: true, lowLatencyMode: true });
                            hls.loadSource(src);
                            hls.attachMedia(video);
                            hls.on(Hls.Events.MANIFEST_PARSED, function () { video.play().catch(function () {}); });
                            hls.on(Hls.Events.ERROR, function (_e, data) {
                                if (data && data.fatal) {
                                    setState('error', {
                                        message: 'প্লেব্যাক এরর (' + data.type + '/' + data.details + ')। নিচ থেকে অন্য সার্ভার বেছে নিন বা আবার চেষ্টা করুন।'
                                    });
                                }
                            });
                            art.on('destroy', function () { try { hls.destroy(); } catch (e) {} });
                        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                            video.src = src;
                            video.addEventListener('loadedmetadata', function () { video.play().catch(function () {}); });
                        } else {
                            showStatus('<i class="fas fa-exclamation-triangle"></i> এই ব্রাউজারে HLS সাপোর্ট নেই।', true);
                        }
                    }
                }
            });

            // A player is attached: leave the loading state and start saving.
            art.once('ready', function () {
                setState('playing');
                playerActive = true;
                // The real file duration beats the catalogue estimate.
                if (art.duration && art.duration > 0) {
                    episodeRuntime = Math.round(art.duration);
                }
                beginPlayClock();
                startProgressLoop(currentEp);
            });

            // The clock follows the real player, so pausing the video stops
            // watch time even while the tab stays open.
            art.on('video:play', beginPlayClock);
            art.on('video:playing', beginPlayClock);
            art.on('video:pause', pausePlayClock);
            art.on('video:ended', pausePlayClock);
            art.on('video:waiting', pausePlayClock);

            // Resume where the user left off (needs duration, so wait for metadata).
            if (resumeAt && resumeAt > 10) {
                art.once('video:loadedmetadata', function () {
                    const dur = art.duration;
                    if (dur && resumeAt < dur - 10) {
                        art.currentTime = resumeAt;
                        art.notice.show = 'Resumed at ' + formatClock(resumeAt);
                    }
                });
            }

            // Auto-play next episode when video ends.
            art.on('video:ended', function () {
                var dur = art.duration || 0;
                if (dur > 0 && art.currentTime < dur - 3) return;
                flushProgress();
                showNextEpisode();
            });

            // Attach subtitles (Artplayer exposes `subtitle` as a property).
            const subs = Array.isArray(subtitles) ? subtitles : [];
            if (subs.length) {
                const preferred = subs.find(function (s) { return s.default; }) || subs[0];
                art.once('ready', function () {
                    art.subtitle = {
                        url: preferred.url,
                        type: (preferred.format === 'srt') ? 'srt' : 'vtt',
                        escape: false,
                        encoding: 'utf-8'
                    };
                    if (subs.length > 1) {
                        art.setting.add({
                            html: 'Subtitle',
                            selector: subs.map(function (s, i) {
                                return {
                                    html: s.language || ('Subtitle ' + (i + 1)),
                                    onSelect: function () {
                                        art.subtitle = {
                                            url: s.url,
                                            type: (s.format === 'srt') ? 'srt' : 'vtt',
                                            escape: false,
                                            encoding: 'utf-8'
                                        };
                                        art.notice.show = (s.language || 'Subtitle') + ' selected';
                                        return true;
                                    }
                                };
                            }),
                            index: subs.indexOf(preferred)
                        });
                    }

                    // Subtitle customization settings
                    art.setting.add({
                        name: 'SubtitleStyle',
                        html: 'Subtitle Style',
                        selector: [
                            {
                                html: 'Size: Normal',
                                onSelect: function () {
                                    art.style.fontSize = '22px';
                                    art.notice.show = 'Subtitle size: Normal';
                                    return true;
                                }
                            },
                            {
                                html: 'Size: Large',
                                onSelect: function () {
                                    art.style.fontSize = '28px';
                                    art.notice.show = 'Subtitle size: Large';
                                    return true;
                                }
                            },
                            {
                                html: 'Size: Small',
                                onSelect: function () {
                                    art.style.fontSize = '18px';
                                    art.notice.show = 'Subtitle size: Small';
                                    return true;
                                }
                            }
                        ]
                    });

                    art.setting.add({
                        name: 'SubtitleBg',
                        html: 'Subtitle Background',
                        selector: [
                            {
                                html: 'Dark',
                                onSelect: function () {
                                    var subs = art.querySelectorAll('.art-subtitle');
                                    subs.forEach(function (s) { s.style.backgroundColor = 'rgba(0,0,0,0.7)'; });
                                    art.notice.show = 'Subtitle bg: Dark';
                                    return true;
                                }
                            },
                            {
                                html: 'Light',
                                onSelect: function () {
                                    var subs = art.querySelectorAll('.art-subtitle');
                                    subs.forEach(function (s) { s.style.backgroundColor = 'rgba(255,255,255,0.7)'; s.style.color = '#000'; });
                                    art.notice.show = 'Subtitle bg: Light';
                                    return true;
                                }
                            },
                            {
                                html: 'Transparent',
                                onSelect: function () {
                                    var subs = art.querySelectorAll('.art-subtitle');
                                    subs.forEach(function (s) { s.style.backgroundColor = 'transparent'; s.style.color = '#fff'; s.style.textShadow = '1px 1px 2px rgba(0,0,0,0.8)'; });
                                    art.notice.show = 'Subtitle bg: Transparent';
                                    return true;
                                }
                            }
                        ]
                    });
                });
            }

            // Intro / outro chapter markers.
            if (intro && typeof intro.start === 'number' && intro.end > intro.start) {
                addChapterMarker(art, intro.start, intro.end, 'Skip Intro', '#1f6feb');
            }
            if (outro && typeof outro.start === 'number' && outro.end > outro.start) {
                addChapterMarker(art, outro.start, outro.end, 'Skip Outro', '#238636');
            }
        }

        function addChapterMarker(player, start, end, label, color) {
            player.once('ready', function () {
                player.setting.add({
                    name: label.replace(/\s+/g, ''),
                    html: label,
                    selector: [
                        { html: 'Skip ahead', onSelect: function () { player.currentTime = end; return true; } },
                        { html: 'Rewatch', onSelect: function () { player.currentTime = start; return true; } }
                    ],
                    onSelect: function () { player.currentTime = end; return true; }
                });
            });
        }

        // ─── Embed fallback playback ────────────────────────────────
        function playEmbed(url) {
            hideStatus();
            destroyPlayers();
            if (artBox) artBox.style.display = 'none';
            if (embedBox) embedBox.style.display = 'block';
            setState('loading', { message: 'সার্ভার লোড হচ্ছে…' });

            let settled = false;
            const watchdog = setTimeout(function () {
                if (settled) return;
                setState('error', { message: 'সার্ভার সময়মতো সাড়া দেয়নি। নিচ থেকে অন্য সার্ভার বেছে নিন।' });
            }, 25000);

            if (!embedFrame) return;

            embedFrame.onload = function () {
                const src = embedFrame.getAttribute('src') || '';
                if (!src || src === 'about:blank') return;
                settled = true;
                clearTimeout(watchdog);
                setState('playing');
                saveHistory(currentEp);

                // The embed only loads because the user asked to play, so the
                // watch clock starts here. Its playhead is unreachable, which
                // is exactly why the clock exists.
                playerActive = true;
                beginPlayClock();
                startProgressLoop(currentEp);
            };
            embedFrame.src = url;
        }

        // ─── Server chips ───────────────────────────────────────────
        function renderServers(servers) {
            lastServers = Array.isArray(servers) ? servers : [];
            if (!chipsEl) return;
            chipsEl.innerHTML = '';

            if (!lastServers.length) {
                chipsEl.innerHTML = '<span style="color:#ff9999;">কোনো সার্ভার নেই।</span>';
                return;
            }

            var filtered = lastServers.filter(function (s) {
                return s.lang === currentMode || s.lang === 'any';
            });

            if (!filtered.length) {
                chipsEl.innerHTML = '<span style="color:#ff9999;">' +
                    currentMode.toUpperCase() +
                    ' ভাষায় কোনো সার্ভার নেই।</span>';
                return;
            }

            var lbl = document.createElement('span');
            lbl.className = 'kp-chip-label';
            lbl.innerHTML = '<i class="fas fa-server"></i> সার্ভার';
            chipsEl.appendChild(lbl);

            filtered.forEach(function (server) {
                var btn = document.createElement('button');
                btn.className = 'server-chip';
                btn.dataset.key = server.key;
                var langTag = server.lang === 'any' ? '' : ' [' + server.lang.toUpperCase() + ']';
                btn.textContent = (server.label || server.key || 'HD').replace(/ · /g, ' ') + langTag;
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.server-chip').forEach(function (b) {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    useServer(server);
                });
                chipsEl.appendChild(btn);
            });

            var first = chipsEl.querySelector('.server-chip');
            if (first) first.classList.add('active');
        }

        function renderLangToggle() {
            if (!langEl) return;
            langEl.innerHTML = '';

            /* SUB button — Japanese audio + English sub */
            var subBtn = document.createElement('button');
            subBtn.textContent = 'SUB';
            if (currentMode === 'sub') subBtn.className = 'active';
            subBtn.addEventListener('click', function () {
                if (currentMode === 'sub') return;
                currentMode = 'sub';
                currentLang = 'sub';
                renderLangToggle();
                resolve(currentEp, { autoplay: true, force: true });
            });
            langEl.appendChild(subBtn);

            /* DUB button — English/other audio */
            var dubBtn = document.createElement('button');
            dubBtn.textContent = 'DUB';
            if (currentMode === 'dub') dubBtn.className = 'active';
            dubBtn.addEventListener('click', function () {
                if (currentMode === 'dub') return;
                currentMode = 'dub';
                currentLang = 'dub';
                renderLangToggle();
                resolve(currentEp, { autoplay: true, force: true });
            });
            langEl.appendChild(dubBtn);
        }

        function useServer(server) {
            if (!server) return;

            if (server.mode === 'embed') {
                playEmbed(server.url);
                return;
            }

            const resumeAt = wantResume ? (resumeCache[currentEp] || 0) : 0;
            // Servers we already know the metadata for keep their subtitles and
            // chapter markers; anything else plays bare.
            const isCurrent = !!(payload && payload.url === server.url);

            if (server.url) {
                playHls(
                    server.url,
                    isCurrent ? (payload.subtitles || []) : [],
                    isCurrent ? payload.intro : null,
                    isCurrent ? payload.outro : null,
                    resumeAt
                );
                return;
            }
            if (!server.dataLink) return;

            setState('loading', { message: 'স্ট্রিম ডিক্রিপ্ট হচ্ছে…' });
            fetch('./includes/get_reanime_stream.php?link=' + encodeURIComponent(server.dataLink))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.url) {
                        playHls(data.url, data.subtitles || [], data.intro_chapter, data.outro_chapter, resumeAt);
                    } else {
                        setState('error', { message: 'এই সার্ভার থেকে স্ট্রিম পাওয়া যায়নি।' });
                    }
                })
                .catch(function () {
                    setState('error', { message: 'সার্ভার রেসপন্স ব্যর্থ হয়েছে।' });
                });
        }

        // ─── Gate content ───────────────────────────────────────────
        function resumeTargetFor(ep) {
            const pos = resumeCache[ep];
            if (ep !== currentEp) return 0;
            return (typeof pos === 'number' && pos > 10) ? pos : 0;
        }

        function updateGate() {
            const meta = (KP.episodes || []).find(function (e) { return e.n === currentEp; });
            const epTitle = meta && meta.t ? meta.t : '';

            if (gateEpEl) {
                gateEpEl.textContent = 'Episode ' + currentEp + (KP.total ? ' of ' + KP.total : '');
            }
            if (gateTitleEl) {
                gateTitleEl.textContent = epTitle ? (KP.title + ' — ' + epTitle) : KP.title;
            }
            if (gateBg && (KP.banner || KP.poster)) gateBg.src = KP.banner || KP.poster;
            if (gatePoster && KP.poster) gatePoster.src = KP.poster;

            if (gateSource) {
                const source = (payload && payload.source) ? String(payload.source).replace('embed:', '') : KP.provider;
                gateSource.textContent = source || '—';
            }

            var at = resumeTargetFor(currentEp);
            if (gatePlayLabel) {
                gatePlayLabel.textContent = at > 0
                    ? 'Resume from ' + formatClock(at)
                    : 'Play Episode ' + currentEp;
            }

            if (gateNote) {
                gateNote.textContent = (payload && payload.mode === 'embed')
                    ? 'বাইরের প্লেয়ার — নিচে SUB/DUB সার্ভার বদলাতে পারবেন।'
                    : '';
            }
        }

        /** Fill in the resume affordance for an episode we have not loaded yet. */
        function refreshResume(ep) {
            loadProgress(ep).then(function (pos) {
                resumeCache[ep] = pos;
                if (ep === currentEp) updateGate();
            });
        }

        // ─── Resolve + play ─────────────────────────────────────────
        // opts.autoplay : start playing as soon as the stream resolves
        // opts.force    : re-resolve even if this episode is already loaded
        function resolve(ep, opts) {
            opts = opts || {};
            const autoplay = !!opts.autoplay;

            if (!opts.force && ep === currentEp && payload) {
                if (autoplay) playPayload();
                return Promise.resolve(payload);
            }

            // Bank the outgoing episode's watch time before anything changes.
            flushProgress();

            currentEp = ep;
            payload = null;
            resetWatchClock(resumeCache[ep] || 0);

            if (autoplay) {
                setState('loading', { message: 'EP ' + ep + ' এর সোর্স খোঁজা হচ্ছে…' });
            }
            if (chipsEl) {
                chipsEl.innerHTML = '<span class="kp-chip-label"><i class="fas fa-spinner fa-spin"></i> Servers…</span>';
            }
            destroyPlayers();
            refreshResume(ep);

            const params = new URLSearchParams({ id: KP.id, ep: ep, lang: currentMode || currentLang, title: KP.title });

            return fetch('./includes/get_stream.php?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    payload = data;

                    if (!data || !data.ok) {
                        if (chipsEl) chipsEl.innerHTML = '';
                        setState('error', {
                            message: (data && data.message) || 'এই এপিসোডের জন্য কোনো সোর্স পাওয়া যায়নি।'
                        });
                        return;
                    }

                    renderServers(data.servers || []);

                    if (autoplay) {
                        playPayload();
                    } else {
                        setState('gate');
                        updateGate();
                    }
                })
                .catch(function (err) {
                    console.error('resolve error', err);
                    if (chipsEl) chipsEl.innerHTML = '';
                    setState('error', { message: 'নেটওয়ার্ক সমস্যা — stream endpoint-এ পৌঁছানো যায়নি।' });
                });
        }

        /** Play whatever `resolve()` last produced. */
        function playPayload() {
            if (!payload || !payload.ok) return;
            saveHistory(currentEp);

            if (payload.mode === 'hls') {
                const resumeAt = wantResume ? (resumeCache[currentEp] || 0) : 0;
                playHls(payload.url, payload.subtitles || [], payload.intro, payload.outro, resumeAt);
            } else {
                playEmbed(payload.url);
            }
        }

        function saveHistory(ep) {
            if (!KP.userId) return;
            fetch('./includes/save_watch_history.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'anime_slug=' + encodeURIComponent(KP.catalogId) + '&episode_number=' + encodeURIComponent(ep)
            }).catch(function () {});
            /* Count view once per page load */
            if (!window._kpViewCounted) {
                window._kpViewCounted = true;
                fetch('./includes/count_view.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'imdb_id=' + encodeURIComponent(KP.id)
                }).catch(function () {});
            }
        }

        function selectEpisode(el, ep) {
            document.querySelectorAll('#anikuro-episode-container .episode').forEach(function (e) {
                e.classList.remove('active-play');
            });
            if (el) el.classList.add('active-play');

            const url = new URL(window.location.href);
            url.searchParams.set('ep', ep);
            url.searchParams.set('lang', currentMode || currentLang);
            history.replaceState({}, '', url.toString());

            // Episode switch is an explicit play intent, so autoplay and resume.
            wantResume = true;
            resolve(ep, { autoplay: true, force: true });
            loadNotes(ep);
        }

        // ─── Episode list (built here so long series stay a small payload) ──
        function episodeElement(number, title) {
            const el = document.createElement('div');
            const active = (number === currentEp);
            el.className = 'episode kp-ep' + (active ? ' active-play' : '');
            el.dataset.episode = number;
            el.dataset.search = ('ep ' + number + ' ' + (title || '')).toLowerCase();

            const label = document.createElement('span');
            label.className = 'kp-ep-main';
            const num = document.createElement('strong');
            num.textContent = 'EP' + String(number).padStart(3, '0');
            label.appendChild(num);
            if (title) {
                const t = document.createElement('span');
                t.className = 'kp-ep-title';
                t.textContent = ' · ' + (title.length > 42 ? title.slice(0, 42) + '…' : title);
                label.appendChild(t);
            }
            el.appendChild(label);

            const right = document.createElement('span');
            right.className = 'kp-ep-right';

            const badge = document.createElement('span');
            badge.className = 'kp-ep-resume';
            badge.style.display = 'none';
            right.appendChild(badge);

            const icon = document.createElement('i');
            icon.className = 'fas fa-play-circle';
            right.appendChild(icon);
            el.appendChild(right);

            el.style.setProperty('--kp-pct', '0%');
            el.addEventListener('click', function () { selectEpisode(el, number); });
            return el;
        }

        /** Paint the "resume at mm:ss" badge + progress underline on one row. */
        function markEpisodeProgress(number, seconds) {
            const el = document.querySelector('#anikuro-episode-container .episode[data-episode="' + number + '"]');
            if (!el) return;

            const badge = el.querySelector('.kp-ep-resume');
            const has = seconds > 10;

            if (badge) {
                badge.style.display = has ? '' : 'none';
                if (has) badge.textContent = formatClock(seconds);
            }
            el.classList.toggle('kp-ep-started', has);
            el.style.setProperty('--kp-pct', has ? '100%' : '0%');
        }

        /**
         * One request paints every episode, instead of one request per episode
         * (a 1000-episode series would otherwise fire 1000 fetches).
         */
        function loadAllProgress() {
            if (!KP.userId) return Promise.resolve();
            return fetch('./includes/get_progress.php?anime_id=' + encodeURIComponent(KP.id))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    const map = (data && data.progress) || {};
                    Object.keys(map).forEach(function (ep) {
                        const pos = Number(map[ep]) || 0;
                        resumeCache[ep] = pos;
                        markEpisodeProgress(ep, pos);
                    });
                })
                .catch(function () { /* progress is decoration — never block on it */ });
        }

        function buildEpisodeList() {
            const container = document.getElementById('anikuro-episode-container');
            if (!container) return;
            container.innerHTML = '';

            const list = Array.isArray(KP.episodes) ? KP.episodes : [];
            if (!list.length) {
                const p = document.createElement('p');
                p.style.cssText = 'color:#aaa; padding:8px;';
                p.textContent = 'এপিসোড লিস্ট পাওয়া যায়নি — নিচে ম্যানুয়ালি নম্বর দিন।';
                container.appendChild(p);
                return;
            }

            const frag = document.createDocumentFragment();
            list.forEach(function (ep) { frag.appendChild(episodeElement(ep.n, ep.t)); });
            container.appendChild(frag);

            Object.keys(resumeCache).forEach(function (ep) {
                markEpisodeProgress(ep, resumeCache[ep]);
            });

            const active = container.querySelector('.episode.active-play');
            if (active) active.scrollIntoView({ block: 'center' });

            loadAllProgress();
        }

        const epSearch = document.getElementById('ep-search');
        if (epSearch) {
            epSearch.addEventListener('input', function () {
                const q = epSearch.value.trim().toLowerCase();
                document.querySelectorAll('#anikuro-episode-container .episode').forEach(function (el) {
                    const hay = (el.dataset.search || '') + ' ' + el.dataset.episode;
                    el.style.display = (!q || hay.indexOf(q) !== -1) ? 'flex' : 'none';
                });
            });
        }

        // ─── Episode notes ──────────────────────────────────────────
        // Lives here (not in the legacy script) because the notes UI is only
        // rendered for catalogue anime — and `art` + the watch clock, which
        // give a note its timestamp, only exist in this scope.
        const noteList  = document.getElementById('notes-list');
        const noteInput = document.getElementById('note-input');
        const noteSave  = document.getElementById('note-save-btn');

        function notesVideoId(ep) { return KP.id + ':' + ep; }

        function noteTimeLabel(sec) {
            sec = Math.max(0, Math.floor(Number(sec) || 0));
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            const pad = (n) => String(n).padStart(2, '0');
            return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : pad(m) + ':' + pad(s);
        }

        function setNotesState(html, isError) {
            if (!noteList) return;
            noteList.innerHTML = '<p class="kp-note-state' + (isError ? ' is-error' : '') + '">' + html + '</p>';
        }

        function noteRow(note, ep) {
            const row = document.createElement('div');
            row.className = 'kp-note-row';

            const timeBtn = document.createElement('button');
            timeBtn.type = 'button';
            timeBtn.className = 'kp-note-time';
            timeBtn.textContent = noteTimeLabel(note.time);
            timeBtn.addEventListener('click', function () {
                if (art && !isNaN(art.currentTime)) {
                    art.currentTime = Number(note.time) || 0;
                } else {
                    kpToast('এমবেড প্লেয়ারে নির্দিষ্ট সময়ে যাওয়া যায় না।', 'info');
                }
            });

            const text = document.createElement('span');
            text.className = 'kp-note-text';
            text.textContent = note.note || '';
            text.title = note.note || '';

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'kp-note-del';
            del.title = 'Delete note';
            del.innerHTML = '<i class="fas fa-trash-alt"></i>';
            del.addEventListener('click', function () {
                del.disabled = true;
                fetch('./includes/delete_episode_note.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'note_id=' + encodeURIComponent(note.id)
                })
                .then(function () { loadNotes(ep); })
                .catch(function () {
                    del.disabled = false;
                    kpToast('নোট ডিলিট করা যায়নি।', 'error');
                });
            });

            row.appendChild(timeBtn);
            row.appendChild(text);
            row.appendChild(del);
            return row;
        }

        function loadNotes(ep) {
            if (!noteList) return;
            setNotesState('<i class="fas fa-spinner fa-spin"></i> নোট লোড হচ্ছে…');

            fetch('./includes/get_episode_notes.php?video_id=' + encodeURIComponent(notesVideoId(ep)))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!Array.isArray(data)) {
                        setNotesState('নোট লোড করা যায়নি।', true);
                        return;
                    }
                    if (!data.length) {
                        setNotesState('এই এপিসোডে এখনো কোনো নোট নেই।');
                        return;
                    }
                    noteList.innerHTML = '';
                    data.forEach(function (n) { noteList.appendChild(noteRow(n, ep)); });
                })
                .catch(function () {
                    setNotesState('নেটওয়ার্ক সমস্যা — নোট লোড করা যায়নি।', true);
                });
        }

        function saveNote() {
            if (!noteInput || !noteSave) return;
            const text = noteInput.value.trim();
            if (text === '') { noteInput.focus(); return; }

            const at = currentEpisodePosition();
            noteSave.disabled = true;
            noteInput.disabled = true;

            fetch('./includes/save_episode_note.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'video_id=' + encodeURIComponent(notesVideoId(currentEp))
                    + '&timestamp=' + encodeURIComponent(at.toFixed(2))
                    + '&note=' + encodeURIComponent(text)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'save failed');
                }
                noteInput.value = '';
                kpToast('নোট সেভ হয়েছে — ' + noteTimeLabel(at), 'success');
                loadNotes(currentEp);
            })
            .catch(function () {
                kpToast('নোট সেভ করা যায়নি। আবার চেষ্টা করুন।', 'error');
            })
            .finally(function () {
                noteSave.disabled = false;
                noteInput.disabled = false;
            });
        }

        function initEpisodeNotes() {
            if (!noteList || !noteInput || !noteSave) return;
            noteSave.addEventListener('click', saveNote);
            noteInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); saveNote(); }
            });
            loadNotes(currentEp);
        }

        // Boot
        window.addEventListener('DOMContentLoaded', function () {
            const vc = document.getElementById('viewCount');
            if (vc) vc.textContent = formatNumber(parseInt(vc.textContent.replace(/,/g, ''), 10) || 0);
            const lc = document.getElementById('likeCount');
            if (lc) lc.textContent = formatNumber(parseInt(lc.textContent.replace(/,/g, ''), 10) || 0);

            buildEpisodeList();
            renderLangToggle();
            initEpisodeNotes();

            // From-start and resume are two distinct intents, so the gate gets
            // two buttons rather than one button with a hidden seek.
            if (gatePlay) {
                gatePlay.addEventListener('click', function () {
                    wantResume = resumeTargetFor(currentEp) > 0;
                    if (payload) playPayload(); else resolve(currentEp, { autoplay: true });
                });
            }
            if (errorRetry) {
                errorRetry.addEventListener('click', function () {
                    resolve(currentEp, { autoplay: true, force: true });
                });
            }

            // Resolve in the background so the gate can show the real source and
            // a resume offer, but do not start playback until the user asks.
            resolve(currentEp, { autoplay: false });
        });
    })();
</script>
<?php endif; ?>

<script>
    // ─── Watchlist + like (shared by every provider) ─────────────
    function formatNumber(num) {
        if (num >= 1e6) return (num / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (num >= 1e3) return (num / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
        return String(num);
    }

    const sharedUserId = <?= $user_id ? (int)$user_id : 'null' ?>;
    const sharedImdbId = '<?= addslashes($raw_id) ?>';

    const dropdown = document.querySelector('.custom-dropdown-watchlist');
    if (dropdown) {
        const toggle = dropdown.querySelector('.dropdown-toggle');
        const menu = dropdown.querySelector('.dropdown-menu');
        toggle.addEventListener('click', function () { dropdown.classList.toggle('open'); });
        window.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target)) dropdown.classList.remove('open');
        });
        menu.addEventListener('click', function (e) {
            if (e.target.tagName !== 'LI') return;
            const status = e.target.dataset.value;
            toggle.innerHTML = '<i class="fas fa-list"></i> ' + e.target.textContent.trim();
            dropdown.classList.remove('open');
            if (!sharedUserId || !sharedImdbId) return;
            fetch('./includes/save_watchlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'user_id=' + encodeURIComponent(sharedUserId) + '&imdb_id=' + encodeURIComponent(sharedImdbId) + '&status=' + encodeURIComponent(status)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data.success) {
                    kpToast('Watchlist updated: ' + e.target.textContent.trim(), 'success');
                } else {
                    kpToast('Failed to save: ' + (data.message || 'Unknown error'), 'error');
                }
            }).catch(function () {
                kpToast('Network error — could not save.', 'error');
            });
        });
    }

    const likeButton = document.getElementById('likeButton');
    if (likeButton) {
        const likeIcon = document.getElementById('likeIcon');
        const likeCountElem = document.getElementById('likeCount');
        likeButton.addEventListener('click', function () {
            if (likeButton.classList.contains('is-busy')) return;
            if (!sharedUserId || !sharedImdbId) {
                kpToast('লাইক করতে লগইন করুন।', 'info');
                return;
            }

            const wasLiked = likeIcon.classList.contains('liked');
            likeButton.classList.add('is-busy');
            // Optimistic: the icon answers the click immediately and is undone
            // if the request fails, instead of the button looking dead.
            likeIcon.classList.toggle('liked', !wasLiked);

            fetch('./includes/toggle_like.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'imdb_id=' + encodeURIComponent(sharedImdbId)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    likeIcon.classList.toggle('liked', wasLiked);
                    kpToast((data && data.message) || 'লাইক সেভ করা যায়নি।', 'error');
                    return;
                }
                const liked = (typeof data.liked === 'boolean') ? data.liked : !wasLiked;
                likeIcon.classList.toggle('liked', liked);
                if (data.likes !== undefined) likeCountElem.textContent = formatNumber(data.likes);
                kpToast(liked ? 'Added to liked!' : 'Removed from liked', 'success');
            })
            .catch(function () {
                likeIcon.classList.toggle('liked', wasLiked);
                kpToast('Network error — could not save.', 'error');
            })
            .finally(function () { likeButton.classList.remove('is-busy'); });
        });
    }

    const followButton = document.getElementById('followButton');
    if (followButton) {
        const followIcon = document.getElementById('followIcon');
        const followLabel = document.getElementById('followLabel');
        followButton.addEventListener('click', function () {
            if (followButton.classList.contains('is-busy')) return;
            const slug = followButton.dataset.slug;
            const title = followButton.dataset.title;
            if (!sharedUserId || !slug) {
                kpToast('ফলো করতে লগইন করুন।', 'info');
                return;
            }

            followButton.classList.add('is-busy');
            followButton.style.opacity = '0.6';

            fetch('./includes/save_follow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'anime_slug=' + encodeURIComponent(slug) + '&anime_title=' + encodeURIComponent(title)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    kpToast((data && data.message) || 'ফলো সেভ করা যায়নি।', 'error');
                    return;
                }
                followButton.classList.toggle('is-following', data.following);
                followIcon.classList.toggle('liked', data.following);
                followLabel.textContent = data.following ? 'Following' : 'Follow';
                kpToast(data.following ? 'Following! You\'ll get notified of new episodes.' : 'Unfollowed', 'success');
            })
            .catch(function () {
                kpToast('Network error — could not save.', 'error');
            })
            .finally(function () {
                followButton.classList.remove('is-busy');
                followButton.style.opacity = '';
            });
        });
    }

    // ─── Report modal ─────────────────────────────────────────────
    (function initReport() {
        const openBtn = document.getElementById('kp-report-open');
        const modal = document.getElementById('kp-report-modal');
        if (!openBtn || !modal) return;

        const reasonEl  = document.getElementById('kp-report-reason');
        const detailsEl = document.getElementById('kp-report-details');
        const submitBtn = document.getElementById('kp-report-submit');
        const sourceEl  = document.getElementById('kp-report-source');

        function currentEpisodeNumber() {
            const params = new URLSearchParams(window.location.search);
            const ep = parseInt(params.get('ep') || params.get('episode') || '1', 10);
            return ep > 0 ? ep : 1;
        }

        function openModal() {
            const chip = document.querySelector('.server-chip.active');
            if (chip && sourceEl) {
                sourceEl.innerHTML = '<i class="fas fa-server"></i> ' + chip.textContent.trim();
            }
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            if (detailsEl) setTimeout(function () { detailsEl.focus(); }, 60);
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        openBtn.addEventListener('click', openModal);
        modal.querySelectorAll('[data-kp-report-close]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });

        submitBtn.addEventListener('click', function () {
            if (submitBtn.disabled) return;
            const original = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> পাঠানো হচ্ছে…';

            const body = 'anime_slug=' + encodeURIComponent(sharedImdbId)
                       + '&episode=' + encodeURIComponent(currentEpisodeNumber())
                       + '&reason=' + encodeURIComponent(reasonEl ? reasonEl.value : 'other')
                       + '&details=' + encodeURIComponent(detailsEl ? detailsEl.value.trim() : '')
                       + '&source=' + encodeURIComponent(sourceEl ? sourceEl.textContent.trim() : '');

            fetch('./includes/save_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'report failed');
                }
                if (detailsEl) detailsEl.value = '';
                kpToast('রিপোর্ট পাঠানো হয়েছে — ধন্যবাদ!', 'success');
                closeModal();
            })
            .catch(function () {
                kpToast('রিপোর্ট পাঠানো যায়নি। আবার চেষ্টা করুন।', 'error');
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.innerHTML = original;
            });
        });
    })();
</script>

<?php if (!$is_api): ?>
<script>
    (function initLegacyPlayer() {
        const legacyVideoUrl = '<?= !empty($video_url) ? addslashes($video_url) : '' ?>';
        const legacyPosterUrl = '<?= !empty($poster_url) ? addslashes($poster_url) : '' ?>';
        const sharedImdbIdLegacy = '<?= addslashes($raw_id) ?>';

        if (!legacyVideoUrl || !document.getElementById('artplayer')) return;

        const getSavedTime = async (id) => {
            try {
                const res = await fetch(`./includes/get_progress.php?video_id=${encodeURIComponent(id)}`);
                const data = await res.json();
                return typeof data.position === 'number' ? data.position : 0;
            } catch (e) { return 0; }
        };

        const art = new Artplayer({
            container: '#artplayer',
            url: legacyVideoUrl,
            poster: legacyPosterUrl,
            volume: 0.6,
            autoplay: false,
            fullscreen: true,
            playbackRate: true,
            aspectRatio: true,
            mutex: true,
            backdrop: true,
            playsInline: true,
            theme: '#ff2e63',
            setting: true,
            flip: true
        });

        let legacyLastTime = 0;
        let legacySessionStarted = Date.now();
        const LEGACY_MIN_WATCH_MS = 10000; // 10s minimum before counting
        const saveTime = () => {
            if (!art || isNaN(art.currentTime)) return;
            const time = art.currentTime;
            localStorage.setItem(`watch_time_${sharedImdbIdLegacy}`, time);

            // Ratio-based seek detection: jumps >15% of episode = seek
            const dur = (art.duration && !isNaN(art.duration)) ? Math.round(art.duration) : 0;
            const seekThreshold = dur > 0 ? dur * 0.15 : 30;
            let played = 0;
            const delta = time - legacyLastTime;
            if (delta > 0 && delta < seekThreshold) {
                played = Math.round(delta);
            } else if (delta > 0) {
                // Large forward jump = seek, don't count as watch time
                // but still track the position
            }
            legacyLastTime = time;

            // Minimum session threshold: don't count if watched < 10s total
            const sessionMs = Date.now() - legacySessionStarted;
            if (sessionMs < LEGACY_MIN_WATCH_MS && played > 0) {
                played = 0; // Don't count yet
            }

            fetch('./includes/save_progress.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `video_id=${encodeURIComponent(sharedImdbIdLegacy)}&last_position=${time.toFixed(2)}&watched_seconds=${played}&duration=${dur}`
            }).catch(() => {});
        };
        setInterval(saveTime, 5000);
        window.addEventListener('pagehide', saveTime);

        <?php if (!$is_movie): ?>
        const requestedSeasonId = <?= $season_id ?? 'null' ?>;
        const requestedEpisodeId = parseInt(<?= $episode_id ?? 'null' ?>);
        let currentVideoId = sharedImdbIdLegacy;

        const loadEpisodes = async (seasonId) => {
            const res = await fetch(`./includes/get_episodes.php?season_id=${seasonId}`);
            const data = await res.json();
            const container = document.getElementById('episode-container');
            container.innerHTML = '';
            let episodePlayed = false;

            data.forEach((ep) => {
                const epDiv = document.createElement('div');
                epDiv.className = 'episode';
                epDiv.textContent = `EP${String(ep.episode_number).padStart(2, '0')}`;

                epDiv.addEventListener('click', async () => {
                    currentVideoId = ep.video_id || ep.id;
                    document.querySelectorAll('.episode').forEach(el => el.classList.remove('active-play'));
                    epDiv.classList.add('active-play');

                    art.url = ep.video_url;
                    if (ep.poster) art.poster = ep.poster;

                    art.once('video:loadedmetadata', async () => {
                        const resumeTime = await getSavedTime(currentVideoId);
                        if (resumeTime > 0 && resumeTime < art.duration - 5) {
                            art.currentTime = resumeTime;
                            art.notice.show(`Resumed at ${Math.floor(resumeTime)}s`, 3000);
                        }
                        art.play();
                    });
                });

                container.appendChild(epDiv);

                if (parseInt(requestedEpisodeId) === parseInt(ep.video_id || ep.id) && !episodePlayed) {
                    epDiv.classList.add('active-play');
                    setTimeout(() => epDiv.click(), 100);
                    episodePlayed = true;
                }
            });
        };

        document.querySelectorAll('.season').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.season').forEach(b => b.classList.remove('active-play'));
                btn.classList.add('active-play');
                loadEpisodes(btn.dataset.seasonId);
            });
        });

        window.addEventListener('DOMContentLoaded', async () => {
            const btn = document.querySelector(`.season[data-season-id='${requestedSeasonId}']`) || document.querySelector('.season');
            if (btn) {
                btn.classList.add('active-play');
                await loadEpisodes(btn.dataset.seasonId);
                if (!requestedEpisodeId) {
                    const firstEp = document.querySelector('#episode-container .episode');
                    if (firstEp) firstEp.click();
                }
            }
        });
        <?php else: ?>
        art.on('ready', async () => {
            const time = await getSavedTime(sharedImdbIdLegacy);
            if (time > 0 && time < art.duration - 5) {
                art.currentTime = time;
                art.notice.show(`Resumed at ${Math.floor(time)}s`, 3000);
            }
        });
        <?php endif; ?>
    })();
</script>
<?php endif; ?>

<?php include_once './includes/footer.php'; ?>
<?php endif; ?>
