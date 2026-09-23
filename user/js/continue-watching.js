/**
 * Continue Watching tab.
 *
 * Renders a "next up" hero card for the most recent title, then a grid of the
 * rest. Shows initially 8 cards with a "Show All" button to expand.
 */
(function () {
    const container = document.getElementById('continue-watching-list');
    if (!container) return;

    const endpoint = './includes/get_continue_watching.php';
    const clearEndpoint = './includes/clear_progress.php';
    const INITIAL_COUNT = 8;

    const esc = (value) => String(value == null ? '' : value);

    function posterOf(item) {
        return item.poster || './uploads/thumbnails/default.png';
    }

    function epLabel(item) {
        if (item.type === 'episode') {
            return 'S' + esc(item.season_number) + ' E' + esc(item.episode_number);
        }
        // TMDB TV carries a season, so label it the way the watch page does.
        if (item.season_number) {
            return 'S' + esc(item.season_number) + ' E' + esc(item.episode_number);
        }
        if (item.episode_number) {
            return 'EP ' + esc(item.episode_number)
                + (item.total_episodes ? ' / ' + esc(item.total_episodes) : '');
        }
        return 'Movie';
    }

    /**
     * "12:30 / 24:00 · 4 ep watched" — the resume point the player stored plus
     * how much of the series has real watch time behind it.
     */
    function timeLabel(item) {
        const parts = [];

        if (item.last_position && item.runtime) {
            parts.push(esc(item.last_position) + ' / ' + esc(item.runtime));
        }

        const eps = parseInt(item.watched_episodes, 10) || 0;
        const total = esc(item.watched_total_time);
        if (eps > 0 && total) {
            parts.push(eps + ' ep · ' + total + ' watched');
        }

        return parts.join(' · ');
    }

    function emptyState(message, icon) {
        container.innerHTML = '';
        const box = document.createElement('div');
        box.className = 'kp-empty-state';
        box.innerHTML = '<i class="' + (icon || 'fas fa-ghost') + '"></i>'
                      + '<h4>Nothing here yet</h4><p></p>'
                      + '<a class="kp-empty-cta" href="./home.php"><i class="fas fa-fire"></i> Find something to watch</a>';
        box.querySelector('p').textContent = message;
        container.appendChild(box);
    }

    /**
     * Drop a title from Continue Watching.
     *
     * Shared by the hero card and the grid cards so the trash button behaves
     * the same wherever it is clicked.
     */
    function removeFromHistory(item, card) {
        if (!window.confirm('Remove "' + (item.title || 'this title') + '" from your watch history?')) return;

        card.classList.add('is-busy');
        fetch(clearEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'video_id=' + encodeURIComponent(item.video_id)
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.ok) throw new Error('clear failed');
                card.remove();
                if (!container.querySelector('.kp-cw-card, .kp-cw-hero')) {
                    emptyState('Your recent activity is clear. Start watching something new.');
                }
                if (typeof kpToast === 'function') kpToast('Removed from Continue Watching', 'success');
            })
            .catch(() => card.classList.remove('is-busy'));
    }

    // ─── Next up card (hero) ────────────────────────────────────────
    function renderHero(item) {
        const pct = Math.max(2, Math.min(100, parseInt(item.percent, 10) || 0));
        const bg = item.banner || posterOf(item);

        const card = document.createElement('article');
        card.className = 'kp-cw-hero';
        card.innerHTML =
            '<img class="kp-cw-hero-bg" src="' + esc(bg) + '" alt="" loading="lazy" decoding="async">'
          + '<div class="kp-cw-hero-shade"></div>'
          + '<div class="kp-cw-hero-body">'
          +   '<img class="kp-cw-hero-poster" src="' + esc(posterOf(item)) + '" alt="" loading="lazy" decoding="async">'
          +   '<div class="kp-cw-hero-copy">'
          +     '<span class="kp-cw-tag"><i class="fas fa-play"></i> Next up</span>'
          +     '<h3 class="kp-cw-hero-title"></h3>'
          +     '<p class="kp-cw-hero-sub"></p>'
          +     '<div class="kp-cw-bar"><span style="width:' + pct + '%"></span></div>'
          +     '<div class="kp-cw-actions">'
          +       '<a class="kp-cw-resume" href="' + esc(item.url) + '">'
          +         '<i class="fas fa-circle-play"></i> Resume</a>'
          +       '<a class="kp-cw-next" href="' + esc(item.next_url || item.url) + '">'
          +         (item.next_episode ? 'Watch EP ' + esc(item.next_episode) : 'Watch now')
          +         ' <i class="fas fa-chevron-right"></i></a>'
          +       '<button type="button" class="kp-cw-btn ghost kp-cw-remove" title="Remove from history"'
          +         ' aria-label="Remove from history"><i class="fas fa-trash"></i></button>'
          +     '</div>'
          +   '</div>'
          + '</div>';

        card.querySelector('.kp-cw-hero-title').textContent = item.title || 'Unknown';
        card.querySelector('.kp-cw-hero-sub').textContent =
            epLabel(item) + (item.percent > 0 ? ' \u00b7 ' + item.percent + '% watched' : '');
        if (!item.next_url || !item.next_episode) {
            card.querySelector('.kp-cw-next').remove();
        }

        const heroRemove = card.querySelector('.kp-cw-remove');
        if (heroRemove) {
            heroRemove.addEventListener('click', (e) => {
                e.preventDefault();
                removeFromHistory(item, card);
            });
        }
        return card;
    }

    // ─── Grid card ──────────────────────────────────────────────────
    function renderCard(item) {
        const pct = Math.max(0, Math.min(100, parseInt(item.percent, 10) || 0));
        const rating = item.imdb_rating && item.imdb_rating !== 'N/A' ? item.imdb_rating : '';

        const card = document.createElement('article');
        card.className = 'kp-cw-card';
        card.innerHTML =
            '<a class="kp-cw-thumb" href="' + esc(item.url) + '">'
          +   '<img src="' + esc(posterOf(item)) + '" alt="" loading="lazy" decoding="async">'
          +   (rating ? '<span class="kp-cw-rating"><i class="fas fa-star"></i> ' + esc(rating) + '</span>' : '')
          +   '<span class="kp-cw-ep">' + esc(epLabel(item)) + '</span>'
          +   (pct > 0 ? '<span class="kp-cw-progress"><span style="width:' + pct + '%"></span></span>' : '')
          +   '<span class="kp-cw-play"><i class="fas fa-play"></i></span>'
          + '</a>'
          + '<div class="kp-cw-body">'
          +   '<p class="kp-cw-title"></p>'
          +   '<div class="kp-cw-actions">'
          +     '<a class="kp-cw-btn" href="' + esc(item.url) + '"><i class="fas fa-play"></i> Resume</a>'
          +     (item.next_url
                  ? '<a class="kp-cw-btn ghost" href="' + esc(item.next_url) + '" title="Next episode">'
                    + '<i class="fas fa-forward"></i></a>'
                  : '')
          +     '<button type="button" class="kp-cw-btn ghost kp-cw-remove" title="Remove from history"'
          +       ' aria-label="Remove from history"><i class="fas fa-trash"></i></button>'
          +   '</div>'
          + '</div>';

        card.querySelector('.kp-cw-title').textContent = item.title || 'Unknown';

        const time = timeLabel(item);
        if (time) {
            const meta = document.createElement('p');
            meta.className = 'kp-cw-meta';
            meta.textContent = time;
            card.querySelector('.kp-cw-body').insertBefore(meta, card.querySelector('.kp-cw-actions'));
        }

        const remove = card.querySelector('.kp-cw-remove');
        if (remove) {
            remove.addEventListener('click', (e) => {
                e.preventDefault();
                removeFromHistory(item, card);
            });
        }

        return card;
    }

    // ─── Show All button ────────────────────────────────────────────
    function renderShowAll(remaining) {
        const btn = document.createElement('div');
        btn.className = 'kp-cw-show-all';
        btn.innerHTML = '<button type="button" class="kp-cw-show-all-btn">'
            + '<i class="fas fa-chevron-down"></i> Show All (' + remaining + ' more)'
            + '</button>';
        return btn;
    }

    // ─── Load ───────────────────────────────────────────────────────
    function setLoading() {
        container.innerHTML = '<div class="kp-cw-loading">'
            + '<span></span><span></span><span></span>'
            + '</div>';
    }

    function render(data) {
        container.innerHTML = '';

        // Hero is always the first item
        if (data.length > 0) {
            container.appendChild(renderHero(data[0]));
        }

        const gridItems = data.slice(1); // All items after hero
        const grid = document.createElement('div');
        grid.className = 'kp-cw-grid';

        const initialItems = gridItems.slice(0, INITIAL_COUNT);
        const remainingItems = gridItems.slice(INITIAL_COUNT);

        // Render initial cards
        initialItems.forEach((item) => {
            grid.appendChild(renderCard(item));
        });

        container.appendChild(grid);

        // Add Show All button if there are more items
        if (remainingItems.length > 0) {
            const showAllWrapper = document.createElement('div');
            showAllWrapper.className = 'kp-cw-show-all';
            const showAllBtn = document.createElement('button');
            showAllBtn.type = 'button';
            showAllBtn.className = 'kp-cw-show-all-btn';
            showAllBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Show All (' + remainingItems.length + ' more)';

            showAllBtn.addEventListener('click', () => {
                remainingItems.forEach((item) => {
                    grid.appendChild(renderCard(item));
                });
                showAllWrapper.remove();
            });

            showAllWrapper.appendChild(showAllBtn);
            container.appendChild(showAllWrapper);
        }
    }

    setLoading();

    fetch(endpoint)
        .then((res) => res.json())
        .then((data) => {
            if (!Array.isArray(data) || data.length === 0) {
                emptyState('Your watch history is empty. Open any anime and it will show up here.');
                return;
            }
            render(data);
        })
        .catch((err) => {
            console.error('Continue watching error:', err);
            emptyState('Could not load your history. Please refresh the page.', 'fas fa-triangle-exclamation');
        });
})();
