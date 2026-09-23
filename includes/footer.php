<footer class="site-footer">
    <div class="footer-glow" aria-hidden="true"></div>
    <div class="footer-inner">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="index.php" class="footer-logo">KitsuPlay<span>.</span></a>
                <p class="footer-tagline">Your ultimate anime streaming hub — watch, track and discover your next favourite.</p>
                <div class="footer-socials">
                    <a href="#" class="footer-social" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="footer-social" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="footer-social" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="footer-social" aria-label="Discord"><i class="fab fa-discord"></i></a>
                </div>
            </div>

            <nav class="footer-col" aria-label="Explore">
                <h4>Explore</h4>
                <ul class="footer-links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="schedule.php">Schedule</a></li>
                    <li><a href="movies.php">Movies</a></li>
                    <li><a href="tv.php">TV Shows</a></li>
                    <li><a href="genre.php">Genres</a></li>
                </ul>
            </nav>

            <nav class="footer-col" aria-label="Account">
                <h4>Account</h4>
                <ul class="footer-links">
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="authentication.php">Sign in / Register</a></li>
                </ul>
            </nav>

            <div class="footer-col footer-support">
                <h4>Data &amp; Support</h4>
                <p class="footer-note">Metadata &amp; artwork provided by</p>
                <div class="footer-credits">
                    <a href="https://anilist.co" target="_blank" rel="noopener">AniList</a>
                    <a href="https://www.themoviedb.org" target="_blank" rel="noopener">TMDB</a>
                </div>
            </div>
        </div>

        <!-- Install CTA: one strip for both breakpoints (full-width button on
             phones) instead of a button under a paragraph inside a column. -->
        <div class="footer-cta">
            <div class="footer-cta-copy">
                <span class="footer-cta-icon" aria-hidden="true"><i class="fas fa-mobile-screen-button"></i></span>
                <span class="footer-cta-text">
                    <strong>Install KitsuPlay</strong>
                    <small>Add the app to your home screen for faster launches and offline browsing.</small>
                </span>
            </div>
            <button type="button" class="footer-install" id="kp-footer-install">
                <i class="fas fa-download" aria-hidden="true"></i> Install App
            </button>
        </div>

        <div class="footer-bottom">
            <p class="footer-copy">&copy; <?= date('Y') ?> KitsuPlay. All rights reserved.</p>
            <p class="footer-made">Made with <i class="fas fa-heart" aria-hidden="true"></i> for anime fans</p>
            <button type="button" class="footer-top-btn" id="kp-back-top" aria-label="Back to top">
                <i class="fas fa-arrow-up" aria-hidden="true"></i><span>Back to top</span>
            </button>
        </div>
    </div>
</footer>

<?php
/*
 * Card watchlist modal — one shared copy for every page (footer is on all of
 * them), so the `+` button on any card anywhere can open it.
 */
if (!isset($kp_watchlist_modal_done)) {
    $kp_watchlist_modal_done = true;
    include __DIR__ . '/watchlist_modal.php';
}
?>
    <!-- Footer styles live in assets/css/nav_style.css (see "Site footer"):
         a <style> block down here forced a second style recalculation on
         every page. -->

    <!-- PWA: register the root-scoped service worker (offline shell) and wire
         the install banner + confirmation dialog. Their stylesheet is linked
         HERE (lazy, so it still never blocks the page) instead of being
         injected by the script: a sheet injected at click time applies a beat
         late, which painted the dialog as a bare block of text. The lazy-CSS
         guard in header.php retries this tag if the fetch fails.
         kp_asset() adds ?v=<mtime>-<size> so the immutable .css rule stays safe. -->
    <link rel="stylesheet" id="kp-css-install-prompt" data-kp-lazy="1"
          href="<?= htmlspecialchars(kp_asset('css', 'assets/pwa/install-prompt.css'), ENT_QUOTES, 'UTF-8') ?>"
          media="print" onload="this.media='all';this.setAttribute('data-kp-ready','1')"
          onerror="window.kpCssRetry && window.kpCssRetry(this)">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?= kp_base() ?>sw.js').catch(function () {});
            });
        }
    </script>
    <script src="<?= htmlspecialchars(kp_asset('js', 'assets/pwa/install-prompt.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script>
        // Footer extras: back-to-top + install trigger. The install button
        // opens install-prompt.js's confirmation modal (which runs the native
        // prompt, or shows per-platform manual steps when there is none).
        (function () {
            var top = document.getElementById('kp-back-top');
            if (top) top.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            var ins = document.getElementById('kp-footer-install');
            if (ins) ins.addEventListener('click', function () {
                if (typeof window.kpConfirmInstall === 'function') {
                    window.kpConfirmInstall();
                    return;
                }
                if (typeof kpToast === 'function') {
                    kpToast('Open your browser menu → "Add to Home screen"', 'info');
                }
            });
        })();
    </script>
</body>
<script>
    document.addEventListener("contextmenu", function (e) {
        e.preventDefault();
    });
</script>

</html>