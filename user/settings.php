<?php
session_start();
if (!isset($_SESSION['userID'])) exit();

include '../includes/db.php';
include_once '../includes/functions.php';

$userID = $_SESSION['userID'];
$stmt = $conn->prepare("SELECT * FROM users WHERE User_ID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$settings = kp_user_settings($pdo, $userID);
?>

<div class="tab-content">
    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-cog"></i>
            <h3>Settings</h3>
        </div>

        <div class="kp-settings-grid">

            <!-- Account Info -->
            <div class="kp-settings-card">
                <div class="kp-settings-card-head">
                    <i class="fas fa-user-circle"></i>
                    <h4>Account</h4>
                </div>
                <div class="kp-settings-card-body">
                    <form class="kp-account-form" data-kp-account="name" autocomplete="off">
                        <label class="kp-account-field">
                            <span><i class="fas fa-id-badge"></i> Display name</span>
                            <input type="text" name="name" value="<?= htmlspecialchars($user['User_Name']) ?>"
                                   minlength="3" maxlength="40" required autocomplete="nickname">
                        </label>
                        <p class="kp-account-hint">Shown on your profile and next to your reviews.</p>
                        <button type="submit" class="kp-account-save">
                            <i class="fas fa-check"></i> Save name
                        </button>
                        <p class="kp-account-msg" data-kp-account-msg role="status"></p>
                    </form>

                    <div class="kp-settings-row">
                        <span class="kp-settings-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="kp-settings-value"><?= htmlspecialchars($user['User_Email']) ?></span>
                    </div>
                    <div class="kp-settings-row">
                        <span class="kp-settings-label"><i class="fas fa-key"></i> Role</span>
                        <span class="kp-settings-value"><?= htmlspecialchars(ucfirst($user['User_Role'] ?? 'user')) ?></span>
                    </div>
                    <div class="kp-settings-row">
                        <span class="kp-settings-label"><i class="fas fa-calendar"></i> Joined</span>
                        <span class="kp-settings-value"><?= htmlspecialchars($user['User_Join']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Preferences -->
            <div class="kp-settings-card">
                <div class="kp-settings-card-head">
                    <i class="fas fa-sliders"></i>
                    <h4>Preferences</h4>
                </div>
                <div class="kp-settings-card-body">
                    <label class="kp-settings-toggle">
                        <div class="kp-settings-toggle-info">
                            <i class="fas fa-thumbtack"></i>
                            <div>
                                <strong>Sticky Navbar</strong>
                                <span>Navbar stays fixed at top while scrolling</span>
                            </div>
                        </div>
                        <input type="checkbox" data-setting="sticky_navbar" <?= $settings['sticky_navbar'] ? 'checked' : '' ?>>
                        <span class="kp-settings-switch"></span>
                    </label>
                    <label class="kp-settings-toggle">
                        <div class="kp-settings-toggle-info">
                            <i class="fas fa-play-circle"></i>
                            <div>
                                <strong>Auto-play Next</strong>
                                <span>Automatically play next episode</span>
                            </div>
                        </div>
                        <input type="checkbox" data-setting="autoplay" <?= $settings['autoplay'] ? 'checked' : '' ?>>
                        <span class="kp-settings-switch"></span>
                    </label>
                    <label class="kp-settings-toggle">
                        <div class="kp-settings-toggle-info">
                            <i class="fas fa-star"></i>
                            <div>
                                <strong>Show Ratings</strong>
                                <span>Display score badges on cards</span>
                            </div>
                        </div>
                        <input type="checkbox" data-setting="show_ratings" <?= $settings['show_ratings'] ? 'checked' : '' ?>>
                        <span class="kp-settings-switch"></span>
                    </label>
                </div>
            </div>

            <!-- Security -->
            <div class="kp-settings-card">
                <div class="kp-settings-card-head">
                    <i class="fas fa-shield-halved"></i>
                    <h4>Security</h4>
                </div>
                <div class="kp-settings-card-body">
                    <form class="kp-account-form" data-kp-account="password" autocomplete="off">
                        <label class="kp-account-field">
                            <span><i class="fas fa-lock"></i> Current password</span>
                            <input type="password" name="current_password" required autocomplete="current-password">
                        </label>
                        <label class="kp-account-field">
                            <span><i class="fas fa-key"></i> New password</span>
                            <input type="password" name="new_password" minlength="8" required autocomplete="new-password">
                        </label>
                        <label class="kp-account-field">
                            <span><i class="fas fa-key"></i> Confirm new password</span>
                            <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
                        </label>
                        <p class="kp-account-hint">At least 8 characters. Passwords are stored hashed — nobody can read them back.</p>
                        <button type="submit" class="kp-account-save">
                            <i class="fas fa-shield-halved"></i> Update password
                        </button>
                        <p class="kp-account-msg" data-kp-account-msg role="status"></p>
                    </form>

                    <div class="kp-settings-row">
                        <span class="kp-settings-label"><i class="fas fa-eraser"></i> History</span>
                        <span class="kp-settings-value">Saved locally</span>
                    </div>
                    <div class="kp-settings-row">
                        <span class="kp-settings-label"><i class="fas fa-user-secret"></i> Privacy</span>
                        <span class="kp-settings-value">No 3rd party sharing</span>
                    </div>
                    <div class="kp-settings-row" style="margin-top:10px;">
                        <a href="./auth/logout.php" class="kp-settings-logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

            <!-- About -->
            <div class="kp-settings-card">
                <div class="kp-settings-card-head">
                    <i class="fas fa-circle-info"></i>
                    <h4>About</h4>
                </div>
                <div class="kp-settings-card-body">
                    <div class="kp-settings-about">
                        <strong>KitsuPlay</strong>
                        <span>Anime Streaming Hub</span>
                        <div class="kp-settings-tech">
                            <span>PHP</span><span>MySQL</span><span>ReAnime API</span><span>HLS.js</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>


