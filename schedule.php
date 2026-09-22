<?php
session_start();

if (!isset($_SESSION['userID'])) {
    header("Location: authentication.php");
    exit();
}

include 'includes/db.php';
include_once 'includes/functions.php';
include 'includes/header.php';

$tz = 'Asia/Dhaka';
$week = isset($_GET['week']) ? intval($_GET['week']) : 0;
$week = max(-4, min(4, $week));

$buckets = catalog_schedule($tz, $week);

$dayNameToIndex = [
    'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
    'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
];
$sortable = [];
foreach ($buckets as $b) {
    $d = $b['day'] ?? null;
    if ($d === null) $idx = 99;
    elseif (isset($dayNameToIndex[$d])) $idx = $dayNameToIndex[$d];
    elseif (is_numeric($d)) $idx = intval($d);
    else $idx = 98;
    $sortable[] = [$idx, $b];
}
usort($sortable, fn($a, $b) => $a[0] <=> $b[0]);

$totalEps = 0;
foreach ($sortable as $s) $totalEps += count($s[1]['episodes'] ?? []);

$weekLabel = 'This Week';
if ($week === -1) $weekLabel = 'Last Week';
elseif ($week === 1) $weekLabel = 'Next Week';
elseif ($week < 0) $weekLabel = abs($week) . ' weeks ago';
elseif ($week > 0) $weekLabel = $week . ' weeks ahead';

// Today info
$now = new DateTime('now', new DateTimeZone($tz));
$todayName = $now->format('l');
$todayDate = $now->format('M d, Y');

// Build day tabs data
$dayAbbrMap = ['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'];
$dayTabs = [];
foreach ($sortable as $entry) {
    $bucket = $entry[1];
    $day = $bucket['day'] ?? null;
    $count = count($bucket['episodes'] ?? []);
    if ($day && $count > 0) {
        $dayTabs[] = ['name' => $day, 'abbr' => $dayAbbrMap[$day] ?? substr($day, 0, 3), 'count' => $count, 'is_today' => $day === $todayName];
    }
}

// Episodes for today
$todayCount = 0;
foreach ($sortable as $entry) {
    if (($entry[1]['day'] ?? '') === $todayName) {
        $todayCount = count($entry[1]['episodes'] ?? []);
        break;
    }
}
?>

