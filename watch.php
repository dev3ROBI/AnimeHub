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
include_once './includes/tmdb_movie_api.php';
include_once './includes/embed_movie.php';
include_once './includes/embed_tv.php';

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
$is_api   = in_array($provider, ['anilist', 'reanime', 'jikan', 'anikuro', 'tmdb'], true);
$is_tmdb  = ($provider === 'tmdb');
$is_tmdb_movie = $is_tmdb && preg_match('/^movie:\d+$/', $parsed['id'] ?? '');
$is_tmdb_tv    = $is_tmdb && preg_match('/^tv:\d+$/', $parsed['id'] ?? '');

// The digits behind "movie:" / "tv:" — the id every embed request and every
// rebuilt template URL needs. (int)('tv:224263') is 0, so it has to be parsed
// out here; KP.tmdbId below used to hand 0 to get_tv_stream.php, which is why
// TV episodes fell back to "/tv/0/{season}/{episode}" and the providers 404ed.
$tmdb_remote_id = 0;
if ($is_tmdb && preg_match('/^(?:movie|tv):(\d+)$/', (string)($parsed['id'] ?? ''), $kpTmdbMatch)) {
    $tmdb_remote_id = (int)$kpTmdbMatch[1];
}

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
$embedServers = [];

if ($is_api) {
    if ($is_tmdb_movie) {
        // TMDB Movie route
        preg_match('/^movie:(\d+)$/', $parsed['id'] ?? '', $m);
        $tmdb_movie_id = (int)($m[1] ?? 0);
        $anime_data = tmdb_movie_detail($tmdb_movie_id);
        $is_movie = true;
        if ($anime_data) {
            $episodes_list = $anime_data['episodes_list'] ?? [];
            // Set embed URL server-side for direct fallback
            if (function_exists('movie_embed_resolve')) {
                $embedData = movie_embed_resolve($tmdb_movie_id);
                $video_url = $embedData['url'] ?? null;
            }
            $embedServers = function_exists('movie_embed_all') ? movie_embed_all($tmdb_movie_id) : [];
        }
    } elseif ($is_tmdb_tv) {
        // TMDB TV route
        preg_match('/^tv:(\d+)$/', $parsed['id'] ?? '', $m);
        $tmdb_tv_id = (int)($m[1] ?? 0);
        $start_episode = max(1, intval($_GET['ep'] ?? 1));
        $season_id = max(1, intval($_GET['season'] ?? 1));
        $anime_data = tmdb_tv_detail($tmdb_tv_id);
        if ($anime_data) {
            $episodes_list = $anime_data['episodes_list'] ?? [];
            // Set embed URL server-side for direct fallback
            if (function_exists('tv_embed_resolve')) {
                $embedData = tv_embed_resolve($tmdb_tv_id, $season_id, $start_episode);
                $video_url = $embedData['url'] ?? null;
            }
            $embedServers = function_exists('tv_embed_all') ? tv_embed_all($tmdb_tv_id, $season_id, $start_episode) : [];
        }
    } elseif ($provider === 'anikuro') {
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
$season_chain = [];
$kp_season = 0;
$kp_ep_offset = 0;
$banner_url = '';
$next_airing = null;
$title_logo = $title_logo ?? null;
$cast_list  = $cast_list ?? [];
$mal_id     = $mal_id ?? null;
$season_count = 0;

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
    if ($is_tmdb_tv && !empty($episodes_list)) {
        $season_numbers = array_unique(array_map(fn($e) => (int)($e['season'] ?? 1), $episodes_list));
        $season_count = count($season_numbers);
    }
    $anilist_id     = $anime_data['anilist_id'] ?? null;
    $mal_id         = $anime_data['mal_id'] ?? null;
    $relations      = $anime_data['relations'] ?? [];
    $recommendations = $anime_data['recommendations'] ?? [];
    $external_links = $anime_data['external_links'] ?? [];
    $next_airing    = $anime_data['next_airing'] ?? null;
    $anime_status   = $anime_data['status'] ?? '';
    $anime_season   = $anime_data['season'] ?? '';
    $title_logo     = $anime_data['title_logo'] ?? null;
    $cast_list      = $anime_data['cast_list'] ?? [];

    // AniList franchises: Season 1..N switcher via SEQUEL/PREQUEL chain.
    if ($provider === 'anilist' && function_exists('anilist_season_chain')) {
        $season_chain = anilist_season_chain($anime_data, $relations);
        if ($season_chain) {
            $chainIds = [];
            foreach ($season_chain as $sc) {
                $chainIds[] = $sc['id'];
                foreach (($sc['members'] ?? []) as $mem) {
                    if (!empty($mem['id'])) $chainIds[] = $mem['id'];
                }
            }
            $chainIds = array_values(array_unique($chainIds));
            $relations = array_values(array_filter(
                $relations,
                fn($r) => !in_array($r['id'] ?? '', $chainIds, true)
            ));
        }
    }

    // Season number + episode offset for AniList S1E1-style labels (split-cour safe).
    $kp_season    = ($provider === 'anilist') ? 1 : 0;
    $kp_ep_offset = 0;
    foreach ($season_chain as $sc) {
        if (empty($sc['active'])) continue;
        $kp_season = (int)($sc['season_num'] ?? 1);
        foreach (($sc['members'] ?? []) as $mem) {
            if (!empty($mem['current'])) break;
            $kp_ep_offset += (int)($mem['episodes'] ?? 0);
        }
        break;
    }

    // Full-season episode list for multi-member groups (split-cours).
    // Chip shows the summed count, so the list must too: S2E1..S2E25 with
    // each row tagged by source entry (src) + season display number (dn).
    if ($provider === 'anilist' && $season_chain) {
        foreach ($season_chain as $sc) {
            if (empty($sc['active'])) continue;
            $members = $sc['members'] ?? [];
            if (count($members) > 1) {
                $merged = [];
                $dn     = 0;
                foreach ($members as $mem) {
                    $memKey   = (string)($mem['id'] ?? '');
                    $isCur    = !empty($mem['current']);
                    $memCount = (int)($mem['episodes'] ?? 0);
                    $srcEps   = [];

                    if ($isCur) {
                        $srcEps = $episodes_list;
                    } else {
                        $mid = (int)($mem['anilist_id'] ?? 0);
                        if ($mid > 0 && function_exists('anilist_media_detail')) {
                            $detail = anilist_media_detail(['id' => $mid]);
                            if (!empty($detail['episodes_list']) && is_array($detail['episodes_list'])) {
                                $srcEps = $detail['episodes_list'];
                            }
                        }
                    }
                    if (!$srcEps && $memCount > 0) {
                        for ($i = 1; $i <= min($memCount, 2000); $i++) {
                            $srcEps[] = ['number' => $i, 'title' => '', 'image' => '', 'aired' => ''];
                        }
                    }

                    $seen = 0;
                    foreach ($srcEps as $ep) {
                        $seen++;
                        $dn++;
                        $merged[] = [
                            'number' => (int)($ep['number'] ?? $seen),
                            'title'  => (string)($ep['title'] ?? ''),
                            'image'  => (string)($ep['image'] ?? ''),
                            'aired'  => (string)($ep['aired'] ?? ''),
                            'dn'     => $dn,
                            'src'    => $memKey,
                        ];
                    }
                    if ($memCount > $seen) {
                        for ($i = $seen + 1; $i <= $memCount; $i++) {
                            $dn++;
                            $merged[] = [
                                'number' => $i,
                                'title'  => '',
                                'image'  => '',
                                'aired'  => '',
                                'dn'     => $dn,
                                'src'    => $memKey,
                            ];
                        }
                    }
                }
                if ($merged) {
                    $episodes_list  = $merged;
                    $episodes_total = $dn;
                    $kp_ep_offset   = 0; // dn already carries the season offset
                }
            }
            // Keep chip / header / KP.total in sync with the actual list.
            if (!empty($episodes_list) && !$is_tmdb_movie) {
                $episodes_total = max($episodes_total, count($episodes_list));
            }
            break;
        }
    } elseif (!empty($episodes_list) && !$is_tmdb_movie) {
        $episodes_total = max($episodes_total, count($episodes_list));
    }

    // Lock unreleased episodes — full planned count is listed, but not all aired.
    // Firing condition: some rows out, not all out. aired==0 (pre-premiere /
    // NOT_YET_RELEASED) must lock too; the old `aired > 0` silently skipped it.
    $kp_aired_eps = (int)($anime_data['aired_episodes'] ?? 0);
    $kp_is_airing = in_array($anime_status, ['RELEASING', 'NOT_YET_RELEASED'], true);

    // Exact schedule first (AniList airingSchedule, cached 1h) — it also extends
    // the list: titles with an unknown/short total (e.g. One Piece: episodes=null)
    // then get future rows up to the last episode AniList has scheduled.
    $kp_air_map = [];
    if ($kp_is_airing && $provider === 'anilist' && !empty($anilist_id) && function_exists('anilist_upcoming_airing')) {
        $kp_air_map = anilist_upcoming_airing((int)$anilist_id);
    }
    if ($kp_air_map && !$is_tmdb_movie) {
        $kp_have = 0;
        $kp_foreign = false;
        foreach ($episodes_list as $kp_row) {
            $kp_have = max($kp_have, (int)($kp_row['number'] ?? 0));
            if (($kp_row['src'] ?? '') !== '') $kp_foreign = true; // merged split-cour → numbers reset per member
        }
        $kp_air_max = max(array_keys($kp_air_map));
        if (!$kp_foreign && $kp_air_max > $kp_have) {
            for ($kp_n = $kp_have + 1; $kp_n <= $kp_air_max; $kp_n++) {
                $episodes_list[] = ['number' => $kp_n, 'title' => '', 'image' => '', 'aired' => ''];
            }
            $episodes_total = max($episodes_total, $kp_air_max);
        }
    }

    $kp_can_lock = !$is_tmdb_movie && !empty($episodes_list) && $episodes_total > 0
        && $kp_aired_eps < $episodes_total
        && ($kp_aired_eps > 0 || !empty($next_airing) || $kp_is_airing);
    if ($kp_can_lock) {
        $kp_next_air_ts = !empty($next_airing['airingAt']) ? (int)$next_airing['airingAt'] : 0;
        $kp_next_air_ep = !empty($next_airing['episode']) ? (int)$next_airing['episode'] : ($kp_aired_eps + 1);

        // NOT_YET_RELEASED with no nextAiring: premiere from startDate (JST), weekly after.
        if ($kp_next_air_ts === 0 && $anime_status === 'NOT_YET_RELEASED' && !empty($anime_data['aired'])) {
            try {
                $kp_premiere = new DateTime(
                    (string)$anime_data['aired'] . ' 00:00:00',
                    new DateTimeZone('Asia/Tokyo')
                );
                $kp_next_air_ts = $kp_premiere->getTimestamp();
                $kp_next_air_ep = 1;
            } catch (Exception $kp_date_err) {
                $kp_next_air_ts = 0; // unknown date → rows still lock, no arrival shown
            }
        }

        foreach ($episodes_list as &$epLock) {
            $epNo = (int)($epLock['number'] ?? 0);
            if ($epNo > $kp_aired_eps) {
                $epLock['lk'] = 1;
                $kp_src_ok = ($epLock['src'] ?? '') === '' || $epLock['src'] === $raw_id;
                if ($kp_src_ok && isset($kp_air_map[$epNo])) {
                    $epLock['a'] = (int)$kp_air_map[$epNo];
                    $epLock['ax'] = 1; // exact air time from AniList
                } elseif ($kp_next_air_ts > 0) {
                    $epLock['a'] = $kp_next_air_ts + (($epNo - $kp_next_air_ep) * 604800);
                    $epLock['ax'] = 0; // weekly estimate → UI shows "≈"
                }
            }
        }
        unset($epLock);
    }
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
    $title_logo     = null;
    $cast_list      = [];
}

$plot = is_string($plot) ? strip_tags($plot) : '';

// Title logo fallback for non-TMDB sources (same artwork path the slider uses)
if (empty($title_logo) && $is_api && !empty($mal_id) && function_exists('anime_title_logo')) {
    $title_logo = anime_title_logo([
        'mal_id' => $mal_id,
        'title'  => $display_title ?? '',
        'year'   => (int)substr((string)($release_date ?? ''), 0, 4),
    ]);
}

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
    <div id="kp-page-loader">
        <div class="kp-loader-spinner"></div>
        <p>Loading servers &amp; data…</p>
    </div>
    <div class="video-box">
        <div id="anime-player-container" class="kp-player-shell" data-embed-url="<?= kp_e($video_url ?? '') ?>">
            <div id="artplayer"></div>
            <div id="embedplayer" style="display:none;">
                <iframe id="embed-frame" src="about:blank" allowfullscreen frameborder="0"
                        referrerpolicy="no-referrer-when-downgrade"
                        allow="autoplay; fullscreen; encrypted-media; picture-in-picture; encrypted-media"></iframe>
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
                <button type="button" id="kp-error-open-tab" style="margin-left:10px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); color:#fff; padding:8px 16px; border-radius:8px; cursor:pointer;"><i class="fas fa-external-link-alt"></i> New Tab-এ খুলুন</button>
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

            .tmdb-seasons-wrap { margin-bottom: 8px; }
            .tmdb-seasons-wrap h4 { margin-bottom: 6px; }
            /* "18 episodes in total" — the heading itself only counts seasons. */
            .kp-seasons-note {
                margin: 0 0 8px;
                color: #8b8f95;
                font-size: 11.5px;
            }
            .tmdb-season-tabs {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                padding-bottom: 4px;
            }
            .tmdb-season-tab {
                display: inline-flex;
                flex-direction: row;
                align-items: baseline;
                gap: 6px;
                padding: 7px 14px;
                border-radius: 8px;
                border: 1px solid rgba(255,255,255,.12);
                background: rgba(255,255,255,.06);
                color: #ccc;
                cursor: pointer;
                transition: all .2s;
                font-size: 12px;
                line-height: 1.2;
                white-space: nowrap;
                flex-shrink: 0;
            }
            .tmdb-season-tab:hover {
                background: rgba(255,46,99,.15);
                border-color: rgba(255,46,99,.3);
                color: #fff;
            }
            .tmdb-season-tab.active {
                background: linear-gradient(135deg, rgba(255,46,99,.25), rgba(217,4,41,.15));
                border-color: #ff2e63;
                color: #fff;
                box-shadow: 0 0 14px rgba(255,46,99,.3);
            }
            .tmdb-season-tab strong {
                font-size: 12px;
                font-weight: 600;
            }
            .tmdb-season-ep-count {
                font-size: 11px;
                opacity: .6;
            }
            a.tmdb-season-tab {
                text-decoration: none;
                color: inherit;
            }

            .kp-movie-info-card {
                background: linear-gradient(135deg, rgba(255,46,99,.08), rgba(255,255,255,.03));
                border: 1px solid rgba(255,46,99,.15);
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
            }
            .kp-movie-info-card .kp-movie-info-title {
                font-size: 11px; font-weight: 600; color: #ff2e63;
                text-transform: uppercase; letter-spacing: .5px;
                margin-bottom: 10px;
                display: flex; align-items: center; gap: 6px;
            }
            .kp-movie-info-row {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 7px 10px;
                color: #ccc;
                font-size: 13px;
                border-radius: 8px;
                background: rgba(0,0,0,.2);
                margin-bottom: 4px;
            }
            .kp-movie-info-row:last-child { margin-bottom: 0; }
            .kp-movie-info-row i {
                color: #ff2e63;
                width: 16px;
                font-size: 12px;
                text-align: center;
            }

            /* Loading overlay */
            #kp-page-loader {
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(10,10,14,.92);
                backdrop-filter: blur(12px);
                z-index: 9999;
                display: flex; flex-direction: column;
                align-items: center; justify-content: center;
                gap: 18px;
                transition: opacity .4s;
            }
            #kp-page-loader .kp-loader-spinner {
                width: 42px; height: 42px;
                border: 3px solid rgba(255,255,255,.12);
                border-top-color: #ff2e63;
                border-radius: 50%;
                animation: kp-spin .7s linear infinite;
            }
            @keyframes kp-spin { to { transform: rotate(360deg); } }
            #kp-page-loader p { color: #aaa; font-size: 14px; }

            /* Description card */
            .watch-desc-card {
                background: linear-gradient(135deg, rgba(255,46,99,.06), rgba(255,255,255,.03));
                border: 1px solid rgba(255,255,255,.08);
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 16px;
            }
            .watch-desc-card h4 {
                color: #ff2e63; font-size: 13px; font-weight: 600;
                text-transform: uppercase; letter-spacing: .5px;
                margin-bottom: 10px;
                display: flex; align-items: center; gap: 6px;
            }
            .watch-desc-card .desc-text {
                color: #ccc; font-size: 13.5px; line-height: 1.65;
                max-height: 4.5em; overflow: hidden;
                transition: max-height .3s;
            }
            .watch-desc-card .desc-text.expanded { max-height: 2000px; }
            .watch-desc-card .desc-toggle {
                background: none; border: none; color: #ff2e63;
                font-size: 12px; cursor: pointer; margin-top: 6px;
                font-weight: 500;
            }
            .watch-desc-meta {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 8px; margin-top: 12px; padding-top: 12px;
                border-top: 1px solid rgba(255,255,255,.06);
            }
            .watch-desc-meta .meta-item {
                display: flex; align-items: center; gap: 6px;
                font-size: 12px; color: #aaa;
            }
            .watch-desc-meta .meta-item i { color: #ff2e63; font-size: 11px; width: 14px; text-align: center; }
            .watch-desc-meta .meta-item span { color: #ddd; }

            /* Related content section */
            .watch-section-head {
                display: flex; align-items: center; gap: 10px;
                margin: 24px 0 14px;
            }
            .watch-section-head .kp-head-bar {
                width: 4px; height: 20px; border-radius: 2px;
                background: linear-gradient(180deg, #ff2e63, #d90429);
            }
            .watch-section-head h3 {
                font-size: 16px; font-weight: 700; color: #eee; margin: 0;
            }

            /* Related / Recommendations vertical grid on watch page */
            .watch-related-section { margin-bottom: 28px; }
            .watch-related-section .show-item-con {
                display: grid !important;
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 14px 10px;
                padding: 4px 0 12px;
            }
            .watch-related-section .show-item-con .watch-item {
                min-width: 0;
            }
            .watch-related-section .show-item-con .movie-card {
                border-radius: 10px;
                background: rgba(255,255,255,.04);
                border: 1px solid rgba(255,255,255,.06);
                transition: transform .25s, border-color .25s;
            }
            .watch-related-section .show-item-con .movie-card:hover {
                transform: translateY(-3px) scale(1.02);
                border-color: rgba(255,46,99,.35);
            }
            .watch-related-section .show-item-con .thumb-wrapper {
                border-radius: 10px 10px 0 0;
                aspect-ratio: 3/4;
                overflow: hidden;
            }
            .watch-related-section .show-item-con .thumb-wrapper img {
                width: 100%; height: 100%;
                object-fit: cover;
                transition: transform .35s;
            }
            .watch-related-section .show-item-con .movie-card:hover .thumb-wrapper img {
                transform: scale(1.06);
            }
            .watch-related-section .show-item-con .kp-card-info {
                padding: 6px 8px 8px;
            }
            .watch-related-section .show-item-con .kp-card-title {
                font-size: 11.5px; font-weight: 600; color: #eee;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                margin: 0 0 2px;
            }
            .watch-related-section .show-item-con .kp-card-meta {
                font-size: 10px; color: #888; margin: 0;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .watch-related-section .show-item-con .kp-card-rating-badge {
                top: 6px; right: 6px;
            }
            .watch-related-section .show-item-con .kp-card-rating-badge .kp-card-rating {
                font-size: 10px; padding: 2px 6px;
                background: rgba(0,0,0,.7); border-radius: 6px;
            }
            .watch-related-section .show-item-con .kp-card-ep-bar {
                bottom: 0; left: 0; right: 0;
                font-size: 9.5px; padding: 3px 6px;
                background: linear-gradient(transparent, rgba(0,0,0,.85));
            }
            .watch-related-section .show-item-con .kp-card-hover-overlay {
                opacity: 0; transition: opacity .2s;
            }
            .watch-related-section .show-item-con .movie-card:hover .kp-card-hover-overlay {
                opacity: 1;
            }
            .watch-related-section .anime-con .head-show {
                display: none;
            }

            /* Related / Recommendations — stacked vertically, same width as synopsis card */
            .watch-related-row {
                display: flex;
                flex-direction: column;
                gap: 8px;
                width: calc(100% - 48px);
                max-width: none;
                margin: 0 24px 28px;
                align-self: stretch;
                box-sizing: border-box;
            }
            .watch-related-row .watch-related-section {
                flex: 1 1 auto;
                width: 100%;
                min-width: 0;
            }
            .watch-related-section .anime-con.show-container {
                background: transparent;
                border: none;
                border-radius: 0;
                padding: 0;
                box-shadow: none;
            }
            .watch-related-section .show-item-con {
                width: 100%;
            }
            .watch-related-section .show-item-con .movie-card,
            .watch-related-section .show-item-con .watch-item {
                width: 100%;
                max-width: none;
            }
            .watch-related-section .show-item-con .thumb-wrapper {
                width: 100%;
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
            <?php if (!empty($season_chain)): ?>
            <!-- AniList franchise seasons (SEQUEL/PREQUEL) → full page nav -->
            <div class="tmdb-seasons-wrap" id="anilist-seasons-wrap">
                <h4>Seasons</h4>
                <div class="tmdb-season-tabs" id="anilist-season-tabs">
                    <?php foreach ($season_chain as $s): ?>
                    <a class="tmdb-season-tab<?= !empty($s['active']) ? ' active' : '' ?>"
                       href="<?= kp_e($s['url']) ?>"
                       title="<?= kp_e($s['title']) ?>"
                       <?= !empty($s['active']) ? 'aria-current="page"' : '' ?>>
                        <strong><?= kp_e($s['label']) ?></strong>
                        <span class="tmdb-season-ep-count">
                            <?php if (!empty($s['episodes'])): ?>
                                <?= (int)$s['episodes'] ?> eps
                            <?php elseif (!empty($s['year'])): ?>
                                <?= kp_e((string)$s['year']) ?>
                            <?php endif; ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_tmdb_tv): ?>
            <div class="tmdb-seasons-wrap" id="tmdb-seasons-wrap">
                <?php /* Count of seasons here — $episodes_total is the episode count
                         across the whole series and used to print as "Seasons (18)". */ ?>
                <h4>Seasons<?= $season_count ? ' (' . (int)$season_count . ')' : '' ?></h4>
                <?php if ($episodes_total > 0): ?>
                <p class="kp-seasons-note"><?= (int)$episodes_total ?> episodes in total</p>
                <?php endif; ?>
                <div class="tmdb-season-tabs" id="tmdb-season-tabs"></div>
            </div>
            <?php elseif ($is_tmdb_movie): ?>
            <!-- TMDB Movie: no episode list needed -->
            <div class="kp-movie-info-card">
                <div class="kp-movie-info-title"><i class="fas fa-film"></i> Movie Info</div>
                <div class="kp-movie-info-row">
                    <i class="fas fa-film"></i>
                    <span>Movie</span>
                </div>
                <?php if (!empty($genre) && $genre !== 'N/A'): ?>
                <div class="kp-movie-info-row">
                    <i class="fas fa-tags"></i>
                    <span><?= kp_e($genre) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($runtime) && $runtime !== 'N/A'): ?>
                <div class="kp-movie-info-row">
                    <i class="fas fa-clock"></i>
                    <span><?= kp_e($runtime) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <h4>Episodes<?= $episodes_total ? ' (' . (int)$episodes_total . ')' : '' ?></h4>
            <?php endif; ?>
            <?php if (!empty($episodes_list) && !$is_tmdb_movie): ?>
                <input type="text" id="ep-search" placeholder="Filter episode…" style="margin-bottom:6px;">
            <?php endif; ?>
            <?php if (!$is_tmdb_movie): ?>
            <div class="episode-scroll">
                <div class="episodes" id="anikuro-episode-container">
                    <div class="kp-ep-skeleton" aria-hidden="true">
                        <?php for ($kpSkel = 0; $kpSkel < 6; $kpSkel++): ?>
                            <span class="kp-skel-row"></span>
                        <?php endfor; ?>
                    </div>
                    <p class="kp-ep-loading" role="status"><i class="fas fa-spinner fa-spin"></i> এপিসোড লোড হচ্ছে…</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Episode Notes -->
            <?php if ($is_api && $user_id): ?>
            <div class="kp-notes">
                <div class="kp-notes-head">
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

    <div class="movie-details kp-detail-hero">
        <div class="kp-detail-bg" aria-hidden="true">
            <?php if (!empty($banner_url)): ?>
                <img src="<?= kp_e($banner_url) ?>" alt=""<?= kp_img_attrs($banner_url, ['sizes' => '100vw']) ?>>
            <?php elseif (!empty($poster_url)): ?>
                <img src="<?= kp_e($poster_url) ?>" alt=""<?= kp_img_attrs($poster_url, ['sizes' => '100vw']) ?>>
            <?php endif; ?>
            <div class="kp-detail-bg-shade"></div>
        </div>

        <div class="kp-detail-head">
            <?php if (!empty($title_logo)): ?>
                <img class="kp-detail-logo" src="<?= kp_e($title_logo) ?>" alt="<?= kp_e($display_title) ?>" decoding="async">
                <h2 class="kp-detail-title kp-sr-only"><?= kp_e($display_title) ?></h2>
            <?php else: ?>
                <h2 class="kp-detail-title"><?= kp_e($display_title) ?></h2>
            <?php endif; ?>
            <div class="kp-detail-badges">
                <span class="kp-source-badge kp-source-<?= kp_e($provider === 'legacy' ? 'local' : $provider) ?>">
                    <i class="fas fa-database"></i> <?= kp_e(ucfirst($provider === 'legacy' ? 'local' : $provider)) ?>
                </span>
                <?php if ($rating && $rating !== 'N/A'): ?>
                <span class="kp-detail-rating"><i class="fas fa-star"></i> <?= kp_e(is_numeric($rating) ? number_format((float)$rating, 1) : $rating) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="watch-desc-card">
            <h4><i class="fas fa-book-open"></i> Synopsis</h4>
            <div class="desc-text">
                <p><?= $plot !== '' ? nl2br(kp_e($plot)) : 'No description available.' ?></p>
            </div>
            <?php if ($plot !== '' && mb_strlen($plot) > 200): ?>
            <button class="desc-toggle">Read more</button>
            <?php endif; ?>
            <?php if ($is_tmdb_movie): ?>
            <div class="watch-desc-meta">
                <div class="meta-item"><i class="fas fa-theater-masks"></i> <span><?= kp_e($genre) ?></span></div>
                <div class="meta-item"><i class="fas fa-clock"></i> <span><?= kp_e($runtime) ?></span></div>
                <div class="meta-item"><i class="fas fa-language"></i> <span><?= kp_e($language) ?></span></div>
                <div class="meta-item"><i class="fas fa-calendar-alt"></i> <span><?= kp_e($release_date) ?></span></div>
            </div>
            <?php elseif ($is_tmdb_tv): ?>
            <div class="watch-desc-meta">
                <div class="meta-item"><i class="fas fa-theater-masks"></i> <span><?= kp_e($genre) ?></span></div>
                <div class="meta-item"><i class="fas fa-layer-group"></i> <span><?= $season_count ?> Season<?= $season_count !== 1 ? 's' : '' ?></span></div>
                <div class="meta-item"><i class="fas fa-language"></i> <span><?= kp_e($language) ?></span></div>
                <div class="meta-item"><i class="fas fa-calendar-alt"></i> <span><?= kp_e($release_date) ?></span></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($is_api): ?>
        <div class="kp-meta-panel">
            <div class="kp-meta-head"><i class="fas fa-circle-info"></i> Details</div>
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
                        <span class="kp-val"><?= (int)$next_airing['episode'] ?><?= !empty($next_airing['airingAt']) ? ' · ' . kp_e(date('D, d M · g:i A', (int)$next_airing['airingAt'])) : '' ?><?= !empty($next_airing['airingAt']) ? ' ' . kp_countdown_chip((int)$next_airing['airingAt']) : '' ?></span>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($cast_list)): ?>
        <div class="kp-cast-strip">
            <div class="kp-cast-head"><i class="fas fa-theater-masks"></i> Cast</div>
            <div class="kp-cast-row">
                <?php foreach (array_slice($cast_list, 0, 12) as $c): ?>
                <div class="kp-cast-card">
                    <div class="kp-cast-photo">
                        <?php if (!empty($c['photo'])): ?>
                            <img src="<?= kp_e($c['photo']) ?>" alt="<?= kp_e($c['name']) ?>"<?= kp_img_attrs($c['photo'], ['sizes' => '90px']) ?>>
                        <?php else: ?>
                            <span class="kp-cast-initial"><?= kp_e(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="kp-cast-name"><?= kp_e($c['name']) ?></span>
                    <?php if (!empty($c['character'])): ?>
                    <span class="kp-cast-char"><?= kp_e($c['character']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($external_links)): ?>
            <div class="kp-ext-links">
                <div class="kp-ext-head"><i class="fas fa-up-right-from-square"></i> Official / Streaming</div>
                <div class="kp-links">
                    <?php foreach (array_slice($external_links, 0, 10) as $link): ?>
                        <a href="<?= kp_e($link['url']) ?>" target="_blank" rel="noopener">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                            <?= kp_e($link['site'] ?: 'Link') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="watch-related-row">
    <?php if (!empty($relations)): ?>
        <div class="watch-related-section">
            <div class="watch-section-head">
                <div class="kp-head-bar"></div>
                <h3>Related Anime</h3>
            </div>
            <?= render_card_row(null, null, array_slice($relations, 0, 12), ['show_ep_badge' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($recommendations)): ?>
        <div class="watch-related-section">
            <div class="watch-section-head">
                <div class="kp-head-bar"></div>
                <h3>You Might Also Like</h3>
            </div>
            <?= render_card_row(null, null, array_slice($recommendations, 0, 12), ['show_ep_badge' => false]) ?>
        </div>
    <?php endif; ?>
    </div>
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
            isTmdb: <?= $is_tmdb ? 'true' : 'false' ?>,
            isTmdbMovie: <?= $is_tmdb_movie ? 'true' : 'false' ?>,
            isTmdbTv: <?= $is_tmdb_tv ? 'true' : 'false' ?>,
            tmdbType: '<?= $is_tmdb_movie ? 'movie' : ($is_tmdb_tv ? 'tv' : '') ?>',
            tmdbId: <?= $is_tmdb ? (int)$tmdb_remote_id : 'null' ?>,
            serverEmbedUrl: <?= json_encode($video_url ?? null, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            embedServers: <?= json_encode($embedServers ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            <?php /* The template list has to match the page: a movie id in a TV
                     template ("…/tv/550/1/1") silently resolves to nothing. */ ?>
            embedTemplates: <?= json_encode(array_map(
                fn($p) => ['label' => $p['label'] ?? '', 'url' => $p['url'] ?? ''],
                $is_tmdb_movie ? ($GLOBALS['MOVIE_EMBED_PROVIDERS'] ?? []) : ($GLOBALS['TV_EMBED_PROVIDERS'] ?? [])
            ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            // Settings > Preferences (header.php loads $kp_settings for the
            // signed-in user; the default keeps auto-play on when it is absent).
            autoplayNext: <?= !empty($kp_settings['autoplay']) ? 'true' : 'false' ?>,
            season: <?= $season_id ? (int)$season_id : '1' ?>,
            currentSeason: <?= $season_id ? (int)$season_id : '1' ?>,
            kpSeason: <?= (int)$kp_season ?>,
            kpEpOffset: <?= (int)$kp_ep_offset ?>,
            resume: <?= json_encode($resume ?: null, JSON_UNESCAPED_UNICODE) ?>,
            episodes: <?= json_encode(
                array_map(fn($e) => [
                    'n'   => (int)$e['number'],
                    't'   => (string)($e['title'] ?? ''),
                    's'   => (int)($e['season'] ?? 1),
                    'dn'  => isset($e['dn']) ? (int)$e['dn'] : null,
                    'src' => (string)($e['src'] ?? ''),
                    'a'   => isset($e['a']) && $e['a'] ? (int)$e['a'] : null,
                    'ax'  => !empty($e['ax']),
                    'lk'  => !empty($e['lk']),
                ], $episodes_list),
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

        // ─── Episode keys ────────────────────────────────────────────
        // TMDB TV restarts episode numbers at 1 in every season, so resume
        // points, watch history and notes have to be stored against a
        // season-aware number or S2E2 and S1E2 would share one row. The key is
        // built the same way includes/progress.php builds it:
        // (season - 1) * 10000 + episode. Season 1 keeps the plain episode
        // number, and every other provider is untouched.
        const TV_STRIDE = 10000;
        function epKey(ep) {
            if (!KP.isTmdbTv) return parseInt(ep, 10) || 0;
            return ((KP.currentSeason || 1) - 1) * TV_STRIDE + (parseInt(ep, 10) || 0);
        }
        /** Plain episode number behind a stored key — null when it is another season's. */
        function localEp(key) {
            const k = parseInt(key, 10) || 0;
            if (!KP.isTmdbTv) return k;
            const season = Math.floor((k - 1) / TV_STRIDE) + 1;
            if (season !== (KP.currentSeason || 1)) return null;
            return k - (season - 1) * TV_STRIDE;
        }

        // ─── Remembered embed server (TMDB movies & series) ───────────
        // Whichever chip the viewer picks is stored per title, so the next
        // visit — and every other episode or season of the same title — opens
        // with the mirror that actually worked for them. Kept in localStorage:
        // it is a per-device playback preference, not account data.
        const SERVER_MEMO_PREFIX = 'kp_server_';
        function serverMemoKey() { return SERVER_MEMO_PREFIX + (KP.catalogId || KP.id || ''); }
        function isEmbedPage() { return !!(KP.isTmdbMovie || KP.isTmdbTv); }
        function rememberServer(key) {
            if (!key || !isEmbedPage()) return;
            try { localStorage.setItem(serverMemoKey(), String(key)); } catch (e) { /* private mode */ }
        }
        function rememberedServer() {
            if (!isEmbedPage()) return '';
            try { return localStorage.getItem(serverMemoKey()) || ''; } catch (e) { return ''; }
        }

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
        const MIN_WATCH_SESSION_MS = 5000; // 5s minimum before counting
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

        // KP.resume.episode is the *stored* key (season-aware for TMDB TV), so
        // it can be cached as-is.
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
        function progressVideoId(ep) { return KP.id + ':' + epKey(ep); }

        function loadProgress(ep) {
            if (!KP.userId) return Promise.resolve(0);
            const key = epKey(ep);
            if (typeof resumeCache[key] === 'number') return Promise.resolve(resumeCache[key]);
            return fetch('./includes/get_progress.php?video_id=' + encodeURIComponent(KP.id + ':' + key))
                .then((r) => r.json())
                .then((d) => {
                    const pos = (d && typeof d.position === 'number') ? d.position : 0;
                    resumeCache[key] = pos;
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
                resumeCache[epKey(ep)] = position;
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
            resumeCache[epKey(ep)] = position;
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
            var nextEp = currentEp;
            var nextSeason = KP.currentSeason;
            var nextMeta = null;
            var nextUrl = null;
            var title = '';

            if (KP.isTmdbTv) {
                nextEp = currentEp + 1;
                var currentSeasonEps = (KP.episodes || []).filter(function (e) {
                    return (e.s || 1) === nextSeason;
                });
                var maxEpInSeason = currentSeasonEps.length ? Math.max.apply(null, currentSeasonEps.map(function (e) { return e.n; })) : 0;

                if (nextEp > maxEpInSeason) {
                    var allSeasons = [];
                    var seen = {};
                    (KP.episodes || []).forEach(function (e) {
                        var s = e.s || 1;
                        if (!seen[s]) { seen[s] = true; allSeasons.push(s); }
                    });
                    allSeasons.sort(function (a, b) { return a - b; });
                    var idx = allSeasons.indexOf(nextSeason);
                    if (idx >= 0 && idx < allSeasons.length - 1) {
                        nextSeason = allSeasons[idx + 1];
                        nextEp = 1;
                    } else {
                        return;
                    }
                }

                nextMeta = (KP.episodes || []).find(function (e) {
                    return e.n === nextEp && (e.s || 1) === nextSeason;
                });
                title = nextMeta && nextMeta.t ? nextMeta.t : ('S' + nextSeason + ' E' + nextEp);
                var tmdbUrl = new URL(window.location.href);
                tmdbUrl.searchParams.set('ep', nextEp);
                tmdbUrl.searchParams.set('season', nextSeason);
                KP.currentSeason = nextSeason;
                tmdbUrl.searchParams.delete('lang');
                nextUrl = tmdbUrl.toString();
            } else if ((KP.episodes || []).some(function (e) { return e.dn != null; })) {
                // AniList full-season list: advance by display number (dn), not local n.
                var curDn = null;
                for (var i = 0; i < KP.episodes.length; i++) {
                    var e = KP.episodes[i];
                    var srcOk = !e.src || e.src === KP.id;
                    if (srcOk && e.n === currentEp) { curDn = e.dn; break; }
                }
                if (curDn == null) curDn = currentEp + (KP.kpEpOffset || 0);
                nextMeta = (KP.episodes || []).find(function (x) { return x.dn === curDn + 1; });
                if (!nextMeta) return;
                title = nextMeta.t ? nextMeta.t : ('S' + KP.kpSeason + 'E' + nextMeta.dn);
                if (nextMeta.src && nextMeta.src !== KP.id) {
                    nextUrl = './watch.php?id=' + encodeURIComponent(nextMeta.src) + '&ep=' + nextMeta.n;
                } else {
                    var aniUrl = new URL(window.location.href);
                    aniUrl.searchParams.set('ep', nextMeta.n);
                    aniUrl.searchParams.delete('lang');
                    nextUrl = aniUrl.toString();
                }
            } else {
                nextEp = currentEp + 1;
                var hasMore = !KP.total || nextEp <= KP.total;
                if (!hasMore) return;
                nextMeta = (KP.episodes || []).find(function (e2) {
                    return e2.n === nextEp && (e2.s || 1) === nextSeason;
                });
                title = nextMeta && nextMeta.t ? nextMeta.t : ('S' + nextSeason + ' E' + nextEp);
                var legacyUrl = new URL(window.location.href);
                legacyUrl.searchParams.set('ep', nextEp);
                legacyUrl.searchParams.delete('lang');
                nextUrl = legacyUrl.toString();
            }

            if (!nextOverlay || !nextUrl) return;

            if (nextTitle) nextTitle.textContent = title;

            nextOverlay.style.display = 'flex';
            nextCancelled = false;

            // "Auto-play Next" (Settings tab) decides whether the countdown is
            // allowed to jump to the next episode on its own.
            var autoplayNext = KP.autoplayNext !== false;
            var remaining = 5;
            if (nextCount) nextCount.textContent = autoplayNext ? remaining : '';
            nextBar.style.transition = 'none';
            nextBar.style.width = autoplayNext ? '100%' : '0%';

            nextPlayBtn.onclick = function () {
                clearInterval(nextTimer);
                window.location.href = nextUrl;
            };
            nextCancelBtn.onclick = function () {
                nextCancelled = true;
                clearInterval(nextTimer);
                nextOverlay.style.display = 'none';
            };

            if (!autoplayNext) return;   // overlay stays open until the viewer chooses

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

            if (!embedFrame) return;
            if (!url || url === 'about:blank' || url === 'null' || url === '') {
                setState('error', { message: 'No embed URL found. Try a different server below.' });
                return;
            }

            console.log('[KP] playEmbed loading:', url);
            let settled = false;
            const watchdog = setTimeout(function () {
                if (settled) return;
                setState('error', { message: 'সার্ভার সময়মতো সাড়া দেয়নি। নিচ থেকে অন্য সার্ভার বেছে নিন বা ব্রাউজারে সরাসরি খুলুন।' });
            }, 20000);

            embedFrame.onload = function () {
                const src = embedFrame.getAttribute('src') || '';
                if (!src || src === 'about:blank') return;
                settled = true;
                clearTimeout(watchdog);
                setState('playing');
                saveHistory(currentEp);

                playerActive = true;
                beginPlayClock();
                startProgressLoop(currentEp);
            };
            embedFrame.onerror = function () {
                settled = true;
                clearTimeout(watchdog);
                setState('error', { message: 'সার্ভার লোড হয়নি। অন্য সার্ভার বেছে নিন।' });
            };
            embedFrame.src = url;
        }

        // ─── Client-side TV embed rebuild (avoids stale S1E1 fallback) ──
        function buildTvServers(season, ep) {
            season = parseInt(season, 10) || 1;
            ep = parseInt(ep, 10) || 1;
            var out = [];
            var templates = KP.embedTemplates || {};
            Object.keys(templates).forEach(function (key) {
                var p = templates[key];
                if (!p || !p.url) return;
                var url = String(p.url)
                    .replace(/\{tmdb\}/g, KP.tmdbId)
                    .replace(/\{season\}/g, season)
                    .replace(/\{episode\}/g, ep);
                out.push({ key: key, label: p.label || key, mode: 'embed', url: url });
            });
            return out;
        }

        function clearStaleEmbedState() {
            KP.serverEmbedUrl = null;
            KP.embedServers = [];
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
                return !s.lang || s.lang === currentMode || s.lang === 'any';
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

            var wanted       = rememberedServer();
            var rememberedBtn = null;
            var rememberedHit = null;

            filtered.forEach(function (server) {
                var btn = document.createElement('button');
                btn.className = 'server-chip';
                btn.dataset.key = server.key;
                var langTag = (!server.lang || server.lang === 'any') ? '' : ' [' + server.lang.toUpperCase() + ']';
                btn.textContent = (server.label || server.key || 'HD').replace(/ · /g, ' ') + langTag;
                if (wanted && server.key === wanted) {
                    rememberedBtn = btn;
                    rememberedHit = server;
                }
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.server-chip').forEach(function (b) {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    useServer(server);
                });
                chipsEl.appendChild(btn);
            });

            if (rememberedBtn) {
                // This title still offers the server the viewer chose last time,
                // so start there instead of the first mirror in the list.
                rememberedBtn.classList.add('active');
                if (rememberedHit && payload && payload.ok) {
                    payload.url    = rememberedHit.url;
                    payload.source = 'embed:' + (rememberedHit.key || '');
                }
            } else {
                var first = chipsEl.querySelector('.server-chip');
                if (first) first.classList.add('active');
            }
        }

        function renderLangToggle() {
            if (!langEl) return;
            langEl.innerHTML = '';

            // TMDB movies/TV use external embeds with their own language controls
            if (KP.isTmdbMovie || KP.isTmdbTv) return;

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
            // An explicit pick is what gets remembered for this title.
            rememberServer(server.key);

            if (server.mode === 'embed') {
                playEmbed(server.url);
                return;
            }

            const resumeAt = wantResume ? (resumeCache[epKey(currentEp)] || 0) : 0;
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
            const pos = resumeCache[epKey(ep)];
            if (ep !== currentEp) return 0;
            return (typeof pos === 'number' && pos > 10) ? pos : 0;
        }

        function updateGate() {
            const meta = (KP.episodes || []).find(function (e) {
                const srcOk = !e.src || e.src === KP.id;
                return srcOk && e.n === currentEp;
            });
            const epTitle = meta && meta.t ? meta.t : '';
            const showNum = (meta && meta.dn != null)
                ? meta.dn
                : (currentEp + (KP.kpEpOffset || 0));

            if (gateEpEl) {
                if (KP.isTmdbMovie) {
                    gateEpEl.textContent = 'Watch Movie';
                } else if (KP.isTmdbTv) {
                    // Episodes of *this* season, not the series total (KP.total).
                    var seasonEps = (KP.episodes || []).filter(function (e) {
                        return (e.s || 1) === KP.currentSeason;
                    });
                    gateEpEl.textContent = 'Season ' + KP.currentSeason + ' · Episode ' + currentEp
                        + (seasonEps.length ? ' of ' + seasonEps.length : '');
                } else if (KP.kpSeason > 0 && meta && meta.dn != null) {
                    gateEpEl.textContent = 'Episode ' + showNum + (KP.total ? ' of ' + KP.total : '');
                } else {
                    gateEpEl.textContent = 'Episode ' + currentEp + (KP.total ? ' of ' + KP.total : '');
                }
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
                if (KP.isTmdbMovie) {
                    gatePlayLabel.textContent = 'Play Movie';
                } else if (KP.kpSeason > 0 && meta && meta.dn != null) {
                    gatePlayLabel.textContent = at > 0
                        ? 'Resume from ' + formatClock(at)
                        : 'Play Episode ' + showNum;
                } else {
                    gatePlayLabel.textContent = at > 0
                        ? 'Resume from ' + formatClock(at)
                        : 'Play Episode ' + currentEp;
                }
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
                resumeCache[epKey(ep)] = pos;
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
            resetWatchClock(resumeCache[epKey(ep)] || 0);

            if (autoplay) {
                setState('loading', { message: 'EP ' + ep + ' এর সোর্স খোঁজা হচ্ছে…' });
            }
            if (chipsEl) {
                chipsEl.innerHTML = '<span class="kp-chip-label"><i class="fas fa-spinner fa-spin"></i> Servers…</span>';
            }
            destroyPlayers();
            refreshResume(ep);

            // TMDB movies/TV use embed players, not the regular stream resolver
            if (KP.isTmdbMovie) {
                var movieParams = new URLSearchParams({ tmdb: KP.tmdbId });
                return fetch('./includes/get_movie_stream.php?' + movieParams.toString())
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        payload = data;
                        if (!data || !data.ok || !data.url) {
                            // Fallback: use PHP-resolved embed URL
                            if (KP.serverEmbedUrl) {
                                payload = { ok: true, mode: 'embed', url: KP.serverEmbedUrl, servers: KP.embedServers || [] };
                                renderServers(KP.embedServers || []);
                                if (autoplay) playPayload();
                                else { setState('gate'); updateGate(); }
                                return;
                            }
                            if (chipsEl) chipsEl.innerHTML = '';
                            setState('error', { message: 'No source found for this movie.' });
                            return;
                        }
                        renderServers(data.servers || []);
                        if (autoplay) playPayload();
                        else { setState('gate'); updateGate(); }
                    })
                    .catch(function(err) {
                        console.error('resolve error', err);
                        // Fallback: use PHP-resolved embed URL
                        if (KP.serverEmbedUrl) {
                            payload = { ok: true, mode: 'embed', url: KP.serverEmbedUrl, servers: KP.embedServers || [] };
                            renderServers(KP.embedServers || []);
                            if (autoplay) playPayload();
                            else { setState('gate'); updateGate(); }
                            return;
                        }
                        if (chipsEl) chipsEl.innerHTML = '';
                        setState('error', { message: 'Network error.' });
                    });
            }

            if (KP.isTmdbTv) {
                var tvParams = new URLSearchParams({ tmdb: KP.tmdbId, season: KP.currentSeason || 1, episode: ep });
                return fetch('./includes/get_tv_stream.php?' + tvParams.toString())
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        payload = data;
                        if (!data || !data.ok || !data.url) {
                            // Fallback: rebuild embed URLs for the requested season/episode
                            var rebuiltTv = buildTvServers(KP.currentSeason || 1, ep);
                            if (rebuiltTv.length) {
                                payload = { ok: true, mode: 'embed', url: rebuiltTv[0].url, servers: rebuiltTv };
                                renderServers(rebuiltTv);
                                if (autoplay) playPayload();
                                else { setState('gate'); updateGate(); }
                                return;
                            }
                            if (chipsEl) chipsEl.innerHTML = '';
                            setState('error', { message: 'No source found for this episode.' });
                            return;
                        }
                        renderServers(data.servers || []);
                        if (autoplay) playPayload();
                        else { setState('gate'); updateGate(); }
                    })
                    .catch(function(err) {
                        console.error('resolve error', err);
                        // Fallback: rebuild embed URLs for the requested season/episode
                        var rebuiltTvCatch = buildTvServers(KP.currentSeason || 1, ep);
                        if (rebuiltTvCatch.length) {
                            payload = { ok: true, mode: 'embed', url: rebuiltTvCatch[0].url, servers: rebuiltTvCatch };
                            renderServers(rebuiltTvCatch);
                            if (autoplay) playPayload();
                            else { setState('gate'); updateGate(); }
                            return;
                        }
                        if (chipsEl) chipsEl.innerHTML = '';
                        setState('error', { message: 'Network error.' });
                    });
            }

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
                const resumeAt = wantResume ? (resumeCache[epKey(currentEp)] || 0) : 0;
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
                body: 'anime_slug=' + encodeURIComponent(KP.catalogId) + '&episode_number=' + encodeURIComponent(epKey(ep))
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
            // The season has to travel with the episode, otherwise a refresh (or
            // a shared link) drops the viewer back into season 1.
            if (KP.isTmdbTv) url.searchParams.set('season', KP.currentSeason || 1);
            url.searchParams.set('ep', ep);
            url.searchParams.set('lang', currentMode || currentLang);
            history.replaceState({}, '', url.toString());

            // Episode switch is an explicit play intent, so autoplay and resume.
            wantResume = true;
            resolve(ep, { autoplay: true, force: true });
            loadNotes(ep);
        }

        // ─── Episode list (built here so long series stay a small payload) ──
        function episodeElement(ep) {
            const number = ep.n;
            const title  = ep.t;
            const season = ep.s;
            const dn     = (ep.dn != null) ? ep.dn : (number + (KP.kpEpOffset || 0));
            const src    = ep.src || '';
            const isCur  = !src || src === KP.id;

            const el = document.createElement('div');
            const active = isCur && (number === currentEp);
            el.className = 'episode kp-ep' + (active ? ' active-play' : '');
            // Progress keys are local to the current entry — tag foreign rows so they never match.
            el.dataset.episode = isCur ? number : (src + ':' + number);
            el.dataset.search = ('s' + (KP.kpSeason || season || 1) + 'e' + dn + ' ep ' + number + ' ' + (title || '')).toLowerCase();

            const label = document.createElement('span');
            label.className = 'kp-ep-main';
            const num = document.createElement('strong');
            if (KP.isTmdbTv) {
                num.textContent = 'S' + (season || KP.currentSeason || 1) + ' E' + number;
            } else if (KP.kpSeason > 0) {
                num.textContent = 'S' + KP.kpSeason + 'E' + dn;
            } else {
                num.textContent = 'EP' + String(number).padStart(3, '0');
            }
            label.appendChild(num);
            if (title && !KP.isTmdbTv) {
                const t = document.createElement('span');
                t.className = 'kp-ep-title';
                t.textContent = ' · ' + (title.length > 42 ? title.slice(0, 42) + '…' : title);
                label.appendChild(t);
            }
            const isLocked = !!ep.lk;
            if (isLocked) el.classList.add('kp-ep-locked');
            el.appendChild(label);

            const right = document.createElement('span');
            right.className = 'kp-ep-right';

            const badge = document.createElement('span');
            badge.className = 'kp-ep-resume';
            badge.style.display = 'none';
            right.appendChild(badge);

            if (isLocked) {
                const lockI = document.createElement('i');
                lockI.className = 'fas fa-lock';
                right.appendChild(lockI);
                if (ep.a) {
                    const when = new Date(ep.a * 1000);
                    const exact = !!ep.ax;
                    const air = document.createElement('span');
                    air.className = 'kp-ep-air' + (exact ? '' : ' kp-ep-air-est');
                    // Exact schedule → "Oct 5 · 20:30"; weekly estimate → "≈ Oct 12".
                    air.textContent = (exact ? '' : '\u2248 ') + when.toLocaleString(undefined, exact
                        ? { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }
                        : { month: 'short', day: 'numeric' });
                    air.title = (exact ? 'Airs ' : 'Estimated ~ ') + when.toLocaleString();
                    right.appendChild(air);

                    const cd = document.createElement('span');
                    cd.className = 'kp-cd';
                    cd.setAttribute('data-release', String(ep.a));
                    cd.setAttribute('data-done-text', 'Airing now');
                    right.appendChild(cd); // countdown.js ticks it → fires kp:released at 0
                }
            } else {
                const icon = document.createElement('i');
                icon.className = 'fas fa-play-circle';
                right.appendChild(icon);
            }
            el.appendChild(right);

            el.style.setProperty('--kp-pct', '0%');
            el.addEventListener('click', function () {
                if (isLocked) {
                    if (typeof kpToast === 'function') {
                        kpToast(ep.a
                            ? 'EP ' + number + ' arrives ' + new Date(ep.a * 1000).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
                            : 'EP ' + number + " hasn't aired yet — locked until release", 'info');
                    }
                    return;
                }
                if (!isCur) {
                    window.location.href = './watch.php?id=' + encodeURIComponent(src) + '&ep=' + number;
                    return;
                }
                selectEpisode(el, number);
            });
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
                    Object.keys(map).forEach(function (stored) {
                        const pos = Number(map[stored]) || 0;
                        // Keys come back as stored numbers; only this season's
                        // rows belong on the episode list that is on screen.
                        const ep = localEp(stored);
                        if (ep === null) return;
                        resumeCache[stored] = pos;
                        markEpisodeProgress(ep, pos);
                    });
                })
                .catch(function () { /* progress is decoration — never block on it */ });
        }

        // A countdown hit zero → that episode just premiered: unlock its row live
        // (server-side aired data can lag up to the next cache TTL).
        document.addEventListener('kp:released', function (e) {
            const cd = e.detail && e.detail.el;
            const row = cd && cd.closest && cd.closest('.kp-ep');
            if (!row || !row.classList.contains('kp-ep-locked')) return;
            row.classList.remove('kp-ep-locked');
            const lockI = row.querySelector('.kp-ep-right .fa-lock');
            if (lockI) lockI.classList.replace('fa-lock', 'fa-play-circle');
            const air = row.querySelector('.kp-ep-air');
            if (air) air.remove();
            const numRaw = row.dataset.episode || '';
            const p = numRaw.indexOf(':') >= 0 ? numRaw.split(':') : ['', numRaw];
            const srcKey = p[0] || '';
            const numKey = p[p.length - 1];
            (KP.episodes || []).forEach(function (x) {
                if (String(x.n) !== String(numKey)) return;
                const xSrc = x.src || '';
                if (xSrc === srcKey || (srcKey === '' && (xSrc === '' || xSrc === KP.id))) x.lk = false;
            });
            if (typeof kpToast === 'function') {
                kpToast('Episode ' + numKey + ' has arrived — tap to play', 'success');
            }
        });

        function buildEpisodeList() {
            const container = document.getElementById('anikuro-episode-container');
            if (!container) return;
            container.innerHTML = '';

            const list = Array.isArray(KP.episodes) ? KP.episodes : [];
            const isTmdbTv = KP.isTmdbTv;
            const filtered = isTmdbTv
                ? list.filter(function (e) { return (e.s || 1) === KP.currentSeason; })
                : list;

            if (!filtered.length) {
                const p = document.createElement('p');
                p.style.cssText = 'color:#aaa; padding:8px;';
                p.textContent = isTmdbTv
                    ? 'এই সিজনে কোনো এপিসোড পাওয়া যায়নি।'
                    : 'এপিসোড লিস্ট পাওয়া যায়নি — নিচে ম্যানুয়ালি নম্বর দিন।';
                container.appendChild(p);
                return;
            }

            const frag = document.createDocumentFragment();
            const lockedN = filtered.filter(function (e) { return !!e.lk; }).length;
            let dividerDone = false;
            filtered.forEach(function (ep) {
                if (lockedN && ep.lk && !dividerDone) {
                    dividerDone = true;
                    const div = document.createElement('div');
                    div.className = 'kp-ep-coming';
                    div.innerHTML = '<i class="fas fa-lock"></i><span>Coming Soon</span><b>' + lockedN + ' scheduled</b>';
                    frag.appendChild(div);
                }
                frag.appendChild(episodeElement(ep));
            });
            container.appendChild(frag);
            if (typeof kpCountdownScan === 'function') kpCountdownScan();

            Object.keys(resumeCache).forEach(function (stored) {
                const ep = localEp(stored);
                if (ep === null) return;
                markEpisodeProgress(ep, resumeCache[stored]);
            });

            const active = container.querySelector('.episode.active-play');
            const scroller = active && active.closest('.episode-scroll');
            if (active && scroller) {
                scroller.scrollTop = active.offsetTop - scroller.clientHeight / 2 + active.offsetHeight / 2;
            }

            loadAllProgress();
        }

        // ─── Season tabs for TMDB TV ──────────────────────────────
        function buildSeasonTabs() {
            if (!KP.isTmdbTv) return;
            const tabsEl = document.getElementById('tmdb-season-tabs');
            if (!tabsEl) return;
            tabsEl.innerHTML = '';

            var seasons = {};
            (KP.episodes || []).forEach(function (ep) {
                var s = ep.s || 1;
                if (!seasons[s]) seasons[s] = 0;
                seasons[s]++;
            });

            var keys = Object.keys(seasons).map(Number).sort(function (a, b) { return a - b; });
            if (!keys.length) {
                document.getElementById('tmdb-seasons-wrap').style.display = 'none';
                return;
            }

            keys.forEach(function (sNum) {
                var btn = document.createElement('button');
                btn.className = 'tmdb-season-tab' + (sNum === KP.currentSeason ? ' active' : '');
                btn.dataset.season = sNum;
                btn.innerHTML = '<strong>Season ' + sNum + '</strong><span class="tmdb-season-ep-count">' + seasons[sNum] + ' eps</span>';
                btn.addEventListener('click', function () {
                    if (KP.currentSeason === sNum) return;
                    KP.currentSeason = sNum;
                    clearStaleEmbedState();

                    document.querySelectorAll('.tmdb-season-tab').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');

                    // Update gate label
                    var gateEpEl = document.getElementById('kp-gate-ep');
                    if (gateEpEl) gateEpEl.textContent = 'Season ' + sNum;

                    // Update URL
                    var url = new URL(window.location.href);
                    url.searchParams.set('season', sNum);
                    url.searchParams.delete('ep');
                    history.replaceState({}, '', url.toString());

                    currentEp = 1;
                    payload = null;
                    buildSeasonTabs();
                    buildEpisodeList();

                    // Auto-play first episode of the season
                    var firstEp = document.querySelector('#anikuro-episode-container .episode');
                    if (firstEp) selectEpisode(firstEp, 1);
                });
                tabsEl.appendChild(btn);
            });
        }

        const epSearch = document.getElementById('ep-search');
        if (epSearch) {
            let epSearchTimer = null;
            epSearch.addEventListener('input', function () {
                clearTimeout(epSearchTimer);
                epSearchTimer = setTimeout(function () {
                    const q = epSearch.value.trim().toLowerCase();
                    document.querySelectorAll('#anikuro-episode-container .episode').forEach(function (el) {
                        const hay = (el.dataset.search || '') + ' ' + el.dataset.episode;
                        el.style.display = (!q || hay.indexOf(q) !== -1) ? 'flex' : 'none';
                    });
                    const comingRow = document.querySelector('#anikuro-episode-container .kp-ep-coming');
                    if (comingRow) comingRow.style.display = q ? 'none' : 'flex';
                }, 120);
            });
        }

        // ─── Episode notes ──────────────────────────────────────────
        // Lives here (not in the legacy script) because the notes UI is only
        // rendered for catalogue anime — and `art` + the watch clock, which
        // give a note its timestamp, only exist in this scope.
        const noteList  = document.getElementById('notes-list');
        const noteInput = document.getElementById('note-input');
        const noteSave  = document.getElementById('note-save-btn');

        function notesVideoId(ep) { return KP.id + ':' + epKey(ep); }

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

            buildSeasonTabs();
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
            const openTabBtn = document.getElementById('kp-error-open-tab');
            if (openTabBtn) {
                openTabBtn.addEventListener('click', function () {
                    if (payload && payload.url) {
                        window.open(payload.url, '_blank');
                    }
                });
            }

            // Resolve in the background so the gate can show the real source and
            // a resume offer, but do not start playback until the user asks.
            resolve(currentEp, { autoplay: false }).then(function() {
                // Remove page loader
                var pageLoader = document.getElementById('kp-page-loader');
                if (pageLoader) { pageLoader.style.display = 'none'; }
            }).catch(function() {
                var pageLoader = document.getElementById('kp-page-loader');
                if (pageLoader) { pageLoader.style.display = 'none'; }
            });
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

    // ─── Description toggle ──────────────────────────────────────
    document.querySelectorAll('.desc-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var text = btn.previousElementSibling;
            if (text) {
                text.classList.toggle('expanded');
                btn.textContent = text.classList.contains('expanded') ? 'Show less' : 'Read more';
            }
        });
    });
</script>
<?php endif; ?>

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
