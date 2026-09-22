/**
 * Notification tab — loads and displays notifications with filters, pagination, and preferences.
 */
(function() {
    const container = document.getElementById('kp-noti-list');
    const unreadBadge = document.getElementById('kp-noti-unread-badge');
    const pagination = document.getElementById('kp-noti-pagination');
    const pageInfo = document.getElementById('kp-noti-page-info');
    const prevBtn = document.getElementById('kp-noti-prev');
    const nextBtn = document.getElementById('kp-noti-next');
    const markAllBtn = document.getElementById('kp-noti-mark-all');
    const clearAllBtn = document.getElementById('kp-noti-clear-all');
    if (!container) return;

    let currentPage = 1;
    let currentFilter = 'all';
    let totalPages = 1;

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return new Date(dateStr).toLocaleDateString();
    }

    function watchUrl(slug) {
        return './watch.php?id=' + encodeURIComponent(slug);
    }

    function typeIcon(type) {
        switch (type) {
            case 'episode': return 'fa-solid fa-tv';
            case 'follow':  return 'fa-solid fa-heart';
            case 'system':  return 'fa-solid fa-circle-info';
            default:        return 'fa-solid fa-bell';
        }
    }

    function renderNotification(n) {
        var div = document.createElement('div');
        div.className = 'kp-noti-item' + (n.is_read ? '' : ' kp-noti-unread');
        div.setAttribute('data-id', n.id || '');

        var iconColor = n.is_read ? '#555' : '#ff2e63';

        div.innerHTML =
            '<div class="kp-noti-icon" style="color:' + iconColor + ';">'
          +   '<i class="' + typeIcon(n.type) + '"></i>'
          + '</div>'
          + '<div class="kp-noti-body">'
          +   '<div class="kp-noti-title">' + (n.title || 'Unknown Anime') + '</div>'
          +   '<div class="kp-noti-msg">' + (n.message || '') + '</div>'
          +   '<div class="kp-noti-time">' + timeAgo(n.time) + '</div>'
          + '</div>'
          + '<div class="kp-noti-item-actions">'
          +   '<a class="kp-noti-link" href="' + watchUrl(n.slug) + '" title="Watch now">'
          +     '<i class="fas fa-play"></i>'
          +   '</a>'
          +   '<button class="kp-noti-del-btn" title="Delete">'
          +     '<i class="fas fa-xmark"></i>'
          +   '</button>'
          + '</div>';

        // Mark as read on click
        div.addEventListener('click', function(e) {
            if (e.target.closest('.kp-noti-del-btn') || e.target.closest('.kp-noti-link')) return;
            if (!n.is_read) {
                fetch('./includes/mark_notification_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: n.id })
                });
                div.classList.remove('kp-noti-unread');
                updateUnreadBadge(-1);
            }
        });

        // Delete button
        var delBtn = div.querySelector('.kp-noti-del-btn');
        if (delBtn) {
            delBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                div.style.opacity = '0';
                div.style.transform = 'translateX(20px)';
                setTimeout(function() { div.remove(); }, 200);
                if (!n.is_read) updateUnreadBadge(-1);
                fetch('./includes/delete_notification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: n.id })
                });
            });
        }

        return div;
    }

    function renderEmpty(msg) {
        container.innerHTML =
            '<div class="kp-empty-state">'
          +   '<i class="fas fa-bell-slash"></i>'
          +   '<h4>' + (msg || 'No notifications yet') + '</h4>'
          +   '<p>Follow anime or start watching to get notified about new episodes.</p>'
          +   '<a class="kp-empty-cta" href="./home.php"><i class="fas fa-fire"></i> Browse trending</a>'
          + '</div>';
    }

    function updateUnreadBadge(delta) {
        if (!unreadBadge) return;
        var current = parseInt(unreadBadge.textContent) || 0;
        var next = Math.max(0, current + delta);
        unreadBadge.textContent = next > 0 ? next + ' unread' : '';
    }

    function loadNotifications() {
        var url = './includes/check_notifications.php?page=' + currentPage + '&limit=15';
        if (currentFilter === 'unread') {
            // Client-side filter for unread — we load all and filter
            url = './includes/check_notifications.php?page=' + currentPage + '&limit=50';
        } else if (currentFilter !== 'all') {
            url += '&type=' + currentFilter;
        }

        container.innerHTML = '<div class="kp-cw-loading"><span></span><span></span><span></span></div>';

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var notifs = data.notifications || [];
                var unread = data.unread || 0;
                totalPages = data.pages || 1;

                if (unreadBadge) {
                    unreadBadge.textContent = unread > 0 ? unread + ' unread' : '';
                }

                // Filter for "unread" tab
                if (currentFilter === 'unread') {
                    notifs = notifs.filter(function(n) { return !n.is_read; });
                }

                if (notifs.length === 0) {
                    renderEmpty(currentFilter === 'unread' ? 'No unread notifications' : 'No notifications yet');
                    if (pagination) pagination.style.display = 'none';
                    return;
                }

                container.innerHTML = '';
                notifs.forEach(function(n) {
                    container.appendChild(renderNotification(n));
                });

                // Pagination
                if (pagination) {
                    if (totalPages > 1) {
                        pagination.style.display = 'flex';
                        if (pageInfo) pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages;
                        if (prevBtn) prevBtn.disabled = currentPage <= 1;
                        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
                    } else {
                        pagination.style.display = 'none';
                    }
                }
            })
            .catch(function() {
                container.innerHTML =
                    '<div class="kp-empty-state">'
                  +   '<i class="fas fa-triangle-exclamation"></i>'
                  +   '<h4>Failed to load notifications</h4>'
                  +   '<p>Please refresh the page and try again.</p>'
                  + '</div>';
            });
    }

    // Filter buttons
    document.querySelectorAll('.kp-noti-filter').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.kp-noti-filter').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.getAttribute('data-filter');
            currentPage = 1;
            loadNotifications();
        });
    });

    // Pagination
    if (prevBtn) prevBtn.addEventListener('click', function() {
        if (currentPage > 1) { currentPage--; loadNotifications(); }
    });
    if (nextBtn) nextBtn.addEventListener('click', function() {
        if (currentPage < totalPages) { currentPage++; loadNotifications(); }
    });

    // Mark all read
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function() {
            fetch('./includes/mark_notifications_read.php', { method: 'POST' })
                .then(function() {
                    document.querySelectorAll('#kp-noti-list .kp-noti-unread').forEach(function(el) {
                        el.classList.remove('kp-noti-unread');
                    });
                    if (unreadBadge) unreadBadge.textContent = '';
                });
        });
    }

    // Clear all
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function() {
            renderEmpty('All cleared');
            if (unreadBadge) unreadBadge.textContent = '';
            if (pagination) pagination.style.display = 'none';
            fetch('./includes/delete_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ clear_all: true })
            });
        });
    }

    // Load preferences
    fetch('./includes/notification_settings.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.settings) return;
            var s = data.settings;
            var ep = document.getElementById('kp-pref-episode');
            var fo = document.getElementById('kp-pref-follow');
            var sy = document.getElementById('kp-pref-system');
            var so = document.getElementById('kp-pref-sound');
            var to = document.getElementById('kp-pref-toast');
            if (ep) ep.checked = !!s.episode_alerts;
            if (fo) fo.checked = !!s.follow_alerts;
            if (sy) sy.checked = !!s.system_alerts;
            if (so) so.checked = !!s.sound_enabled;
            if (to) to.checked = !!s.toast_enabled;
        });

    // Save preferences on toggle
    document.querySelectorAll('.kp-noti-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var payload = {
                episode_alerts: document.getElementById('kp-pref-episode') ? (document.getElementById('kp-pref-episode').checked ? 1 : 0) : 1,
                follow_alerts:  document.getElementById('kp-pref-follow')  ? (document.getElementById('kp-pref-follow').checked  ? 1 : 0) : 1,
                system_alerts:  document.getElementById('kp-pref-system')  ? (document.getElementById('kp-pref-system').checked  ? 1 : 0) : 1,
                sound_enabled:  document.getElementById('kp-pref-sound')  ? (document.getElementById('kp-pref-sound').checked  ? 1 : 0) : 0,
                toast_enabled:  document.getElementById('kp-pref-toast')  ? (document.getElementById('kp-pref-toast').checked  ? 1 : 0) : 1,
            };
            fetch('./includes/notification_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        });
    });

    loadNotifications();
})();
