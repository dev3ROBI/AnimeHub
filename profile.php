<?php
session_start();

if (!isset($_SESSION['userID'])) {
    header("Location: authentication.php");
    exit();
}

include 'includes/db.php';
include 'includes/header.php';

$userID = $_SESSION['userID'];

// Prepare statement safely
$sql = "SELECT * FROM users WHERE User_ID = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
} else {
    echo "<p>User not found.</p>";
    include 'includes/footer.php';
    exit();
}

include_once 'includes/avatars.php';
include_once 'includes/catalog.php';

// ─── Avatar ───────────────────────────────────────────────────────────
$avatarValue = (string)($user['User_Avatar'] ?? '');
$avatarUrl   = avatar_resolve($avatarValue);
$avatarInfo  = avatar_meta($avatarValue);
$gallery     = avatar_gallery();

// ─── Random cover anime (for the glass-blur banner) ──────────────────
$coverAnime = [];
try {
    $allTrending = catalog_trending(40);
    if (!empty($allTrending)) {
        $shuffled = $allTrending;
        shuffle($shuffled);
        foreach (array_slice($shuffled, 0, 6) as $item) {
            $poster = $item['banner'] ?? $item['poster'] ?? '';
            if (!empty($poster)) {
                $coverAnime[] = $poster;
            }
        }
    }
} catch (Throwable $e) {
    // Cover is cosmetic; failure is fine.
}

// ─── At-a-glance stats ────────────────────────────────────────────────
$stats = ['episodes' => 0, 'watchlist' => 0, 'likes' => 0, 'days' => 0];

try {
    $q = $conn->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id = ?");
    $q->bind_param("i", $userID);
    $q->execute();
    $stats['episodes'] = (int)$q->get_result()->fetch_row()[0];

    $q = $conn->prepare("SELECT COUNT(*) FROM watchlist WHERE user_id = ?");
    $q->bind_param("i", $userID);
    $q->execute();
    $stats['watchlist'] = (int)$q->get_result()->fetch_row()[0];

    $q = $conn->prepare("SELECT COUNT(*) FROM likes WHERE user_id = ?");
    $q->bind_param("i", $userID);
    $q->execute();
    $stats['likes'] = (int)$q->get_result()->fetch_row()[0];
} catch (Throwable $e) {
    // Stats are decoration; a failure must not take the page down.
}

if (!empty($user['User_Join'])) {
    $joined = strtotime((string)$user['User_Join']);
    if ($joined) $stats['days'] = max(1, (int)floor((time() - $joined) / 86400));
}
?>

