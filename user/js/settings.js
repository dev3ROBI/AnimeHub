// user/js/settings.js — settings tab behaviour
(function(){
    document.querySelectorAll('[data-setting]').forEach(function(input){
        input.addEventListener('change', function(){
            var key = this.dataset.setting;
            var value = this.checked ? 1 : 0;
            fetch('includes/save_setting.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'key=' + encodeURIComponent(key) + '&value=' + value
            }).then(function(r){ return r.json(); }).then(function(d){
                if (d.success) {
                    if (typeof kpToast === 'function') {
                        var labels = {sticky_navbar:'Sticky Navbar',autoplay:'Auto-play',show_ratings:'Show Ratings'};
                        kpToast(labels[key] + (value ? ' enabled' : ' disabled'), 'success');
                    }
                    if (key === 'sticky_navbar') {
                        document.body.classList.toggle('sticky-nav-enabled', !!value);
                    }
                } else {
                    if (typeof kpToast === 'function') kpToast('Failed: ' + (d.message || 'Unknown'), 'error');
                }
            }).catch(function(){
                if (typeof kpToast === 'function') kpToast('Network error', 'error');
            });
        });
    });
})();
