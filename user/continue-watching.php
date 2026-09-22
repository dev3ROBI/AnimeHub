<?php
session_start();
if (!isset($_SESSION['userID'])) exit();

include '../includes/db.php';

$recentCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT anime_slug) FROM watch_history WHERE user_id = ?");
    $stmt->execute([$_SESSION['userID']]);
    $recentCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    // cosmetic count
}
?>

<div class="tab-content" id="kp-continue-watching">
    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-history"></i>
            <h3>Continue Watching</h3>
            <span class="kp-panel-note">Pick up exactly where you stopped</span>
        </div>

        <div id="continue-watching-list" class="kp-cw-root">
            <div class="kp-cw-loading"><span></span><span></span><span></span></div>
        </div>
    </div>
</div>
