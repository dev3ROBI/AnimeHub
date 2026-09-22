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
        if (data.e) chips.appendChild(chip(data.e + ' EP'));
        if (data.d) chips.appendChild(chip(data.d, 'fa-regular fa-clock'));
        if (chips.childElementCount) meta.appendChild(chips);

        head.appendChild(thumb);
        head.appendChild(meta);
        panel.appendChild(head);

        if (data.g && data.g.length) {
            const genres = el('div', 'kp-preview-genres');
            data.g.forEach((g) => genres.appendChild(el('span', 'kp-preview-genre', g)));
            panel.appendChild(genres);
        }

        if (data.x) panel.appendChild(el('p', 'kp-preview-desc', data.x));

        const foot = el('div', 'kp-preview-foot');
        const cta = el('span', 'kp-preview-cta');
        cta.appendChild(el('i', 'fa-solid fa-circle-play'));
        cta.appendChild(document.createTextNode(data.ep ? ' Play Episode ' + data.ep : ' Watch Now'));
        foot.appendChild(cta);
        if (data.st) {
            var statusText = data.st.replace(/_/g, ' ');
            var statusEl = el('span', 'kp-preview-status', statusText);
            if (data.st === 'RELEASING' || data.st === 'NOT_YET_RELEASED') {
                statusEl.classList.add('kp-status-airing');
            } else if (data.st === 'FINISHED') {
                statusEl.classList.add('kp-status-done');
            }
            foot.appendChild(statusEl);
        }
        panel.appendChild(foot);
    }

    function place(card) {
        const rect = card.getBoundingClientRect();
        const pw = panel.offsetWidth;
        const ph = panel.offsetHeight;

        // Prefer the card's right edge; flip left when there is no room.
        let left = rect.right + MARGIN;
        if (left + pw > window.innerWidth - MARGIN) {
            const flipped = rect.left - pw - MARGIN;
            left = flipped >= MARGIN ? flipped : Math.max(MARGIN, window.innerWidth - pw - MARGIN);
        }

        let top = rect.top;
        if (top + ph > window.innerHeight - MARGIN) {
            top = Math.max(MARGIN, window.innerHeight - ph - MARGIN);
        }
        if (top < MARGIN) top = MARGIN;

        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(top) + 'px';
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
    }

    function close() {
        current = null;
        panel.classList.remove('is-visible', 'is-in');
        panel.setAttribute('aria-hidden', 'true');
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
