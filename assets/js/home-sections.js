/**
 * Home page widgets.
 *
 * 1. Sidebar "Top Trending" tabs — DAY / WEEK / MONTH each pull a different
 *    AniList window (trending / currently airing / this season) and swap the
 *    rendered rows in place. Periods are cached after the first fetch.
 * 2. Upcoming day tabs (TODAY / NEXT / LATER) — pure class toggle, no network.
 * 3. Genre browser — clicking a chip loads a few titles for that genre and
 *    points the "View all" link at the matching genre page.
 *
 * All widgets are progressive: without this file the server-rendered content
 * and the plain genre links still work.
 */
(function () {
    'use strict';

    // ─── Sidebar trending tabs (DAY / WEEK / MONTH) ────────────────
    // Scoped to #trendTabs so the Upcoming tabs (same .kp-trend-tab class)
    // are never disabled or double-bound. Works on touch as well as mouse —
    // a previous coarse-pointer shortcut turned every switch off on phones.
    const trendTabs = Array.from(document.querySelectorAll('#trendTabs .kp-trend-tab'));
    const trendList = document.getElementById('kpTrendList');

    if (trendTabs.length && trendList) {
        const endpoint = './includes/trending_period.php';
        const cache = new Map();

        // Whatever the server rendered is already the current period's list.
        cache.set(trendList.dataset.period || 'day', trendList.innerHTML);

        trendTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const period = tab.dataset.period || 'day';
                trendTabs.forEach((t) => t.classList.toggle('active', t === tab));

                if (cache.has(period)) {
                    trendList.innerHTML = cache.get(period);
                    return;
                }

                trendList.classList.add('is-loading');
                fetch(endpoint + '?period=' + encodeURIComponent(period) + '&limit=10')
                    .then((r) => r.json())
                    .then((data) => {
                        if (!data || typeof data.html !== 'string') throw new Error('bad payload');
                        cache.set(period, data.html);
                        trendList.innerHTML = data.html;
                    })
                    .catch(() => {
                        if (typeof kpToast === 'function') {
                            kpToast('Could not load ' + period + ' trending', 'error');
                        }
                    })
                    .finally(() => trendList.classList.remove('is-loading'));
            });
        });
    }

    // ─── Upcoming sidebar tabs (TODAY / NEXT / LATER) ──────────────
    // Groups are all server-rendered; switching is a pure class toggle, so
    // this works with zero network cost and degrades to stacked lists
    // without JS.
    const upTabs = document.getElementById('upTabs');
    if (upTabs) {
        const panes = Array.from(document.querySelectorAll('.kp-up-pane'));
        upTabs.addEventListener('click', (event) => {
            const tab = event.target.closest('.kp-trend-tab[data-up]');
            if (!tab) return;
            const key = tab.dataset.up;
            upTabs.querySelectorAll('.kp-trend-tab').forEach((t) => t.classList.toggle('active', t === tab));
            panes.forEach((pane) => pane.classList.toggle('active', pane.dataset.upPane === key));
        });
    }

    // ─── Genre browser ──────────────────────────────────────────────
    const browse = document.getElementById('kpGenreBrowse');
    if (!browse) return;

    const nav = document.getElementById('kpGenreNav');
    const grid = document.getElementById('kpGenreGrid');
    const viewAll = document.getElementById('kpGenreViewAll');
    if (!nav || !grid) return;

    const endpoint = './includes/genre_items.php';
    const sort = browse.dataset.sort || 'popular';
    const limit = browse.dataset.limit || '6';
    const emptyHtml = '<p class="kp-genre-empty"><i class="fa-solid fa-ghost"></i> No titles for this genre yet.</p>';
    const cache = new Map();
    let requestId = 0;

    cache.set(browse.dataset.genre || '', grid.innerHTML);

    nav.addEventListener('click', (event) => {
        const chip = event.target.closest('.kp-genre-chip');
        if (!chip) return;

        event.preventDefault();
        const genre = chip.dataset.genre || '';
        if (genre === (browse.dataset.genre || '')) return;

        browse.dataset.genre = genre;
        nav.querySelectorAll('.kp-genre-chip').forEach((c) => c.classList.toggle('active', c === chip));
        if (viewAll) viewAll.href = './genre.php?g=' + encodeURIComponent(genre);

        if (cache.has(genre)) {
            grid.innerHTML = cache.get(genre);
            return;
        }

        // Only the newest request is allowed to paint the grid.
        const token = ++requestId;
        grid.classList.add('is-loading');

        fetch(endpoint + '?g=' + encodeURIComponent(genre)
            + '&sort=' + encodeURIComponent(sort)
            + '&limit=' + encodeURIComponent(limit))
            .then((r) => r.json())
            .then((data) => {
                if (token !== requestId) return;
                const html = data && typeof data.html === 'string' && data.html ? data.html : emptyHtml;
                cache.set(genre, html);
                grid.innerHTML = html;
            })
            .catch(() => {
                if (token === requestId && typeof kpToast === 'function') {
                    kpToast('Could not load that genre', 'error');
                }
            })
            .finally(() => {
                if (token === requestId) grid.classList.remove('is-loading');
            });
    });
})();
