<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-top">
            <div class="footer-brand">
                <span class="footer-logo">KitsuPlay</span>
                <p class="footer-tagline">Your ultimate anime streaming hub</p>
            </div>
            <div class="footer-socials">
                <a href="#" class="footer-social" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" class="footer-social" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" class="footer-social" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="#" class="footer-social" aria-label="Discord"><i class="fab fa-discord"></i></a>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> KitsuPlay. All rights reserved.</p>
        </div>
    </div>
</footer>
    <!-- Footer styles live in assets/css/nav_style.css (see "Site footer"):
         a <style> block down here forced a second style recalculation on
         every page. -->

    <!-- PWA: register the root-scoped service worker (offline shell) and
         wire the install banner. Script loaded deferred; the banner CSS is
         injected only if the browser actually offers an install prompt.
         kp_asset() adds ?v=<mtime> so the immutable .js rule stays safe. -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?= kp_base() ?>sw.js').catch(function () {});
            });
        }
    </script>
    <script src="<?= htmlspecialchars(kp_asset('js', 'assets/pwa/install-prompt.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
<script>
    document.addEventListener("contextmenu", function (e) {
        e.preventDefault();
    });
</script>

</html>
