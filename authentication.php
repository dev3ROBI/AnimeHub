<?php
include 'includes/db.php';
include 'includes/header.php';

// Check if already logged in
if (isset($_SESSION['userID'])) {
    header("Location: index.php");
    exit();
}
?>
<!-- authentication -->
<div class="auth-container">
    <div class="auth-card">
        <!-- Left decorative panel -->
        <div class="auth-hero">
            <div class="auth-hero-content">
                <div class="auth-hero-icon">
                    <i class="fas fa-film"></i>
                </div>
                <h2 class="auth-hero-title">KitsuPlay</h2>
                <p class="auth-hero-sub">Your ultimate anime streaming hub</p>
                <div class="auth-hero-features">
                    <div class="auth-hero-feature">
                        <i class="fas fa-play-circle"></i>
                        <span>Stream HD anime</span>
                    </div>
                    <div class="auth-hero-feature">
                        <i class="fas fa-list-check"></i>
                        <span>Track your watchlist</span>
                    </div>
                    <div class="auth-hero-feature">
                        <i class="fas fa-chart-simple"></i>
                        <span>Watch statistics</span>
                    </div>
                </div>
            </div>
            <div class="auth-hero-bg">
                <div class="auth-hero-circle auth-hero-circle-1"></div>
                <div class="auth-hero-circle auth-hero-circle-2"></div>
                <div class="auth-hero-circle auth-hero-circle-3"></div>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="auth-form-panel">
            <div class="auth-tabs">
                <button type="button" class="auth-tab active" data-tab="login" onclick="showLogin()">
                    <i class="fas fa-right-to-bracket"></i> Login
                </button>
                <button type="button" class="auth-tab" data-tab="register" onclick="showRegister()">
                    <i class="fas fa-user-plus"></i> Register
                </button>
                <div class="auth-tab-indicator"></div>
            </div>

            <div class="auth-forms">
                <!-- Login Form -->
                <form id="login" class="auth-form" action="./auth/login.php" method="POST" autocomplete="off">
                    <div class="auth-welcome">
                        <h3>Welcome back!</h3>
                        <p>Login with your username or email</p>
                    </div>

                    <div class="auth-input-group">
                        <div class="auth-input-wrap">
                            <i class="fas fa-user"></i>
                            <input type="text" class="auth-input" id="username" name="username"
                                placeholder="Username or email" autocomplete="off" required>
                        </div>
                    </div>

                    <div class="auth-input-group">
                        <div class="auth-input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" class="auth-input" id="password" name="password"
                                placeholder="Password" autocomplete="new-password" required>
                            <button type="button" class="auth-pw-toggle" onclick="togglePassword('password', this)" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="auth-options">
                        <label class="auth-checkbox">
                            <input type="checkbox" id="remember" name="remember">
                            <span class="auth-checkbox-mark"></span>
                            <span>Remember me</span>
                        </label>
                    </div>

                    <button type="submit" class="auth-submit-btn" id="loginBtn">
                        <span class="auth-btn-text">Login</span>
                        <span class="auth-btn-loading" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                </form>

                <!-- Register Form -->
                <form id="register" class="auth-form" action="./auth/register.php" method="POST" style="display:none;" autocomplete="off">
                    <div class="auth-welcome">
                        <h3>Create account</h3>
                        <p>Join KitsuPlay and start watching</p>
                    </div>

                    <div class="auth-input-group">
                        <div class="auth-input-wrap">
                            <i class="fas fa-user"></i>
                            <input type="text" class="auth-input" id="name" name="name"
                                placeholder="Full name" autocomplete="off" required>
                        </div>
                    </div>

                    <div class="auth-input-group">
                        <div class="auth-input-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" class="auth-input" id="email" name="email"
                                placeholder="Email address" autocomplete="off" required>
                        </div>
                    </div>

                    <div class="auth-input-group">
                        <div class="auth-input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" class="auth-input" id="passwordReg" name="password"
                                placeholder="Create password" autocomplete="new-password" required>
                            <button type="button" class="auth-pw-toggle" onclick="togglePassword('passwordReg', this)" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="auth-options">
                        <label class="auth-checkbox">
                            <input type="checkbox" id="accept" name="accept" required>
                            <span class="auth-checkbox-mark"></span>
                            <span>I agree to the <a href="#" style="color:#ff2e63;text-decoration:none;">Privacy Policy</a></span>
                        </label>
                    </div>

                    <button type="submit" class="auth-submit-btn" id="registerBtn">
                        <span class="auth-btn-text">Create Account</span>
                        <span class="auth-btn-loading" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- /authentication -->