<!-- Profile Main Container -->
<div class="prof-main-con">

    <!-- profile container -->
    <div class="profile-container">

        <!-- Identity banner (avatar lives here so it shows before any tab loads) -->
        <div class="kp-prof-banner">
            <div class="kp-prof-cover">
                <?php if (!empty($coverAnime)): ?>
                    <div class="kp-prof-cover-mosaic">
                        <?php foreach ($coverAnime as $i => $img): ?>
                            <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="" class="kp-prof-cover-img kp-prof-cover-img-<?= $i ?>" loading="eager">
                        <?php endforeach; ?>
                    </div>
                    <div class="kp-prof-cover-glass"></div>
                <?php endif; ?>
            </div>
            <div class="kp-prof-identity">
                <div class="kp-prof-avatar-wrap">
                    <img id="prof-avatar" data-kp-avatar-img class="kp-prof-avatar"
                         src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Profile avatar">
                    <button type="button" class="kp-prof-avatar-edit" data-kp-avatar-open
                            title="Change anime avatar" aria-label="Change anime avatar">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>

                <div class="kp-prof-meta">
                    <h1 class="kp-prof-name"><?= htmlspecialchars($user['User_Name']) ?></h1>
                    <p class="kp-prof-sub">
                        <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['User_Email']) ?></span>
                        <span><i class="fas fa-crown"></i> <?= htmlspecialchars(ucfirst($user['User_Role'] ?? 'user')) ?></span>
                        <span><i class="fas fa-calendar-check"></i>
                            <?= htmlspecialchars(date('M j, Y', strtotime((string)$user['User_Join']))) ?></span>
                    </p>
                    <p class="kp-prof-avatar-name<?= $avatarInfo ? ' has-avatar' : '' ?>" data-kp-avatar-name>
                        <?php if ($avatarInfo): ?>
                            <?= htmlspecialchars($avatarInfo['name']) ?><?= $avatarInfo['from'] !== '' ? ' &middot; ' . htmlspecialchars($avatarInfo['from']) : '' ?>
                        <?php else: ?>
                            No anime avatar picked yet
                        <?php endif; ?>
                    </p>
                </div>

                <button type="button" class="kp-prof-avatar-btn" data-kp-avatar-open>
                    <i class="fas fa-wand-magic-sparkles"></i>
                    <span class="kp-avatar-btn-label"><?= $avatarValue !== '' ? 'Change Avatar' : 'Pick an Anime Avatar' ?></span>
                </button>
            </div>

            <div class="kp-prof-stats">
                <div class="kp-stat">
                    <div class="kp-stat-icon-wrap"><i class="fas fa-film"></i></div>
                    <strong><?= number_format($stats['episodes']) ?></strong>
                    <span>Episodes Watched</span>
                </div>
                <div class="kp-stat">
                    <div class="kp-stat-icon-wrap"><i class="fas fa-bookmark"></i></div>
                    <strong><?= number_format($stats['watchlist']) ?></strong>
                    <span>In Watchlist</span>
                </div>
                <div class="kp-stat">
                    <div class="kp-stat-icon-wrap"><i class="fas fa-heart"></i></div>
                    <strong><?= number_format($stats['likes']) ?></strong>
                    <span>Liked</span>
                </div>
                <div class="kp-stat">
                    <div class="kp-stat-icon-wrap"><i class="fas fa-star"></i></div>
                    <strong><?= number_format($stats['days']) ?></strong>
                    <span>Days as Member</span>
                </div>
            </div>
        </div>
        <!-- /Identity banner -->

        <!-- Menu option -->
        <div class="menu-option-tabs">
            <ul class="nav nav-tabs pre-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="#" data-target="profile" role="tab">
                        <i class="fas fa-user mr-2" aria-hidden="true"></i>
                        <span class="tab-text">Profile</span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="#" data-target="continue-watching" role="tab">
                        <i class="fas fa-history mr-2" aria-hidden="true"></i>
                        <span class="tab-text">Continue Watching</span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="#" data-target="watch-list" role="tab">
                        <i class="fas fa-heart mr-2" aria-hidden="true"></i>
                        <span class="tab-text">Watch Lists</span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="#" data-target="notification" role="tab">
                        <i class="fas fa-bell mr-2" aria-hidden="true"></i>
                        <span class="tab-text">Notification</span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="#" data-target="stats" role="tab">
                        <i class="fas fa-chart-bar mr-2" aria-hidden="true"></i>
                        <span class="tab-text">Stats</span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="#" data-target="settings" role="tab">
                        <i class="fas fa-cog mr-2" aria-hidden="true"></i>
                        <span class="tab-text">Settings</span>
                    </a>
                </li>
            </ul>
        </div>
        <!-- /Menu option -->

        <!-- Menu option content -->
        <div class="color-bg-for-tabs">
            <div class="menu-option-tabs-content">
                <div id="tab-loading-spinner" style="display: none; text-align: center; padding: 30px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #fff;"></i>
                </div>
                <div id="tab-inner-content"></div>
            </div>
        </div>
        <!-- /Menu option content -->

    </div>
    <!-- /profile container -->

