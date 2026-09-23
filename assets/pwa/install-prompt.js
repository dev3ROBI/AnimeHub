/**
 * KitsuPlay PWA install flow.
 *
 *   beforeinstallprompt  → a small banner. A dismissal is remembered forever in
 *                          localStorage.
 *   kpConfirmInstall()   → the confirmation dialog. Both the footer's
 *                          "Install App" button and the banner's Install button
 *                          open it. Install runs the native prompt, or swaps in
 *                          per-platform manual steps when the browser has none
 *                          (iOS Safari never fires beforeinstallprompt);
 *                          "Not now" and the × close it.
 *   kpRequestInstall()   → the native prompt alone, for callers that already
 *                          asked the user (returns false when unavailable).
 *
 * Styles live in install-prompt.css. The footer links that sheet with the page
 * (lazy, so it stays off the critical path); a page without the footer gets it
 * injected here. Either way the banner and the dialog are built with
 * `display: none` and only revealed once the sheet is actually applied —
 * opening first and styling later showed a bare pile of unstyled text at the
 * bottom of the document, which is exactly what the link is there to avoid.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'kp-install-dismissed';
    var deferred = null;      // captured beforeinstallprompt event
    var banner = null;
    var modal = null;
    var modalCard = null;
    var modalClose = null;
    var openToken = 0;        // invalidates a pending reveal after a close
    var lastFocus = null;

    // Resolve asset URLs against THIS script's location, not the document —
    // the same file is served on /, /watch.php and /admin/*.php pages.
    var BASE = resolveBase();

    function resolveBase() {
        var src = document.currentScript && document.currentScript.src;

        if (!src) {
            var scripts = document.getElementsByTagName('script');
            for (var i = 0; i < scripts.length; i++) {
                if (/install-prompt(\.min)?\.js/i.test(scripts[i].src || '')) {
                    src = scripts[i].src;
                    break;
                }
            }
        }
        if (!src) return './';

        // kp_asset() usually serves install-prompt.min.js?v=<mtime>-<size>, so
        // strip the file name plus any query instead of assuming the source
        // build — a mismatched BASE silently 404s the stylesheet.
        var base = src.replace(/assets\/pwa\/install-prompt(\.min)?\.js.*$/i, '');
        return base === src ? './' : base;
    }

    /* ---- Stylesheet ------------------------------------------------------ */

    function cssLink() {
        return document.querySelector('link[href*="install-prompt.css"]');
    }

    function ensureCss() {
        if (cssLink()) return;

        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id = 'kp-css-install-prompt';
        link.setAttribute('data-kp-lazy', '1');
        link.href = BASE + 'assets/pwa/install-prompt.css';
        // Same async pattern as the header sheets: print until it lands, so it
        // never blocks the page. header.php's guard can retry this tag too.
        link.media = 'print';
        link.onload = function () {
            link.media = 'all';
            link.setAttribute('data-kp-ready', '1');
        };
        link.onerror = function () {
            if (typeof window.kpCssRetry === 'function') window.kpCssRetry(link);
            // Deliberately left print-only: an unstyled dialog is worse than none.
        };
        document.head.appendChild(link);
    }

    /*
     * Is the stylesheet actually in effect? `link.sheet` is NOT enough: Chrome
     * still creates an (empty) sheet for a same-origin URL it refuses to apply
     * — a 403/404 page, or a wrong MIME type — so a presence check would open
     * the dialog at zero rules, i.e. unstyled. Confirming that at least one of
     * our selectors made it into the sheet is the real signal.
     */
    function sheetApplied(link) {
        if (!link || !link.sheet) return false;
        if (link.getAttribute('data-kp-ready') === '1') return true;   // its onload ran

        try {
            var rules = link.sheet.cssRules;
            for (var i = 0; i < rules.length; i++) {
                if ((rules[i].selectorText || '').indexOf('kp-install-') !== -1) return true;
            }
            return false;
        } catch (e) {
            return false;   // cross-origin sheet: the load event decides instead
        }
    }

    // cb(true) once the sheet is applied, cb(false) if it never arrives.
    function whenStylesReady(cb) {
        var link = cssLink();
        if (sheetApplied(link)) {
            cb(true);
            return;
        }

        var settled = false;
        function done(ok) {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            cb(ok);
        }
        var timer = setTimeout(function () { done(sheetApplied(link)); }, 1500);

        if (link) {
            link.addEventListener('load', function () { done(true); });
            link.addEventListener('error', function () { done(false); });
        }
    }

    // Last resort when the sheet cannot load: the browser's own menu path.
    function guidanceToast() {
        if (typeof window.kpToast !== 'function') return;
        var steps = platformSteps();
        window.kpToast(steps.title + ': ' + steps.steps[0] + '. ' + steps.steps[1], 'info');
    }

    function dismissed() {
        try { return localStorage.getItem(STORAGE_KEY) === '1'; }
        catch (e) { return false; }
    }

    function rememberDismissal() {
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
    }

    /* ---- Native prompt -------------------------------------------------- */

    // True when the native prompt was shown, false when the browser has none
    // (iOS → caller shows manual guidance instead).
    function requestInstall() {
        if (!deferred) return false;
        deferred.prompt();
        var done = deferred.userChoice || Promise.resolve(null);
        done.then(function () { deferred = null; dismiss(false); });
        return true;
    }

    window.kpRequestInstall = requestInstall;

    /* ---- Banner --------------------------------------------------------- */

    function dismiss(permanent) {
        if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
        banner = null;
        if (permanent) rememberDismissal();
    }

    function show() {
        if (banner || !deferred) return;
        ensureCss();

        banner = document.createElement('div');
        banner.className = 'kp-install-banner';
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-label', 'Install KitsuPlay');
        banner.style.display = 'none';   // revealed below, once styled

        var icon = document.createElement('div');
        icon.className = 'kp-install-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>';

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
        // Goes through the confirmation dialog, like the footer button.
        installBtn.addEventListener('click', function () { openModal(); });

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
        banner.appendChild(icon);
        banner.appendChild(text);
        banner.appendChild(actions);
        banner.appendChild(closeBtn);
        document.body.appendChild(banner);

        whenStylesReady(function (ok) {
            if (!banner) return;
            if (!ok) { dismiss(false); return; }
            banner.style.display = '';
        });
    }

    /* ---- Confirmation dialog -------------------------------------------- */

    var MODAL_ICON = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>';

    function perk(text) {
        return '<li><i class="fas fa-check" aria-hidden="true"></i><span>' + text + '</span></li>';
    }

    function confirmHtml() {
        return '<div class="kp-install-modal-badge" aria-hidden="true">' + MODAL_ICON + '</div>' +
            '<h3 id="kpInstallTitle">Install KitsuPlay?</h3>' +
            '<p class="kp-install-modal-sub">Add the app to this device for a faster, full-screen experience. You can remove it again whenever you like.</p>' +
            '<ul class="kp-install-perks">' +
                perk('Opens from your home screen in one tap') +
                perk('Keeps working offline with the cached shell') +
                perk('Full screen &mdash; no browser bars in the way') +
            '</ul>' +
            '<div class="kp-install-modal-actions">' +
                '<button type="button" class="kp-install-btn kp-install-no" data-kp-cancel>Not now</button>' +
                '<button type="button" class="kp-install-btn kp-install-yes" data-kp-confirm>Install</button>' +
            '</div>';
    }

    // Copy is ours (no user input), so building it as HTML is safe.
    function platformSteps() {
        var ua = navigator.userAgent || '';
        var iOS = /iPad|iPhone|iPod/.test(ua) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        if (iOS) {
            return {
                title: 'Add KitsuPlay to your Home Screen',
                note: 'Safari does not let sites install themselves on iOS, but it takes three taps:',
                steps: [
                    'Tap the Share button in the Safari toolbar',
                    'Scroll down and choose "Add to Home Screen"',
                    'Tap "Add" &mdash; KitsuPlay appears with your other apps'
                ]
            };
        }
        if (/Android/i.test(ua)) {
            return {
                title: 'Install KitsuPlay from Chrome',
                note: 'Chrome can install KitsuPlay in two taps:',
                steps: [
                    'Open the three-dot menu in the top-right corner',
                    'Choose "Install app" or "Add to Home screen"',
                    'Confirm with "Install"'
                ]
            };
        }
        return {
            title: 'Install KitsuPlay from your browser',
            note: 'This browser has no automatic install prompt, so do it from its menu:',
            steps: [
                'Look for the install icon in the address bar',
                'Or open the browser menu and pick "Install KitsuPlay"',
                'Confirm to finish &mdash; the app shows up with your other apps'
            ]
        };
    }

    function stepsHtml() {
        var info = platformSteps();
        var items = '';
        for (var i = 0; i < info.steps.length; i++) {
            items += '<li><span>' + info.steps[i] + '</span></li>';
        }
        return '<div class="kp-install-modal-badge is-info" aria-hidden="true"><i class="fas fa-circle-info"></i></div>' +
            '<h3 id="kpInstallTitle">' + info.title + '</h3>' +
            '<p class="kp-install-modal-sub">' + info.note + '</p>' +
            '<ol class="kp-install-steps">' + items + '</ol>' +
            '<div class="kp-install-modal-actions">' +
                '<button type="button" class="kp-install-btn kp-install-yes" data-kp-cancel>Got it</button>' +
            '</div>';
    }

    function buildModal() {
        if (modal) return;

        modal = document.createElement('div');
        modal.className = 'kp-install-overlay';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'kpInstallTitle');
        // Hidden inline so it can never paint unstyled, whatever the sheet does.
        modal.style.display = 'none';
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();   // backdrop click
        });

        modalCard = document.createElement('div');
        modalCard.className = 'kp-install-modal';

        modalClose = document.createElement('button');
        modalClose.type = 'button';
        modalClose.className = 'kp-install-close kp-install-modal-close';
        modalClose.setAttribute('aria-label', 'Close');
        modalClose.innerHTML = '&times;';
        modalClose.addEventListener('click', closeModal);

        modalCard.appendChild(modalClose);
        modal.appendChild(modalCard);
        document.body.appendChild(modal);
    }

    function setCard(html) {
        modalCard.innerHTML = html;
        modalCard.appendChild(modalClose);   // innerHTML wiped it

        var yes = modalCard.querySelector('[data-kp-confirm]');
        if (yes) yes.addEventListener('click', onConfirm);

        var no = modalCard.querySelector('[data-kp-cancel]');
        if (no) no.addEventListener('click', closeModal);
    }

    function openModal() {
        ensureCss();
        buildModal();
        setCard(confirmHtml());

        lastFocus = document.activeElement;
        var token = ++openToken;

        whenStylesReady(function (ok) {
            if (token !== openToken) return;          // closed, or reopened since
            if (!ok) { guidanceToast(); return; }     // never show it unstyled

            modal.style.display = '';                 // stylesheet drives it now
            modal.classList.add('is-open');
            document.body.classList.add('kp-install-noscroll');
            document.addEventListener('keydown', onModalKeydown);

            var primary = modalCard.querySelector('[data-kp-confirm]');
            if (primary) primary.focus();
        });
    }

    function closeModal() {
        if (!modal) return;
        openToken++;                                  // cancel a pending reveal
        modal.classList.remove('is-open');
        modal.style.display = 'none';
        document.body.classList.remove('kp-install-noscroll');
        document.removeEventListener('keydown', onModalKeydown);
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        lastFocus = null;
    }

    function onConfirm() {
        if (deferred) {
            closeModal();
            requestInstall();
            return;
        }
        // No native prompt: show how to install by hand instead of dead-ending.
        setCard(stepsHtml());
        var got = modalCard.querySelector('[data-kp-cancel]');
        if (got) got.focus();
    }

    function onModalKeydown(e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            closeModal();
            return;
        }
        if (e.key !== 'Tab' || !modal) return;

        // Keep focus inside the dialog while it is open.
        var focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) return;

        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    // Gate for the footer button: idempotent (opens the dialog, or re-uses the
    // one already open) and safe on every page.
    window.kpConfirmInstall = function () {
        openModal();
        return true;
    };

    /* ---- Wiring --------------------------------------------------------- */

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        // Capture the event even after a permanent dismissal: the footer CTA
        // should still be able to run the native prompt, it just no longer
        // gets the auto-shown banner.
        deferred = e;
        if (dismissed()) return;
        // Delay slightly so it never competes with first paint.
        setTimeout(show, 2500);
    });

    // Chrome fires this after a successful install (also via our buttons).
    window.addEventListener('appinstalled', function () {
        dismiss(false);
        closeModal();
        if (typeof window.kpToast === 'function') {
            window.kpToast('KitsuPlay installed — open it from your home screen', 'success');
        }
    });
})();
