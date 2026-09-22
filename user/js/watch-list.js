/**
 * My Watch List tab — home page card style.
 *
 * Filters and search run on already-rendered cards; only status changes
 * or removals talk to the server. Import/export use event delegation.
 */
(function () {
    const root = document.getElementById('kp-watchlist');
    if (!root) return;

    const grid = document.getElementById('kp-wl-grid');
    const filters = document.getElementById('kp-wl-filters');
    const search = document.getElementById('kp-wl-search');
    const none = document.getElementById('kp-wl-none');
    const endpoint = './includes/save_watchlist.php';

    const cards = () => Array.from(root.querySelectorAll('[data-kp-watchlist-item]'));

    let activeStatus = 'all';
    let query = '';

    function apply() {
        let visible = 0;

        cards().forEach((card) => {
            const statusOk = activeStatus === 'all' || card.dataset.status === activeStatus;
            const queryOk = !query || (card.dataset.search || '').indexOf(query) !== -1;
            const show = statusOk && queryOk;
            card.hidden = !show;
            if (show) visible++;
        });

        if (none) none.hidden = visible !== 0;
    }

    // ─── Filters ────────────────────────────────────────────────────
    if (filters) {
        filters.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-status]');
            if (!btn) return;
            activeStatus = btn.dataset.status;
            filters.querySelectorAll('[data-status]').forEach((b) => {
                b.classList.toggle('active', b === btn);
            });
            apply();
        });
    }

    if (search) {
        search.addEventListener('input', () => {
            query = search.value.trim().toLowerCase();
            apply();
        });
    }

    // ─── Status change ──────────────────────────────────────────────
    function setStatus(card, status) {
        const select = card.querySelector('[data-kp-wl-status]');
        const previous = card.dataset.status;
        if (select) select.disabled = true;
        card.classList.add('is-busy');

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'imdb_id=' + encodeURIComponent(card.dataset.id)
                + '&status=' + encodeURIComponent(status)
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.success) throw new Error((data && data.message) || 'Save failed');

                card.dataset.status = status;
                const badge = card.querySelector('.kp-wl-card-status-badge');
                if (badge) {
                    const STATUSES = {
                        watching:    { label: 'Watching',    icon: 'fa-solid fa-play',  color: '#238636' },
                        on_hold:     { label: 'On Hold',     icon: 'fa-solid fa-pause', color: '#9e6a03' },
                        watch_later: { label: 'Planning',    icon: 'fa-solid fa-bookmark', color: '#1f6feb' },
                        completed:   { label: 'Completed',   icon: 'fa-solid fa-check', color: '#8957e5' },
                        dropped:     { label: 'Dropped',     icon: 'fa-solid fa-xmark', color: '#da3633' },
                    };
                    const meta = STATUSES[status] || { label: status, icon: 'fa-solid fa-tag', color: '#6e7681' };
                    badge.style.background = meta.color;
                    badge.innerHTML = '<i class="' + meta.icon + '"></i> ' + meta.label;
                    badge.classList.remove('flash-ok');
                    void badge.offsetWidth;
                    badge.classList.add('flash-ok');
                }
                apply();
            })
            .catch((err) => {
                if (select) select.value = previous;
                console.error('Watchlist status error:', err);
            })
            .finally(() => {
                if (select) select.disabled = false;
                card.classList.remove('is-busy');
            });
    }

    // ─── Remove ─────────────────────────────────────────────────────
    function remove(card) {
        const id = card.dataset.id;
        if (!window.confirm('Remove this title from your watch list?')) return;

        card.classList.add('is-busy');

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'imdb_id=' + encodeURIComponent(id) + '&action=remove'
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.success) throw new Error('Remove failed');
                card.remove();
                apply();
            })
            .catch((err) => {
                card.classList.remove('is-busy');
                console.error('Watchlist remove error:', err);
            });
    }

    // ─── Event delegation for status change, remove, import/export ──
    root.addEventListener('change', (e) => {
        const select = e.target.closest('[data-kp-wl-status]');
        if (!select) return;
        const card = select.closest('[data-kp-watchlist-item]');
        if (card) setStatus(card, select.value);
    });

    root.addEventListener('click', (e) => {
        // Remove button
        const removeBtn = e.target.closest('[data-kp-wl-remove]');
        if (removeBtn) {
            const card = removeBtn.closest('[data-kp-watchlist-item]');
            if (card) remove(card);
            return;
        }

        // Export button
        if (e.target.closest('#kp-wl-export-btn')) {
            window.location.href = './includes/export_mal.php';
            return;
        }

        // Import button
        if (e.target.closest('#kp-wl-import-btn')) {
            const fileInput = document.getElementById('kp-wl-import-file');
            if (fileInput) fileInput.click();
            return;
        }
    });

    // ─── Import file change ─────────────────────────────────────────
    const importFile = document.getElementById('kp-wl-import-file');
    if (importFile) {
        importFile.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const importBtn = document.getElementById('kp-wl-import-btn');
            const formData = new FormData();
            formData.append('mal_file', file);

            if (importBtn) {
                importBtn.disabled = true;
                importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
            }

            fetch('./includes/import_mal.php', {
                method: 'POST',
                body: formData
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.success) {
                        if (typeof kpToast === 'function') {
                            kpToast('Imported ' + (data.imported || 0) + ' anime successfully!', 'success');
                        }
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        if (typeof kpToast === 'function') {
                            kpToast(data.message || 'Import failed', 'error');
                        }
                    }
                })
                .catch(() => {
                    if (typeof kpToast === 'function') kpToast('Network error during import', 'error');
                })
                .finally(() => {
                    if (importBtn) {
                        importBtn.disabled = false;
                        importBtn.innerHTML = '<i class="fas fa-file-import"></i> Import';
                    }
                    importFile.value = '';
                });
        });
    }

    apply();
})();
