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

<body class="<?= trim($kp_body_class) ?>">
    <!-- =================== Overlay =================== -->
    <div class="overlay" id="overlay"></div>
    <!-- =================== /Overlay =================== -->

    <!-- =================== Logout Confirmation Modal =================== -->
    <div id="logout-popup-modal" class="logout-popup-modal">
        <div class="logout-popup-content">
            <div class="logout-popup-icon">
                <i class="fas fa-right-from-bracket"></i>
            </div>
            <h3 id="logout-popupTitle">Confirm Logout</h3>
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
            <div class="coming-soon-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h3>Coming Soon</h3>
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
                            <input type="text" id="searchInput" placeholder="Search anime..." autocomplete="off" />
                            <span class="clear-search" id="clearSearch">&times;</span>
                        </div>
                        <span class="kp-search-kbd">ESC</span>
                    </div>
                    <div class="search-suggestions" id="searchSuggestions">
                        <div class="kp-sug-section">
                            <p><i class="fas fa-fire"></i> Trending: One Piece, Solo Leveling, Frieren</p>
                            <p><i class="fas fa-clock"></i> Try: Demon Slayer, Jujutsu Kaisen, Dandadan</p>
                        </div>
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
                    <div id="notif-list" class="kp-notif-list">
                        <div class="kp-notif-empty">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading...</span>
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
                    setTimeout(() => searchInput.focus(), 120);
                }
            });

            bellIcon.addEventListener("click", (e) => {
                togglePopup(notificationPopup, e);
                if (!notificationPopup.classList.contains("active")) return;
                loadNotifications();
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
            searchInput.addEventListener("input", () => {
                clearSearchBtn.style.display = searchInput.value.trim() ? "block" : "none";
            });

            const defaultSuggestions = suggestionBox.innerHTML;

            function resetSuggestions() {
                suggestionBox.classList.remove("kp-results");
                loadSearchHistory();
            }

            function loadSearchHistory() {
                fetch('./includes/get_search_history.php?limit=8')
                    .then(r => r.json())
                    .then(data => {
                        if (!Array.isArray(data) || data.length === 0) {
                            suggestionBox.innerHTML = defaultSuggestions;
                            return;
                        }
                        suggestionBox.innerHTML = '<div class="kp-history-header"><i class="fas fa-clock-rotate-left"></i> Recent Searches <button id="clearAllHistory" style="background:none;border:none;color:#ff2e63;cursor:pointer;font-size:11px;float:right;">Clear all</button></div>';
                        data.forEach(item => {
                            const row = document.createElement('div');
                            row.className = 'kp-sug kp-history-item';

                            const icon = document.createElement('i');
                            icon.className = 'fas fa-clock';
                            icon.style.cssText = 'color:#666; margin-right:10px; font-size:12px;';

                            const text = document.createElement('span');
                            text.textContent = item.term;
                            text.style.cssText = 'flex:1; color:#ccc; font-size:13px;';

                            const del = document.createElement('button');
                            del.className = 'kp-history-del';
                            del.innerHTML = '<i class="fas fa-xmark"></i>';
                            del.style.cssText = 'background:none;border:none;color:#666;cursor:pointer;padding:2px 6px;font-size:12px;border-radius:4px;';
                            del.addEventListener('mouseenter', function() { this.style.color='#ff6b6b'; this.style.background='rgba(255,107,107,0.1)'; });
                            del.addEventListener('mouseleave', function() { this.style.color='#666'; this.style.background='none'; });
                            del.addEventListener('click', function(e) {
                                e.stopPropagation();
                                fetch('./includes/delete_search_history.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: 'term=' + encodeURIComponent(item.term)
                                }).then(() => loadSearchHistory());
                            });

                            row.appendChild(icon);
                            row.appendChild(text);
                            row.appendChild(del);

                            row.addEventListener('click', function() {
                                searchInput.value = item.term;
                                searchInput.dispatchEvent(new Event('input'));
                            });

                            suggestionBox.appendChild(row);
                        });

                        var clearAll = document.getElementById('clearAllHistory');
                        if (clearAll) {
                            clearAll.addEventListener('click', function(e) {
                                e.stopPropagation();
                                data.forEach(function(h) {
                                    fetch('./includes/delete_search_history.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: 'term=' + encodeURIComponent(h.term)
                                    });
                                });
                                setTimeout(loadSearchHistory, 200);
                            });
                        }
                    })
                    .catch(() => {
                        suggestionBox.innerHTML = defaultSuggestions;
                    });
            }

            searchInput.addEventListener('focus', function() {
                if (!searchInput.value.trim()) loadSearchHistory();
            });

            clearSearchBtn.addEventListener("click", () => {
                searchInput.value = "";
                clearSearchBtn.style.display = "none";
                searchInput.focus();
                resetSuggestions();
            });

            searchInput.addEventListener("input", () => {
                const query = searchInput.value.trim();
                clearTimeout(debounceTimeout);

                debounceTimeout = setTimeout(() => {
                    if (query.length < 2) {
                        resetSuggestions();
                        return;
                    }

                    suggestionBox.classList.add("kp-results");
                    suggestionBox.innerHTML =
                        '<p class="kp-sug-loading"><i class="fas fa-spinner fa-spin"></i> Searching…</p>';

                    const controller = new AbortController();
                    const timer = setTimeout(() => controller.abort(), 12000);

                    fetch(`./includes/search.php?term=${encodeURIComponent(query)}`, { signal: controller.signal })
                        .then(res => res.json())
                        .then(data => {
                            clearTimeout(timer);
                            suggestionBox.innerHTML = "";

                            if (!Array.isArray(data) || data.length === 0) {
                                suggestionBox.innerHTML =
                                    '<p class="kp-sug-empty"><i class="fas fa-ghost"></i> No results found</p>';
                                return;
                            }

                            data.forEach(item => {
                                const row = document.createElement("div");
                                row.className = "kp-sug";

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
                                if (metaBits.length) {
                                    const meta = document.createElement("div");
                                    meta.className = "kp-sug-meta";
                                    meta.innerHTML = metaBits.join("");
                                    text.appendChild(meta);
                                }

                                row.appendChild(poster);
                                row.appendChild(text);

                                if (item.source && item.source !== "local") {
                                    const prov = document.createElement("span");
                                    prov.className = "kp-sug-prov";
                                    prov.textContent = item.source;
                                    row.appendChild(prov);
                                }

                                row.addEventListener("click", () => {
                                    searchInput.value = item.title;
                                    fetch('./includes/save_search_history.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: 'term=' + encodeURIComponent(item.title)
                                    });
                                    resetSuggestions();
                                    hideAllPopups();
                                    window.location.href = `watch.php?id=${encodeURIComponent(item.imdb_id)}`;
                                });
                                suggestionBox.appendChild(row);
                            });
                        })
                        .catch(err => {
                            clearTimeout(timer);
                            if (err && err.name === "AbortError") return;
                            console.error("Search error:", err);
                            suggestionBox.innerHTML =
                                '<p class="kp-sug-empty"><i class="fas fa-triangle-exclamation"></i> Error fetching results</p>';
                        });
                }, 300);
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
            const notifList = document.getElementById('notif-list');
            const notifBadge = document.getElementById('notif-badge');
            const notifCountLabel = document.getElementById('notif-count-label');
            const notifMarkAllBtn = document.getElementById('notif-mark-all');
            const notifClearAllBtn = document.getElementById('notif-clear-all');
            let lastUnreadCount = 0;

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

            function loadNotifications() {
                fetch('./includes/check_notifications.php')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var unread = data.unread || 0;

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
                        if (notifs.length === 0) {
                            notifList.innerHTML = '<div class="kp-notif-empty"><i class="fas fa-bell-slash"></i><span>No notifications yet</span><span class="kp-notif-empty-sub">Follow anime to get notified about new episodes</span></div>';
                            return;
                        }

                        notifList.innerHTML = '';
                        notifs.forEach(function(n) {
                            var row = document.createElement('div');
                            row.className = 'kp-notif-item';
                            row.setAttribute('data-id', n.id || '');
                            if (!n.is_read) row.classList.add('unread');

                            var icon = document.createElement('div');
                            icon.className = 'kp-notif-icon';
                            icon.innerHTML = '<i class="' + (n.icon || notifTypeIcon(n.type)) + '"></i>';

                            var body = document.createElement('div');
                            body.className = 'kp-notif-body';
                            var strong = document.createElement('strong');
                            strong.textContent = n.title;
                            var msg = document.createElement('span');
                            msg.textContent = n.message || ('Ep. ' + n.episode + ' is out!');
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
                                    unread--;
                                    if (notifBadge && unread <= 0) notifBadge.style.display = 'none';
                                    if (notifBadge && unread > 0) notifBadge.textContent = unread > 99 ? '99+' : unread;
                                }
                                // System notes (badges, rank, welcome) carry their own link.
                                window.location.href = n.url || ('./watch.php?id=' + encodeURIComponent(n.slug) + '&ep=' + n.episode);
                            });

                            notifList.appendChild(row);
                        });

                        // Show toast for new notifications
                        if (unread > lastUnreadCount && lastUnreadCount > 0 && document.hidden) {
                            var newest = notifs.find(function(n) { return !n.is_read; });
                            if (newest && notifPrefs.toast_enabled) showNotifToast(newest);
                            if (newest && notifPrefs.sound_enabled) kpNotifBeep();
                        }
                        lastUnreadCount = unread;
                    })
                    .catch(function() {
                        if (notifList) notifList.innerHTML = '<div class="kp-notif-empty"><i class="fas fa-triangle-exclamation"></i><span>Failed to load</span></div>';
                    });
            }

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

                    if (notifList) notifList.innerHTML = '<div class="kp-notif-empty"><i class="fas fa-bell-slash"></i><span>No notifications yet</span><span class="kp-notif-empty-sub">Follow anime to get notified about new episodes</span></div>';
                    if (notifBadge) notifBadge.style.display = 'none';
                    if (notifCountLabel) notifCountLabel.style.display = 'none';
                    if (notifMarkAllBtn) notifMarkAllBtn.style.display = 'none';
                    notifClearAllBtn.style.display = 'none';
                    lastUnreadCount = 0;

                    fetch('./includes/delete_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ clear_all: true })
                    }).then(function() {
                        if (typeof kpToast === 'function') kpToast('Notifications cleared', 'success');
                    }).catch(function() {
                        loadNotifications();
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

            loadNotifications();
            setInterval(loadNotifications, 60000);

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