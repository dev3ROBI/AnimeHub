<?php
/**
 * Card watchlist modal + behaviour — shared by every page that renders cards.
 *
 * render_anime_card() stamps every card with a `+` button (openCardWatchlist),
 * and this include provides the one modal + fetch handlers behind it. It is
 * pulled in by includes/footer.php so home, genre, schedule, movies, TV and
 * search pages all get the same add-to-list flow without duplicating markup.
 *
 * Guests get a toast pointing at the login page instead of a silent failure.
 */
?>
<!-- Watchlist Modal (shared — opened by the + button on every card) -->
<div class="kp-wl-modal-overlay" id="kpWlModal">
    <div class="kp-wl-modal">
        <div class="kp-wl-modal-header">
            <h4><i class="fas fa-list-ul"></i> Add to List</h4>
            <button type="button" class="kp-wl-modal-close" onclick="closeCardWatchlist()"><i class="fas fa-xmark"></i></button>
        </div>
        <ul class="kp-wl-modal-list" id="kpWlList">
            <li data-status="watching" onclick="saveCardWatchlist('watching')"><i class="fas fa-eye"></i> Watching</li>
            <li data-status="watch_later" onclick="saveCardWatchlist('watch_later')"><i class="fas fa-bookmark"></i> Planning</li>
            <li data-status="completed" onclick="saveCardWatchlist('completed')"><i class="fas fa-check-circle"></i> Completed</li>
            <li data-status="on_hold" onclick="saveCardWatchlist('on_hold')"><i class="fas fa-pause-circle"></i> On Hold</li>
            <li data-status="dropped" onclick="saveCardWatchlist('dropped')"><i class="fas fa-trash-can"></i> Dropped</li>
        </ul>
        <button type="button" class="kp-wl-modal-remove" id="kpWlRemove" onclick="removeCardWatchlist()" style="display:none;">
            <i class="fas fa-trash-can"></i> Remove from List
        </button>
    </div>
</div>

<script>
(function() {
    let wlCardId = null;
    let wlSaving = false;
    const modal = document.getElementById('kpWlModal');
    const list = document.getElementById('kpWlList');
    const removeBtn = document.getElementById('kpWlRemove');
    const isLoggedIn = document.body.dataset.user === '1';

    function toast(msg, type) {
        if (typeof kpToast === 'function') kpToast(msg, type);
    }

    window.openCardWatchlist = async function(movieCard) {
        if (wlSaving) return;
        wlCardId = movieCard?.dataset?.id || null;
        if (!wlCardId) return;
        if (!isLoggedIn) {
            toast('Log in to add titles to your watch list', 'info');
            return;
        }
        list.querySelectorAll('li').forEach(li => li.classList.remove('is-active'));
        removeBtn.style.display = 'none';
        modal.classList.add('is-open');
        try {
            const resp = await fetch('includes/check_watchlist.php?imdb_id=' + encodeURIComponent(wlCardId));
            const data = await resp.json();
            if (data.success && data.status) {
                const active = list.querySelector('li[data-status="' + data.status + '"]');
                if (active) active.classList.add('is-active');
                removeBtn.style.display = 'block';
            }
        } catch(e) {}
    };

    window.closeCardWatchlist = function() {
        modal.classList.remove('is-open');
        wlCardId = null;
    };

    window.saveCardWatchlist = async function(status) {
        if (!wlCardId || wlSaving) return;
        const activeItem = list.querySelector('li.is-active');
        if (activeItem && activeItem.dataset.status === status) { closeCardWatchlist(); return; }
        wlSaving = true;
        list.querySelectorAll('li').forEach(li => li.classList.toggle('is-active', li.dataset.status === status));
        removeBtn.style.display = 'block';
        const labels = {watching:'Watching',watch_later:'Planning',completed:'Completed',on_hold:'On Hold',dropped:'Dropped'};
        try {
            const resp = await fetch('includes/save_watchlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'imdb_id=' + encodeURIComponent(wlCardId) + '&status=' + encodeURIComponent(status)
            });
            const result = await resp.json();
            if (result.success) {
                toast(activeItem ? 'Updated to ' + labels[status] : 'Added to ' + labels[status], 'success');
                // Keep the card's + button in sync (becomes a tick while saved).
                document.querySelectorAll('.movie-card[data-id="' + CSS.escape(wlCardId) + '"] .kp-card-add-btn')
                    .forEach(function(btn) {
                        btn.classList.add('is-saved');
                        btn.innerHTML = '<i class="fas fa-check"></i>';
                    });
                setTimeout(() => { closeCardWatchlist(); wlSaving = false; }, 400);
            } else {
                toast('Failed to save', 'error');
                list.querySelectorAll('li').forEach(li => li.classList.remove('is-active'));
                wlSaving = false;
            }
        } catch(e) {
            toast('Network error', 'error');
            list.querySelectorAll('li').forEach(li => li.classList.remove('is-active'));
            wlSaving = false;
        }
    };

    window.removeCardWatchlist = async function() {
        if (!wlCardId || wlSaving) return;
        wlSaving = true;
        try {
            const resp = await fetch('includes/save_watchlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'imdb_id=' + encodeURIComponent(wlCardId) + '&action=remove'
            });
            const result = await resp.json();
            if (result.success) {
                toast('Removed from list', 'success');
                list.querySelectorAll('li').forEach(li => li.classList.remove('is-active'));
                removeBtn.style.display = 'none';
                document.querySelectorAll('.movie-card[data-id="' + CSS.escape(wlCardId) + '"] .kp-card-add-btn')
                    .forEach(function(btn) {
                        btn.classList.remove('is-saved');
                        btn.innerHTML = '<i class="fas fa-plus"></i>';
                    });
                setTimeout(() => { closeCardWatchlist(); wlSaving = false; }, 400);
            } else { toast('Failed to remove', 'error'); wlSaving = false; }
        } catch(e) { toast('Network error', 'error'); wlSaving = false; }
    };

    modal?.addEventListener('click', function(e) { if (e.target === modal) closeCardWatchlist(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal?.classList.contains('is-open')) closeCardWatchlist(); });
})();
</script>
