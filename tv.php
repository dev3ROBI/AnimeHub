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

$genreId  = isset($_GET['genre']) ? max(0, intval($_GET['genre'])) : 0;
$page     = isset($_GET['page']) ? max(1, min(500, intval($_GET['page']))) : 1;
$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$country  = isset($_GET['country']) ? trim((string)$_GET['country']) : '';
$year     = isset($_GET['year']) ? max(0, intval($_GET['year'])) : 0;
$lang     = isset($_GET['lang']) ? trim((string)$_GET['lang']) : '';
$sortBy   = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'popularity.desc';

$genres = tmdb_tv_genre_list();

$sortOptions = [
    'popularity.desc'       => 'Popularity',
    'vote_average.desc'     => 'Rating',
    'first_air_date.desc'   => 'Release Date',
    'original_name.asc'     => 'A → Z',
];

$countries = [
    ''=>'All Countries','US'=>'United States','GB'=>'United Kingdom','JP'=>'Japan','KR'=>'South Korea',
    'FR'=>'France','DE'=>'Germany','IT'=>'Italy','ES'=>'Spain','IN'=>'India','BR'=>'Brazil',
    'CA'=>'Canada','AU'=>'Australia','MX'=>'Mexico','RU'=>'Russia','CN'=>'China','TR'=>'Turkey',
    'SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark','NL'=>'Netherlands','BE'=>'Belgium','CH'=>'Switzerland',
    'AT'=>'Austria','PL'=>'Poland','CZ'=>'Czech Republic','PT'=>'Portugal','IE'=>'Ireland',
    'AR'=>'Argentina','CL'=>'Chile','CO'=>'Colombia','NG'=>'Nigeria','ZA'=>'South Africa',
    'EG'=>'Egypt','TH'=>'Thailand','PH'=>'Philippines','ID'=>'Indonesia','MY'=>'Malaysia',
];

$langList = [
    ''=>'All Languages','en'=>'English','ja'=>'Japanese','ko'=>'Korean','fr'=>'French',
    'de'=>'German','es'=>'Spanish','it'=>'Italian','pt'=>'Portuguese','zh'=>'Chinese',
    'hi'=>'Hindi','ar'=>'Arabic','tr'=>'Turkish','ru'=>'Russian','sv'=>'Swedish',
    'da'=>'Danish','no'=>'Norwegian','fi'=>'Finnish','nl'=>'Dutch','pl'=>'Polish',
    'th'=>'Thai','id'=>'Indonesian','ms'=>'Malay','tl'=>'Filipino','vi'=>'Vietnamese',
];

$years = [''=>'All Years'];
for ($y = date('Y'); $y >= 1920; $y--) $years[$y] = $y;

$sectionTitle = 'All TV Shows';
$discoverOpts = [];
if ($genreId > 0) {
    $discoverOpts['with_genres'] = $genreId;
    $genreName = $genres[$genreId] ?? 'Genre #' . $genreId;
    $sectionTitle = $genreName . ' Shows';
}
if ($country !== '') {
    $discoverOpts['with_origin_country'] = $country;
    $sectionTitle .= ' · ' . ($countries[$country] ?? $country);
}
if ($year > 0) {
    $discoverOpts['first_air_date_year'] = $year;
    $sectionTitle .= ' · ' . $year;
}
if ($lang !== '') {
    $discoverOpts['with_original_language'] = $lang;
    $sectionTitle .= ' · ' . ($langList[$lang] ?? $lang);
}
if ($sortBy !== 'popularity.desc') {
    $discoverOpts['sort_by'] = $sortBy;
}
$discoverOpts['language'] = 'en-US';

if ($search !== '') {
    $items = tmdb_tv_search($search, $page);
    $sectionTitle = 'Search: ' . $search;
} elseif (!empty($discoverOpts) && $discoverOpts !== ['language' => 'en-US']) {
    $items = tmdb_tv_discover($page, $discoverOpts);
} else {
    $items = tmdb_tv_trending($page);
}

function kp_tv_url($page = 1, $genreId = 0, $country = '', $year = 0, $lang = '', $sortBy = 'popularity.desc', $search = '') {
    $params = ['page' => max(1, (int)$page)];
    if ($genreId > 0) $params['genre'] = $genreId;
    if ($country !== '') $params['country'] = $country;
    if ($year > 0) $params['year'] = $year;
    if ($lang !== '') $params['lang'] = $lang;
    if ($sortBy !== 'popularity.desc') $params['sort'] = $sortBy;
    if ($search !== '') $params['q'] = $search;
    return './tv.php?' . http_build_query($params);
}
?>