<!-- Popup Modal -->
<div id="popupModal" class="auth-popup">
    <div class="auth-popup-content">
        <div class="auth-popup-icon" id="popupIconWrap">
            <i class="fas fa-check" id="popupIcon"></i>
        </div>
        <h3 id="popupTitle">Message</h3>
        <p id="popupMessage"></p>
        <button class="auth-popup-btn" onclick="hidePopup()">OK</button>
    </div>
</div>

<!-- JavaScript -->
<script>
    function showLogin() {
        document.getElementById("login").style.display = "flex";
        document.getElementById("register").style.display = "none";
        document.querySelectorAll('.auth-tab')[0].classList.add('active');
        document.querySelectorAll('.auth-tab')[1].classList.remove('active');
        document.querySelector('.auth-tab-indicator').style.transform = 'translateX(0)';
    }

    function showRegister() {
        document.getElementById("login").style.display = "none";
        document.getElementById("register").style.display = "flex";
        document.querySelectorAll('.auth-tab')[0].classList.remove('active');
        document.querySelectorAll('.auth-tab')[1].classList.add('active');
        document.querySelector('.auth-tab-indicator').style.transform = 'translateX(100%)';
    }

    function togglePassword(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    function showPopup(title, message, isError) {
        const modal = document.getElementById("popupModal");
        const iconWrap = document.getElementById("popupIconWrap");
        const icon = document.getElementById("popupIcon");
        document.getElementById("popupTitle").innerText = title;
        document.getElementById("popupMessage").innerText = message;

        if (isError) {
            iconWrap.className = 'auth-popup-icon auth-popup-icon-error';
            icon.className = 'fas fa-xmark';
        } else {
            iconWrap.className = 'auth-popup-icon auth-popup-icon-success';
            icon.className = 'fas fa-check';
        }
        modal.classList.add("show");
    }

    function hidePopup() {
        document.getElementById("popupModal").classList.remove("show");
    }

    window.onclick = function (e) {
        if (e.target === document.getElementById("popupModal")) hidePopup();
    };

    // Login form submit
    document.getElementById("login").addEventListener("submit", function (e) {
        e.preventDefault();
        const btn = document.getElementById("loginBtn");
        btn.querySelector('.auth-btn-text').style.display = 'none';
        btn.querySelector('.auth-btn-loading').style.display = 'inline';
        btn.disabled = true;

        const formData = new FormData(this);
        fetch('./auth/login.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'error') {
                    showPopup("Login Failed", data.message, true);
                } else {
                    showPopup("Welcome!", "Login successful. Redirecting...", false);
                    setTimeout(() => window.location.href = "index.php", 1000);
                    return;
                }
            })
            .catch(() => showPopup("Error", "An unexpected error occurred.", true))
            .finally(() => {
                btn.querySelector('.auth-btn-text').style.display = 'inline';
                btn.querySelector('.auth-btn-loading').style.display = 'none';
                btn.disabled = false;
            });
    });

    // Register form submit
    document.getElementById("register").addEventListener("submit", function (e) {
        e.preventDefault();
        const btn = document.getElementById("registerBtn");
        btn.querySelector('.auth-btn-text').style.display = 'none';
        btn.querySelector('.auth-btn-loading').style.display = 'inline';
        btn.disabled = true;

        const formData = new FormData(this);
        fetch('./auth/register.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'error') {
                    showPopup("Registration Failed", data.message, true);
                } else {
                    showPopup("Success", data.message, false);
                    setTimeout(() => showLogin(), 1500);
                }
            })
            .catch(() => showPopup("Error", "An unexpected error occurred.", true))
            .finally(() => {
                btn.querySelector('.auth-btn-text').style.display = 'inline';
                btn.querySelector('.auth-btn-loading').style.display = 'none';
                btn.disabled = false;
            });
    });

    showLogin(); // Default
</script>

<?php include 'includes/footer.php'; ?>
