/**
 * Stats tab — renders the watch statistics from get_watch_stats.php.
 *
 * Watch time is stored in seconds, so everything here is formatted around
 * `kpFmtTime`: hours cards stay readable ("12h 30m") while the heatmap and the
 * weekly chart stay proportional.
 */
(function () {
    var loading = document.getElementById('stats-loading');
    var content = document.getElementById('stats-content');

    /** 3725 -> "1h 2m", 125 -> "2m 5s", 45 -> "45s". */
    function kpFmtTime(seconds) {
        seconds = Math.max(0, Math.round(Number(seconds) || 0));
        if (seconds === 0) return '0m';

        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;

        if (h > 0) return h + 'h ' + (m > 0 ? m + 'm' : '0m');
        if (m > 0) return m + 'm ' + s + 's';
        return s + 's';
    }

    /** 3725 -> "1:02:05" (matches the server-side clock format). */
    function kpClock(seconds) {
        seconds = Math.max(0, Math.round(Number(seconds) || 0));
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function localKey(date) {
        // toISOString() is UTC — build the key from local parts so "today" lines
        // up with the server's DATE() for users outside UTC.
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    fetch('./includes/get_watch_stats.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (loading) loading.style.display = 'none';
            if (content) content.style.display = 'block';

            if (!data || data.error) throw new Error((data && data.error) || 'no data');

            var totalSeconds = Number(data.total_seconds) || 0;

            setText('stat-hours', data.total_hours || 0);
            setText('stat-hours-sub', totalSeconds > 0 ? kpClock(totalSeconds) + ' total' : 'No watch time yet');
            setText('stat-episodes', data.total_episodes || 0);
            setText('stat-episodes-sub', data.watched_episodes
                ? data.watched_episodes + ' tracked'
                : (data.avg_episode_time ? '~' + data.avg_episode_time + ' avg' : ''));
            setText('stat-anime', data.total_anime || 0);
            setText('stat-anime-sub', data.avg_episode_time ? '~' + data.avg_episode_time + '/ep' : '');
            setText('stat-week', kpFmtTime(data.week_seconds || 0));
            setText('stat-week-sub', 'last 7 days');

            renderTopAnime(data.top_anime || []);
            renderHeatmap(data.heatmap || {}, data.heatmap_episodes || {});
            renderGenres(data.top_genres || [], data.top_genre_basis || 0);
            renderWeekly(data.weekly_pattern || {}, data.weekly_unit || 'episodes');
        })
        .catch(function () {
            if (loading) {
                loading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#ff6b6b;"></i>'
                    + '<p style="margin-top:8px;">Failed to load stats</p>';
            }
        });

    // ─── Most watched series ────────────────────────────────────────
    function renderTopAnime(list) {
        var el = document.getElementById('top-anime-list');
        if (!el) return;
        el.innerHTML = '';

        if (!list.length) {
            el.innerHTML = '<p class="kp-stats-empty">Nothing tracked yet — watch an episode and it will show up here.</p>';
            return;
        }

        var max = Math.max.apply(null, list.map(function (a) { return a.seconds || 0; }).concat([1]));

        list.forEach(function (anime) {
            var row = document.createElement('a');
            row.className = 'kp-top-anime-row';
            row.href = anime.url || '#';

            var poster = document.createElement('div');
            poster.className = 'kp-top-anime-poster';
            if (anime.poster) {
                var img = document.createElement('img');
                img.src = anime.poster;
                img.alt = '';
                img.loading = 'lazy';
                img.onerror = function () { img.remove(); };
                poster.appendChild(img);
            }
            row.appendChild(poster);

            var main = document.createElement('div');
            main.className = 'kp-top-anime-main';

            var title = document.createElement('span');
            title.className = 'kp-top-anime-title';
            title.textContent = anime.title || anime.slug || 'Unknown';
            main.appendChild(title);

            var track = document.createElement('div');
            track.className = 'kp-top-anime-track';
            var fill = document.createElement('div');
            fill.className = 'kp-top-anime-fill';
            fill.style.width = Math.round((anime.seconds / max) * 100) + '%';
            track.appendChild(fill);
            main.appendChild(track);

            row.appendChild(main);

            var meta = document.createElement('div');
            meta.className = 'kp-top-anime-meta';
            var time = document.createElement('span');
            time.className = 'kp-top-anime-time';
            time.textContent = kpFmtTime(anime.seconds);
            var eps = document.createElement('span');
            eps.className = 'kp-top-anime-eps';
            eps.textContent = (anime.episodes || 0) + ' ep';
            meta.appendChild(time);
            meta.appendChild(eps);
            row.appendChild(meta);

            el.appendChild(row);
        });
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ─── 30 day heatmap (intensity from watch seconds) ──────────────
    // Every cell is a real button: tapping one opens that day's breakdown, and
    // the arrow keys walk from day to day instead of tabbing through 30 cells.
    function renderHeatmap(heatmap, episodes) {
        var el = document.getElementById('heatmap-grid');
        if (!el) return;
        el.innerHTML = '';

        var colors = ['#2d2d2d', '#4a1525', '#8c1c3c', '#c9184a', '#ff2e63'];
        var today = new Date();
        var cells = [];

        var dayTotal = 0;
        var activeDays = 0;
        var best = { key: '', seconds: 0 };

        for (var i = 29; i >= 0; i--) {
            var d = new Date(today);
            d.setDate(d.getDate() - i);
            var key = localKey(d);
            var seconds = Number(heatmap[key]) || 0;
            var count = Number(episodes[key]) || 0;

            dayTotal += seconds;
            if (seconds > 0) activeDays++;
            if (seconds > best.seconds) best = { key: key, seconds: seconds };

            var cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'kp-heatmap-cell';
            cell.setAttribute('data-kp-day', key);

            // 0 / <30m / <1h / <2h / 2h+
            var intensity = 0;
            if (seconds > 0) {
                intensity = seconds < 1800 ? 1 : seconds < 3600 ? 2 : seconds < 7200 ? 3 : 4;
            }
            cell.style.background = colors[intensity];
            if (i === 0) cell.classList.add('is-today');

            var label = key + ' — ' + (seconds > 0 ? kpFmtTime(seconds) : 'no watch time');
            cell.setAttribute('aria-label', label);
            cell.title = label + (count > 0 ? ' (' + count + ' episode' + (count === 1 ? '' : 's') + ')' : '');

            (function (dayKey) {
                cell.addEventListener('click', function () { openDay(dayKey); });
            })(key);

            el.appendChild(cell);
            cells.push(cell);
        }

        el.addEventListener('keydown', function (e) {
            var idx = cells.indexOf(document.activeElement);
            if (idx === -1) return;
            var step = e.key === 'ArrowLeft' ? -1 : e.key === 'ArrowRight' ? 1 : 0;
            if (!step) return;
            var next = idx + step;
            if (next < 0 || next >= cells.length) return;
            e.preventDefault();
            cells[next].focus();
        });

        renderHeatSummary(dayTotal, activeDays, best);
    }

    // Totals for the 30 day strip: the numbers a colour ramp cannot show.
    function renderHeatSummary(totalSeconds, activeDays, best) {
        var el = document.getElementById('heatmap-stats');
        if (!el) return;

        if (totalSeconds <= 0) {
            el.innerHTML = '<span class="kp-heat-note">No watch time in the last 30 days yet.</span>';
            return;
        }

        var avg = Math.round(totalSeconds / Math.max(1, activeDays));
        var bestDay = best.key ? best.key : '';

        el.innerHTML =
            '<span class="kp-heat-stat"><span class="kp-heat-stat-value">' + kpFmtTime(totalSeconds) + '</span><span class="kp-heat-stat-label">Last 30 days</span></span>'
          + '<span class="kp-heat-stat"><span class="kp-heat-stat-value">' + activeDays + '</span><span class="kp-heat-stat-label">Active days</span></span>'
          + '<span class="kp-heat-stat"><span class="kp-heat-stat-value">' + kpFmtTime(avg) + '</span><span class="kp-heat-stat-label">Daily average</span></span>'
          + (bestDay
                ? '<button type="button" class="kp-heat-stat is-best" data-kp-day="' + bestDay + '">'
                + '<span class="kp-heat-stat-value">' + kpFmtTime(best.seconds) + '</span>'
                + '<span class="kp-heat-stat-label">Best day · ' + bestDay.slice(5) + '</span></button>'
                : '');

        var bestBtn = el.querySelector('.is-best');
        if (bestBtn) bestBtn.addEventListener('click', function () { openDay(bestDay); });
    }

    // ─── Day detail modal ───────────────────────────────────────────
    // The per-day breakdown comes from get_day_stats.php, which merges the
    // seconds watched with the episodes opened on that date.
    var dayModal = document.getElementById('kp-day-modal');
    var dayBody = document.getElementById('kp-day-body');
    var dayTitle = document.getElementById('kp-day-title');

    // The tab fragment is injected inside a container that animates with a
    // transform, and a transformed ancestor makes `position: fixed` relative to
    // itself — a modal declared in there would be centred on the tab content
    // (often off screen). Hoisting it to <body> keeps it a real overlay, and the
    // drop of the previous copy stops one lingering per tab visit.
    if (dayModal) {
        Array.prototype.slice.call(document.body.children).forEach(function (node) {
            if (node !== dayModal && node.classList && node.classList.contains('kp-day-modal')) {
                node.remove();
            }
        });
        if (dayModal.parentNode !== document.body) document.body.appendChild(dayModal);
    }

    function closeDay() {
        if (!dayModal || dayModal.hidden) return;
        dayModal.hidden = true;
        document.body.classList.remove('kp-modal-open');
    }

    function openDay(key) {
        if (!dayModal || !key) return;

        if (dayTitle) dayTitle.textContent = key;
        if (dayBody) dayBody.innerHTML = '<div class="kp-cw-loading"><span></span><span></span><span></span></div>';
        dayModal.hidden = false;
        document.body.classList.add('kp-modal-open');

        fetch('./includes/get_day_stats.php?date=' + encodeURIComponent(key))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) throw new Error('no data');
                renderDay(data);
            })
            .catch(function () {
                if (dayBody) dayBody.innerHTML = '<p class="kp-stats-empty">Could not load that day. Please try again.</p>';
            });
    }

    function renderDay(data) {
        if (!dayBody) return;
        if (dayTitle) dayTitle.textContent = data.label || data.date || '';

        var html = '<div class="kp-day-stats">'
            + '<span class="kp-day-stat"><span class="kp-day-stat-value">' + kpFmtTime(data.seconds || 0) + '</span><span class="kp-day-stat-label">Watch time</span></span>'
            + '<span class="kp-day-stat"><span class="kp-day-stat-value">' + (data.episodes || 0) + '</span><span class="kp-day-stat-label">Episodes</span></span>'
            + '<span class="kp-day-stat"><span class="kp-day-stat-value">' + (data.titles || 0) + '</span><span class="kp-day-stat-label">Titles</span></span>'
            + '</div>';

        if (!data.items || !data.items.length) {
            html += '<p class="kp-stats-empty">Nothing was played on this day.</p>';
        } else {
            html += '<div class="kp-day-list">';
            data.items.forEach(function (item) {
                var chips = (item.labels || []).map(function (l) {
                    return '<span class="kp-day-chip">' + escapeHtml(l) + '</span>';
                }).join('');
                if (item.more > 0) chips += '<span class="kp-day-chip is-more">+' + item.more + '</span>';

                html += '<a class="kp-day-row" href="' + escapeHtml(item.url || '#') + '">'
                    + '<span class="kp-day-poster">' + (item.poster ? '<img src="' + escapeHtml(item.poster) + '" alt="" loading="lazy">' : '') + '</span>'
                    + '<span class="kp-day-main">'
                    +   '<span class="kp-day-name">' + escapeHtml(item.title || item.slug || '') + '</span>'
                    +   (chips ? '<span class="kp-day-chips">' + chips + '</span>' : '')
                    + '</span>'
                    + '<span class="kp-day-meta">'
                    +   '<span class="kp-day-time">' + kpFmtTime(item.seconds || 0) + '</span>'
                    +   '<span class="kp-day-eps">' + (item.episodes || 0) + ' ep</span>'
                    + '</span>'
                    + '</a>';
            });
            html += '</div>';
        }

        dayBody.innerHTML = html;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-kp-day-close]'), function (el) {
        el.addEventListener('click', closeDay);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDay();
    });

    // ─── Top genres ────────────────────────────────────────────────
    // Weighted by seconds actually watched (not by how many episodes a show
    // happens to have), so one long series cannot dominate the list.
    function renderGenres(list, basis) {
        var el = document.getElementById('genre-bars');
        if (!el) return;
        el.innerHTML = '';

        var note = document.getElementById('genre-note');
        if (note) {
            note.textContent = basis > 0
                ? 'Weighted by watch time · ' + basis + ' title' + (basis === 1 ? '' : 's')
                : 'Weighted by watch time';
        }

        if (!list || !list.length) {
            el.innerHTML = '<p class="kp-stats-empty">No genre data yet. Watch a few titles and this fills in.</p>';
            return;
        }

        var maxVal = Math.max.apply(null, list.map(function (g) { return g.seconds || 0; }).concat([1]));
        var colors = ['#ff2e63', '#a78bfa', '#f97316', '#1f6feb', '#238636', '#eab308', '#06b6d4', '#ec4899'];

        list.forEach(function (genre, idx) {
            var seconds = Number(genre.seconds) || 0;
            var pct = Math.round((seconds / maxVal) * 100);

            var row = document.createElement('div');
            row.className = 'kp-genre-row';
            row.innerHTML = '<span class="kp-genre-label"></span>'
                + '<div class="kp-genre-track"><div class="kp-genre-fill" style="width:' + Math.max(2, pct) + '%;background:' + colors[idx % colors.length] + ';"></div></div>'
                + '<span class="kp-genre-meta"><span class="kp-genre-time"></span><span class="kp-genre-count"></span></span>';

            row.querySelector('.kp-genre-label').textContent = genre.name || '';
            row.querySelector('.kp-genre-time').textContent = kpFmtTime(seconds);
            row.querySelector('.kp-genre-count').textContent = (genre.episodes || 0) + ' ep';
            row.title = (genre.name || '') + ' — ' + kpFmtTime(seconds) + ' across ' + (genre.episodes || 0) + ' episode' + ((genre.episodes || 0) === 1 ? '' : 's');

            el.appendChild(row);
        });
    }

    // ─── Weekly pattern (seconds, or episode counts on old accounts) ──
    // The unit comes from whichever table answered, and the busiest weekday is
    // called out so the chart reads as an answer, not just seven bars.
    function renderWeekly(weekly, unit) {
        var el = document.getElementById('weekly-bars');
        if (!el) return;
        el.innerHTML = '';

        var isTime = unit === 'seconds';
        var fmt = function (v) { return isTime ? kpFmtTime(v) : String(v); };

        var note = document.getElementById('weekly-unit-note');
        if (note) note.textContent = isTime ? 'All-time watch time per weekday' : 'Episodes per weekday (older history)';

        var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var values = days.map(function (d) { return Number(weekly[d]) || 0; });
        var maxWeek = Math.max.apply(null, values.concat([1]));
        var total = values.reduce(function (a, b) { return a + b; }, 0);
        var bestValue = Math.max.apply(null, values);
        var bestIdx = values.indexOf(bestValue);

        days.forEach(function (d, i) {
            var value = values[i];
            var pct = Math.round((value / maxWeek) * 100);

            var bar = document.createElement('div');
            bar.className = 'kp-weekly-bar' + (total > 0 && i === bestIdx ? ' is-best' : '');
            bar.innerHTML = '<div class="kp-weekly-fill" style="height:' + Math.max(4, pct) + '%;"></div>'
                + '<span class="kp-weekly-day">' + d + '</span>'
                + '<span class="kp-weekly-count">' + fmt(value) + '</span>';
            bar.title = d + ' — ' + fmt(value);
            el.appendChild(bar);
        });

        var summary = document.getElementById('weekly-summary');
        if (summary) {
            if (total <= 0) {
                summary.textContent = 'No watch activity recorded yet.';
            } else {
                summary.innerHTML = 'Your biggest day is <strong>' + days[bestIdx] + '</strong> with '
                    + fmt(bestValue) + ' — weekly total ' + fmt(total) + '.';
            }
        }
    }
})();
