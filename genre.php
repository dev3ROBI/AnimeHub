<?php
session_start();

if (!isset($_SESSION['userID'])) {
    header("Location: authentication.php");
    exit();
}

include 'includes/db.php';
include_once 'includes/functions.php';
include 'includes/header.php';

$genres = catalog_genres();
$sorts  = catalog_sort_options();

$genre = isset($_GET['g']) ? trim((string)$_GET['g']) : '';
$sort  = isset($_GET['sort']) && array_key_exists($_GET['sort'], $sorts) ? $_GET['sort'] : 'popular';
$page  = isset($_GET['page']) ? max(1, min(500, intval($_GET['page']))) : 1;
$perPage = 24;

$normalized = catalog_normalize_genre($genre);
$result = ['items' => [], 'page' => $page, 'has_next' => false, 'genre' => $normalized];
if ($normalized !== null) {
    $result = catalog_by_genre($normalized, $page, $perPage, $sort);
}

$items = $result['items'] ?? [];
$hasNext = !empty($result['has_next']);

function kp_genre_url($genre, $page = 1, $sort = 'popular') {
    return './genre.php?' . http_build_query(['g' => $genre, 'page' => max(1, (int)$page), 'sort' => $sort]);
}
?>

<div class="home-index-con">
    <div class="notice-box-container">
        <div class="not-con">
            <p>
                🏷️ <strong>Browse by Genre</strong>
                <?php if ($normalized !== null): ?>
                    — <span style="opacity:0.85; font-weight:400;">
                        <?= kp_e($normalized) ?> · <?= kp_e($sorts[$sort]) ?> · page <?= (int)$page ?>
                    </span>
                <?php else: ?>
                    — <span style="opacity:0.85; font-weight:400;">একটা genre বেছে নিন</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="show-container">
        <div class="head-show">
            <i class="fa-solid fa-tags" style="color:#ff2e63;"></i>
            <p>Genres</p>
            <span class="kp-count"><?= count($genres) ?></span>
        </div>
        <div class="kp-genre-nav">
            <?php foreach ($genres as $g): ?>
                <a class="kp-genre-chip<?= ($normalized === $g) ? ' active' : '' ?>"
                   href="<?= kp_e(kp_genre_url($g, 1, $sort)) ?>"><?= kp_e($g) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($normalized === null): ?>
        <?= render_notice('উপরের যেকোনো genre-এ ক্লিক করুন।', 'fa-solid fa-hand-pointer') ?>
    <?php else: ?>

        <div class="show-container">
            <div class="head-show">
                <i class="fa-solid fa-sliders" style="color:#ff2e63;"></i>
                <p>Sort</p>
                <div class="kp-sort-bar">
                    <?php foreach ($sorts as $key => $label): ?>
                        <a class="kp-genre-chip<?= ($sort === $key) ? ' active' : '' ?>"
                           href="<?= kp_e(kp_genre_url($normalized, 1, $key)) ?>"><?= kp_e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (empty($items)): ?>
                <p class="kp-genre-empty">
                    <i class="fa-solid fa-ghost"></i> এই genre-এ কোনো অ্যানিমে পাওয়া যায়নি।
                </p>
            <?php else: ?>
                <div class="show-item-con" id="kpGenreList">
                    <?php foreach ($items as $item): ?>
                        <?= render_anime_card($item, ['show_ep_badge' => true]) ?>
                    <?php endforeach; ?>
                </div>

                <!-- Auto-loads the next page once it scrolls into view
                     (assets/js/genre-scroll.js) instead of Prev/Next buttons. -->
                <div class="kp-load-more" id="kpLoadMore"
                     data-endpoint="./includes/genre_items.php"
                     data-genre="<?= kp_e((string)$normalized) ?>"
                     data-sort="<?= kp_e($sort) ?>"
                     data-page="<?= (int)$page ?>"
                     data-per-page="<?= (int)$perPage ?>"
                     data-has-next="<?= $hasNext ? '1' : '0' ?>">
                    <span class="kp-load-spinner"><i class="fa-solid fa-spinner fa-spin"></i> Loading more…</span>
                    <span class="kp-load-end"><i class="fa-solid fa-flag-checkered"></i> সব অ্যানিমে দেখা হয়ে গেছে</span>
                    <button type="button" class="kp-load-retry" id="kpLoadRetry">
                        <i class="fa-solid fa-rotate-right"></i> Try again
                    </button>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
