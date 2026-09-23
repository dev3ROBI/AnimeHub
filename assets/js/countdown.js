/**
 * AnimeHub — release countdowns for locked/upcoming episodes.
 *
 * Contract: <span class="kp-cd" data-release="unix" data-done-text="...">
 *   • PHP (kp_countdown_chip) or JS (watch.php episodeElement) renders the chip
 *   • this file ticks every second while any chip is pending; idle (no
 *     interval at all) when none — watch.php re-arms via kpCountdownScan()
 *   • at zero → adds .is-live, swaps in data-done-text, dispatches one-shot
 *     document event `kp:released` with detail { el, release }
 *
 * fmt() must stay identical to kp_time_left() in includes/functions.php —
 * PHP paints the first frame, JS takes over from there.
 */
(function () {
    'use strict';

    var timer = null;

    function fmt(ms) {
        if (ms <= 0) return '';
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400);
        var h = Math.floor((s % 86400) / 3600);
        var m = Math.floor((s % 3600) / 60);
        if (d >= 1) return d + 'd ' + h + 'h';
        if (h >= 1) return h + 'h ' + m + 'm';
        if (m >= 1) return m + 'm';
        return s + 's';
    }

    function tick() {
        var nodes = document.querySelectorAll('.kp-cd[data-release]');
        var now = Date.now();
        var pending = 0;
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.getAttribute('data-done')) continue;
            var ts = parseInt(el.getAttribute('data-release'), 10);
            if (!ts) { el.setAttribute('data-done', '1'); continue; }
            var diff = ts * 1000 - now;
            if (diff <= 0) {
                el.setAttribute('data-done', '1');
                el.classList.add('is-live');
                el.textContent = el.getAttribute('data-done-text') || 'Airing now';
                try {
                    document.dispatchEvent(new CustomEvent('kp:released', { detail: { el: el, release: ts } }));
                } catch (err) { /* no CustomEvent → unlock-on-release simply won't fire */ }
            } else {
                pending++;
                var t = fmt(diff);
                if (el.textContent !== t) el.textContent = t;
            }
        }
        if (!pending && timer) {
            clearInterval(timer);
            timer = null; // idle until the next kpCountdownScan()
        }
    }

    /** Arm the ticker if new chips appeared (dynamic episode lists call this). */
    window.kpCountdownScan = function () {
        if (timer) return;
        tick(); // immediate paint — also unlocks rows whose release already passed
        var armed = document.querySelectorAll('.kp-cd[data-release]:not([data-done])').length;
        if (armed && !timer) timer = setInterval(tick, 1000);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.kpCountdownScan);
    } else {
        window.kpCountdownScan();
    }
})();