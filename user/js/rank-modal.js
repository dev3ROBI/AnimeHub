/**
 * Rank ladder modal.
 *
 * Loaded by profile.php (the root page) rather than by the profile tab, because
 * scripts inside a fragment injected with innerHTML never execute.
 * Mirrors the open/close pattern used by avatar-picker.js.
 */
(function () {
    if (window.__kpRankModalLoaded) return;
    window.__kpRankModalLoaded = true;

    let modal = document.getElementById('kp-rank-modal');
    if (!modal) return;

    // A transformed ancestor becomes the containing block for `position: fixed`;
    // re-parent to <body> so the overlay is not trapped by the tab wrapper.
    function attach(node) {
        if (node && node.parentElement !== document.body) {
            document.body.appendChild(node);
        }
    }

    attach(modal);

    let lastFocus = null;

    function resolve() {
        if (modal && document.contains(modal)) return modal;
        // The profile tab was re-fetched; pick up the fresh copy.
        modal = document.getElementById('kp-rank-modal') || modal;
        attach(modal);
        document.querySelectorAll('#kp-rank-modal').forEach((node) => {
            if (node !== modal) node.remove();
        });
        return modal;
    }

    function open() {
        const m = resolve();
        if (!m) return;
        lastFocus = document.activeElement;
        m.classList.add('is-open');
        m.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');
        const closeBtn = m.querySelector('#kp-rank-modal-close') || m.querySelector('.kp-avatar-close');
        if (closeBtn) closeBtn.focus();
    }

    function close() {
        const m = resolve();
        if (!m) return;
        m.classList.remove('is-open');
        m.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('no-scroll');
        if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }

    function isOpen() {
        const m = resolve();
        return !!(m && m.classList.contains('is-open'));
    }

    document.addEventListener('click', (e) => {
        const target = e.target;
        if (!(target instanceof Element)) return;

        if (target.closest('#kp-rank-viewall')) {
            e.preventDefault();
            open();
            return;
        }
        if (target.closest('#kp-rank-modal-close') || (target.classList.contains('kp-avatar-modal') && target.id === 'kp-rank-modal')) {
            e.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen()) close();
    });
})();
