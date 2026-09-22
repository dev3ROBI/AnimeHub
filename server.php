<?php
/**
 * AnimeHub — Database Setup Script
 *
 * Visiting this page in a browser creates the database and all 20 tables
 * required by the application. Safe to run repeatedly — every statement
 * uses IF NOT EXISTS so existing data is never touched.
 */

include_once __DIR__ . '/config/config.php';

$host    = DB_HOST;
$dbName  = DB_NAME;
$dbUser  = DB_USER;
$dbPass  = DB_PASS;
$charset = 'utf8mb4';

$conn = new mysqli($host, $dbUser, $dbPass);
if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

$conn->set_charset($charset);

$created = [];
$errors  = [];

function run($conn, $label, $sql) {
    global $created, $errors;
    if ($conn->query($sql) === TRUE) {
        $created[] = $label;
    } else {
        $errors[] = "$label — " . $conn->error;
    }
}

// ─── Database ──────────────────────────────────────────────────────────
$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
$conn->select_db($dbName);

// ─── 1. users ──────────────────────────────────────────────────────────
run($conn, 'users', "
CREATE TABLE IF NOT EXISTS `users` (
    `User_ID`        INT AUTO_INCREMENT PRIMARY KEY,
    `User_Name`      VARCHAR(100)  NOT NULL,
    `User_Email`     VARCHAR(255)  NOT NULL UNIQUE,
    `User_Password`  VARCHAR(255)  NOT NULL,
    `User_Role`      VARCHAR(20)   NOT NULL DEFAULT 'user',
    `User_Join`      DATETIME      NOT NULL,
    `User_Avatar`    VARCHAR(500)  DEFAULT NULL,
    `theme_preference` VARCHAR(20) DEFAULT 'dark',
    INDEX `idx_user_name` (`User_Name`),
    INDEX `idx_user_email` (`User_Email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 2. movies ─────────────────────────────────────────────────────────
run($conn, 'movies', "
CREATE TABLE IF NOT EXISTS `movies` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(500)  NOT NULL,
    `category`      VARCHAR(50)   NOT NULL DEFAULT 'movie',
    `imdb_id`       VARCHAR(50)   NOT NULL,
    `video_url`     TEXT          DEFAULT NULL,
    `imdb_rating`   VARCHAR(10)   DEFAULT NULL,
    `imdb_poster`   TEXT          DEFAULT NULL,
    `plot`          TEXT          DEFAULT NULL,
    `genre`         VARCHAR(255)  DEFAULT NULL,
    `release_date`  VARCHAR(50)   DEFAULT NULL,
    `runtime`       VARCHAR(50)   DEFAULT NULL,
    `actors`        TEXT          DEFAULT NULL,
    `director`      TEXT          DEFAULT NULL,
    `writer`        TEXT          DEFAULT NULL,
    `language`      VARCHAR(100)  DEFAULT NULL,
    `country`       VARCHAR(100)  DEFAULT NULL,
    INDEX `idx_movies_imdb` (`imdb_id`),
    INDEX `idx_movies_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 3. shows ──────────────────────────────────────────────────────────
run($conn, 'shows', "
CREATE TABLE IF NOT EXISTS `shows` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `title`         VARCHAR(500)  NOT NULL,
    `type`          VARCHAR(50)   NOT NULL DEFAULT 'series',
    `imdb_id`       VARCHAR(50)   NOT NULL,
    `imdb_rating`   VARCHAR(10)   DEFAULT NULL,
    `imdb_poster`   TEXT          DEFAULT NULL,
    `plot`          TEXT          DEFAULT NULL,
    `genre`         VARCHAR(255)  DEFAULT NULL,
    `release_date`  VARCHAR(50)   DEFAULT NULL,
    `runtime`       VARCHAR(50)   DEFAULT NULL,
    `actors`        TEXT          DEFAULT NULL,
    `director`      TEXT          DEFAULT NULL,
    `writer`        TEXT          DEFAULT NULL,
    `language`      VARCHAR(100)  DEFAULT NULL,
    `country`       VARCHAR(100)  DEFAULT NULL,
    INDEX `idx_shows_imdb` (`imdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 4. seasons ────────────────────────────────────────────────────────
run($conn, 'seasons', "
CREATE TABLE IF NOT EXISTS `seasons` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `show_id`        INT NOT NULL,
    `season_number`  INT NOT NULL DEFAULT 1,
    `season_title`   VARCHAR(255) DEFAULT NULL,
    INDEX `idx_seasons_show` (`show_id`),
    CONSTRAINT `fk_seasons_show` FOREIGN KEY (`show_id`) REFERENCES `shows`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 5. episodes ───────────────────────────────────────────────────────
run($conn, 'episodes', "
CREATE TABLE IF NOT EXISTS `episodes` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `season_id`       INT NOT NULL,
    `episode_number`  INT NOT NULL DEFAULT 1,
    `video_url`       TEXT DEFAULT NULL,
    `poster`          TEXT DEFAULT NULL,
    INDEX `idx_episodes_season` (`season_id`),
    CONSTRAINT `fk_episodes_season` FOREIGN KEY (`season_id`) REFERENCES `seasons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 6. watchlist ──────────────────────────────────────────────────────
run($conn, 'watchlist', "
CREATE TABLE IF NOT EXISTS `watchlist` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT          NOT NULL,
    `imdb_id`    VARCHAR(100) NOT NULL,
    `status`     VARCHAR(20)  NOT NULL DEFAULT 'watching',
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_watchlist_user_anime` (`user_id`, `imdb_id`),
    INDEX `idx_watchlist_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 7. likes ──────────────────────────────────────────────────────────
run($conn, 'likes', "
CREATE TABLE IF NOT EXISTS `likes` (
    `id`       INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`  INT          NOT NULL,
    `imdb_id`  VARCHAR(100) NOT NULL,
    UNIQUE INDEX `uq_likes_user_anime` (`user_id`, `imdb_id`),
    INDEX `idx_likes_imdb` (`imdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 8. views ──────────────────────────────────────────────────────────
run($conn, 'views', "
CREATE TABLE IF NOT EXISTS `views` (
    `id`       INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`  INT          NOT NULL,
    `imdb_id`  VARCHAR(100) NOT NULL,
    `viewed_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_views_imdb` (`imdb_id`),
    INDEX `idx_views_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 9. watch_history ──────────────────────────────────────────────────
run($conn, 'watch_history', "
CREATE TABLE IF NOT EXISTS `watch_history` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT          NOT NULL,
    `anime_slug`      VARCHAR(255) NOT NULL,
    `episode_number`  INT          NOT NULL DEFAULT 1,
    `watched_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_watch_history` (`user_id`, `anime_slug`, `episode_number`),
    INDEX `idx_watch_history_user` (`user_id`, `watched_at`),
    INDEX `idx_watch_history_anime` (`anime_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 10. video_progress ────────────────────────────────────────────────
run($conn, 'video_progress', "
CREATE TABLE IF NOT EXISTS `video_progress` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT          NOT NULL,
    `video_id`       VARCHAR(255) NOT NULL,
    `last_position`  INT          NOT NULL DEFAULT 0,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_video_progress` (`user_id`, `video_id`),
    INDEX `idx_video_progress_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 11. watch_time ────────────────────────────────────────────────────
run($conn, 'watch_time', "
CREATE TABLE IF NOT EXISTS `watch_time` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT          NOT NULL,
    `anime_slug`      VARCHAR(191) NOT NULL,
    `episode_number`  INT          NOT NULL DEFAULT 0,
    `seconds`         INT          NOT NULL DEFAULT 0,
    `duration`        INT          NOT NULL DEFAULT 0,
    `started_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uniq_watch_time` (`user_id`, `anime_slug`, `episode_number`),
    INDEX `idx_watch_time_user` (`user_id`, `updated_at`),
    INDEX `idx_watch_time_anime` (`anime_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 12. reports ───────────────────────────────────────────────────────
run($conn, 'reports', "
CREATE TABLE IF NOT EXISTS `reports` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT          NOT NULL,
    `anime_slug`  VARCHAR(191) NOT NULL,
    `episode`     INT          NOT NULL DEFAULT 0,
    `reason`      VARCHAR(64)  NOT NULL DEFAULT 'other',
    `details`     TEXT         DEFAULT NULL,
    `source`      VARCHAR(64)  DEFAULT NULL,
    `status`      VARCHAR(16)  NOT NULL DEFAULT 'open',
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reports_user` (`user_id`, `created_at`),
    INDEX `idx_reports_anime` (`anime_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 13. follows ───────────────────────────────────────────────────────
run($conn, 'follows', "
CREATE TABLE IF NOT EXISTS `follows` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT          NOT NULL,
    `anime_slug`   VARCHAR(255) NOT NULL,
    `anime_title`  VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `unique_follow` (`user_id`, `anime_slug`),
    INDEX `idx_follow_user` (`user_id`),
    INDEX `idx_follow_slug` (`anime_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 14. notifications ────────────────────────────────────────────────
run($conn, 'notifications', "
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT          NOT NULL,
    `notification_type` VARCHAR(30) NOT NULL DEFAULT 'episode',
    `anime_title`    VARCHAR(255) NOT NULL DEFAULT '',
    `anime_slug`     VARCHAR(255) NOT NULL DEFAULT '',
    `episode`        INT          NOT NULL DEFAULT 0,
    `message`        VARCHAR(500) NOT NULL DEFAULT '',
    `is_read`        TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`     DATETIME     DEFAULT NULL,
    UNIQUE KEY `uniq_notif_entry` (`user_id`, `anime_slug`, `episode`),
    INDEX `idx_notif_user` (`user_id`, `is_read`, `created_at`),
    INDEX `idx_notif_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Migration: add new columns to existing notifications table
$notif_cols = $conn->query("SHOW COLUMNS FROM `notifications` LIKE 'notification_type'");
if ($notif_cols && $notif_cols->num_rows === 0) {
    $conn->query("ALTER TABLE `notifications` ADD COLUMN `notification_type` VARCHAR(30) NOT NULL DEFAULT 'episode' AFTER `user_id`");
}
$notif_cols2 = $conn->query("SHOW COLUMNS FROM `notifications` LIKE 'expires_at'");
if ($notif_cols2 && $notif_cols2->num_rows === 0) {
    $conn->query("ALTER TABLE `notifications` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `created_at`");
}
// Add unique constraint if missing (skip on error — already exists)
$conn->query("ALTER TABLE `notifications` ADD UNIQUE INDEX `uniq_notif_entry` (`user_id`, `anime_slug`, `episode`)");

// ─── 15. user_settings ────────────────────────────────────────────────
run($conn, 'user_settings', "
CREATE TABLE IF NOT EXISTS `user_settings` (
    `user_id`        INT UNSIGNED NOT NULL PRIMARY KEY,
    `sticky_navbar`  TINYINT(1)   NOT NULL DEFAULT 0,
    `autoplay`       TINYINT(1)   NOT NULL DEFAULT 1,
    `show_ratings`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 15b. notification_settings ───────────────────────────────────────
run($conn, 'notification_settings', "
CREATE TABLE IF NOT EXISTS `notification_settings` (
    `user_id`          INT UNSIGNED NOT NULL PRIMARY KEY,
    `episode_alerts`   TINYINT(1)   NOT NULL DEFAULT 1,
    `follow_alerts`    TINYINT(1)   NOT NULL DEFAULT 1,
    `system_alerts`    TINYINT(1)   NOT NULL DEFAULT 1,
    `sound_enabled`    TINYINT(1)   NOT NULL DEFAULT 0,
    `toast_enabled`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 16. api_cache ────────────────────────────────────────────────────
run($conn, 'api_cache', "
CREATE TABLE IF NOT EXISTS `api_cache` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `cache_key`   VARCHAR(191)  NOT NULL,
    `provider`    VARCHAR(32)   NOT NULL DEFAULT '',
    `payload`     MEDIUMTEXT    NOT NULL,
    `expires_at`  INT UNSIGNED  NOT NULL DEFAULT 0,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_api_cache_key` (`cache_key`),
    INDEX `idx_api_cache_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 17. anikuro_cache ────────────────────────────────────────────────
run($conn, 'anikuro_cache', "
CREATE TABLE IF NOT EXISTS `anikuro_cache` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `session`     VARCHAR(255)  NOT NULL,
    `title`       VARCHAR(500)  DEFAULT NULL,
    `data_json`   MEDIUMTEXT    NOT NULL,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_anikuro_session` (`session`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 18. anime_cache ──────────────────────────────────────────────────
run($conn, 'anime_cache', "
CREATE TABLE IF NOT EXISTS `anime_cache` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `slug`            VARCHAR(255)  NOT NULL,
    `title`           VARCHAR(500)  DEFAULT NULL,
    `anilist_id`      INT           DEFAULT NULL,
    `episodes_json`   MEDIUMTEXT    NOT NULL,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_anime_cache_slug` (`slug`),
    INDEX `idx_anime_cache_anilist` (`anilist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 19. search_history ───────────────────────────────────────────────
run($conn, 'search_history', "
CREATE TABLE IF NOT EXISTS `search_history` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT          NOT NULL,
    `term`         VARCHAR(255) NOT NULL,
    `searched_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_search_user_term` (`user_id`, `term`),
    INDEX `idx_search_user` (`user_id`, `searched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── 20. episode_notes ────────────────────────────────────────────────
run($conn, 'episode_notes', "
CREATE TABLE IF NOT EXISTS `episode_notes` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT          NOT NULL,
    `video_id`            VARCHAR(255) NOT NULL,
    `timestamp_seconds`   FLOAT        NOT NULL DEFAULT 0,
    `note`                VARCHAR(500) NOT NULL DEFAULT '',
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notes_user_video` (`user_id`, `video_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnimeHub — Database Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0a0a0f; color: #e0e0e0; min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { max-width: 700px; width: 100%; }
        .card { background: #14141f; border: 1px solid #2a2a3a; border-radius: 12px; padding: 32px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin-bottom: 8px; color: #fff; }
        .subtitle { color: #888; margin-bottom: 24px; font-size: 14px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
        .badge-success { background: #1a3a1a; color: #4caf50; border: 1px solid #2d5a2d; }
        .badge-error { background: #3a1a1a; color: #f44336; border: 1px solid #5a2d2d; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 12px; background: #1a1a2e; color: #aaa; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #2a2a3a; }
        td { padding: 10px 12px; border-bottom: 1px solid #1a1a2e; font-size: 14px; }
        tr:hover td { background: #1a1a28; }
        .icon { margin-right: 8px; }
        .footer { text-align: center; color: #555; font-size: 12px; margin-top: 16px; }
        .error-box { background: #1f0a0a; border: 1px solid #5a2d2d; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .error-box h3 { color: #f44336; margin-bottom: 8px; font-size: 14px; }
        .error-box p { color: #ff8a80; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>AnimeHub Database Setup</h1>
        <p class="subtitle">Database: <strong><?= htmlspecialchars($dbName) ?></strong> @ <?= htmlspecialchars($host) ?></p>

        <?php if (empty($errors)): ?>
            <span class="badge badge-success">All <?= count($created) ?> tables created successfully</span>
        <?php else: ?>
            <span class="badge badge-error"><?= count($created) ?> OK / <?= count($errors) ?> errors</span>
        <?php endif; ?>

        <table>
            <thead><tr><th>#</th><th>Table</th><th>Status</th></tr></thead>
            <tbody>
                <?php
                $allTables = [
                    'users', 'movies', 'shows', 'seasons', 'episodes',
                    'watchlist', 'likes', 'views', 'watch_history', 'video_progress',
                    'watch_time', 'reports', 'follows', 'notifications', 'user_settings',
                    'api_cache', 'anikuro_cache', 'anime_cache', 'search_history', 'episode_notes'
                ];
                foreach ($allTables as $i => $tbl):
                    $ok = in_array($tbl, $created);
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code><?= $tbl ?></code></td>
                    <td><?= $ok ? '✅ Created' : '⚠️ Check' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <h3>Errors:</h3>
                <?php foreach ($errors as $e): ?>
                    <p><?= htmlspecialchars($e) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <p class="footer">You can safely re-run this page — no existing data will be affected.</p>
</div>
</body>
</html>
