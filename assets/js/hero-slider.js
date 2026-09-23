/**
 * Home page trending slider.
 *
 * The server renders the first slide and gives every slide a `data-kp-slide`
 * JSON payload. Slides 2..n are built here on first use, so a 10-slide
 * carousel ships one slide of markup instead of ten.
 *
 * Behaviour: auto-advance, arrows, dots, keyboard, touch swipe, pause button.
 * Hover, focus, a hidden tab, or prefers-reduced-motion all stop the rotation.
 */
(function () {
    const root = document.querySelector('[data-kp-slider]');
    if (!root) return;

    // A one-item banner is itself the slide, so it is not a descendant match.
    const slides = Array.from(root.querySelectorAll('.kp-slide'));
    if (root.classList.contains('kp-slide')) slides.unshift(root);
    if (!slides.length) return;

    const dots = Array.from(root.querySelectorAll('[data-kp-goto]'));
    const pauseBtn = root.querySelector('[data-kp-pause]');
    const counter = root.querySelector('[data-kp-current]');
    const interval = parseInt(root.dataset.kpInterval || '7000', 10);
    const tag = root.dataset.kpTag || 'Trending Now';

    let index = 0;
    let timer = null;
    let paused = false;
    let pausedByUser = false;
    let built = new Set([0]);

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // ─── Slide rendering ────────────────────────────────────────────
    const el = (tagName, className, text) => {
        const node = document.createElement(tagName);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    };

    // `extra` lets the score chip carry its own accent class.
    const stat = (value, icon, extra) => {
        const span = document.createElement('span');
        span.className = 'kp-hero-badge' + (extra ? ' ' + extra : '');
        if (icon) {
            span.appendChild(el('i', icon));
            span.appendChild(document.createTextNode(' ' + value));
        } else {
            span.appendChild(document.createTextNode(value));
        }
        return span;
    };

    const actionLink = (href, icon, modifier, label) => {
        const a = el('a', 'kp-hero-btn ' + modifier);
        a.href = href || '#';
        a.appendChild(el('i', icon));
        a.appendChild(document.createTextNode(' ' + label));
        return a;
    };

    // ─── Title logos (optional TMDB artwork) ────────────────────────
    // The page never waits on TMDB: it renders the text title and swaps in a
    // logo once one arrives. MAL id, title and year are all sent, because the
    // endpoint needs the title to search when the MAL mapping is missing
    // (season-qualified entries usually are).
    const logoBySlide = new WeakMap();
    let logosRequested = false;

    function applyLogo(slide) {
        if (!slide) return;

        // A logo baked into the markup (config override) beats the fetched one.
        const url = slide.dataset.kpLogo || logoBySlide.get(slide) || '';
        if (!url) return;

        const copy = slide.querySelector('.kp-hero-copy');
        const title = copy && copy.querySelector('.kp-hero-title');
        if (!copy || !title || copy.querySelector('.kp-hero-logo')) return;

        const img = el('img', 'kp-hero-logo');
        img.src = url;
        img.alt = title.textContent || '';
        img.decoding = 'async';
        // A broken logo should leave the text title in charge.
        img.addEventListener('error', () => {
            img.remove();
            copy.classList.remove('has-logo');
        });

        copy.insertBefore(img, title);
        copy.classList.add('has-logo');
    }

    function loadLogos() {
        if (logosRequested) return;
        logosRequested = true;

        const entries = [];
        slides.forEach((slide) => {
            let data;
            try {
                data = JSON.parse(slide.dataset.kpSlide || '{}');
            } catch (e) {
                return;
            }
            if (!data || (!data.m && !data.t)) return;
            entries.push({ slide: slide, m: data.m || 0, t: data.t || '', y: data.y || 0 });
        });
        if (!entries.length) return;

        const params = new URLSearchParams();
        entries.slice(0, 12).forEach((entry) => {
            params.append('m[]', String(entry.m));
            params.append('t[]', String(entry.t));
            params.append('y[]', String(entry.y));
        });

        fetch('./includes/title_logos.php?' + params.toString())
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.enabled || !data.logos) {
                    if (data && data.enabled === false) {
                        console.info('[hero] title logos off: ' + (data.reason || 'not configured'));
                    }
                    return;
                }
                // The endpoint answers by request index.
                Object.keys(data.logos).forEach((index) => {
                    const entry = entries[Number(index)];
                    if (entry) logoBySlide.set(entry.slide, data.logos[index]);
                });
                // Every slide that is already built gets its upgrade now.
                slides.forEach(applyLogo);
            })
            .catch((err) => console.warn('[hero] title logos request failed:', err));
    }

    function buildSlide(slide) {
        if (built.has(Number(slide.dataset.kpIndex))) return;
        built.add(Number(slide.dataset.kpIndex));

        let data;
        try {
            data = JSON.parse(slide.dataset.kpSlide);
        } catch (e) {
            return;
        }

        const bg = el('img', 'kp-hero-bg');
        bg.alt = '';
        bg.decoding = 'async';
        // Lazy-load non-active slides' background images — only the visible
        // slide gets fetched immediately, the rest wait until built.
        bg.loading = 'lazy';
        bg.src = data.b || data.p || '';

        const shade = el('div', 'kp-hero-shade');

        const body = el('div', 'kp-hero-body');
        const copy = el('div', 'kp-hero-copy');

        // Same order as the server-rendered slide: tag, chips, title, synopsis.
        const eyebrow = el('span', 'kp-hero-tag');
        eyebrow.appendChild(el('i', 'fa-solid fa-fire'));
        eyebrow.appendChild(document.createTextNode(' ' + tag));
        copy.appendChild(eyebrow);

        const stats = el('div', 'kp-hero-stats');
        if (data.s) stats.appendChild(stat(data.s, 'fa-solid fa-star', 'kp-hero-badge-star'));
        if (data.f) stats.appendChild(stat(data.f));
        if (data.e) stats.appendChild(stat(data.e + ' Ep', 'fa-solid fa-closed-captioning'));
        if (data.y) stats.appendChild(stat(String(data.y)));
        if (stats.childElementCount) copy.appendChild(stats);

        copy.appendChild(el('h2', 'kp-hero-title', data.t || ''));

        if (data.x) copy.appendChild(el('p', 'kp-hero-desc', data.x));

        const actions = el('div', 'kp-hero-actions');
        actions.appendChild(actionLink(data.u, 'fa-solid fa-play', 'kp-hero-watch', 'Watch Now'));
        if (data.i) {
            actions.appendChild(actionLink(
                './watch.php?id=' + encodeURIComponent(data.i),
                'fa-solid fa-circle-info',
                'kp-hero-details',
                'Details'
            ));
        }
        copy.appendChild(actions);

        body.appendChild(copy);

        slide.appendChild(bg);
        slide.appendChild(shade);
        slide.appendChild(body);

        applyLogo(slide);
    }

    // ─── Navigation ─────────────────────────────────────────────────
    function show(next, userInitiated) {
        const target = (next + slides.length) % slides.length;
        if (target === index && built.has(target)) {
            return;
        }

        buildSlide(slides[target]);
        applyLogo(slides[target]);

        slides[index].classList.remove('is-active');
        slides[index].removeAttribute('aria-current');
        slides[index].setAttribute('aria-hidden', 'true');
        slides[target].classList.add('is-active');
        slides[target].setAttribute('aria-current', 'true');
        slides[target].removeAttribute('aria-hidden');

        if (dots[index]) dots[index].classList.remove('is-active');
        if (dots[target]) dots[target].classList.add('is-active');
        if (counter) counter.textContent = String(target + 1).padStart(2, '0');

        // Off-screen slides should not be tabbable — including their CTAs.
        slides.forEach((s, i) => {
            const active = i === target;
            if (active) s.removeAttribute('tabindex');
            else s.setAttribute('tabindex', '-1');
            s.querySelectorAll('a, button').forEach((node) => {
                if (active) node.removeAttribute('tabindex');
                else node.setAttribute('tabindex', '-1');
            });
        });

        index = target;

        if (userInitiated) restart();
    }

    const next = (user) => show(index + 1, user);
    const prev = (user) => show(index - 1, user);

    // ─── Auto-advance ───────────────────────────────────────────────
    function start() {
        stop();
        if (paused || pausedByUser || reduceMotion.matches) return;
        if (document.hidden) return;
        timer = setInterval(() => next(false), interval);
    }

    function stop() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    function restart() {
        stop();
        start();
    }

    function setPaused(value, byUser) {
        paused = value;
        if (byUser) pausedByUser = value;
        if (pauseBtn) {
            const icon = pauseBtn.querySelector('i');
            if (icon) icon.className = value ? 'fa-solid fa-play' : 'fa-solid fa-pause';
            pauseBtn.setAttribute('aria-label', value ? 'Play slideshow' : 'Pause slideshow');
        }
        if (value) stop();
        else start();
    }

    // ─── Wiring ─────────────────────────────────────────────────────
    const nextBtn = root.querySelector('[data-kp-next]');
    const prevBtn = root.querySelector('[data-kp-prev]');
    if (nextBtn) nextBtn.addEventListener('click', (e) => { e.preventDefault(); next(true); });
    if (prevBtn) prevBtn.addEventListener('click', (e) => { e.preventDefault(); prev(true); });

    dots.forEach((dot) => {
        dot.addEventListener('click', (e) => {
            e.preventDefault();
            show(parseInt(dot.dataset.kpGoto, 10) || 0, true);
        });
    });

    if (pauseBtn) {
        pauseBtn.addEventListener('click', (e) => {
            e.preventDefault();
            setPaused(!paused, true);
        });
    }

    root.addEventListener('mouseenter', () => setPaused(true, false));
    root.addEventListener('mouseleave', () => setPaused(false, false));
    root.addEventListener('focusin', () => setPaused(true, false));
    root.addEventListener('focusout', () => setPaused(false, false));

    root.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); next(true); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); prev(true); }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop();
        else start();
    });

    reduceMotion.addEventListener('change', () => restart());

    // Touch swipe.
    let startX = null;
    let startY = null;
    root.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        stop();
    }, { passive: true });

    root.addEventListener('touchend', (e) => {
        if (startX === null) return;
        const dx = e.changedTouches[0].clientX - startX;
        const dy = e.changedTouches[0].clientY - startY;
        // Ignore mostly-vertical drags so page scrolling still works.
        if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
            if (dx < 0) next(true); else prev(true);
        }
        startX = startY = null;
        start();
    }, { passive: true });

    slides.forEach((s, i) => { if (i !== 0) s.setAttribute('tabindex', '-1'); });

    // A single banner still gets its logo; it just has nothing to rotate.
    loadLogos();

    // On coarse-pointer (touch) devices, skip auto-rotation and idle-callback
    // warmup to save CPU/battery on low-end mobile.
    if (!window.matchMedia('(pointer: coarse)').matches) {
        start();

        // Prefetch the next slide's images once the page has settled.
        const warm = () => {
            const upcoming = slides[(index + 1) % slides.length];
            if (upcoming) buildSlide(upcoming);
        };
        if ('requestIdleCallback' in window) {
            requestIdleCallback(warm, { timeout: 3000 });
        } else {
            setTimeout(warm, 2500);
        }
    }
})();
