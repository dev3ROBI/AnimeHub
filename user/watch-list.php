<?php
/**
 * Watch list tab — home page card style.
 *
 * Entries are stored as `{provider}:{remoteId}` for catalogue anime and as a
 * plain IMDb id for locally hosted titles.
 */
session_start();
if (!isset($_SESSION['userID'])) exit();

include '../includes/db.php';
include_once '../includes/functions.php';

$userID = $_SESSION['userID'];

$STATUSES = [
    'watching'    => ['label' => 'Watching',    'icon' => 'fa-solid fa-play',        'color' => '#238636'],
    'on_hold'     => ['label' => 'On Hold',     'icon' => 'fa-solid fa-pause',       'color' => '#9e6a03'],
    'watch_later' => ['label' => 'Planning',    'icon' => 'fa-solid fa-bookmark',    'color' => '#1f6feb'],
    'completed'   => ['label' => 'Completed',   'icon' => 'fa-solid fa-check',       'color' => '#8957e5'],
    'dropped'     => ['label' => 'Dropped',     'icon' => 'fa-solid fa-xmark',       'color' => '#da3633'],
];

$entries = [];
try {
    $stmt = $pdo->prepare(
        "SELECT imdb_id, status, updated_at
           FROM watchlist
          WHERE user_id = ?
       ORDER BY FIELD(status, 'watching', 'on_hold', 'watch_later', 'completed', 'dropped'), updated_at DESC
          LIMIT 200"
    );
    $stmt->execute([$userID]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Watchlist error: ' . $e->getMessage());
}

/** Local (movies/shows) lookup. */
function watchlist_local_lookup($conn, $imdb_id) {
    try {
        $s = $conn->prepare(
            "SELECT name AS title, imdb_poster AS poster, imdb_rating AS rating FROM movies WHERE imdb_id = ?
             UNION ALL
             SELECT title, imdb_poster, imdb_rating FROM shows WHERE imdb_id = ? LIMIT 1"
        );
        $s->bind_param("ss", $imdb_id, $imdb_id);
        $s->execute();
        return $s->get_result()->fetch_assoc() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ─── Resolve every entry ──────────────────────────────────────────────
$items = [];
$seen = [];
foreach ($entries as $e) {
    $id = (string)($e['imdb_id'] ?? '');
    if ($id === '' || isset($seen[$id])) continue;
    $seen[$id] = true;

    $status  = (string)($e['status'] ?? 'watch_later');
    $parsed  = catalog_parse_id($id);
    $provider = $parsed['provider'] ?? 'local';

    $title    = '';
    $poster   = '';
    $rating   = '';
    $year     = null;
    $format   = '';
    $episodes = 0;
    $progress = 0;

    if (in_array($provider, ['anilist', 'reanime', 'jikan', 'anikuro'], true)) {
        $info = catalog_info($id);
        if ($info) {
            $title    = (string)($info['title'] ?? '');
            $poster   = (string)($info['poster'] ?? '');
            $rating   = (string)($info['score'] ?? '');
            $year     = $info['year'] ?? null;
            $format   = (string)($info['format'] ?? '');
            $episodes = (int)max($info['episodes'] ?? 0, $info['aired_episodes'] ?? 0);
        }
    } else {
        $row = watchlist_local_lookup($conn, $id);
        if ($row) {
            $title  = (string)($row['title'] ?? '');
            $poster = (string)($row['poster'] ?? '');
            $rating = (string)($row['rating'] ?? '');
        }
    }

    try {
        $q = $conn->prepare("SELECT COUNT(DISTINCT episode_number) FROM watch_history WHERE user_id = ? AND anime_slug = ?");
        $q->bind_param("is", $userID, $id);
        $q->execute();
        $progress = (int)$q->get_result()->fetch_row()[0];
    } catch (Throwable $ex) {
        $progress = 0;
    }

    if ($title === '') $title = $id;
    if ($poster === '') $poster = './uploads/thumbnails/default.png';

    $items[] = [
        'id'       => $id,
        'status'   => $status,
        'title'    => $title,
        'poster'   => $poster,
        'rating'   => $rating,
        'year'     => $year,
        'format'   => $format,
        'episodes' => $episodes,
        'progress' => $progress,
        'provider' => $provider,
        'url'      => './watch.php?id=' . urlencode($id),
    ];
}

$statusCounts = array_fill_keys(array_keys($STATUSES), 0);
foreach ($items as $it) {
    if (isset($statusCounts[$it['status']])) $statusCounts[$it['status']]++;
}
?>

<div class="tab-content" id="kp-watchlist" data-count="<?= count($items) ?>">

    <div class="kp-panel">
        <div class="kp-panel-head">
            <i class="fas fa-heart"></i>
            <h3>My Watch List</h3>
            <span class="kp-count"><?= count($items) ?></span>
        </div>

        <?php if (empty($items)): ?>
            <div class="kp-empty-state">
                <i class="fas fa-heart-broken"></i>
                <h4>Your watch list is empty</h4>
                <p>
                    Open any anime and use the <strong>Add to Watchlist</strong> dropdown on the watch page
                    to start tracking it here.
                </p>
                <a class="kp-empty-cta" href="./index.php"><i class="fas fa-fire"></i> Browse trending anime</a>
            </div>
        <?php else: ?>
            <div class="kp-wl-toolbar">
                <div class="kp-wl-filters" id="kp-wl-filters">
                    <button type="button" class="kp-genre-chip active" data-status="all">
                        All <span class="kp-chip-count"><?= count($items) ?></span>
                    </button>
                    <?php foreach ($STATUSES as $key => $meta): ?>
                        <?php if ($statusCounts[$key] === 0) continue; ?>
                        <button type="button" class="kp-genre-chip" data-status="<?= htmlspecialchars($key) ?>">
                            <?= htmlspecialchars($meta['label']) ?>
                            <span class="kp-chip-count"><?= (int)$statusCounts[$key] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="kp-wl-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="search" id="kp-wl-search" placeholder="Search your list..." autocomplete="off">
                </div>

                <div class="kp-wl-import-export">
                    <button type="button" class="kp-wl-ie-btn" id="kp-wl-export-btn" title="Export to MAL XML">
                        <i class="fas fa-file-export"></i> Export
                    </button>
                    <button type="button" class="kp-wl-ie-btn" id="kp-wl-import-btn" title="Import from MAL XML">
                        <i class="fas fa-file-import"></i> Import
                    </button>
                    <input type="file" class="kp-wl-import-input" id="kp-wl-import-file" accept=".xml">
                </div>
            </div>

            <div class="kp-wl-card-grid" id="kp-wl-grid">
                <?php foreach ($items as $it): ?>
                    <?php
                    $meta = $STATUSES[$it['status']] ?? ['label' => $it['status'], 'icon' => 'fa-solid fa-tag', 'color' => '#6e7681'];
                    $pct  = ($it['episodes'] > 0 && $it['progress'] > 0)
                        ? min(100, (int)round($it['progress'] / $it['episodes'] * 100))
                        : 0;
                    $metaLine = array_filter([$it['format'], $it['year'] ? (string)$it['year'] : '']);
                    $metaText = implode(' · ', $metaLine);
                    ?>
                    <article class="kp-wl-card-item"
                             data-kp-watchlist-item
                             data-id="<?= htmlspecialchars($it['id'], ENT_QUOTES, 'UTF-8') ?>"
                             data-status="<?= htmlspecialchars($it['status']) ?>"
                             data-search="<?= htmlspecialchars(mb_strtolower($it['title'] . ' ' . $it['id']), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="kp-wl-card-poster-wrap">
                            <a href="<?= htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8') ?>">
                                <img class="kp-wl-card-poster"
                                     src="<?= htmlspecialchars($it['poster'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') ?>"<?= kp_img_attrs($it['poster'], ['sizes' => '30vw']) ?>
                                     onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
                            </a>

                            <?php if ($it['rating'] !== '' && $it['rating'] !== 'N/A'): ?>
                                <span class="kp-wl-card-rating"><i class="fa-solid fa-star"></i> <?= htmlspecialchars($it['rating']) ?></span>
                            <?php endif; ?>

                            <div class="kp-wl-card-hover">
                                <a class="kp-wl-card-play" href="<?= htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8') ?>" title="Watch">
                                    <i class="fas fa-play"></i>
                                </a>
                            </div>

                            <?php if ($pct > 0): ?>
                                <span class="kp-wl-card-progress"><span style="width:<?= $pct ?>%"></span></span>
                            <?php endif; ?>

                            <span class="kp-wl-card-status-badge" style="background:<?= htmlspecialchars($meta['color']) ?>">
                                <i class="<?= htmlspecialchars($meta['icon']) ?>"></i>
                                <?= htmlspecialchars($meta['label']) ?>
                            </span>
                        </div>

                        <div class="kp-wl-card-info">
                            <a class="kp-wl-card-title" href="<?= htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($it['title']) ?>
                            </a>
                            <p class="kp-wl-card-meta">
                                <?= $metaText ? htmlspecialchars($metaText) : '' ?>
                                <?php if ($it['episodes'] > 0): ?>
                                    <?= $metaText ? ' · ' : '' ?><?= (int)$it['progress'] ?>/<?= (int)$it['episodes'] ?> eps
                                <?php endif; ?>
                            </p>
                            <div class="kp-wl-card-actions">
                                <select class="kp-wl-card-status-select" data-kp-wl-status aria-label="Watch status">
                                    <?php foreach ($STATUSES as $key => $m): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= $it['status'] === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($m['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="kp-wl-card-remove-btn" data-kp-wl-remove
                                        title="Remove from list" aria-label="Remove from list">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <p class="kp-wl-none" id="kp-wl-none" hidden>
                <i class="fas fa-ghost"></i> Nothing matches this filter.
            </p>
        <?php endif; ?>
    </div>
</div>
