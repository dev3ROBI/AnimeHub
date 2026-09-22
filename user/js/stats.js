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
            renderGenres(data.top_genres || {});
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

    // ─── 30 day heatmap (intensity from watch seconds) ──────────────
    function renderHeatmap(heatmap, episodes) {
        var el = document.getElementById('heatmap-grid');
        if (!el) return;
        el.innerHTML = '';

        var colors = ['#2d2d2d', '#4a1525', '#8c1c3c', '#c9184a', '#ff2e63'];
        var today = new Date();

        for (var i = 29; i >= 0; i--) {
            var d = new Date(today);
            d.setDate(d.getDate() - i);
            var key = localKey(d);
            var seconds = Number(heatmap[key]) || 0;
            var count = Number(episodes[key]) || 0;

            var cell = document.createElement('div');
            cell.className = 'kp-heatmap-cell';

            // 0 / <30m / <1h / <2h / 2h+
            var intensity = 0;
            if (seconds > 0) {
                intensity = seconds < 1800 ? 1 : seconds < 3600 ? 2 : seconds < 7200 ? 3 : 4;
            }
            cell.style.background = colors[intensity];
            cell.title = key + ' — ' + (seconds > 0 ? kpFmtTime(seconds) : 'no watch time')
                + (count > 0 ? ' (' + count + ' episode' + (count === 1 ? '' : 's') + ')' : '');
            el.appendChild(cell);
        }
    }

    // ─── Top genres ────────────────────────────────────────────────
    function renderGenres(genres) {
        var el = document.getElementById('genre-bars');
        if (!el) return;
        el.innerHTML = '';

        var names = Object.keys(genres);
        if (!names.length) {
            el.innerHTML = '<p class="kp-stats-empty">No genre data yet. Start watching!</p>';
            return;
        }

        var maxVal = Math.max.apply(null, names.map(function (g) { return genres[g] || 0; }).concat([1]));
        var colors = ['#ff2e63', '#a78bfa', '#f97316', '#1f6feb', '#238636', '#eab308', '#06b6d4', '#ec4899'];

        names.forEach(function (genre, idx) {
            var pct = Math.round(((genres[genre] || 0) / maxVal) * 100);
            var row = document.createElement('div');
            row.className = 'kp-genre-row';
            row.innerHTML = '<span class="kp-genre-label"></span>'
                + '<div class="kp-genre-track"><div class="kp-genre-fill" style="width:' + pct + '%;background:' + colors[idx % colors.length] + ';"></div></div>'
                + '<span class="kp-genre-count">' + (genres[genre] || 0) + '</span>';
            row.querySelector('.kp-genre-label').textContent = genre;
            el.appendChild(row);
        });
    }

    // ─── Weekly pattern (seconds, or episode counts on old accounts) ──
    function renderWeekly(weekly, unit) {
        var el = document.getElementById('weekly-bars');
        if (!el) return;
        el.innerHTML = '';

        var note = document.getElementById('weekly-unit-note');
        if (note) note.textContent = unit === 'seconds' ? 'Watch time per weekday' : 'Episodes per weekday';

        var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var maxWeek = Math.max.apply(null, days.map(function (d) { return weekly[d] || 0; }).concat([1]));

        days.forEach(function (d) {
            var value = weekly[d] || 0;
            var pct = Math.round((value / maxWeek) * 100);

            var bar = document.createElement('div');
            bar.className = 'kp-weekly-bar';
            bar.innerHTML = '<div class="kp-weekly-fill" style="height:' + Math.max(4, pct) + '%;"></div>'
                + '<span class="kp-weekly-day">' + d + '</span>'
                + '<span class="kp-weekly-count">' + (unit === 'seconds' ? kpFmtTime(value) : value) + '</span>';
            el.appendChild(bar);
        });
    }
})();