<div class="sched-page">

    <!-- Hero Header -->
    <div class="sched-hero">
        <div class="sched-hero-bg">
            <div class="sched-hero-circle sched-hero-circle-1"></div>
            <div class="sched-hero-circle sched-hero-circle-2"></div>
        </div>
        <div class="sched-hero-content">
            <div class="sched-hero-icon">
                <i class="fas fa-calendar-week"></i>
            </div>
            <h1>Weekly Schedule</h1>
            <p class="sched-hero-sub"><?= kp_e($todayDate) ?> · <?= kp_e($tz) ?></p>

            <div class="sched-week-nav">
                <a href="?week=<?= $week - 1 ?>" class="sched-nav-btn <?= $week <= -4 ? 'disabled' : '' ?>" <?= $week <= -4 ? 'aria-disabled="true"' : '' ?>>
                    <i class="fas fa-chevron-left"></i>
                </a>
                <div class="sched-week-label">
                    <span class="sched-week-text"><?= kp_e($weekLabel) ?></span>
                    <?php if ($week === 0): ?>
                        <span class="sched-week-badge">Current</span>
                    <?php endif; ?>
                </div>
                <a href="?week=<?= $week + 1 ?>" class="sched-nav-btn <?= $week >= 4 ? 'disabled' : '' ?>" <?= $week >= 4 ? 'aria-disabled="true"' : '' ?>>
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="sched-stats">
        <div class="sched-stat">
            <div class="sched-stat-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="sched-stat-info">
                <span class="sched-stat-num"><?= $todayCount ?></span>
                <span class="sched-stat-label">Airing Today</span>
            </div>
        </div>
        <div class="sched-stat">
            <div class="sched-stat-icon"><i class="fas fa-film"></i></div>
            <div class="sched-stat-info">
                <span class="sched-stat-num"><?= $totalEps ?></span>
                <span class="sched-stat-label">This Week</span>
            </div>
        </div>
        <div class="sched-stat">
            <div class="sched-stat-icon"><i class="fas fa-layer-group"></i></div>
            <div class="sched-stat-info">
                <span class="sched-stat-num"><?= count($sortable) ?></span>
                <span class="sched-stat-label">Active Days</span>
            </div>
        </div>
    </div>

    <!-- Day Tabs -->
    <?php if (!empty($dayTabs)): ?>
    <div class="sched-day-tabs" id="schedDayTabs">
        <?php foreach ($dayTabs as $tab): ?>
            <a href="#day-<?= strtolower($tab['name']) ?>" class="sched-day-tab <?= $tab['is_today'] ? 'active' : '' ?>" data-day="<?= kp_e($tab['name']) ?>">
                <span class="sched-day-abbr"><?= kp_e($tab['abbr']) ?></span>
                <span class="sched-day-count"><?= $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Schedule Content -->
    <?php if (empty($sortable)): ?>
        <div class="sched-empty">
            <div class="sched-empty-icon">
                <i class="fas fa-calendar-xmark"></i>
            </div>
            <h3>No Episodes Scheduled</h3>
            <p>No schedule data available for <?= kp_e($weekLabel) ?>. The primary provider may be unavailable. Try again later.</p>
        </div>
    <?php else: ?>
        <div class="sched-days">
            <?php foreach ($sortable as $entry): ?>
                <?php
                $bucket = $entry[1];
                $day = $bucket['day'] ?? 'Unknown day';
                $date = $bucket['date'] ?? null;
                $episodes = is_array($bucket['episodes'] ?? null) ? $bucket['episodes'] : [];
                if (!$episodes) continue;

                $isToday = $day === $todayName;
                ?>
                <div class="sched-day-section" id="day-<?= strtolower($day) ?>">
                    <div class="sched-day-header <?= $isToday ? 'today' : '' ?>">
                        <div class="sched-day-left">
                            <?php if ($isToday): ?>
                                <span class="sched-today-badge">TODAY</span>
                            <?php endif; ?>
                            <div class="sched-day-name">
                                <i class="fas fa-calendar-day"></i>
                                <span><?= kp_e($day) ?></span>
                            </div>
                            <?php if ($date): ?>
                                <span class="sched-day-date"><?= kp_e($date) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="sched-ep-count"><?= count($episodes) ?> ep<?= count($episodes) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="show-item-con">
                        <?php foreach ($episodes as $ep): ?>
                            <?= render_anime_card($ep, [
                                'show_ep_badge' => true,
                                'show_time'     => true,
                                'link_episode'  => $ep['episode'] ?? null,
                            ]) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sched-footer">
            <i class="fas fa-info-circle"></i>
            <?= (int)$totalEps ?> episodes across <?= count($sortable) ?> day(s) · <?= kp_e($tz) ?>
        </div>
    <?php endif; ?>

</div>

<script>
(function() {
    // Scroll to today on load
    var todaySection = document.querySelector('.sched-day-section .today');
    if (todaySection) {
        var section = todaySection.closest('.sched-day-section');
        if (section) {
            setTimeout(function() {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }
    }

    // Day tabs smooth scroll
    document.querySelectorAll('.sched-day-tab').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.querySelectorAll('.sched-day-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
            }
        });
    });

    // Highlight tab on scroll
    var sections = document.querySelectorAll('.sched-day-section');
    var tabs = document.querySelectorAll('.sched-day-tab');
    if (sections.length && tabs.length) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var day = entry.target.id.replace('day-', '');
                    tabs.forEach(function(t) {
                        t.classList.toggle('active', t.getAttribute('data-day').toLowerCase() === day);
                    });
                }
            });
        }, { rootMargin: '-20% 0px -70% 0px' });
        sections.forEach(function(s) { observer.observe(s); });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
