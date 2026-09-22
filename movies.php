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

$section  = isset($_GET['section']) ? trim((string)$_GET['section']) : 'trending';
$genreId  = isset($_GET['genre']) ? max(0, intval($_GET['genre'])) : 0;
$page     = isset($_GET['page']) ? max(1, min(500, intval($_GET['page']))) : 1;
$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$validSections = ['trending', 'popular', 'now_playing', 'upcoming', 'top_rated'];
if (!in_array($section, $validSections)) $section = 'trending';

$sectionLabels = [
    'trending'    => 'Trending Movies',
    'popular'     => 'Popular Movies',
    'now_playing' => 'Now Playing',
    'upcoming'    => 'Upcoming Movies',
    'top_rated'   => 'Top Rated Movies',
];

$genres = tmdb_movie_genre_list();

if ($search !== '') {
    $items = tmdb_movie_search($search, $page);
    $sectionTitle = 'Search: ' . $search;
} elseif ($genreId > 0) {
    $items = tmdb_movie_by_genre($genreId, $page);
    $genreName = $genres[$genreId] ?? 'Genre #' . $genreId;
    $sectionTitle = $genreName . ' Movies';
} else {
    switch ($section) {
        case 'popular':     $items = tmdb_movie_popular($page); break;
        case 'now_playing': $items = tmdb_movie_now_playing($page); break;
        case 'upcoming':    $items = tmdb_movie_upcoming($page); break;
        case 'top_rated':   $items = tmdb_movie_top_rated($page); break;
        default:            $items = tmdb_movie_trending($page); break;
    }
    $sectionTitle = $sectionLabels[$section] ?? 'Movies';
}

function kp_movie_url($section = 'trending', $genreId = 0, $page = 1, $search = '') {
    $params = ['section' => $section, 'page' => max(1, (int)$page)];
    if ($genreId > 0) $params['genre'] = $genreId;
    if ($search !== '') $params['q'] = $search;
    return './movies.php?' . http_build_query($params);
}
?>

<div class="home-index-con">
    <div class="notice-box-container">
        <div class="not-con">
            <p>
                <i class="fas fa-clapperboard" style="color:#ff2e63;"></i> <strong>Movies</strong>
                — <span style="opacity:0.85; font-weight:400;">
                    <?= kp_e($sectionTitle) ?> · page <?= (int)$page ?>
                </span>
            </p>
        </div>
    </div>

    <div class="show-container">
        <div class="head-show">
            <i class="fas fa-clapperboard" style="color:#ff2e63;"></i>
            <p>Browse</p>
            <div class="kp-sort-bar">
                <?php foreach ($sectionLabels as $key => $label): ?>
                    <a class="kp-genre-chip<?= ($section === $key && $genreId <= 0 && $search === '') ? ' active' : '' ?>"
                       href="<?= kp_e(kp_movie_url($key)) ?>"><?= kp_e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Genre Filters -->
    <div class="show-container">
        <div class="head-show">
            <i class="fas fa-tags" style="color:#ff2e63;"></i>
            <p>Genres</p>
            <div class="kp-sort-bar">
                <a class="kp-genre-chip<?= $genreId <= 0 && $search === '' ? '' : '' ?>"
                   href="<?= kp_e(kp_movie_url($section)) ?>" style="<?= $genreId <= 0 ? 'border-color:#ff2e63;color:#ff2e63;' : '' ?>">All</a>
                <?php foreach ($genres as $gId => $gName): ?>
                    <a class="kp-genre-chip<?= $genreId === $gId ? ' active' : '' ?>"
                       href="<?= kp_e(kp_movie_url($section, $gId)) ?>"><?= kp_e($gName) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="show-container">
        <div class="head-show">
            <i class="fas fa-fire" style="color:#ff2e63;"></i>
            <p><?= kp_e($sectionTitle) ?></p>
            <span class="kp-count"><?= count($items) ?></span>
        </div>

        <?php if (empty($items)): ?>
            <div class="kp-empty-state">
                <i class="fas fa-film"></i>
                <h4>No movies found</h4>
                <p>Try a different section or check back later.</p>
            </div>
        <?php else: ?>
            <div class="show-item-con">
                <?php foreach ($items as $i => $item): ?>
                    <?= render_anime_card($item, ['show_ep_badge' => false]) ?>
                <?php endforeach; ?>
            </div>

            <div class="kp-pagination">
                <?php if ($page > 1): ?>
                    <a class="kp-page-btn" href="<?= kp_e(kp_movie_url($section, $genreId, $page - 1, $search)) ?>">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>
                <span class="kp-page-info">Page <?= (int)$page ?></span>
                <a class="kp-page-btn" href="<?= kp_e(kp_movie_url($section, $genreId, $page + 1, $search)) ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
