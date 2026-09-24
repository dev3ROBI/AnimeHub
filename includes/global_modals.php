<?php
/**
 * Global welcome + AdGuard modals — shared by every page (footer include).
 *
 * 1. Welcome modal: first-day users only (User_Join is today), once ever.
 * 2. AdGuard modal: every user/guest — once per calendar day (date-stamped
 *    localStorage), plus a permanent footer link that reopens the guide anytime.
 *
 * Both are bilingual (bn/en) with a pill toggle; choice persists in
 * localStorage['kp-lang']. Show order: welcome → (after close) adguard.
 */
$kp_gm_first_day = 0;
$kp_gm_user_name = trim((string)($_SESSION['userName'] ?? ''));
if (!empty($_SESSION['userID'])) {
    try {
        if (!isset($pdo)) include_once __DIR__ . '/db.php';
        if (!empty($pdo)) {
            $kp_stmt = $pdo->prepare('SELECT User_Join, User_Name FROM users WHERE User_ID = ? LIMIT 1');
            $kp_stmt->execute([(int)$_SESSION['userID']]);
            $kp_row = $kp_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($kp_row) {
                $kp_join_ts = strtotime((string)($kp_row['User_Join'] ?? ''));
                if ($kp_join_ts && date('Y-m-d', $kp_join_ts) === date('Y-m-d')) {
                    $kp_gm_first_day = 1;
                }
                if ($kp_gm_user_name === '') {
                    $kp_gm_user_name = trim((string)($kp_row['User_Name'] ?? ''));
                }
            }
        }
    } catch (Throwable $e) {
        $kp_gm_first_day = 0;
    }
}
?>
<!-- =================== Global modals: Welcome + AdGuard =================== -->
<div class="kp-gm-overlay" id="kpWelcomeModal" role="dialog" aria-modal="true"
     aria-hidden="true" aria-labelledby="kpWelcomeTitle" data-first-day="<?= $kp_gm_first_day ? '1' : '0' ?>">
    <div class="kp-gm-card kp-gm-welcome">
        <div class="kp-gm-head">
            <h3 class="kp-gm-head-title" id="kpWelcomeTitle">
                <i class="fas fa-hand-sparkles" aria-hidden="true"></i>
                <span data-i18n="w.title"></span>
            </h3>
            <div class="kp-gm-head-tools">
                <button type="button" class="kp-gm-lang" data-kp-gm-lang aria-label="Switch language">
                    <span data-kp-gm-lang-label>বাং</span>
                </button>
                <button type="button" class="kp-gm-close" data-kp-gm-close aria-label="Close">&times;</button>
            </div>
        </div>
        <div class="kp-gm-body">
            <div class="kp-gm-hero">
                <div class="kp-gm-icon kp-gm-icon-welcome" aria-hidden="true">
                    <i class="fas fa-hand-sparkles"></i>
                </div>
                <div class="kp-gm-confetti" aria-hidden="true">
                    <span></span><span></span><span></span><span></span>
                    <span></span><span></span><span></span><span></span>
                </div>
            </div>
            <p class="kp-gm-sub" data-i18n="w.sub" data-kp-gm-sub></p>
            <ul class="kp-gm-features">
                <li><span class="kp-gm-feat-ic"><i class="fas fa-bell"></i></span>
                    <span data-i18n="w.f1"></span></li>
                <li><span class="kp-gm-feat-ic"><i class="fas fa-list-check"></i></span>
                    <span data-i18n="w.f2"></span></li>
                <li><span class="kp-gm-feat-ic"><i class="fas fa-film"></i></span>
                    <span data-i18n="w.f3"></span></li>
            </ul>
            <div class="kp-gm-actions">
                <button type="button" class="kp-gm-btn kp-gm-btn-primary" data-kp-gm-close data-kp-gm-cta>
                    <span data-i18n="w.cta"></span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="kp-gm-overlay" id="kpAdguardModal" role="dialog" aria-modal="true"
     aria-hidden="true" aria-labelledby="kpAdguardTitle">
    <div class="kp-gm-card kp-gm-adguard">
        <div class="kp-gm-head">
            <h3 class="kp-gm-head-title" id="kpAdguardTitle">
                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                <span data-i18n="a.title"></span>
            </h3>
            <div class="kp-gm-head-tools">
                <button type="button" class="kp-gm-lang" data-kp-gm-lang aria-label="Switch language">
                    <span data-kp-gm-lang-label>বাং</span>
                </button>
                <button type="button" class="kp-gm-close" data-kp-gm-close aria-label="Close">&times;</button>
            </div>
        </div>
        <div class="kp-gm-body">
            <div class="kp-gm-hero">
                <div class="kp-gm-icon kp-gm-icon-shield" aria-hidden="true">
                    <i class="fas fa-shield-halved"></i>
                </div>
            </div>
            <p class="kp-gm-sub" data-i18n="a.sub"></p>
            <ol class="kp-gm-steps">
                <li><span class="kp-gm-step-n">1</span><span data-i18n="a.s1"></span></li>
                <li><span class="kp-gm-step-n">2</span><span data-i18n="a.s2"></span></li>
                <li><span class="kp-gm-step-n">3</span><span data-i18n="a.s3"></span></li>
            </ol>
            <div class="kp-gm-actions kp-gm-actions-split">
                <button type="button" class="kp-gm-btn kp-gm-btn-ghost" data-kp-gm-close>
                    <span data-i18n="a.later"></span>
                </button>
                <a class="kp-gm-btn kp-gm-btn-primary" href="https://adguard.com" target="_blank"
                   rel="noopener noreferrer" data-kp-gm-dismiss-link>
                    <span data-i18n="a.cta"></span>
                    <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Closed-state mirror — nav_style may still be loading on first paint. */
