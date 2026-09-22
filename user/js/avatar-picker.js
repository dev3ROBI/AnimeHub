/**
 * Anime avatar picker.
 *
 * Loaded by profile.php (the root page) rather than by the profile tab, because
 * scripts inside a fragment injected with innerHTML never execute.
 *
 * The banner avatar, the modal grid and the navbar avatar are all kept in sync
 * after a save, and the panel is moved to <body> so the transformed tab wrapper
 * cannot trap its `position: fixed`.
 */
(function () {
    if (window.__kpAvatarPickerLoaded) return;
    window.__kpAvatarPickerLoaded = true;

    const modal = document.getElementById('kp-avatar-modal');
    if (!modal) return;

    const grid = document.getElementById('kp-avatar-grid');
    const statusEl = document.getElementById('kp-avatar-status');
    const resetBtn = document.getElementById('kp-avatar-reset');
    const endpoint = './user/set_avatar.php';
    const DEFAULT_AVATAR = './assets/images/user_avatar.svg';

    // A transformed ancestor becomes the containing block for `position: fixed`,
    // and the tab wrapper has `transform: translateY(0)`. Re-parent to <body>.
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    // The profile tab can be re-fetched; drop any stale copy left in <body>.
    // Keep the rank ladder modal — it shares the .kp-avatar-modal class.
    document.querySelectorAll('body > .kp-avatar-modal').forEach((node) => {
        if (node !== modal && node.id !== 'kp-rank-modal') node.remove();
    });

    let lastFocus = null;

    function open() {
        lastFocus = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');
        const selected = grid && grid.querySelector('.kp-avatar-choice.is-selected');
        const focusTarget = selected || (grid && grid.querySelector('.kp-avatar-choice'));
        if (focusTarget) focusTarget.focus();
    }

    function close() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('no-scroll');
        if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }

    function setStatus(text, kind) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = 'kp-avatar-status' + (kind ? ' is-' + kind : '');
    }

    function markSelected(id) {
        if (!grid) return;
        grid.querySelectorAll('.kp-avatar-choice').forEach((btn) => {
            btn.classList.toggle('is-selected', btn.dataset.avatarId === id);
        });
    }

    function applyAvatar(url, value, meta) {
        // Every place the avatar appears: page banner, profile tab, navbar.
        document.querySelectorAll('[data-kp-avatar-img], .kp-nav-avatar').forEach((img) => {
            img.src = url;
        });

        document.querySelectorAll('[data-kp-avatar-name]').forEach((el) => {
            if (meta) {
                el.textContent = meta.name + (meta.from ? ' \u00b7 ' + meta.from : '');
                el.classList.add('has-avatar');
            } else {
                el.textContent = 'No anime avatar picked yet';
                el.classList.remove('has-avatar');
            }
        });

        document.querySelectorAll('.kp-avatar-btn-label').forEach((el) => {
            el.textContent = value ? 'Change Avatar' : 'Pick an Anime Avatar';
        });

        markSelected(value || '');
    }

    function save(avatarId) {
        setStatus('Saving...', '');

        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'avatar=' + encodeURIComponent(avatarId)
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.ok) throw new Error(data.message || 'Save failed');

                applyAvatar(data.url || DEFAULT_AVATAR, data.avatar || '', data.meta);
                setStatus(data.avatar ? 'Avatar updated' : 'Back to the default avatar', 'ok');
                setTimeout(close, 650);
            })
            .catch((err) => {
                setStatus(err.message || 'Could not save the avatar', 'error');
            });
    }

    // ─── Wiring (delegated, so re-rendered buttons keep working) ───────
    document.addEventListener('click', (e) => {
        const target = e.target;
        if (!(target instanceof Element)) return;

        if (target.closest('[data-kp-avatar-open]')) {
            e.preventDefault();
            open();
            return;
        }
        if (target.closest('#kp-avatar-close') || target === modal) {
            e.preventDefault();
            close();
            return;
        }
        const choice = target.closest('.kp-avatar-choice');
        if (choice && grid && grid.contains(choice)) {
            save(choice.dataset.avatarId);
            return;
        }
        if (target.closest('#kp-avatar-reset')) {
            e.preventDefault();
            save('');
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });

    // Tabs are plain links, but keyboard users expect arrow keys in a listbox.
    if (grid) {
        grid.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const items = Array.from(grid.querySelectorAll('.kp-avatar-choice'));
            const current = items.indexOf(document.activeElement);
            if (current === -1) return;
            e.preventDefault();
            const next = e.key === 'ArrowRight'
                ? (current + 1) % items.length
                : (current - 1 + items.length) % items.length;
            items[next].focus();
        });
    }
})();
