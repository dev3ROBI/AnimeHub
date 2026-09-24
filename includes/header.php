<?php
/*
 * Front-end performance layer — gzip output buffer, cache-busted/minified
 * asset URLs, responsive-image srcset helpers. Included before the first byte
 * of markup so the compression buffer is already in place.
 */
include_once __DIR__ . '/performance.php';

// Self-hosted font sheet (local file, cacheable for a year) plus the
// DNS/TLS warnings for the image CDNs the catalogue streams from.
[$kpFontsUrl, $kpPreconnectHosts] = kp_font_and_cdn_hints();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes" />
    <meta name="theme-color" content="#292929" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <title>KitsuPlay · Anime Streaming</title>
    <?php foreach ($kpPreconnectHosts as $kpHost): ?>
    <link rel="preconnect" href="<?= htmlspecialchars($kpHost, ENT_QUOTES, 'UTF-8') ?>" />
    <?php endforeach; ?>
    <?php
    /*
     * Fonts are self-hosted (assets/css/fonts.css + assets/fonts/*.woff2):
     * same-origin, 1-year cache, no fonts.googleapis.com round trip before
     * first paint. Preload the two faces the first screen draws with — body
     * copy (Poppins) and the logo (Tangerine, bold) — so swap never flashes
     * the fallback for above-the-fold text. Font preloads require crossorigin
     * even same-origin because fonts are always fetched in CORS mode.
     */
    ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin href="<?= htmlspecialchars(kp_base() . 'assets/fonts/poppins-400.woff2', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="preload" as="font" type="font/woff2" crossorigin href="<?= htmlspecialchars(kp_base() . 'assets/fonts/tangerine-700.woff2', ENT_QUOTES, 'UTF-8') ?>" />
    <?php /* PWA: installable manifest + home-screen icons (iOS uses the apple one). */ ?>
    <link rel="manifest" href="<?= htmlspecialchars(kp_base() . 'manifest.json', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(kp_base() . 'assets/icons/favicon-48.png', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(kp_base() . 'assets/icons/apple-touch-icon.png', ENT_QUOTES, 'UTF-8') ?>" />
    <?php /* fonts.css (local) replaces the old Google Fonts <link>. */ ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($kpFontsUrl, ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />

    <?php
    /*
     * Above-the-fold CSS is inlined (one round trip saved, first paint no
     * longer waits on ~100 KB of sheets). The full sheets follow asynchronously
     * with a <noscript> fallback, and the async copy always wins on conflicts
     * because it is parsed later.
     */
    $kpCriticalCss = kp_inline_css('critical.css');
    if ($kpCriticalCss !== ''):
    ?>
    <style><?= $kpCriticalCss ?></style>
    <?php endif; ?>

    <?php
    /*
     * Async-sheet guard.
     *
     * The media="print" → media="all" swap is what keeps these sheets off the
     * critical path, but the swap only happens in onload: if the request fails
     * (flaky mobile link, SW hiccup, proxy error) the sheet stays print-only
     * and the page renders UNSTYLED with no way back. So every lazy sheet is
     * tagged data-kp-lazy, onload marks it ready, and anything still unmarked
     * after load (or 3s) is forced on and re-requested once with a fresh URL.
     * Defined before the tags so a fast onerror can retry immediately.
     */
    ?>
    <script>
        (function () {
            function retry(link) {
                if (!link || link.getAttribute('data-kp-ready')) return;
                link.setAttribute('data-kp-ready', '1');   // one retry per sheet
                link.media = 'all';                        // apply what is cached

                var again = link.cloneNode(false);
                again.removeAttribute('id');
                again.media = 'all';
                again.href = link.href + (link.href.indexOf('?') > -1 ? '&' : '?') + 'kp-css-retry=1';
                document.head.appendChild(again);
            }

            window.kpCssRetry = retry;

            function sweep() {
                var lazy = document.querySelectorAll('link[data-kp-lazy]:not([data-kp-ready])');
                for (var i = 0; i < lazy.length; i++) retry(lazy[i]);
            }

            setTimeout(sweep, 3000);
            window.addEventListener('load', function () { setTimeout(sweep, 150); });
        })();
    </script>
    <?php
    /* Stylesheet tag for a sheet that must not block the first paint. */
    $kpLazyCss = static function ($file) {
        $url   = kp_asset('css', $file);
        $urlTxt = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $id    = 'kp-css-' . preg_replace('/[^a-z0-9]+/i', '-', pathinfo($file, PATHINFO_FILENAME));
        echo '<link rel="stylesheet" id="' . $id . '" data-kp-lazy="1" href="' . $urlTxt . '" media="print"'
            . ' onload="this.media=\'all\';this.setAttribute(\'data-kp-ready\',\'1\')"'
            . ' onerror="window.kpCssRetry&amp;&amp;window.kpCssRetry(this)">' . "\n";
        echo '    <noscript><link rel="stylesheet" href="' . $urlTxt . '"></noscript>' . "\n";
    };
    ?>
    <?php $kpLazyCss('nav_style.css'); ?>
    <?php $kpLazyCss('home.css'); ?>

    <?php
    /* Page-specific CSS/JS — skip heavy sheets on pages that don't need them,
       especially on low-end mobile where every KB matters. */
    $kp_is_auth = strpos($_SERVER['SCRIPT_NAME'] ?? '', 'authentication') !== false;
    $kp_is_watch = strpos($_SERVER['SCRIPT_NAME'] ?? '', 'watch.php') !== false ||
                   strpos($_SERVER['SCRIPT_NAME'] ?? '', 'tv.php') !== false;
    ?>
    <?php if ($kp_is_auth): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(kp_asset('css', 'authentication.css'), ENT_QUOTES, 'UTF-8') ?>" />
    <?php endif; ?>
    <?php if ($kp_is_auth || strpos($_SERVER['SCRIPT_NAME'] ?? '', 'profile') !== false): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(kp_asset('css', 'profile.css'), ENT_QUOTES, 'UTF-8') ?>" />
    <?php endif; ?>
    <?php if ($kp_is_watch): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(kp_asset('css', 'watch_page_style.css'), ENT_QUOTES, 'UTF-8') ?>" />
    <!-- ArtPlayer Core CSS and JS — only on watch pages -->
    <link rel="stylesheet" href="https://unpkg.com/artplayer/dist/artplayer.css">
    <script src="https://unpkg.com/artplayer/dist/artplayer.js" defer></script>
    <!-- Ambilight Plugin -->
    <script src="https://unpkg.com/artplayer-plugin-ambilight/dist/artplayer-plugin-ambilight.js" defer></script>
    <!-- jQuery — only on watch pages where ArtPlayer needs it -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" defer></script>
    <?php endif; ?>

    <!-- Shared UI behaviour -->
    <?php
    // Same cache-busting for the local scripts (kp_asset() also swaps in the
    // .min.js build whenever tools/minify.php has produced a fresh one).
    ?>
    <script src="<?= htmlspecialchars(kp_asset('js', 'hero-slider.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(kp_asset('js', 'card-preview.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(kp_asset('js', 'home-sections.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(kp_asset('js', 'genre-scroll.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <!-- Release countdown chips (.kp-cd[data-release]) — ticks while pending, fires `kp:released` at zero -->
    <script src="<?= htmlspecialchars(kp_asset('js', 'countdown.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
</head>



<?php
$kp_body_class = '';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['userID'])) {
    include_once __DIR__ . '/db.php';
    include_once __DIR__ . '/functions.php';
    $kp_settings = kp_user_settings($pdo ?? null, $_SESSION['userID']);
    if (!empty($kp_settings['sticky_navbar'])) {
        $kp_body_class = 'sticky-nav-enabled';
    }
    // "Show Ratings" is a display preference, so it rides on <body> and every
    // card on the page (and the next ones) follows it.
    if (isset($kp_settings['show_ratings']) && !$kp_settings['show_ratings']) {
        $kp_body_class .= ' ratings-hidden';
    }
} else {
    $kp_settings = ['sticky_navbar' => 0, 'autoplay' => 1, 'show_ratings' => 1];
}
?>

