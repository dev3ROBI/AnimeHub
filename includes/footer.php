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
<style>
    .site-footer {
        background: #1a1a22;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        margin-top: 20px;
    }

    .footer-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 28px 20px 18px;
    }

    .footer-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 18px;
        margin-bottom: 16px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .footer-brand {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .footer-logo {
        font-size: 22px;
        font-family: 'Tangerine', serif;
        font-weight: bold;
        color: #ff2e63;
        text-shadow: 0 0 8px rgba(255, 46, 99, 0.3);
    }

    .footer-tagline {
        margin: 0;
        font-size: 11.5px;
        color: #666;
        font-family: 'Poppins', sans-serif;
    }

    .footer-socials {
        display: flex;
        gap: 8px;
    }

    .footer-social {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.06);
        color: #aaa;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.25s ease;
    }

    .footer-social:hover {
        background: rgba(255, 46, 99, 0.12);
        border-color: rgba(255, 46, 99, 0.3);
        color: #ff2e63;
        transform: translateY(-2px);
    }

    .footer-bottom {
        text-align: center;
    }

    .footer-bottom p {
        margin: 0;
        font-size: 12px;
        color: #555;
        font-family: 'Poppins', sans-serif;
    }

    @media (max-width: 480px) {
        .footer-top {
            flex-direction: column;
            gap: 14px;
            text-align: center;
        }
    }
</style>
</body>
<script>
    document.addEventListener("contextmenu", function (e) {
        e.preventDefault();
    });
</script>

</html>