</div>
<!-- /Profile Main Container -->

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabs = document.querySelectorAll('.nav-link');
        const contentWrapper = document.querySelector('.menu-option-tabs-content');
        const spinner = document.getElementById('tab-loading-spinner');
        const innerContent = document.getElementById('tab-inner-content');

        function getQueryParam(param) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(param);
        }

        // Scripts inside a fragment set with innerHTML never run, so each tab
        // that needs behaviour ships a real file and declares it here.
        const TAB_SCRIPTS = {
            'continue-watching': ['./user/js/continue-watching.js'],
            'watch-list':        ['./user/js/watch-list.js'],
            'notification':      ['./user/js/notification.js'],
            'stats':             ['./user/js/stats.js'],
            'settings':          ['./user/js/settings.js']
        };

        function loadTabScript(target) {
            (TAB_SCRIPTS[target] || []).forEach((src) => {
                const script = document.createElement('script');
                script.src = src;
                document.body.appendChild(script);
            });
        }

        function activateTab(target) {
            tabs.forEach(t => t.classList.remove('active'));
            const targetTab = Array.from(tabs).find(t => t.getAttribute('data-target') === target);
            if (targetTab) targetTab.classList.add('active');

            contentWrapper.classList.remove('show');
            innerContent.innerHTML = '';
            spinner.style.display = 'block';

            setTimeout(() => {
                fetch(`./user/${target}.php`)
                    .then(res => {
                        if (!res.ok) throw new Error('Network error');
                        return res.text();
                    })
                    .then(data => {
                        spinner.style.display = 'none';
                        innerContent.innerHTML = data;

                        // Load JS dynamically for specific tabs
                        loadTabScript(target);

                        // Trigger fade-in
                        void contentWrapper.offsetWidth;
                        contentWrapper.classList.add('show');
                    })
                    .catch(err => {
                        spinner.style.display = 'none';
                        innerContent.innerHTML =
                        `<p style="color:white;">Error: ${err.message}</p>`;
                        contentWrapper.classList.add('show');
                    });
            }, 200);
        }



        const initialTab = getQueryParam('tab') || 'profile';
        activateTab(initialTab);

        tabs.forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                const target = this.getAttribute('data-target');
                activateTab(target);
                history.pushState(null, '', `?tab=${target}`);
            });
        });
    });
</script>

<!-- =================== Anime avatar picker =================== -->
<div class="kp-avatar-modal" id="kp-avatar-modal" aria-hidden="true">
    <div class="kp-avatar-dialog" role="dialog" aria-modal="true" aria-labelledby="kp-avatar-heading">
        <div class="kp-avatar-head">
            <h3 id="kp-avatar-heading"><i class="fas fa-wand-magic-sparkles"></i> Choose your anime avatar</h3>
            <button type="button" class="kp-avatar-close" id="kp-avatar-close" aria-label="Close">&times;</button>
        </div>

        <p class="kp-avatar-hint">
            Pick a character from the gallery — nothing to upload, and you can change it any time.
        </p>

        <div class="kp-avatar-grid" id="kp-avatar-grid" role="listbox" aria-label="Anime avatars">
            <?php foreach ($gallery as $avatar): ?>
                <button type="button" class="kp-avatar-choice<?= ($avatar['id'] === $avatarValue || $avatar['img'] === $avatarValue) ? ' is-selected' : '' ?>"
                        data-avatar-id="<?= htmlspecialchars($avatar['id'], ENT_QUOTES, 'UTF-8') ?>"
                        role="option"
                        title="<?= htmlspecialchars($avatar['name'] . ($avatar['from'] !== '' ? ' — ' . $avatar['from'] : ''), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= htmlspecialchars($avatar['img'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($avatar['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy"
                         onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
                    <span class="kp-avatar-name"><?= htmlspecialchars($avatar['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="kp-avatar-foot">
            <span class="kp-avatar-status" id="kp-avatar-status" role="status"></span>
            <button type="button" class="kp-avatar-reset" id="kp-avatar-reset">
                <i class="fas fa-rotate-left"></i> Default avatar
            </button>
        </div>
    </div>
</div>

<script src="./user/js/avatar-picker.js" defer></script>

<?php include 'includes/footer.php'; ?>