.kp-gm-overlay {
    position: fixed;
    inset: 0;
    z-index: 10002;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, 0.68);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.28s ease, visibility 0.28s ease;
}
.kp-gm-overlay.is-open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}
.kp-gm-card {
    position: relative;
    width: 100%;
    max-width: 420px;
    max-height: min(90vh, 640px);
    overflow-y: auto;
    padding: 0;
    border-radius: 16px;
    background: linear-gradient(145deg, #252525, #1e1e1e);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 46, 99, 0.1);
    font-family: 'Poppins', sans-serif;
    color: #fff;
    transform: translateY(16px) scale(0.96);
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.kp-gm-overlay.is-open .kp-gm-card { transform: translateY(0) scale(1); }
.kp-gm-card::before {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 2px;
    border-radius: 16px 16px 0 0;
    background: linear-gradient(90deg, #7c3aed, #ec4899, #ff2e63);
    opacity: 0.9;
}
.kp-gm-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px 14px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.kp-gm-head-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    min-width: 0;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
}
.kp-gm-head-title i { color: #ff2e63; flex-shrink: 0; }
.kp-gm-head-title span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.kp-gm-head-tools {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.kp-gm-body { padding: 24px 28px 28px; }
.kp-gm-lang {
    min-width: 44px;
    height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    background: rgba(255, 255, 255, 0.07);
    color: #ddd;
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.kp-gm-lang:hover {
    background: rgba(255, 46, 99, 0.18);
    border-color: rgba(255, 46, 99, 0.45);
    color: #fff;
}
.kp-gm-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.07);
    color: #ccc;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    display: grid;
    place-items: center;
    transition: background 0.2s ease, color 0.2s ease;
}
.kp-gm-close:hover {
    background: #ff2e63;
    color: #fff;
}
.kp-gm-hero {
    position: relative;
    display: flex;
    justify-content: center;
    margin-bottom: 16px;
}
.kp-gm-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-size: 30px;
    color: #fff;
    box-shadow: 0 10px 28px rgba(255, 46, 99, 0.35);
    animation: kp-gm-pop 0.5s cubic-bezier(0.34, 1.4, 0.64, 1) both;
}
.kp-gm-icon-welcome { background: linear-gradient(135deg, #ff2e63, #c9184a); }
.kp-gm-icon-shield {
    background: linear-gradient(135deg, #7c3aed, #ec4899);
    box-shadow: 0 10px 28px rgba(124, 58, 237, 0.4);
}
@keyframes kp-gm-pop {
    from { transform: scale(0.4); opacity: 0; }
    to   { transform: scale(1); opacity: 1; }
}
.kp-gm-confetti span {
    position: absolute;
    width: 8px;
    height: 8px;
    border-radius: 2px;
    opacity: 0.85;
    animation: kp-gm-float 2.4s ease-in-out infinite;
}
.kp-gm-confetti span:nth-child(1) { background: #ff2e63; top: -6px; left: 22%; animation-delay: 0s; }
.kp-gm-confetti span:nth-child(2) { background: #ff7700; top: 8px; left: 8%;  animation-delay: 0.3s; border-radius: 50%; }
.kp-gm-confetti span:nth-child(3) { background: #7c3aed; top: -4px; right: 20%; animation-delay: 0.6s; }
.kp-gm-confetti span:nth-child(4) { background: #ec4899; top: 14px; right: 6%; animation-delay: 0.9s; border-radius: 50%; }
.kp-gm-confetti span:nth-child(5) { background: #4ade80; top: 28px; left: 4%;  animation-delay: 1.2s; }
.kp-gm-confetti span:nth-child(6) { background: #60a5fa; top: 30px; right: 4%; animation-delay: 1.5s; border-radius: 50%; }
.kp-gm-confetti span:nth-child(7) { background: #ffb020; top: -10px; left: 48%; animation-delay: 0.4s; }
.kp-gm-confetti span:nth-child(8) { background: #ff2e63; top: 40px; left: 40%; animation-delay: 1.8s; border-radius: 50%; width: 6px; height: 6px; }
@keyframes kp-gm-float {
    0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.7; }
    50%      { transform: translateY(-8px) rotate(18deg); opacity: 1; }
}
.kp-gm-title {
    margin: 0 0 8px;
    text-align: center;
    font-size: 20px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -0.01em;
}
.kp-gm-sub {
    margin: 0 0 18px;
    text-align: center;
    font-size: 13.5px;
    line-height: 1.55;
    color: #a8a8b8;
}
.kp-gm-features {
    list-style: none;
    margin: 0 0 22px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.kp-gm-features li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 13px;
    line-height: 1.4;
    color: #d4d4e0;
    transition: background 0.2s, border-color 0.2s;
}
.kp-gm-features li:hover {
    background: rgba(255, 46, 99, 0.08);
    border-color: rgba(255, 46, 99, 0.2);
}
.kp-gm-feat-ic {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, rgba(255, 46, 99, 0.2), rgba(201, 24, 74, 0.15));
    color: #ff2e63;
    font-size: 14px;
}
.kp-gm-steps {
    list-style: none;
    margin: 0 0 22px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
    counter-reset: none;
}
.kp-gm-steps li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 11px 14px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 13px;
    line-height: 1.45;
    color: #d4d4e0;
}
.kp-gm-step-n {
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #7c3aed, #ec4899);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
.kp-gm-actions {
    display: flex;
    gap: 10px;
}
.kp-gm-actions-split { flex-direction: row; }
.kp-gm-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 18px;
    border-radius: 12px;
    border: none;
    font-family: 'Poppins', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.15s, box-shadow 0.2s, background 0.2s;
}
.kp-gm-btn:hover { transform: translateY(-1px); }
.kp-gm-btn:active { transform: translateY(0); }
.kp-gm-btn-primary {
    background: linear-gradient(135deg, #ff2e63, #c9184a);
    color: #fff;
    box-shadow: 0 4px 16px rgba(255, 46, 99, 0.35);
}
.kp-gm-btn-primary:hover {
    box-shadow: 0 6px 22px rgba(255, 46, 99, 0.5);
}
.kp-gm-btn-ghost {
    background: rgba(255, 255, 255, 0.07);
    color: #bbb;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.kp-gm-btn-ghost:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
}
@media (max-width: 420px) {
    .kp-gm-body { padding: 20px 18px 22px; }
    .kp-gm-head { padding: 12px 12px 12px 16px; }
    .kp-gm-head-title { font-size: 15px; }
    .kp-gm-actions-split { flex-direction: column-reverse; }
}
body.kp-gm-noscroll { overflow: hidden; }
</style>

<script>
(function () {
    'use strict';

    var LANG_KEY = 'kp-lang';
    var WELCOME_KEY = 'kp-welcome-seen';
    // AdGuard: stores YYYY-MM-DD of last show → auto-reappears next day.
    var ADGUARD_KEY = 'kp-adguard-seen';
    var WELCOME_DELAY = 1400;
    var ADGUARD_DELAY = 1800;
    var ADGUARD_AFTER_WELCOME = 900;

    function todayStamp() {
        var d = new Date();
        var m = String(d.getMonth() + 1);
        var day = String(d.getDate());
        return d.getFullYear() + '-' + (m.length < 2 ? '0' + m : m) + '-' + (day.length < 2 ? '0' + day : day);
    }

    // Welcome: ever. AdGuard: only if last-seen is not today (daily reset).
    function welcomeSeen() {
        try { return localStorage.getItem(WELCOME_KEY) === '1'; } catch (e) { return false; }
    }
    function markWelcomeSeen() {
        try { localStorage.setItem(WELCOME_KEY, '1'); } catch (e) {}
    }
    function adguardSeenToday() {
        try { return localStorage.getItem(ADGUARD_KEY) === todayStamp(); } catch (e) { return false; }
    }
    function markAdguardToday() {
        try { localStorage.setItem(ADGUARD_KEY, todayStamp()); } catch (e) {}
    }

    var STR = {
        en: {
            'w.title': 'Welcome to KitsuPlay!',
            'w.sub': 'Hey {name}, your anime journey starts right here.',
            'w.subNoname': 'Your anime journey starts right here.',
            'w.f1': 'Follow shows & get instant new-episode alerts',
            'w.f2': 'Build your personal watch list',
            'w.f3': 'Anime, movies & TV — all in one place',
            'w.cta': 'Start exploring',
            'a.title': 'Stream without interruptions',
            'a.sub': 'Turn on AdGuard’s DNS / proxy to block ads & trackers while you watch.',
            'a.s1': 'Install the AdGuard app or browser extension',
            'a.s2': 'Enable DNS filtering (or the system proxy)',
            'a.s3': 'Enjoy KitsuPlay ad-free',
            'a.cta': 'Get AdGuard',
            'a.later': 'Maybe later',
            'footer.adfree': 'How to block ads'
        },
        bn: {
            'w.title': 'KitsuPlay-এ স্বাগতম!',
            'w.sub': 'হ্যাই {name}, আপনার অ্যানিমে যাত্রা শুরু হোক এখানেই।',
            'w.subNoname': 'আপনার অ্যানিমে যাত্রা শুরু হোক এখানেই।',
            'w.f1': 'সিরিজ ফলো করুন, নতুন এপিসোডের অ্যালার্ট পান',
            'w.f2': 'নিজের ওয়াচলিস্ট তৈরি করুন',
            'w.f3': 'অ্যানিমে, মুভি ও টিভি — সব এক জায়গায়',
            'w.cta': 'শুরু করুন',
            'a.title': 'বিজ্ঞাপন ছাড়া স্ট্রিম করুন',
            'a.sub': 'দেখার সময় অ্যাড ও ট্র্যাকার ব্লক করতে AdGuard-এর DNS / প্রক্সি চালু করুন।',
            'a.s1': 'AdGuard অ্যাপ বা ব্রাউজার এক্সটেনশন ইনস্টল করুন',
            'a.s2': 'DNS ফিল্টারিং (অথবা সিস্টেম প্রক্সি) চালু করুন',
            'a.s3': 'বিজ্ঞাপনমুক্ত KitsuPlay উপভোগ করুন',
            'a.cta': 'AdGuard নিন',
            'a.later': 'পরে দেখব',
            'footer.adfree': 'কিভাবে বিজ্ঞাপন বন্ধ করবেন'
        }
    };

    var userName = <?= json_encode($kp_gm_user_name, JSON_UNESCAPED_UNICODE) ?>;
    var isFirstDay = <?= $kp_gm_first_day ? '1' : '0' ?>;
    var lang = 'en';
    var lastFocus = null;
    var activeModal = null;
    var queue = [];

    try {
        var stored = localStorage.getItem(LANG_KEY);
        if (stored === 'bn' || stored === 'en') lang = stored;
    } catch (e) {}

    function t(key) {
        var table = STR[lang] || STR.en;
        var s = table[key];
        if (s == null) s = STR.en[key] || key;
        if (key === 'w.sub') {
            if (userName) return s.replace('{name}', userName);
            return (STR[lang] || STR.en)['w.subNoname'];
        }
        return s;
    }

    function lsGet(k) {
        try { return localStorage.getItem(k) === '1'; } catch (e) { return false; }
    }
    function lsSet(k) {
        try { localStorage.setItem(k, '1'); } catch (e) {}
    }

    function applyLang(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });
        scope.querySelectorAll('[data-kp-gm-lang-label]').forEach(function (el) {
            el.textContent = lang === 'bn' ? 'EN' : 'বাং';
        });
        scope.querySelectorAll('[data-kp-gm-lang]').forEach(function (el) {
            el.setAttribute('aria-label', lang === 'bn' ? 'Switch to English' : 'বাংলায় দেখুন');
        });
    }

    function applyAllLang() { applyLang(document); }

    function lockScroll(on) {
        document.body.classList.toggle('kp-gm-noscroll', !!on);
    }

    function openModal(el) {
        if (!el || activeModal) return;
        lastFocus = document.activeElement;
        activeModal = el;
        applyLang(el);
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
        lockScroll(true);
        var focusTarget = el.querySelector('.kp-gm-btn-primary, [data-kp-gm-close]');
        if (focusTarget) setTimeout(function () { focusTarget.focus(); }, 60);
    }

    function closeModal(el, mark) {
        if (!el) return;
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
        if (mark === 'welcome') markWelcomeSeen();
        else if (mark === 'adguard') markAdguardToday();
        if (activeModal === el) {
            activeModal = null;
            lockScroll(false);
            if (lastFocus && lastFocus.focus) {
                try { lastFocus.focus(); } catch (e) {}
            }
            lastFocus = null;
            drainQueue();
        }
    }

    function enqueue(fn) { queue.push(fn); drainQueue(); }

    function drainQueue() {
        if (activeModal || !queue.length) return;
        var next = queue.shift();
        if (typeof next === 'function') next();
    }

    function showWelcome() {
        openModal(document.getElementById('kpWelcomeModal'));
    }

    function showAdguard() {
        openModal(document.getElementById('kpAdguardModal'));
    }

    function scheduleWelcome() {
        if (!isFirstDay || welcomeSeen()) {
            scheduleAdguard(ADGUARD_DELAY);
            return;
        }
        setTimeout(function () {
            enqueue(function () {
                if (activeModal) { queue.unshift(function () { openModal(document.getElementById('kpWelcomeModal')); }); return; }
                showWelcome();
            });
        }, WELCOME_DELAY);
    }

    function scheduleAdguard(delay) {
        if (adguardSeenToday()) return;
        setTimeout(function () {
            enqueue(function () {
                if (adguardSeenToday()) return;
                if (activeModal) {
                    queue.unshift(function () {
                        if (!adguardSeenToday()) openModal(document.getElementById('kpAdguardModal'));
                    });
                    return;
                }
                showAdguard();
            });
        }, delay);
    }

    function onWelcomeClosed() {
        closeModal(document.getElementById('kpWelcomeModal'), 'welcome');
        scheduleAdguard(ADGUARD_AFTER_WELCOME);
    }

    // Wire close buttons
    document.querySelectorAll('#kpWelcomeModal [data-kp-gm-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { onWelcomeClosed(); });
    });
    document.querySelectorAll('#kpAdguardModal [data-kp-gm-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(document.getElementById('kpAdguardModal'), 'adguard');
        });
    });
    // CTA link also marks today's show done
    document.querySelectorAll('#kpAdguardModal [data-kp-gm-dismiss-link]').forEach(function (a) {
        a.addEventListener('click', function () {
            markAdguardToday();
            setTimeout(function () {
                closeModal(document.getElementById('kpAdguardModal'), null);
            }, 200);
        });
    });

    // Backdrop click
    ['kpWelcomeModal', 'kpAdguardModal'].forEach(function (id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.addEventListener('click', function (e) {
            if (e.target !== m) return;
            if (id === 'kpWelcomeModal') onWelcomeClosed();
            else closeModal(m, 'adguard');
        });
    });

    // Language toggles
    document.querySelectorAll('[data-kp-gm-lang]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            lang = lang === 'bn' ? 'en' : 'bn';
            try { localStorage.setItem(LANG_KEY, lang); } catch (e) {}
            document.documentElement.lang = lang === 'bn' ? 'bn' : 'en';
            applyAllLang();
        });
    });

    // Escape
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !activeModal) return;
        if (activeModal.id === 'kpWelcomeModal') onWelcomeClosed();
        else if (activeModal.id === 'kpAdguardModal') closeModal(activeModal, 'adguard');
        else closeModal(activeModal, null);
    });

    // Focus trap
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || !activeModal) return;
        var focusables = activeModal.querySelectorAll(
            'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
        );
        if (!focusables.length) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    // Apply saved language immediately (before any modal opens)
    applyAllLang();
    document.documentElement.lang = lang === 'bn' ? 'bn' : 'en';

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleWelcome);
    } else {
        scheduleWelcome();
    }

    // Expose for debugging / manual reopen / footer permanent link
    window.kpGmOpenWelcome = function () {
        if (!welcomeSeen() || isFirstDay) openModal(document.getElementById('kpWelcomeModal'));
    };
    window.kpGmOpenAdguard = function () { openModal(document.getElementById('kpAdguardModal')); };

    // Footer "How to block ads" permanent entry — always opens the guide
    // without waiting for the daily timer, and does NOT burn the daily slot
    // until the user actually closes it via a dismiss path.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest && e.target.closest('[data-kp-open-adguard]');
        if (!trigger) return;
        e.preventDefault();
        if (activeModal) return;
        openModal(document.getElementById('kpAdguardModal'));
    });
})();
</script>
<!-- =================== /Global modals =================== -->
