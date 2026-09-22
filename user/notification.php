<?php
session_start();
if (!isset($_SESSION['userID'])) exit();
?>

<div class="tab-content" id="kp-notifications">
    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-bell"></i>
            <h3>Notifications</h3>
            <span class="kp-noti-unread-badge" id="kp-noti-unread-badge"></span>
        </div>

        <div class="kp-noti-toolbar">
            <div class="kp-noti-filters">
                <button class="kp-noti-filter active" data-filter="all">All</button>
                <button class="kp-noti-filter" data-filter="unread">Unread</button>
                <button class="kp-noti-filter" data-filter="episode">Episodes</button>
                <button class="kp-noti-filter" data-filter="follow">Follows</button>
                <button class="kp-noti-filter" data-filter="system">System</button>
            </div>
            <div class="kp-noti-actions">
                <button id="kp-noti-mark-all" class="kp-noti-action-btn" title="Mark all read">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
                <button id="kp-noti-clear-all" class="kp-noti-action-btn kp-noti-action-danger" title="Clear all">
                    <i class="fas fa-trash-can"></i> Clear all
                </button>
            </div>
        </div>

        <div id="kp-noti-list" class="kp-noti-list">
            <div class="kp-cw-loading"><span></span><span></span><span></span></div>
        </div>

        <div id="kp-noti-pagination" class="kp-noti-pagination" style="display:none;">
            <button id="kp-noti-prev" class="kp-noti-page-btn" disabled><i class="fas fa-chevron-left"></i></button>
            <span id="kp-noti-page-info" class="kp-noti-page-info"></span>
            <button id="kp-noti-next" class="kp-noti-page-btn"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>

    <div class="kp-panel" style="margin-top:16px;">
        <div class="kp-panel-head">
            <i class="fas fa-gear"></i>
            <h3>Notification Settings</h3>
        </div>
        <div class="kp-noti-settings" id="kp-noti-settings">
            <label class="kp-noti-setting-row">
                <span><i class="fas fa-tv"></i> Episode alerts</span>
                <input type="checkbox" id="kp-pref-episode" class="kp-noti-toggle" checked>
            </label>
            <label class="kp-noti-setting-row">
                <span><i class="fas fa-heart"></i> Follow alerts</span>
                <input type="checkbox" id="kp-pref-follow" class="kp-noti-toggle" checked>
            </label>
            <label class="kp-noti-setting-row">
                <span><i class="fas fa-circle-info"></i> System alerts</span>
                <input type="checkbox" id="kp-pref-system" class="kp-noti-toggle" checked>
            </label>
            <label class="kp-noti-setting-row">
                <span><i class="fas fa-volume-high"></i> Notification sound</span>
                <input type="checkbox" id="kp-pref-sound" class="kp-noti-toggle">
            </label>
            <label class="kp-noti-setting-row">
                <span><i class="fas fa-comment"></i> Toast popups</span>
                <input type="checkbox" id="kp-pref-toast" class="kp-noti-toggle" checked>
            </label>
        </div>
    </div>
</div>