<body class="<?= trim($kp_body_class) ?>" data-user="<?= isset($_SESSION['userID']) ? '1' : '0' ?>">
    <!-- Boot loader: styles are inlined in critical.css, so this paints before
         the async sheets swap in. Hidden once DOM + lazy sheets are ready (or a
         hard timeout fires); noscript kills it when JS never runs. -->
    <div id="kp-boot" aria-hidden="true">
        <div class="kp-boot-spinner"></div>
        <p>Loading KitsuPlay…</p>
    </div>
    <noscript><style>#kp-boot{display:none}</style></noscript>
    <script>
        (function () {
            var boot = document.getElementById('kp-boot');
            if (!boot) return;
            var t0 = Date.now(), gone = false;
            function hide() {
                if (gone) return;
                gone = true;
                boot.classList.add('kp-boot-out');
                setTimeout(function () {
                    if (boot.parentNode) boot.parentNode.removeChild(boot);
                }, 450);
            }
            function ready() {
                if (document.readyState === 'loading') return false;
                return !document.querySelector('link[data-kp-lazy]:not([data-kp-ready])');
            }
            function check() {
                if (gone || !ready()) return;
                var wait = Math.max(0, 300 - (Date.now() - t0)); // min on-screen time
                setTimeout(hide, wait);
            }
            document.addEventListener('DOMContentLoaded', check);
            var iv = setInterval(function () {
                check();
                if (gone) clearInterval(iv);
            }, 100);
            window.addEventListener('load', hide);
            window.addEventListener('pageshow', function (e) { if (e.persisted) hide(); });
            setTimeout(function () { clearInterval(iv); hide(); }, 4500); // hard cap
        })();
    </script>
    <!-- =================== Overlay =================== -->
    <div class="overlay" id="overlay"></div>
    <!-- =================== /Overlay =================== -->

    <!-- =================== Logout Confirmation Modal =================== -->
    <div id="logout-popup-modal" class="logout-popup-modal">
        <div class="logout-popup-content">
            <div class="kp-modal-head">
                <div class="kp-modal-head-ic logout-popup-head-ic">
                    <i class="fas fa-right-from-bracket"></i>
                </div>
                <h3 id="logout-popupTitle">Confirm Logout</h3>
                <button type="button" class="kp-modal-close" onclick="closeLogoutPopup()" aria-label="Close">&times;</button>
            </div>
            <p id="logout-popupMessage">Are you sure you want to log out of your account?</p>
            <div class="logout-popup-actions">
                <button class="logout-popup-btn logout-cancel-btn" onclick="closeLogoutPopup()">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button class="logout-popup-btn logout-confirm-btn" onclick="logoutUser()">
                    <i class="fas fa-right-from-bracket"></i> Logout
                </button>
            </div>
        </div>
    </div>
    <!-- =================== /Logout Confirmation Modal =================== -->

    <!-- =================== Coming Soon Modal =================== -->
    <div id="coming-soon-modal" class="coming-soon-modal">
        <div class="coming-soon-content">
            <div class="kp-modal-head">
                <div class="kp-modal-head-ic coming-soon-head-ic">
                    <i class="fas fa-lock"></i>
                </div>
                <h3>Coming Soon</h3>
                <button type="button" class="kp-modal-close" onclick="closeComingSoon()" aria-label="Close">&times;</button>
            </div>
            <p id="coming-soon-text">This section is under development and will be available soon.</p>
            <button class="coming-soon-btn" onclick="closeComingSoon()">
                <i class="fas fa-check"></i> Got it
            </button>
        </div>
    </div>
    <!-- =================== /Coming Soon Modal =================== -->

    <!-- =================== Drawer Navigation =================== -->
    <div class="drawer" id="drawer">
        <ul>
            <li onclick="window.location.href='index.php'"><i class="fas fa-house" style="width: 25px;"></i> Home</li>
            <li onclick="window.location.href='genre.php'"><i class="fas fa-tags" style="width: 25px;"></i> Genres</li>
            <li onclick="window.location.href='schedule.php'"><i class="fas fa-calendar-week" style="width: 25px;"></i> Schedule</li>

            <li class="drawer-divider"></li>

            <li onclick="window.location.href='movies.php'"><i class="fas fa-clapperboard" style="width: 25px;"></i> Movies</li>
            <li onclick="window.location.href='tv.php'"><i class="fas fa-tv" style="width: 25px;"></i> Series</li>

            <li class="drawer-divider"></li>

            <?php if (isset($_SESSION['userID'])): ?>
            <li onclick="window.location.href='profile.php?tab=profile'"><i class="fas fa-user"
                    style="width: 25px;"></i> Profile</li>
            <li onclick="window.location.href='profile.php?tab=continue-watching'"><i class="fas fa-history"
                    style="width: 25px;"></i> Continue Watching</li>
            <li onclick="window.location.href='profile.php?tab=watch-list'"><i class="fas fa-heart"
                    style="width: 25px;"></i> Watch Lists</li>
            <li onclick="window.location.href='profile.php?tab=notification'"><i class="fas fa-bell"
                    style="width: 25px;"></i> Notifications</li>

            <li class="drawer-divider"></li>

            <li onclick="window.location.href='profile.php?tab=settings'"><i class="fas fa-cog"
                    style="width: 25px;"></i> Settings</li>
            <li onclick="openLogoutPopup()"><i class="fas fa-right-from-bracket" style="width: 25px;"></i> Logout</li>

            <?php else: ?>
            <li class="drawer-divider"></li>
            <li onclick="window.location.href='authentication.php'"><i class="fas fa-right-to-bracket"
                    style="width: 25px;"></i> Login</li>
            <li onclick="window.location.href='authentication.php'"><i class="fas fa-user-plus"
                    style="width: 25px;"></i> Register</li>
            <?php endif; ?>
        </ul>

    </div>
    <!-- =================== /Drawer Navigation =================== -->

    <!-- =================== Navbar =================== -->
    <div class="navbar">
        <!-- =================== Left Navigation =================== -->
        <div class="left-nav">
            <i class="fas fa-bars menu-btn" id="menuBtn"></i>
            <div class="logo">
                <a href="./index.php">
                    <span>KitsuPlay</span>
                </a>
            </div>
        </div>
        <!-- =================== /Left Navigation =================== -->

        <!-- =================== Right Navigation =================== -->
        <div class="right-nav">
            <!-- Search Icon -->
            <div class="icon-wrapper">
                <i class="fas fa-search" id="searchIcon"></i>
                <div id="search-popup" class="floating-popup kp-search-popup">
                    <div class="kp-search-head">
                        <div class="kp-search-input-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search anime, movies, TV…" autocomplete="off" />
                            <span class="clear-search" id="clearSearch">&times;</span>
                        </div>
                        <span class="kp-search-kbd">ESC</span>
                    </div>
                    <!-- Kind filter chips: All / Anime / Movies / TV -->
                    <div class="kp-search-filters" id="searchFilters" role="tablist" aria-label="Search filter">
                        <button type="button" class="kp-search-chip is-active" data-kind="all">All</button>
                        <button type="button" class="kp-search-chip" data-kind="anime"><i class="fas fa-dragon"></i> Anime</button>
                        <button type="button" class="kp-search-chip" data-kind="movie"><i class="fas fa-clapperboard"></i> Movies</button>
                        <button type="button" class="kp-search-chip" data-kind="tv"><i class="fas fa-tv"></i> TV</button>
                    </div>
                    <div class="search-suggestions" id="searchSuggestions">
                        <div class="kp-sug-section">
                            <p class="kp-sug-loading"><i class="fas fa-spinner fa-spin"></i> Loading suggestions…</p>
                        </div>
                    </div>
                    <div class="kp-search-foot">
                        <span class="kp-search-hint"><i class="fas fa-arrow-up"></i><i class="fas fa-arrow-down"></i> navigate</span>
                        <span class="kp-search-hint"><i class="fas fa-arrow-turn-up kp-rotate-90"></i> open</span>
                    </div>
                </div>
            </div>

            <!-- Notification Icon -->
            <div class="icon-wrapper">
                <i class="fas fa-bell" id="notificationIcon"></i>
                <span id="notif-badge" class="kp-notif-badge"></span>
                <div id="notification-popup" class="floating-popup kp-notif-popup">
                    <div class="kp-notif-head">
                        <div class="kp-notif-head-left">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                        </div>
                        <div class="kp-notif-head-right">
                            <span id="notif-count-label" class="kp-notif-count"></span>
                            <button id="notif-mark-all" class="kp-notif-mark-all" title="Mark all read" style="display:none;">
                                <i class="fas fa-check-double"></i>
                            </button>
                            <button id="notif-clear-all" class="kp-notif-clear-all" title="Clear all">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Type filter: All / Episodes / System -->
                    <div class="kp-notif-tabs" id="notifTabs">
                        <button type="button" class="kp-notif-tab is-active" data-type="">All</button>
                        <button type="button" class="kp-notif-tab" data-type="episode"><i class="fas fa-clapperboard"></i> Episodes</button>
                        <button type="button" class="kp-notif-tab" data-type="system"><i class="fas fa-medal"></i> System</button>
                    </div>
                    <div id="notif-list" class="kp-notif-list">
                        <div class="kp-notif-empty">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading...</span>
                        </div>
                        <div id="notif-sentinel" class="kp-notif-sentinel" aria-hidden="true" style="display:none;">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Icon -->
            <?php
            $nav_avatar_url = null;
            $nav_user_name = '';
            $nav_user_email = '';
            $nav_rank = null;
            if (isset($_SESSION['userID'])) {
                include_once __DIR__ . '/avatars.php';
                // progress.php pulls in watch_time.php, so the rank helpers and
                // progress_format_time() both come from the one include.
                include_once __DIR__ . '/progress.php';
                $nav_avatar_url = user_avatar_url($_SESSION['userID']);
                // Watch-time rank, shown as a badge next to the dropdown name.
                $nav_rank = watch_time_rank($_SESSION['userID']);
                // Fetch user name/email for dropdown
                try {
                    $navUserStmt = $pdo->prepare("SELECT User_Name, User_Email FROM users WHERE User_ID = ?");
                    $navUserStmt->execute([$_SESSION['userID']]);
                    $navUserRow = $navUserStmt->fetch(PDO::FETCH_ASSOC);
                    if ($navUserRow) {
                        $nav_user_name = $navUserRow['User_Name'] ?? '';
                        $nav_user_email = $navUserRow['User_Email'] ?? '';
                    }
                } catch (Exception $e) {}
            }
            ?>
            <div class="icon-wrapper">
                <?php if ($nav_avatar_url !== null): ?>
                <img class="kp-nav-avatar" id="userIcon" src="<?= htmlspecialchars($nav_avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                     alt="Your profile" />
                <?php else: ?>
                <i class="fas fa-user-circle" id="userIcon"></i>
                <?php endif; ?>
                <div id="user-popup" class="floating-popup kp-user-popup">
                    <?php if (isset($_SESSION['userID'])): ?>
                    <div class="kp-user-header">
                        <?php if ($nav_avatar_url !== null): ?>
                        <img class="kp-user-avatar" src="<?= htmlspecialchars($nav_avatar_url, ENT_QUOTES, 'UTF-8') ?>" alt="" />
                        <?php else: ?>
                        <div class="kp-user-avatar kp-user-avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="kp-user-info">
                            <div class="kp-user-name-row">
                                <span class="kp-user-name"><?= htmlspecialchars($nav_user_name) ?></span>
                                <?php if (!empty($nav_rank)): ?>
                                <span class="kp-user-rank"
                                      title="Rank: <?= htmlspecialchars($nav_rank['title']) ?> — <?= htmlspecialchars(progress_format_time($nav_rank['seconds'])) ?> watched"
                                      style="color:<?= htmlspecialchars($nav_rank['color']) ?>; border-color:<?= htmlspecialchars($nav_rank['color']) ?>3d; background:<?= htmlspecialchars($nav_rank['color']) ?>1a;">
                                    <i class="<?= htmlspecialchars($nav_rank['icon']) ?>"></i>
                                    <?= htmlspecialchars($nav_rank['title']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span class="kp-user-email"><?= htmlspecialchars($nav_user_email) ?></span>
                        </div>
                    </div>
                    <div class="kp-user-divider"></div>
                    <ul>
                        <li onclick="window.location.href='profile.php?tab=profile'">
                            <i class="fas fa-user"></i> <span>My Profile</span>
                        </li>
                        <li onclick="window.location.href='profile.php?tab=watch-list'">
                            <i class="fas fa-bookmark"></i> <span>Watch List</span>
                        </li>
                        <li onclick="window.location.href='profile.php?tab=continue-watching'">
                            <i class="fas fa-clock-rotate-left"></i> <span>Continue Watching</span>
                        </li>
                        <li onclick="window.location.href='profile.php?tab=settings'">
                            <i class="fas fa-cog"></i> <span>Settings</span>
                        </li>
                    </ul>
                    <div class="kp-user-divider"></div>
                    <ul>
                        <li class="kp-user-logout" onclick="openLogoutPopup()">
                            <i class="fas fa-right-from-bracket"></i> <span>Logout</span>
                        </li>
                    </ul>
                    <?php else: ?>
                    <div class="kp-user-empty">
                        <i class="fas fa-user-slash"></i>
                        <span>Sign in to access your profile</span>
                        <a href="authentication.php" class="kp-user-login-btn"><i class="fas fa-right-to-bracket"></i> Login</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <!-- =================== /Right Navigation =================== -->
    </div>
    <!-- =================== /Navbar =================== -->

    <?php if (!empty($kp_settings['sticky_navbar'])): ?>
    <script>
    (function(){
        var nav = document.querySelector('.navbar');
        if (!nav) return;
        window.addEventListener('scroll', function(){
            nav.classList.toggle('scrolled', window.scrollY > 10);
        }, {passive:true});
    })();
    </script>
    <?php endif; ?>

    <!-- =================== Script =================== -->
    <script>
        // ===================== DOM ELEMENTS ===================== //
            const menuBtn = document.getElementById("menuBtn");
            const drawer = document.getElementById("drawer");
            const overlay = document.getElementById("overlay");

            const searchIcon = document.getElementById("searchIcon");
            const bellIcon = document.getElementById("notificationIcon");
            const userIcon = document.getElementById("userIcon");

            const searchPopup = document.getElementById("search-popup");
            const notificationPopup = document.getElementById("notification-popup");
            const userPopup = document.getElementById("user-popup");

            const searchInput = document.getElementById("searchInput");
            const clearSearchBtn = document.querySelector(".clear-search");
            const suggestionBox = document.querySelector(".search-suggestions");

            const logoutPopupModal = document.getElementById("logout-popup-modal");

            let debounceTimeout;

            // ===================== OVERLAY CONTROL ===================== //
            function showOverlay() {
                overlay.classList.add("active");
                document.body.classList.add("no-scroll");
            }

            function hideOverlay() {
                overlay.classList.remove("active");
                document.body.classList.remove("no-scroll");
            }

            // ===================== HIDE ALL POPOVER ===================== //
            function hideAllPopups() {
                [searchPopup, notificationPopup, userPopup].forEach(popup => {
                    popup.classList.remove("active");
                });
                logoutPopupModal.classList.remove("show");
                var csModal = document.getElementById('coming-soon-modal');
                if (csModal) csModal.classList.remove("show");
            }

            // ===================== NAVIGATION DRAWER ===================== //
            // The drawer hangs under the navbar, so its offset is measured from
            // the live navbar box (the bar changes height between layouts).
            function positionDrawer() {
                const nav = document.querySelector(".navbar");
                const top = nav ? Math.max(0, Math.round(nav.getBoundingClientRect().bottom)) : 0;
                document.documentElement.style.setProperty("--kp-drawer-top", top + "px");
            }

            menuBtn.addEventListener("click", () => {
                const isDrawerOpen = drawer.classList.contains("active");

                // close any open popups
                hideAllPopups();

                if (isDrawerOpen) {
                    drawer.classList.remove("active");
                    hideOverlay();
                } else {
                    positionDrawer();
                    drawer.classList.add("active");
                    showOverlay();
                }
            });

            overlay.addEventListener("click", () => {
                drawer.classList.remove("active");
                hideAllPopups(); // Also hide floating popups
                hideOverlay();
            });

            // ===================== POPUP TOGGLE ===================== //
            function togglePopup(popup, event) {
                const isActive = popup.classList.contains("active");

                // Close all popups and drawer first
                hideAllPopups();
                if (drawer.classList.contains("active")) {
                    drawer.classList.remove("active");
                }

                if (isActive) {
                    popup.classList.remove("active");
                    hideOverlay();
                } else {
                    const rect = event.target.getBoundingClientRect();
                    if (window.innerWidth <= 768) {
                        // Phones centre the sheet in the viewport (nav_style.css)
                        // so only the vertical anchor is needed — with priority,
                        // because that stylesheet carries a no-JS fallback top.
                        popup.style.setProperty("top", `${rect.bottom + 12}px`, "important");
                        popup.style.removeProperty("right");
                        popup.style.removeProperty("left");
                    } else {
                        popup.style.setProperty("top", `${rect.bottom + 10}px`, "");
                        popup.style.setProperty("right", `${window.innerWidth - rect.right}px`, "");
                        popup.style.removeProperty("left");
                    }
                    popup.classList.add("active");
                    showOverlay();
                }
            }

            // Rotating the phone (or crossing the breakpoint) must not leave a
            // panel pinned with the other layout's inline offsets.
            window.addEventListener("resize", () => {
                if (drawer.classList.contains("active")) positionDrawer();
                if (window.innerWidth > 768) return;
                [searchPopup, notificationPopup, userPopup].forEach((popup) => {
                    popup.style.removeProperty("top");
                    popup.style.removeProperty("right");
                    popup.style.removeProperty("left");
                });
            });

            // ===================== ICON POPUP HANDLERS ===================== //
            searchIcon.addEventListener("click", (e) => {
                togglePopup(searchPopup, e);
                // Tapping the icon should leave you typing, keyboard included.
                if (searchPopup.classList.contains("active")) {
                    if (!searchInput.value.trim()) loadSearchHistory();
                    setTimeout(() => searchInput.focus(), 120);
                }
            });

            bellIcon.addEventListener("click", (e) => {
                togglePopup(notificationPopup, e);
                if (!notificationPopup.classList.contains("active")) return;
                loadNotifications(false);
                setTimeout(markAllRead, 600);
            });

            userIcon.addEventListener("click", (e) => {
                togglePopup(userPopup, e);
            });

            // Keyboard shortcut: Ctrl+K or Cmd+K to open search
            document.addEventListener("keydown", (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    const isActive = searchPopup.classList.contains("active");
                    hideAllPopups();
                    if (!isActive) {
                        searchPopup.classList.add("active");
                        showOverlay();
                        setTimeout(() => searchInput.focus(), 100);
                    }
                }
                if (e.key === 'Escape') {
                    hideAllPopups();
                    drawer.classList.remove("active");
                    hideOverlay();
                }
            });

            document.addEventListener("click", (e) => {
                if (
                    !e.target.closest(".right-nav") &&
                    !e.target.closest(".floating-popup") &&
                    !e.target.closest("#drawer") &&
                    !e.target.closest("#menuBtn") &&
                    !e.target.closest("#logout-popup-modal")
                ) {
                    hideAllPopups();
                    drawer.classList.remove("active");
                    hideOverlay();
                }
            });

            // ===================== SEARCH FUNCTIONALITY ===================== //
            // Enhanced search: kind filters (anime/movie/TV), keyboard
            // navigation, status badges and search history in one panel.
            let searchKind = 'all';
            let searchResults = [];   // last payload, for keyboard navigation
            let searchActive = -1;    // highlighted row index
            let searchAbort = null;
            let trendingTitles = [];  // live titles for the empty panel

            searchInput.addEventListener("input", () => {
                clearSearchBtn.style.display = searchInput.value.trim() ? "block" : "none";
            });

            function resetSuggestions() {
                suggestionBox.classList.remove("kp-results");
                searchResults = [];
                searchActive = -1;
                loadSearchHistory();
            }

            /** Open the watch page for a result and remember the query. */
            function openSearchResult(item, queryForHistory) {
                if (queryForHistory) {
                    fetch('./includes/save_search_history.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'term=' + encodeURIComponent(queryForHistory)
                    });
                }
                hideAllPopups();
                hideOverlay();
                window.location.href = './watch.php?id=' + encodeURIComponent(item.imdb_id);
            }

            const KIND_META = {
                anime: { label: 'Anime', icon: 'fa-solid fa-dragon' },
                movie: { label: 'Movie', icon: 'fa-solid fa-clapperboard' },
                tv:    { label: 'TV',    icon: 'fa-solid fa-tv' }
            };

            /** Pull live trending titles once per session for the idle panel. */
            function ensureTrendingTitles() {
                if (trendingTitles.length) return Promise.resolve(trendingTitles);
                return fetch('./includes/trending.php')
                    .then(r => r.json())
                    .then(list => {
                        if (!Array.isArray(list)) return [];
                        trendingTitles = list
                            .filter(t => t && t.title)
                            .map(t => ({
                                title: t.title,
                                imdb_id: t.imdb_id || t.id || '',
                                poster: t.poster || ''
                            }))
                            .slice(0, 8);
                        return trendingTitles;
                    })
                    .catch(() => []);
            }

            /** One compact pill: used for both Recent and Trending rows. */
            function makeSuggestChip(label, variant, term) {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'kp-sug-chip' + (variant ? ' kp-sug-chip-' + variant : '');
                const icon = variant === 'recent' ? 'fa-clock' : 'fa-fire';
                chip.innerHTML = '<i class="fas ' + icon + '"></i><span></span>';
                chip.querySelector('span').textContent = term;
                chip.title = term;
                chip.addEventListener('click', function() {
                    searchInput.value = term;
                    clearSearchBtn.style.display = 'block';
                    searchInput.dispatchEvent(new Event('input'));
                    searchInput.focus();
                });
                return chip;
            }

            function renderTrendingSection(titles) {
                if (!titles.length) {
                    suggestionBox.innerHTML = '<div class="kp-sug-section"><p class="kp-sug-loading"><i class="fas fa-magnifying-glass"></i> Start typing to search</p></div>';
                    return;
                }
                suggestionBox.innerHTML = '<div class="kp-history-header"><i class="fas fa-fire-flame-curved"></i> Trending Now</div>';
                const wrap = document.createElement('div');
                wrap.className = 'kp-sug-chips';
                titles.forEach(item => wrap.appendChild(makeSuggestChip(null, 'trend', item.title)));
                suggestionBox.appendChild(wrap);
            }

            function loadSearchHistory() {
                Promise.all([
                    fetch('./includes/get_search_history.php?limit=5').then(r => r.json()).catch(() => []),
                    ensureTrendingTitles()
                ]).then(([history, trending]) => {
                    const recent = (Array.isArray(history) ? history : []).slice(0, 5);
                    if (!recent.length && !trending.length) {
                        renderTrendingSection([]);
                        return;
                    }

                    suggestionBox.innerHTML = '';

                    if (recent.length) {
                        const head = document.createElement('div');
                        head.className = 'kp-history-header';
                        head.innerHTML = '<i class="fas fa-clock-rotate-left"></i> Recent';
                        const clearAll = document.createElement('button');
                        clearAll.type = 'button';
                        clearAll.className = 'kp-history-clear';
                        clearAll.textContent = 'Clear';
                        clearAll.addEventListener('click', function(e) {
                            e.stopPropagation();
                            recent.forEach(function(h) {
                                fetch('./includes/delete_search_history.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: 'term=' + encodeURIComponent(h.term)
                                });
                            });
                            setTimeout(loadSearchHistory, 200);
                        });
                        head.appendChild(clearAll);
                        suggestionBox.appendChild(head);

                        const wrap = document.createElement('div');
                        wrap.className = 'kp-sug-chips';
                        recent.forEach(function(item) {
                            wrap.appendChild(makeSuggestChip(null, 'recent', item.term));
                        });
                        suggestionBox.appendChild(wrap);
                    }

                    if (trending.length) {
                        const head = document.createElement('div');
                        head.className = 'kp-history-header' + (recent.length ? ' kp-trend-head' : '');
                        head.innerHTML = '<i class="fas fa-fire-flame-curved"></i> Trending Now';
                        suggestionBox.appendChild(head);

                        const wrap = document.createElement('div');
                        wrap.className = 'kp-sug-chips';
                        trending.forEach(function(item) {
                            wrap.appendChild(makeSuggestChip(null, 'trend', item.title));
                        });
                        suggestionBox.appendChild(wrap);
                    }
                }).catch(() => {
                    suggestionBox.innerHTML = '<div class="kp-sug-section"><p class="kp-sug-loading"><i class="fas fa-magnifying-glass"></i> Start typing to search</p></div>';
                });
            }

            searchInput.addEventListener('focus', function() {
                if (!searchInput.value.trim()) loadSearchHistory();
            });

            // Warm the trending list as soon as the script runs so the first
            // open of the search popup is already filled with live titles.
            ensureTrendingTitles();

            clearSearchBtn.addEventListener("click", () => {
                searchInput.value = "";
                clearSearchBtn.style.display = "none";
                searchInput.focus();
                resetSuggestions();
            });

            // Kind filter chips — switching re-runs the current query.
            document.querySelectorAll('#searchFilters .kp-search-chip').forEach(chip => {
                chip.addEventListener('click', () => {
                    if (chip.dataset.kind === searchKind) return;
                    document.querySelectorAll('#searchFilters .kp-search-chip').forEach(c => c.classList.remove('is-active'));
                    chip.classList.add('is-active');
                    searchKind = chip.dataset.kind;
                    const q = searchInput.value.trim();
                    if (q.length >= 2) runSearch(q);
                });
            });

            function runSearch(query) {
                clearTimeout(debounceTimeout);
                if (searchAbort) searchAbort.abort();
                searchAbort = new AbortController();
                const signal = searchAbort.signal;
                suggestionBox.classList.add("kp-results");
                suggestionBox.innerHTML =
                    '<p class="kp-sug-loading"><i class="fas fa-spinner fa-spin"></i> Searching…</p>';

                const timer = setTimeout(() => searchAbort.abort(), 12000);

                fetch(`./includes/search.php?term=${encodeURIComponent(query)}&kind=${encodeURIComponent(searchKind)}`, { signal })
                    .then(res => res.json())
                    .then(data => {
                        clearTimeout(timer);
                        suggestionBox.innerHTML = "";
                        searchResults = Array.isArray(data) ? data : [];
                        searchActive = -1;

                        if (searchResults.length === 0) {
                            suggestionBox.innerHTML =
                                '<p class="kp-sug-empty"><i class="fas fa-ghost"></i> No ' +
                                (searchKind === 'all' ? '' : KIND_META[searchKind].label.toLowerCase() + ' ') +
                                'results for “' + query.replace(/</g, '&lt;') + '”</p>';
                            return;
                        }

                        searchResults.forEach((item, idx) => {
                            const row = document.createElement("div");
                            row.className = "kp-sug";
                            row.dataset.index = idx;

                            const poster = document.createElement("img");
                            poster.loading = "lazy";
                            poster.alt = "";
                            poster.src = item.poster || "./uploads/thumbnails/default.png";
                            poster.addEventListener("error", () => {
                                poster.src = "./uploads/thumbnails/default.png";
                            });

                            const text = document.createElement("div");
                            text.className = "kp-sug-text";

                            const title = document.createElement("div");
                            title.className = "kp-sug-title";
                            title.textContent = item.title;
                            text.appendChild(title);

                            const metaBits = [];
                            if (item.year) metaBits.push(`<span>${item.year}</span>`);
                            if (item.rating) {
                                metaBits.push(`<span><i class="fas fa-star kp-star"></i> ${item.rating}</span>`);
                            }
                            if (item.episodes && item.episodes > 0) {
                                metaBits.push(`<span>${item.episodes} EP</span>`);
                            }
                            if (metaBits.length) {
                                const meta = document.createElement("div");
                                meta.className = "kp-sug-meta";
                                meta.innerHTML = metaBits.join("");
                                text.appendChild(meta);
                            }

                            row.appendChild(poster);
                            row.appendChild(text);

                            // Status badge (Airing/Upcoming/Released).
                            if (item.status) {
                                const st = document.createElement('span');
                                st.className = 'kp-sug-status kp-sug-status-' + item.status.toLowerCase();
                                st.textContent = item.status;
                                row.appendChild(st);
                            }

                            // Kind badge (Anime/Movie/TV) — provider goes to the title.
                            const kindMeta = KIND_META[item.kind] || KIND_META.anime;
                            const kind = document.createElement('span');
                            kind.className = 'kp-sug-kind';
                            kind.innerHTML = '<i class="' + kindMeta.icon + '"></i>' + kindMeta.label;
                            row.appendChild(kind);

                            row.addEventListener("click", () => {
                                openSearchResult(item, query);
                            });
                            suggestionBox.appendChild(row);
                        });
                    })
                    .catch(err => {
                        clearTimeout(timer);
                        if (err && (err.name === "AbortError" || signal.aborted)) return;
                        console.error("Search error:", err);
                        suggestionBox.innerHTML =
                            '<p class="kp-sug-empty"><i class="fas fa-triangle-exclamation"></i> Error fetching results</p>';
                    });
            }

            searchInput.addEventListener("input", () => {
                const query = searchInput.value.trim();
                clearTimeout(debounceTimeout);

                debounceTimeout = setTimeout(() => {
                    if (query.length < 2) {
                        resetSuggestions();
                        return;
                    }
                    runSearch(query);
                }, 300);
            });

            // Enter opens the highlighted row (or the first); arrows move it.
            searchInput.addEventListener("keydown", (e) => {
                if (!searchResults.length) {
                    if (e.key === 'Enter' && searchInput.value.trim().length >= 2) {
                        e.preventDefault();
                        const q = searchInput.value.trim();
                        fetch('./includes/save_search_history.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'term=' + encodeURIComponent(q)
                        });
                    }
                    return;
                }
                const rows = suggestionBox.querySelectorAll('.kp-sug:not(.kp-history-item)');
                // Trending chips live outside .kp-sug rows; Enter with no live
                // results still runs the typed query below when empty.
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    searchActive = e.key === 'ArrowDown'
                        ? (searchActive + 1) % searchResults.length
                        : (searchActive - 1 + searchResults.length) % searchResults.length;
                    rows.forEach((r, i) => r.classList.toggle('kp-active', i === searchActive));
                    if (rows[searchActive]) rows[searchActive].scrollIntoView({ block: 'nearest' });
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const pick = searchResults[searchActive >= 0 ? searchActive : 0];
                    if (pick) openSearchResult(pick, searchInput.value.trim());
                }
            });

            searchInput.addEventListener("focus", () => {
                if (searchInput.value.trim().length >= 2 && suggestionBox.innerHTML.trim() !== "") {
                    suggestionBox.style.display = "block";
                }
            });

            // ===================== COMING SOON MODAL ===================== //
            function openComingSoon(type) {
                hideAllPopups();
                if (drawer.classList.contains("active")) {
                    drawer.classList.remove("active");
                }
                var text = document.getElementById('coming-soon-text');
                if (type === 'movies') {
                    text.textContent = 'Movies section is under development and will be available soon.';
                } else {
                    text.textContent = 'Series section is under development and will be available soon.';
                }
                document.getElementById('coming-soon-modal').classList.add("show");
                showOverlay();
            }

            function closeComingSoon() {
                document.getElementById('coming-soon-modal').classList.remove("show");
                hideOverlay();
            }

            // ===================== LOGOUT POPUP ===================== //
            function openLogoutPopup() {
                hideAllPopups();

                // Close drawer if open
                if (drawer.classList.contains("active")) {
                    drawer.classList.remove("active");
                }

                logoutPopupModal.classList.add("show");
                showOverlay();
            }

            function closeLogoutPopup() {
                logoutPopupModal.classList.remove("show");
                hideOverlay();
            }

            function logoutUser() {
                window.location.href = "./auth/logout.php";
            }

            // ===================== NOTIFICATIONS ===================== //
            // Feed is paged + type-filtered; polls keep the badge fresh while
            // the panel is closed, and re-render only when it is open.
            const notifList = document.getElementById('notif-list');
            const notifBadge = document.getElementById('notif-badge');
            const notifCountLabel = document.getElementById('notif-count-label');
            const notifMarkAllBtn = document.getElementById('notif-mark-all');
            const notifClearAllBtn = document.getElementById('notif-clear-all');
            const notifSentinel = document.getElementById('notif-sentinel');
            let lastUnreadCount = 0;
            let notifTypeFilter = '';
            let notifPage = 1;
            let notifPages = 1;
            let notifLoading = false;

            // The two "notification behaviour" preferences from the Notification
            // tab. They were saved but never read, so both toggles did nothing.
            var notifPrefs = { sound_enabled: 0, toast_enabled: 1 };
            fetch('./includes/notification_settings.php')
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.success && d.settings) {
                        notifPrefs.sound_enabled = Number(d.settings.sound_enabled) ? 1 : 0;
                        notifPrefs.toast_enabled = Number(d.settings.toast_enabled) ? 1 : 0;
                    }
                })
                .catch(function() {});

            /** Short two-note chime, synthesised so no audio file has to ship. */
            function kpNotifBeep() {
                try {
                    var Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;
                    var ctx = new Ctx();
                    [880, 1174].forEach(function(freq, i) {
                        var osc  = ctx.createOscillator();
                        var gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = freq;
                        var at = ctx.currentTime + i * 0.14;
                        gain.gain.setValueAtTime(0.0001, at);
                        gain.gain.exponentialRampToValueAtTime(0.16, at + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.13);
                        osc.connect(gain).connect(ctx.destination);
                        osc.start(at);
                        osc.stop(at + 0.15);
                    });
                    setTimeout(function() { try { ctx.close(); } catch (e) {} }, 600);
                } catch (e) { /* autoplay policy — silence is fine */ }
            }

            function notifTypeIcon(type) {
                switch (type) {
                    case 'episode': return 'fa-solid fa-tv';
                    case 'follow':  return 'fa-solid fa-heart';
                    case 'system':  return 'fa-solid fa-circle-info';
                    default:        return 'fa-solid fa-bell';
                }
            }

            function timeAgo(dateStr) {
                if (!dateStr) return '';
                var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
                return new Date(dateStr).toLocaleDateString();
            }

            function notifQuery(page) {
                var qs = '?page=' + (page || 1) + '&limit=15';
                if (notifTypeFilter) qs += '&type=' + encodeURIComponent(notifTypeFilter);
                return './includes/check_notifications.php' + qs;
            }

            /** Build one feed row. Pulled out so paging can append rows. */
            function notifRow(n) {
                var row = document.createElement('div');
                row.className = 'kp-notif-item';
                row.setAttribute('data-id', n.id || '');
                if (!n.is_read) row.classList.add('unread');

                var icon = document.createElement('div');
                icon.className = 'kp-notif-icon';
                if (n.poster) {
                    icon.classList.add('kp-notif-cover');
                    var cover = document.createElement('img');
                    cover.src = n.poster;
                    cover.alt = '';
                    cover.loading = 'lazy';
                    cover.decoding = 'async';
                    cover.onerror = function () {
                        // Drop the art and fall back to the type glyph.
                        icon.classList.remove('kp-notif-cover');
                        icon.textContent = '';
                        var fb = document.createElement('i');
                        fb.className = n.icon || notifTypeIcon(n.type);
                        icon.appendChild(fb);
                    };
                    icon.appendChild(cover);
                } else {
                    var glyph = document.createElement('i');
                    glyph.className = n.icon || notifTypeIcon(n.type);
                    icon.appendChild(glyph);
                }

                var body = document.createElement('div');
                body.className = 'kp-notif-body';
                var strong = document.createElement('strong');
                strong.textContent = n.title;
                strong.title = n.title;
                var msg = document.createElement('span');
                msg.textContent = n.message || ('Ep. ' + n.episode + ' is out!');
                msg.title = msg.textContent;
                var time = document.createElement('div');
                time.className = 'kp-notif-time';
                time.textContent = timeAgo(n.time);

                body.appendChild(strong);
                body.appendChild(msg);
                body.appendChild(time);

                var delBtn = document.createElement('button');
                delBtn.className = 'kp-notif-delete';
                delBtn.innerHTML = '<i class="fas fa-xmark"></i>';
                delBtn.title = 'Dismiss';
                delBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(20px)';
                    setTimeout(function() { row.remove(); }, 200);
                    fetch('./includes/delete_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: n.id })
                    });
                });

                row.appendChild(icon);
                row.appendChild(body);
                row.appendChild(delBtn);

                row.addEventListener('click', function() {
                    if (!n.is_read) {
                        fetch('./includes/mark_notification_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: n.id })
                        });
                        row.classList.remove('unread');
                    }
                    // System notes (badges, rank, welcome) carry their own link.
                    window.location.href = n.url || ('./watch.php?id=' + encodeURIComponent(n.slug) + '&ep=' + n.episode);
                });

                return row;
            }

            function loadNotifications(append) {
                if (notifLoading) return;
                if (append) {
                    if (notifPage >= notifPages) return;
                    notifPage++;
                } else {
                    // Full refresh resets paging so later pages start from 2.
                    notifPage = 1;
                }
                notifLoading = true;
                if (notifSentinel) notifSentinel.style.display = append ? 'flex' : 'none';
                fetch(notifQuery(notifPage))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        notifLoading = false;
                        var unread = data.unread || 0;
                        notifPages = data.pages || 1;

                        if (notifBadge) {
                            if (unread > 0) {
                                var prev = parseInt(notifBadge.textContent) || 0;
                                notifBadge.textContent = unread > 99 ? '99+' : unread;
                                notifBadge.style.display = 'block';
                                if (unread > prev && prev > 0) {
                                    notifBadge.classList.add('kp-notif-pulse');
                                    setTimeout(function() { notifBadge.classList.remove('kp-notif-pulse'); }, 1000);
                                }
                            } else {
                                notifBadge.style.display = 'none';
                            }
                        }

                        if (notifMarkAllBtn) {
                            notifMarkAllBtn.style.display = unread > 0 ? 'flex' : 'none';
                        }

                        // Dismissing everything only makes sense while there is
                        // something to dismiss — the button was never shown before.
                        if (notifClearAllBtn) {
                            var knownTotal = data.total || (data.notifications || []).length;
                            notifClearAllBtn.style.display = knownTotal > 0 ? 'flex' : 'none';
                        }

                        if (notifCountLabel) {
                            var total = data.total || (data.notifications || []).length;
                            if (unread > 0) {
                                notifCountLabel.textContent = unread + ' new';
                                notifCountLabel.style.display = 'inline-block';
                            } else if (total > 0) {
                                notifCountLabel.textContent = total + ' total';
                                notifCountLabel.style.display = 'inline-block';
                            } else {
                                notifCountLabel.style.display = 'none';
                            }
                        }

                        if (!notifList) return;
                        var notifs = data.notifications || [];
                        // An empty page means the server ran out even if it
                        // claimed more pages — stop the observer here.
                        if (append && notifs.length === 0) {
                            notifPages = notifPage;
                            updateNotifSentinel(false);
                            return;
                        }
                        if (append) {
                            // Rows go before the sentinel so the spinner stays last.
                            var frag = document.createDocumentFragment();
                            notifs.forEach(function(n) { frag.appendChild(notifRow(n)); });
                            if (notifSentinel && notifSentinel.parentNode === notifList) {
                                notifList.insertBefore(frag, notifSentinel);
                            } else {
                                notifList.appendChild(frag);
                                if (notifSentinel) notifList.appendChild(notifSentinel);
                            }
                        } else if (notifs.length === 0) {
                            var emptyMsg = notifTypeFilter === 'episode'
                                ? 'No episode alerts yet'
                                : (notifTypeFilter === 'system' ? 'No system notifications' : 'No notifications yet');
                            var emptySub = notifTypeFilter === 'system'
                                ? 'Badges, rank-ups and welcome notes land here'
                                : 'Follow anime to get notified about new episodes';
                            notifList.innerHTML = '<div class="kp-notif-empty"><i class="fas fa-bell-slash"></i><span>' + emptyMsg + '</span><span class="kp-notif-empty-sub">' + emptySub + '</span></div>';
                            if (notifSentinel) notifList.appendChild(notifSentinel);
                            lastUnreadCount = unread;
                            updateNotifSentinel(false);
                            return;
                        } else {
                            notifList.innerHTML = '';
                            notifs.forEach(function(n) { notifList.appendChild(notifRow(n)); });
                            if (notifSentinel) notifList.appendChild(notifSentinel);
                        }
                        // Show the sentinel only after the rows are in place so
                        // IntersectionObserver measures the final layout.
                        updateNotifSentinel(true);

                        // Show toast for new notifications
                        if (!append && unread > lastUnreadCount && lastUnreadCount > 0 && document.hidden) {
                            var newest = notifs.find(function(n) { return !n.is_read; });
                            if (newest && notifPrefs.toast_enabled) showNotifToast(newest);
                            if (newest && notifPrefs.sound_enabled) kpNotifBeep();
                        }
                        lastUnreadCount = unread;
                    })
                    .catch(function() {
                        notifLoading = false;
                        // The page index was bumped before the fetch — put it
                        // back so the next attempt retries the same page.
                        if (append) notifPage--;
                        if (notifList && !append) {
                            notifList.innerHTML = '<div class="kp-notif-empty"><i class="fas fa-triangle-exclamation"></i><span>Failed to load</span></div>';
                            if (notifSentinel) notifList.appendChild(notifSentinel);
                        }
                        // Stay hidden after an error so a flaky network cannot
                        // spin the observer in a tight retry loop.
                        updateNotifSentinel(false);
                    });
            }

            function updateNotifSentinel(ok) {
                if (!notifSentinel) return;
                var show = ok && notifPage < notifPages;
                // Drop and re-show so a sentinel that stayed in view during the
                // fetch still fires IntersectionObserver for the next page.
                notifSentinel.style.display = 'none';
                if (show) {
                    void notifSentinel.offsetHeight;
                    notifSentinel.style.display = 'flex';
                }
            }

            // Infinite scroll: the sentinel sits at the foot of the scrolling
            // list; when it enters view and pages remain, pull the next one.
            if (notifList && notifSentinel && 'IntersectionObserver' in window) {
                new IntersectionObserver(function(entries) {
                    if (entries[0] && entries[0].isIntersecting) {
                        loadNotifications(true);
                    }
                }, { root: notifList, rootMargin: '80px' }).observe(notifSentinel);
            }

            // Type filter tabs — switching resets paging and re-fetches.
            document.querySelectorAll('#notifTabs .kp-notif-tab').forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (tab.dataset.type === notifTypeFilter) return;
                    document.querySelectorAll('#notifTabs .kp-notif-tab').forEach(function(t) { t.classList.remove('is-active'); });
                    tab.classList.add('is-active');
                    notifTypeFilter = tab.dataset.type;
                    loadNotifications(false);
                });
            });

            function markAllRead() {
                fetch('./includes/mark_notifications_read.php', { method: 'POST' })
                    .then(function() {
                        if (notifBadge) notifBadge.style.display = 'none';
                        if (notifCountLabel) notifCountLabel.style.display = 'none';
                        if (notifMarkAllBtn) notifMarkAllBtn.style.display = 'none';
                        document.querySelectorAll('#notif-list .kp-notif-item.unread').forEach(function(el) {
                            el.classList.remove('unread');
                        });
                        lastUnreadCount = 0;
                    });
            }

            if (notifMarkAllBtn) {
                notifMarkAllBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    markAllRead();
                });
            }

            if (notifClearAllBtn) {
                notifClearAllBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    // Destructive and irreversible, so it always asks first.
                    if (!window.confirm('Remove every notification? This cannot be undone.')) return;

                    if (notifList) {
                        notifList.innerHTML = '<div class="kp-notif-empty"><i class="fas fa-bell-slash"></i><span>No notifications yet</span><span class="kp-notif-empty-sub">Follow anime to get notified about new episodes</span></div>';
                        if (notifSentinel) notifList.appendChild(notifSentinel);
                    }
                    if (notifBadge) notifBadge.style.display = 'none';
                    if (notifCountLabel) notifCountLabel.style.display = 'none';
                    if (notifMarkAllBtn) notifMarkAllBtn.style.display = 'none';
                    notifClearAllBtn.style.display = 'none';
                    lastUnreadCount = 0;
                    notifPage = 1;
                    notifPages = 1;
                    updateNotifSentinel(false);

                    fetch('./includes/delete_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ clear_all: true })
                    }).then(function() {
                        if (typeof kpToast === 'function') kpToast('Notifications cleared', 'success');
                    }).catch(function() {
                        loadNotifications(false);
                    });
                });
            }

            function showNotifToast(n) {
                var toast = document.createElement('div');
                toast.className = 'kp-toast-notif';
                toast.innerHTML = '<div class="kp-toast-notif-icon"><i class="' + (n.icon || notifTypeIcon(n.type)) + '"></i></div>'
                    + '<div class="kp-toast-notif-body"><strong>' + (n.title || '') + '</strong><span>' + (n.message || '') + '</span></div>';
                toast.addEventListener('click', function() {
                    window.location.href = n.url || ('./watch.php?id=' + encodeURIComponent(n.slug) + '&ep=' + n.episode);
                });
                var container = document.querySelector('.kp-toast-container') || (function() {
                    var c = document.createElement('div');
                    c.className = 'kp-toast-container';
                    document.body.appendChild(c);
                    return c;
                })();
                container.appendChild(toast);
                setTimeout(function() { toast.classList.add('show'); }, 10);
                setTimeout(function() {
                    toast.classList.remove('show');
                    setTimeout(function() { toast.remove(); }, 300);
                }, 5000);
            }

            // Initial feed pull + badge refresh every minute while the panel
            // is closed. Open panels re-render on demand only.
            loadNotifications(false);
            setInterval(function () {
                if (!notificationPopup.classList.contains('active')) loadNotifications(false);
            }, 60000);

    </script>
    <!-- =================== /Script =================== -->

    <!-- =================== Toast Notification =================== -->
    <style>
        .kp-toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .kp-toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 10px;
            background: #232323;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            color: #f0f0f0;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 500;
            pointer-events: auto;
            transform: translateX(120%);
            opacity: 0;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.35s ease;
        }
        .kp-toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        .kp-toast.hide {
            transform: translateX(120%);
            opacity: 0;
        }
        .kp-toast i {
            font-size: 16px;
            flex: 0 0 auto;
        }
        .kp-toast.success { border-left: 3px solid #4ade80; }
        .kp-toast.success i { color: #4ade80; }
        .kp-toast.error { border-left: 3px solid #f87171; }
        .kp-toast.error i { color: #f87171; }
        .kp-toast.info { border-left: 3px solid #60a5fa; }
        .kp-toast.info i { color: #60a5fa; }
        @media (max-width: 480px) {
            .kp-toast-container { bottom: 16px; right: 16px; left: 16px; }
        }
    </style>
    <div class="kp-toast-container" id="kp-toast-container"></div>
    <script>
    function kpToast(message, type) {
        type = type || 'success';
        var icons = { success: 'fas fa-check-circle', error: 'fas fa-times-circle', info: 'fas fa-info-circle' };
        var container = document.getElementById('kp-toast-container');
        if (!container) return;
        var toast = document.createElement('div');
        toast.className = 'kp-toast ' + type;
        toast.innerHTML = '<i class="' + (icons[type] || icons.success) + '"></i><span>' + message + '</span>';
        container.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        setTimeout(function () {
            toast.classList.remove('show');
            toast.classList.add('hide');
            setTimeout(function () { toast.remove(); }, 400);
        }, 3000);
    }
    </script>