<style>
.kp-filter-bar{display:flex;flex-wrap:wrap;gap:8px;padding:12px 16px;background:rgba(255,255,255,.04);border-radius:12px;margin:8px 0}
.kp-filter-bar select{padding:8px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:#181a20;color:#eee;font-size:13px;cursor:pointer;outline:none;min-width:120px}
.kp-filter-bar select:focus{border-color:#ff2e63}
.kp-filter-bar .kp-filter-apply{padding:8px 18px;border-radius:8px;border:none;background:linear-gradient(135deg,#ff2e63,#d90429);color:#fff;font-weight:600;font-size:13px;cursor:pointer;transition:.2s}
.kp-filter-bar .kp-filter-apply:hover{opacity:.85}
.kp-filter-bar .kp-filter-reset{padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#aaa;font-size:13px;cursor:pointer;transition:.2s}
.kp-filter-bar .kp-filter-reset:hover{border-color:#ff2e63;color:#ff2e63}
</style>

<div class="home-index-con">
    <div class="notice-box-container">
        <div class="not-con">
            <p>
                <i class="fas fa-tv" style="color:#ff2e63;"></i> <strong>TV Series</strong>
                — <span style="opacity:0.85; font-weight:400;">
                    <?= kp_e($sectionTitle) ?> · page <?= (int)$page ?>
                </span>
            </p>
        </div>
    </div>

    <!-- Search -->
    <div class="show-container">
        <form method="get" action="./tv.php" class="kp-search-input-wrap">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Search TV shows..." value="<?= kp_e($search) ?>">
        </form>
    </div>

    <!-- Filter Bar -->
    <div class="show-container">
        <div class="kp-filter-bar">
            <select id="f-genre" onchange="applyFilters()">
                <option value="">All Genres</option>
                <?php foreach ($genres as $gId => $gName): ?>
                    <option value="<?= $gId ?>"<?= $genreId === $gId ? ' selected' : '' ?>><?= kp_e($gName) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="f-country" onchange="applyFilters()">
                <?php foreach ($countries as $cCode => $cName): ?>
                    <option value="<?= kp_e($cCode) ?>"<?= $country === $cCode ? ' selected' : '' ?>><?= kp_e($cName) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="f-year" onchange="applyFilters()">
                <?php foreach ($years as $yVal => $yLabel): ?>
                    <option value="<?= kp_e((string)$yVal) ?>"<?= $year == $yVal ? ' selected' : '' ?>><?= kp_e((string)$yLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="f-lang" onchange="applyFilters()">
                <?php foreach ($langList as $lCode => $lName): ?>
                    <option value="<?= kp_e($lCode) ?>"<?= $lang === $lCode ? ' selected' : '' ?>><?= kp_e($lName) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="f-sort" onchange="applyFilters()">
                <?php foreach ($sortOptions as $sVal => $sName): ?>
                    <option value="<?= kp_e($sVal) ?>"<?= $sortBy === $sVal ? ' selected' : '' ?>><?= kp_e($sName) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="kp-filter-reset" onclick="location.href='./tv.php'">Reset</button>
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
                <i class="fas fa-tv"></i>
                <h4>No TV shows found</h4>
                <p>Try adjusting your filters or check back later.</p>
            </div>
        <?php else: ?>
            <div class="show-item-con">
                <?php foreach ($items as $i => $item): ?>
                    <?= render_anime_card($item, ['show_ep_badge' => false]) ?>
                <?php endforeach; ?>
            </div>

            <div class="kp-pagination">
                <?php if ($page > 1): ?>
                    <a class="kp-page-btn" href="<?= kp_e(kp_tv_url($page - 1, $genreId, $country, $year, $lang, $sortBy, $search)) ?>">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>
                <span class="kp-page-info">Page <?= (int)$page ?></span>
                <a class="kp-page-btn" href="<?= kp_e(kp_tv_url($page + 1, $genreId, $country, $year, $lang, $sortBy, $search)) ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function applyFilters(){
    const g=document.getElementById('f-genre').value;
    const c=document.getElementById('f-country').value;
    const y=document.getElementById('f-year').value;
    const l=document.getElementById('f-lang').value;
    const s=document.getElementById('f-sort').value;
    let p='./tv.php?';
    if(g)p+='genre='+g+'&';
    if(c)p+='country='+c+'&';
    if(y)p+='year='+y+'&';
    if(l)p+='lang='+l+'&';
    if(s&&s!=='popularity.desc')p+='sort='+s+'&';
    location.href=p.replace(/[&?]+$/,'');
}
</script>

<?php include 'includes/footer.php'; ?>
