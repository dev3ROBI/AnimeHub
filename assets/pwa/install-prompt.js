/**
 * KitsuPlay install banner.
 *
 * Listens for beforeinstallprompt, injects the banner (styles from
 * assets/pwa/install-prompt.css, loaded on demand so pages that never see a
 * prompt pay nothing), and remembers dismissal forever in localStorage.
 * iOS Safari has no beforeinstallprompt — there the apple-touch-icon +
 * "Add to Home Screen" menu covers it, so silence is expected.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'kp-install-dismissed';
    var deferred = null;
    var banner = null;
    var cssRequested = false;

    // Resolve asset URLs against THIS script's location, not the document —
    // the same file is served on /, /watch.php and /admin/*.php pages.
    var BASE = './';
    if (document.currentScript && document.currentScript.src) {
        BASE = document.currentScript.src
            .replace(/assets\/pwa\/install-prompt\.js.*$/, '');
    }

    if (!('localStorage' in window)) return;

    function dismissed() {
        try { return localStorage.getItem(STORAGE_KEY) === '1'; }
        catch (e) { return false; }
    }

    function ensureCss() {
        if (cssRequested) return;
        cssRequested = true;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = BASE + 'assets/pwa/install-prompt.css';
        document.head.appendChild(link);
    }

    function dismiss(permanent) {
        if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
        banner = null;
        if (permanent) {
            try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
        }
    }

    function show() {
        if (banner || !deferred) return;
        ensureCss();

        banner = document.createElement('div');
        banner.className = 'kp-install-banner';
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-label', 'Install KitsuPlay');

        var text = document.createElement('div');
        text.className = 'kp-install-text';
        text.innerHTML = '<strong>Install KitsuPlay</strong>' +
            '<span>Add the app to your home screen for faster access</span>';

        var actions = document.createElement('div');
        actions.className = 'kp-install-actions';

        var installBtn = document.createElement('button');
        installBtn.type = 'button';
        installBtn.className = 'kp-install-btn kp-install-yes';
        installBtn.textContent = 'Install';
        installBtn.addEventListener('click', function () {
            if (!deferred) return;
            deferred.prompt();
            var done = deferred.userChoice || Promise.resolve(null);
            done.then(function () { deferred = null; dismiss(false); });
        });

        var laterBtn = document.createElement('button');
        laterBtn.type = 'button';
        laterBtn.className = 'kp-install-btn kp-install-no';
        laterBtn.textContent = 'Not now';
        laterBtn.addEventListener('click', function () { dismiss(false); });

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'kp-install-close';
        closeBtn.setAttribute('aria-label', 'Never show again');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', function () { dismiss(true); });

        actions.appendChild(installBtn);
        actions.appendChild(laterBtn);
        banner.appendChild(text);
        banner.appendChild(actions);
        banner.appendChild(closeBtn);
        document.body.appendChild(banner);
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        if (dismissed()) return;
        deferred = e;
        // Delay slightly so it never competes with first paint.
        setTimeout(show, 2500);
    });

    // Chrome fires this after a successful install (also via the button).
    window.addEventListener('appinstalled', function () { dismiss(false); });
})();
