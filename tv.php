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

$section = isset($_GET['section']) ? trim((string)$_GET['section']) : 'trending';
$page    = isset($_GET['page']) ? max(1, min(500, intval($_GET['page']))) : 1;
$perPage = 20;

$validSections = ['trending', 'popular', 'airing_today', 'on_the_air', 'top_rated'];
if (!in_array($section, $validSections)) $section = 'trending';

$sectionLabels = [
    'trending'     => 'Trending Shows',
    'popular'      => 'Popular Shows',
    'airing_today' => 'Airing Today',
    'on_the_air'   => 'Currently Airing',
    'top_rated'    => 'Top Rated Shows',
];

switch ($section) {
    case 'popular':
        $items = tmdb_tv_popular($page);
        break;
    case 'airing_today':
        $items = tmdb_tv_airing_today($page);
        break;
    case 'on_the_air':
        $items = tmdb_tv_on_the_air($page);
        break;
    case 'top_rated':
        $items = tmdb_tv_top_rated($page);
        break;
    default:
        $items = tmdb_tv_trending($page);
        break;
}

function kp_tv_url($section, $page = 1) {
    return './tv.php?' . http_build_query(['section' => $section, 'page' => max(1, (int)$page)]);
}
?>

<div class="home-index-con">
    <div class="notice-box-container">
        <div class="not-con">
            <p>
                📺 <strong>TV Series</strong>
                — <span style="opacity:0.85; font-weight:400;">
                    <?= kp_e($sectionLabels[$section]) ?> · page <?= (int)$page ?>
                </span>
            </p>
        </div>
    </div>

    <div class="show-container">
        <div class="head-show">
            <i class="fas fa-tv" style="color:#ff2e63;"></i>
            <p>Browse</p>
            <div class="kp-sort-bar">
                <?php foreach ($sectionLabels as $key => $label): ?>
                    <a class="kp-genre-chip<?= ($section === $key) ? ' active' : '' ?>"
                       href="<?= kp_e(kp_tv_url($key, 1)) ?>"><?= kp_e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="show-container">
        <div class="head-show">
            <i class="fas fa-fire" style="color:#ff2e63;"></i>
            <p><?= kp_e($sectionLabels[$section]) ?></p>
            <span class="kp-count"><?= count($items) ?></span>
        </div>

        <?php if (empty($items)): ?>
            <div class="kp-empty-state">
                <i class="fas fa-tv"></i>
                <h4>No TV shows found</h4>
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
                    <a class="kp-page-btn" href="<?= kp_e(kp_tv_url($section, $page - 1)) ?>">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>
                <span class="kp-page-info">Page <?= (int)$page ?></span>
                <a class="kp-page-btn" href="<?= kp_e(kp_tv_url($section, $page + 1)) ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
