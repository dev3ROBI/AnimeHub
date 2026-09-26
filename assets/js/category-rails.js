/**
 * Category rails — lazy filler.
 *
 * Every rail the server did not resolve up front ships as a row of skeletons
 * with data-rail-lazy="1". This watches for one coming near the viewport and
 * swaps in its cards from includes/category_items.php, once each.
 *
 * Nothing happens on a page with no lazy rails (Movies/Series/Anime only), and
 * a failed fetch leaves the skeletons in place instead of an empty gap, with a
 * tap-to-retry on the rail.
 */
(function () {
    'use strict';

    var rails = document.querySelectorAll('.kp-rail[data-rail-lazy]');
    if (!rails.length) return;

    var endpoint = './includes/category_items.php';
    var busy = false;

    function fill(rail) {
        var kind = rail.getAttribute('data-rail-kind') || '';
        var key = rail.getAttribute('data-rail') || '';
        var box = rail.querySelector('.kp-cat-rail');
        if (!key || !box) return;

        rail.removeAttribute('data-rail-lazy');
        rail.classList.add('is-loading');

        fetch(endpoint + '?kind=' + encodeURIComponent(kind) + '&key=' + encodeURIComponent(key), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || typeof data.html !== 'string') throw new Error('bad payload');
                box.innerHTML = data.html ||
                    '<p class="kp-rail-empty"><i class="fa-solid fa-ghost"></i> No titles here yet.</p>';
                rail.classList.remove('is-loading');
            })
            .catch(function () {
                // Keep the skeletons for a tap-to-retry rather than collapsing
                // the row and shifting everything below it.
                rail.classList.remove('is-loading');
                rail.classList.add('is-error');
                rail.setAttribute('data-rail-lazy', '1');
            })
            .finally(function () { busy = false; });
    }

    function queue(rail) {
        if (busy) {
            // One rail per connection: they are light, but a page can carry a
            // dozen and queueing them keeps the main thread and bandwidth for
            // the hero images above.
            setTimeout(function () { queue(rail); }, 120);
            return;
        }
        if (!rail.hasAttribute('data-rail-lazy')) return;
        busy = true;
        fill(rail);
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                queue(entry.target);
            });
        }, { rootMargin: '300px 0px' });

        Array.prototype.forEach.call(rails, function (rail) {
            observer.observe(rail);
        });
    } else {
        // No observer: load them all, one at a time.
        Array.prototype.forEach.call(rails, function (rail) { queue(rail); });
    }

    // Retry on tap when a rail failed to load.
    document.addEventListener('click', function (e) {
        var rail = e.target.closest ? e.target.closest('.kp-rail.is-error') : null;
        if (!rail) return;
        rail.classList.remove('is-error');
        queue(rail);
    });
})();
