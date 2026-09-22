<div class="tab-content" id="kp-stats-tab">
    <div id="stats-loading" class="kp-stats-loading">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading stats...</p>
    </div>

    <div id="stats-content" class="kp-stats-content" style="display:none;">

        <div class="kp-stats-summary">
            <div class="kp-stats-card kp-stats-card-hours">
                <div class="kp-stats-card-icon"><i class="fas fa-clock"></i></div>
                <div class="kp-stats-card-value" id="stat-hours">0</div>
                <div class="kp-stats-card-label">Hours Watched</div>
                <div class="kp-stats-card-sub" id="stat-hours-sub">&nbsp;</div>
            </div>
            <div class="kp-stats-card kp-stats-card-eps">
                <div class="kp-stats-card-icon"><i class="fas fa-film"></i></div>
                <div class="kp-stats-card-value" id="stat-episodes">0</div>
                <div class="kp-stats-card-label">Episodes</div>
                <div class="kp-stats-card-sub" id="stat-episodes-sub">&nbsp;</div>
            </div>
            <div class="kp-stats-card kp-stats-card-anime">
                <div class="kp-stats-card-icon"><i class="fas fa-tv"></i></div>
                <div class="kp-stats-card-value" id="stat-anime">0</div>
                <div class="kp-stats-card-label">Anime Watched</div>
                <div class="kp-stats-card-sub" id="stat-anime-sub">&nbsp;</div>
            </div>
            <div class="kp-stats-card kp-stats-card-week">
                <div class="kp-stats-card-icon"><i class="fas fa-bolt"></i></div>
                <div class="kp-stats-card-value" id="stat-week">0</div>
                <div class="kp-stats-card-label">This Week</div>
                <div class="kp-stats-card-sub" id="stat-week-sub">&nbsp;</div>
            </div>
        </div>

        <div class="kp-panel">
            <div class="kp-panel-head">
                <i class="fas fa-ranking-star"></i>
                <h3>Most Watched</h3>
                <span class="kp-panel-note">Time actually played, per series</span>
            </div>
            <div id="top-anime-list" class="kp-top-anime-list">
                <p class="kp-stats-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</p>
            </div>
        </div>

        <div class="kp-panel">
            <div class="kp-panel-head">
                <i class="fas fa-fire"></i>
                <h3>Watch Activity (Last 30 Days)</h3>
                <span class="kp-panel-note">Darker = more time watched</span>
            </div>
            <div id="heatmap-grid" class="kp-heatmap-grid"></div>
            <div class="kp-heatmap-legend">
                <span>Less</span>
                <div class="kp-heatmap-cell" style="background:#2d2d2d;"></div>
                <div class="kp-heatmap-cell" style="background:#4a1525;"></div>
                <div class="kp-heatmap-cell" style="background:#8c1c3c;"></div>
                <div class="kp-heatmap-cell" style="background:#c9184a;"></div>
                <div class="kp-heatmap-cell" style="background:#ff2e63;"></div>
                <span>More</span>
            </div>
        </div>

        <div class="kp-panel">
            <div class="kp-panel-head">
                <i class="fas fa-tags"></i>
                <h3>Top Genres</h3>
            </div>
            <div id="genre-bars" class="kp-genre-bars"></div>
        </div>

        <div class="kp-panel">
            <div class="kp-panel-head">
                <i class="fas fa-calendar-week"></i>
                <h3>Weekly Pattern</h3>
                <span class="kp-panel-note" id="weekly-unit-note"></span>
            </div>
            <div id="weekly-bars" class="kp-weekly-bars"></div>
        </div>
    </div>
</div>
