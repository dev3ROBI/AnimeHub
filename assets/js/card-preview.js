/**
 * Hover preview card for poster cards.
 *
 * Every card rendered by render_anime_card() carries its own compact JSON in a
 * `data-kp` attribute, so hovering costs no network request. A single floating
 * panel is reused for every card and positioned next to it, flipping sides when
 * it would leave the viewport.
 *
 * Pointer-fine devices only — on touch there is no hover and a tap should just
 * follow the link.
 */
(function () {
    const OPEN_DELAY = 220;
    const CLOSE_DELAY = 140;
    const MARGIN = 12;

    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    let panel = null;
    let openTimer = null;
    let closeTimer = null;
    let current = null;
    let activeCard = null;

    function el(tagName, className, text) {
        const node = document.createElement(tagName);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function buildPanel() {
        const p = el('div', 'kp-preview');
        p.setAttribute('role', 'tooltip');
        p.setAttribute('aria-hidden', 'true');
        document.body.appendChild(p);
        return p;
    }

    function chip(text, icon) {
        const c = el('span', 'kp-preview-chip');
        if (icon) c.appendChild(el('i', icon));
        c.appendChild(document.createTextNode(text));
        return c;
    }

    function render(data) {
        panel.textContent = '';

        const head = el('div', 'kp-preview-head');
        const thumb = el('img', 'kp-preview-thumb');
        thumb.src = data.p || '';
        thumb.alt = '';
        thumb.loading = 'lazy';

        const meta = el('div', 'kp-preview-meta');
        meta.appendChild(el('h4', 'kp-preview-title', data.t || ''));

        const chips = el('div', 'kp-preview-chips');
        if (data.s) chips.appendChild(chip(data.s, 'fa-solid fa-star'));
        if (data.f) chips.appendChild(chip(data.f));
        if (data.y) chips.appendChild(chip(String(data.y)));
        if (data.d) chips.appendChild(chip(data.d, 'fa-regular fa-clock'));
        if (chips.childElementCount) meta.appendChild(chips);

        head.appendChild(thumb);
        head.appendChild(meta);
        panel.appendChild(head);

        /* Availability line — “released / airing / upcoming” + real counts.
           `ae` (aired) is what is actually OUT; `na` is the next unaired
           episode with its unix air time, ticked by countdown.js via the
           .kp-cd[data-release] contract. */
        var avail = el('div', 'kp-preview-avail');
        var status = statusInfo(data);
        var badge = el('span', 'kp-preview-status ' + status.cls, status.text);
        if (status.icon) badge.insertBefore(el('i', status.icon), badge.firstChild);
        avail.appendChild(badge);
        if (data.tvq) {
            avail.id = 'kp-preview-avail-slots';
            avail.setAttribute('data-id', data.i || '');
        }

        var countTxt = availCount(data);
        if (countTxt) avail.appendChild(el('span', 'kp-preview-count', countTxt));
        panel.appendChild(avail);

        if (data.na && data.na.ts) {
            var nextLine = el('div', 'kp-preview-next');
            nextLine.appendChild(el('i', 'fa-regular fa-bell'));
            var nextLabel = el('span', null, data.na.e ? 'Episode ' + data.na.e + ' in ' : 'Next episode in ');
            nextLine.appendChild(nextLabel);
            var cd = el('span', 'kp-cd');
            cd.setAttribute('data-release', String(data.na.ts));
            cd.setAttribute('data-done-text', 'Airing now');
            cd.textContent = window.kpCountdownFmt ? window.kpCountdownFmt(data.na.ts * 1000 - Date.now()) : '';
            nextLine.appendChild(cd);
            panel.appendChild(nextLine);
            if (typeof window.kpCountdownScan === 'function') window.kpCountdownScan();
        }

        if (data.g && data.g.length) {
            const genres = el('div', 'kp-preview-genres');
            data.g.forEach((g) => genres.appendChild(el('span', 'kp-preview-genre', g)));
            panel.appendChild(genres);
        }

        if (data.x) panel.appendChild(el('p', 'kp-preview-desc', data.x));
    }

    /** Humanised time-left — mirrors kp_time_left() in includes/functions.php. */
    function fmt(ms) {
        if (ms <= 0) return '';
        const s = Math.floor(ms / 1000);
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        if (d >= 1) return d + 'd ' + h + 'h';
        if (h >= 1) return h + 'h ' + m + 'm';
        if (m >= 1) return m + 'm';
        return s + 's';
    }
    window.kpCountdownFmt = window.kpCountdownFmt || fmt;

    /** Release status bucket shown as the first badge. */
    function statusInfo(data) {
        var st = (data.st || '').toUpperCase().replace(/\s+/g, '_');
        var upcoming = data.na && data.na.ts > Date.now() / 1000;
        if (upcoming) {
            // Next episode is scheduled in the future — the show is airing,
            // even if the provider row did not carry a RELEASING status.
            return { text: 'Airing Now', cls: 'kp-status-airing', icon: 'fa-solid fa-signal' };
        }
        if (st === 'RELEASING' || st === 'CURRENTLY_AIRING' || st === 'RETURNING SERIES') {
            return { text: 'Airing Now', cls: 'kp-status-airing', icon: 'fa-solid fa-signal' };
        }
        if (st === 'NOT_YET_RELEASED') {
            return { text: 'Upcoming', cls: 'kp-status-upcoming', icon: 'fa-solid fa-hourglass-half' };
        }
        if (st === 'FINISHED' || st === 'FINISHED_AIRING' || st === 'COMPLETE' || st === 'ENDED') {
            return { text: 'Released', cls: 'kp-status-done', icon: 'fa-solid fa-circle-check' };
        }
        if (st === 'UPCOMING') {
            return { text: 'Upcoming', cls: 'kp-status-upcoming', icon: 'fa-solid fa-hourglass-half' };
        }
        if (st === 'CANCELLED' || st === 'CANCELED' || st === 'HIATUS') {
            return { text: st.charAt(0) + st.slice(1).toLowerCase(), cls: 'kp-status-off', icon: 'fa-solid fa-circle-minus' };
        }
        // No provider status: TMDB list rows (tvq=1) show a neutral “checking”
        // badge; the real status fills in when the hover lookup resolves.
        if (!st && data.tvq) {
            return { text: 'Details…', cls: 'kp-status-unknown', icon: 'fa-solid fa-spinner' };
        }
        if (!st && (data.ae || data.e)) {
            return { text: 'Released', cls: 'kp-status-done', icon: 'fa-solid fa-circle-check' };
        }
        return { text: st ? st.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, function(c) { return c.toUpperCase(); }) : 'Released', cls: 'kp-status-done', icon: 'fa-solid fa-circle-check' };
    }

    /** “12 EP” / “12 / 13 EP” for anime, “38S · 1179 EP” for TMDB TV, “Movie” otherwise. */
    function availCount(data) {
        if (data.ct === 'movie') return 'Movie';
        if (data.ct === 'tv') {
            if (data.tvq && !(data.ae > 0)) return '';
            var parts = [];
            if (data.e && data.e > 0) parts.push(data.e + 'S');
            if (data.ae && data.ae > 0) parts.push(data.ae + ' EP');
            return parts.length ? parts.join(' · ') : 'TV Series';
        }
        if (data.ae && data.ae > 0) {
            return data.ae + (data.e && data.e > data.ae ? ' / ' + data.e + ' EP' : ' EP');
        }
        if (data.e && data.e > 0) return data.e + ' EP';
        return '';
    }

    function place(card) {
        const rect = card.getBoundingClientRect();
        const pw = panel.offsetWidth;
        const ph = panel.offsetHeight;

        // Prefer the card's right edge; flip left when there is no room.
        let left = rect.right + MARGIN;
        let side = 'right';
        if (left + pw > window.innerWidth - MARGIN) {
            const flipped = rect.left - pw - MARGIN;
            if (flipped >= MARGIN) {
                left = flipped;
                side = 'left';
            } else {
                left = Math.max(MARGIN, window.innerWidth - pw - MARGIN);
                side = 'bottom';
            }
        }

        let top = rect.top;
        if (top + ph > window.innerHeight - MARGIN) {
            top = Math.max(MARGIN, window.innerHeight - ph - MARGIN);
        }
        if (top < MARGIN) top = MARGIN;

        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(top) + 'px';

        // Point the panel's arrow at the source card so the user can see WHICH
        // card the details belong to, and lift that card while it is open.
        panel.classList.remove('kp-from-right', 'kp-from-left', 'kp-from-bottom');
        panel.classList.add('kp-from-' + side);
        if (side === 'right') {
            panel.style.setProperty('--kp-arrow-top', Math.round(rect.top + rect.height / 2 - top) + 'px');
        } else if (side === 'left') {
            panel.style.setProperty('--kp-arrow-top', Math.round(rect.top + rect.height / 2 - top) + 'px');
        } else {
            panel.style.removeProperty('--kp-arrow-top');
        }

        if (activeCard && activeCard !== card) activeCard.classList.remove('kp-preview-source');
        activeCard = card.querySelector('.movie-card') || card;
        activeCard.classList.add('kp-preview-source');
    }

    const resolvedStatuses = {}; // id -> resolved availability payload
    let resolvingId = null;

    /**
     * TMDB list rows (tvq=1) carry no reliable status. On first hover the real
     * availability is fetched from a cached endpoint and this card's data-kp is
     * patched, so the SAME card shows the truth instantly on every later hover.
     */
    function resolveStatus(card, data) {
        if (!data.tvq || !data.i || resolvedStatuses[data.i]) return;
        if (resolvingId === data.i) return;
        resolvingId = data.i;

        fetch('./includes/tmdb_avail.php?id=' + encodeURIComponent(data.i))
            .then(function (r) { return r.json(); })
            .then(function (avail) {
                resolvingId = null;
                if (!avail || (!avail.st && !avail.ae && !avail.na)) return;

                if (avail.st) data.st = avail.st;
                if (typeof avail.ae === 'number' && avail.ae > 0) data.ae = avail.ae;
                if (avail.na) data.na = avail.na;
                if (typeof avail.e === 'number' && avail.e > 0) data.e = avail.e;
                delete data.tvq;
                resolvedStatuses[data.i] = true;
                try { card.dataset.kp = JSON.stringify(data); } catch (err) {}

                // Live-refresh the panel if this card is still open.
                if (current === card) {
                    render(data);
                    if (typeof window.kpCountdownScan === 'function') window.kpCountdownScan();
                }
            })
            .catch(function () { resolvingId = null; });
    }

    function open(card) {
        if (current === card) return;

        let data;
        try {
            data = JSON.parse(card.dataset.kp);
        } catch (e) {
            return;
        }
        if (!data || !data.t) return;

        current = card;
        render(data);
        // Set the side before measuring so offsetWidth is final.
        panel.style.left = '-9999px';
        panel.style.top = '-9999px';
        panel.classList.add('is-visible');
        panel.setAttribute('aria-hidden', 'false');
        place(card);

        if (!reduceMotion.matches) panel.classList.add('is-in');

        if (data.tvq) resolveStatus(card, data);
    }

    function close() {
        current = null;
        panel.classList.remove('is-visible', 'is-in');
        panel.setAttribute('aria-hidden', 'true');
        if (activeCard) {
            activeCard.classList.remove('kp-preview-source');
            activeCard = null;
        }
    }

    function scheduleOpen(card) {
        clearTimeout(closeTimer);
        clearTimeout(openTimer);
        openTimer = setTimeout(() => open(card), OPEN_DELAY);
    }

    function scheduleClose() {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        closeTimer = setTimeout(close, CLOSE_DELAY);
    }

    function cardFrom(target) {
        if (!(target instanceof Element)) return null;
        const card = target.closest('.watch-item[data-kp]');
        return (card && card.querySelector('.movie-card')) ? card : null;
    }

    document.addEventListener('mouseover', (e) => {
        const card = cardFrom(e.target);
        if (card) scheduleOpen(card);
        else if (current) scheduleClose();
    });

    document.addEventListener('mouseout', (e) => {
        const card = cardFrom(e.target);
        if (!card) return;
        // Moving between children of the same card must not restart the timer.
        if (e.relatedTarget && card.contains(e.relatedTarget)) return;
        scheduleClose();
    });

    // Scrolling would leave the panel pinned to stale coordinates, so dismiss
    // it rather than chase the card around.
    window.addEventListener('scroll', () => {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        if (current) close();
    }, { passive: true });

    window.addEventListener('resize', () => { if (current) place(current); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

    panel = buildPanel();
})();
