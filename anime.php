<?php
/**
 * Anime browse page.
 *
 * The same shape as movies.php / tv.php: notice, search, filters, category
 * rails, then one paginated grid of everything. The catalogue layer already
 * fans out to AniList → ReAnime → Jikan, so this page only decides what to
 * ask for — it never talks to a provider itself.
 *
 * Rails live in includes/category_rails.php ('anime' kind), which means a new
 * category is a one-line addition and Movies / Series / Anime stay in step.
 */
session_start();

if (!isset($_SESSION['userID'])) {
    header("Location: authentication.php");
    exit();
}

include 'includes/db.php';
include_once 'includes/functions.php';
include_once 'includes/catalog.php';
include_once 'includes/category_rails.php';
include 'includes/header.php';

$genre  = isset($_GET['genre']) ? trim((string)$_GET['genre']) : (isset($_GET['g']) ? trim((string)$_GET['g']) : '');
$page   = isset($_GET['page']) ? max(1, min(500, intval($_GET['page']))) : 1;
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$sort   = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'popular';

$genres      = catalog_genres();
$sortOptions = catalog_sort_options();
if (!array_key_exists($sort, $sortOptions)) $sort = 'popular';

$genre = catalog_normalize_genre($genre) ?? '';

$sectionTitle = 'All Anime';
$items = [];

if ($search !== '') {
    $items = catalog_search($search, 24);
    $sectionTitle = 'Search: ' . $search;
} elseif ($genre !== '') {
    $result = catalog_by_genre($genre, $page, 24, $sort);
    $items  = $result['items'] ?? [];
    $sectionTitle = $genre . ' Anime';
    if ($sort !== 'popular') $sectionTitle .= ' · ' . $sortOptions[$sort];
} else {
    // Default browse: the provider's popular list, paged.
    $items = function_exists('anilist_popular') ? anilist_popular($page, 24) : catalog_trending(24);
    if ($sort !== 'popular') {
        $sectionTitle .= ' · ' . $sortOptions[$sort];
    }
}

/** Keep the filters when paging. */
function kp_anime_url($page = 1, $genre = '', $sort = 'popular', $search = '') {
    $params = ['page' => max(1, (int)$page)];
    if ($genre !== '') $params['genre'] = $genre;
    if ($sort !== 'popular') $params['sort'] = $sort;
    if ($search !== '') $params['q'] = $search;
    return './anime.php?' . http_build_query($params);
}
?>

<?php /* The filter styles live in assets/css/home.css (.kp-filter-bar) so all
         three browse pages stay identical. */ ?>

<div class="home-index-con">
    <div class="notice-box-container">
        <div class="not-con">
            <p>
                <i class="fas fa-dragon" style="color:#ff2e63;"></i> <strong>Anime</strong>
                — <span style="opacity:0.85; font-weight:400;">
                    <?= kp_e($sectionTitle) ?> · page <?= (int)$page ?>
                </span>
            </p>
        </div>
    </div>

    <!-- Search + filters in one card (two boxes above the rails was noise) -->
    <div class="show-container">
        <form method="get" action="./anime.php" class="kp-search-input-wrap">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Search anime..." value="<?= kp_e($search) ?>">
        </form>
        <div class="kp-filter-bar">
            <select id="f-genre" onchange="applyFilters()">
                <option value="">All Genres</option>
                <?php foreach ($genres as $gName): ?>
                    <option value="<?= kp_e($gName) ?>"<?= $genre === $gName ? ' selected' : '' ?>><?= kp_e($gName) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="f-sort" onchange="applyFilters()">
                <?php foreach ($sortOptions as $sVal => $sName): ?>
                    <option value="<?= kp_e($sVal) ?>"<?= $sort === $sVal ? ' selected' : '' ?>><?= kp_e($sName) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="kp-filter-reset" onclick="location.href='./anime.php'">Reset</button>
        </div>
    </div>

    <!--
        Category rails — Trending / Popular / Airing / Top Rated plus the big
        genres. One rail is resolved with the page and the rest fill in as they
        scroll into view (includes/category_rails.php); AniList answers are
        slower than TMDB's, so this page keeps one eager rail where Movies and
        Series can afford two.
    -->
    <?= kp_render_category_rails('anime', 1) ?>

    <div class="show-container">
        <div class="head-show">
            <i class="fas fa-fire" style="color:#ff2e63;"></i>
            <p><?= kp_e($sectionTitle) ?></p>
            <span class="kp-count"><?= count($items) ?></span>
        </div>

        <?php if (empty($items)): ?>
            <div class="kp-empty-state">
                <i class="fas fa-dragon"></i>
                <h4>No anime found</h4>
                <p>Try another genre, or clear the search and browse the rails above.</p>
            </div>
        <?php else: ?>
            <div class="show-item-con">
                <?php foreach ($items as $item): ?>
                    <?= render_anime_card($item, ['show_ep_badge' => true]) ?>
                <?php endforeach; ?>
            </div>

            <div class="kp-pagination">
                <?php if ($page > 1): ?>
                    <a class="kp-page-btn" href="<?= kp_e(kp_anime_url($page - 1, $genre, $sort, $search)) ?>">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>
                <span class="kp-page-info">Page <?= (int)$page ?></span>
                <?php if ($search === ''): ?>
                    <a class="kp-page-btn" href="<?= kp_e(kp_anime_url($page + 1, $genre, $sort, $search)) ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function applyFilters(){
    var g = document.getElementById('f-genre').value;
    var s = document.getElementById('f-sort').value;
    var p = './anime.php?';
    if (g) p += 'genre=' + encodeURIComponent(g) + '&';
    if (s && s !== 'popular') p += 'sort=' + s + '&';
    location.href = p.replace(/[&?]+$/, '');
}
</script>

<?php include 'includes/footer.php'; ?>
