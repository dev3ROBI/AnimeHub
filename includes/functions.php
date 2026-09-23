<?php
/**
 * Shared view helpers.
 */

include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/tmdb_api.php';

/*
 * The card helpers below ask performance.php for image srcset/loading
 * attributes, and this file is also used by the JSON fragment endpoints
 * (genre_items.php, trending_period.php) that never load header.php — so the
 * performance layer is pulled in here rather than relying on header.php.
 */
include_once __DIR__ . '/performance.php';

if (!function_exists('kp_e')) {
    function kp_e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Humanized time remaining — MUST stay in step with fmt() in
 * assets/js/countdown.js (PHP paints the first frame, JS ticks from there).
 */
if (!function_exists('kp_time_left')) {
    function kp_time_left($ts) {
        $diff = (int)$ts - time();
        if ($diff <= 0) return 'Airing now';
        $d = intdiv($diff, 86400);
        $h = intdiv($diff % 86400, 3600);
        $m = intdiv($diff % 3600, 60);
        if ($d >= 1) return $d . 'd ' . $h . 'h';
        if ($h >= 1) return $h . 'h ' . $m . 'm';
        if ($m >= 1) return $m . 'm';
        return $diff . 's';
    }
}

/**
 * Countdown chip markup for a unix release timestamp. Pairs with
 * assets/js/countdown.js (loaded in header.php): JS ticks every second while
 * any chip is pending and fires a one-shot `kp:released` CustomEvent at zero.
 * Returns '' for ts <= 0.
 */
if (!function_exists('kp_countdown_chip')) {
    function kp_countdown_chip($ts) {
        $ts = (int)$ts;
        if ($ts <= 0) return '';
        $live = $ts <= time();
        return '<span class="kp-cd' . ($live ? ' is-live' : '') . '"'
             . ' data-release="' . $ts . '"'
             . ' data-done-text="Airing now"'
             . ($live ? ' data-done="1"' : '')
             . '>' . kp_e($live ? 'Airing now' : kp_time_left($ts)) . '</span>';
    }
}

/**
 * Load user settings from the database.
 * Returns an associative array with defaults for missing keys.
 */
function kp_user_settings($pdo, $user_id) {
    $defaults = ['sticky_navbar' => 0, 'autoplay' => 1, 'show_ratings' => 1];
    if (!$user_id || !$pdo) return $defaults;
    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            sticky_navbar TINYINT(1) NOT NULL DEFAULT 0,
            autoplay TINYINT(1) NOT NULL DEFAULT 1,
            show_ratings TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("SELECT sticky_navbar, autoplay, show_ratings FROM user_settings WHERE user_id = ?");
        $stmt->execute([(int)$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $defaults;
        foreach ($defaults as $k => $v) {
            $defaults[$k] = isset($row[$k]) ? (int)$row[$k] : $v;
        }
    } catch (Throwable $e) {
        error_log('[kp_user_settings] ' . $e->getMessage());
    }
    return $defaults;
}

/**
 * URL for a catalogue item's watch page.
 *
 * $season matters for TMDB TV: it numbers episodes per season, so an episode
 * number without its season would always land on season 1 (see
 * progress_episode_key() in includes/progress.php).
 */
function kp_watch_url($item, $episode = null, $season = null) {
    if (!is_array($item)) return './index.php';
    $id = $item['id'] ?? null;
    if (empty($id)) return './index.php';

    $url = './watch.php?id=' . urlencode($id);
    if ($season !== null && (int)$season > 0) $url .= '&season=' . (int)$season;
    if ($episode !== null) $url .= '&ep=' . intval($episode);
    return $url;
}

/**
 * Render one poster card.
 *
 * @param array $item  catalogue item
 * @param array $opts  show_ep_badge, rank, show_time, badge_text, link_episode
 */
function render_anime_card($item, array $opts = []) {
    if (!is_array($item)) return '';

    $title  = kp_e($item['title'] ?? 'Unknown');
    $poster = $item['poster'] ?? '';
    if (empty($poster)) $poster = './uploads/thumbnails/default.png';

    $link = kp_watch_url($item, $opts['link_episode'] ?? null);
    $cardId = kp_e($item['id'] ?? '');

    // Lazy + async decode, and a srcset so a phone downloads the 230px poster
    // instead of the 460px one whenever the card is small enough.
    $posterEsc   = kp_e($poster);
    $posterAttrs = kp_img_attrs($poster);

    // Rating
    $rating = $item['score'] ?? null;
    if ($rating === null || $rating === '' || $rating === 'N/A') {
        $ratingRaw = $item['rating'] ?? null;
        $rating = (is_string($ratingRaw) && !is_numeric($ratingRaw)) ? $ratingRaw : null;
    }

    // Episode counts
    $totalEps = !empty($item['episodes']) && is_numeric($item['episodes']) ? (int)$item['episodes'] : 0;
    $airedEps = !empty($item['aired_episodes']) && is_numeric($item['aired_episodes']) ? (int)$item['aired_episodes'] : 0;
    $currentEp = $item['episode'] ?? ($item['episode_number'] ?? null);
    if ($currentEp !== null && is_numeric($currentEp)) $currentEp = (int)$currentEp;
    else $currentEp = $airedEps;

    // Format + duration
    $format = $item['format'] ?? ($item['type'] ?? '');
    $duration = $item['duration'] ?? '';
    $metaLine = array_filter([$format, $duration]);
    $metaText = implode(' · ', $metaLine);

    // Score display
    $ratingHtml = '';
    if ($rating !== null) {
        $ratingHtml = '<div class="kp-card-rating"><i class="fa-solid fa-star"></i> ' . kp_e($rating) . '</div>';
    }

    // Episode info bar at bottom of poster
    $epBar = '';
    $contentType = $item['content_type'] ?? '';
    if ($contentType === 'movie') {
        // Movies: show a "Movie" badge instead of episode counts
        $epBar = '<div class="kp-card-ep-bar"><span><i class="fas fa-film"></i> Movie</span></div>';
    } elseif ($contentType === 'tv') {
        // TV shows: show seasons + total episodes, or just "TV" badge
        $seasons = $item['seasons_count'] ?? $item['episodes'] ?? 0;
        $totalEpsTv = $item['total_episodes'] ?? $item['aired_episodes'] ?? 0;
        if ($seasons > 0 || $totalEpsTv > 0) {
            $epBar = '<div class="kp-card-ep-bar">'
                . ($seasons > 0 ? '<span><i class="fas fa-layer-group"></i> ' . $seasons . 'S</span>' : '')
                . ($totalEpsTv > 0 ? '<span><i class="fas fa-closed-captioning"></i> ' . $totalEpsTv . 'E</span>' : '')
                . '</div>';
        } else {
            $epBar = '<div class="kp-card-ep-bar"><span><i class="fas fa-tv"></i> TV</span></div>';
        }
    } elseif ($currentEp > 0 || $totalEps > 0) {
        $epBar = '<div class="kp-card-ep-bar">'
            . '<span><i class="fas fa-closed-captioning"></i> ' . ($currentEp ?: '?') . '</span>'
            . '<span><i class="fas fa-microphone"></i> ' . (!empty($item['has_dub']) ? ($currentEp ?: '?') : '—') . '</span>'
            . '<span><i class="fas fa-layer-group"></i> ' . ($totalEps ?: '?') . '</span>'
            . '</div>';
    }

    // Rank badge
    $rank = '';
    if (!empty($opts['rank']) && (int)$opts['rank'] <= 3) {
        $rank = '<div class="kp-card-rank">#' . (int)$opts['rank'] . '</div>';
    }

    // Schedule time box
    $timeBox = '';
    if (!empty($opts['show_time'])) {
        $time = $opts['time_text'] ?? ($item['airing_time'] ?? '');
        if ($time) {
            $aired = ($item['airing_status'] ?? '') === 'aired';
            $timeBox = '<div class="kp-card-time' . ($aired ? ' aired' : '') . '">'
                     . '<i class="fas fa-clock"></i> ' . kp_e($time) . '</div>';
        }
    }

    // Preview payload for hover card
    $preview = kp_preview_payload($item, $link, $opts);

    return <<<HTML
    <div class="watch-item" data-kp='{$preview}'>
        {$rank}
        <a href="{$link}" class="kp-card-link" style="text-decoration:none;">
            <div class="movie-card" data-id="{$cardId}">
                <div class="thumb-wrapper">
                    <img src="{$posterEsc}" alt="{$title}"{$posterAttrs} onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
                    <div class="kp-card-rating-badge">{$ratingHtml}</div>
                    <div class="kp-card-hover-overlay">
                        <button type="button" class="kp-card-play-btn" aria-label="Play" onclick="event.preventDefault();event.stopPropagation();window.location.href=this.closest('a').href;"><i class="fas fa-play"></i></button>
                        <button type="button" class="kp-card-add-btn" aria-label="Add to list" onclick="event.preventDefault();event.stopPropagation();openCardWatchlist(this.closest('.movie-card'))"><i class="fas fa-plus"></i></button>
                    </div>
                    {$epBar}
                    {$timeBox}
                </div>
                <div class="kp-card-info">
                    <p class="kp-card-title">{$title}</p>
                    <p class="kp-card-meta">{$metaText}</p>
                </div>
            </div>
        </a>
    </div>
    HTML;
}

/**
 * One row of the sidebar trending list.
 *
 * Shared by the home page and includes/trending_period.php so the rows the
 * tabs swap in are byte-for-byte the markup the page ships with.
 */
function render_trend_item(array $item, $rank) {
    if (!is_array($item) || empty($item['id'])) return '';

    $title  = kp_e($item['title'] ?? 'Unknown');
    $poster = $item['poster'] ?: './uploads/thumbnails/default.png';
    $banner = !empty($item['banner']) ? $item['banner'] : $poster;
    $link   = kp_watch_url($item);
    $score  = $item['score'] ?? null;
    $format = $item['format'] ?? ($item['type'] ?? 'TV');
    $sub    = $item['aired_episodes'] ?? ($item['episodes'] ?? '?');
    $total  = $item['episodes'] ?? '?';
    $dub    = !empty($item['has_dub']) ? $sub : '?';

    // The thumbnail in a trending row is a background strip, ~half the row on
    // a phone — describe it so the browser can pick a small variant.
    $bannerAttrs = kp_img_attrs($banner, ['sizes' => '(max-width: 1024px) 46vw, 300px']);
    $bannerEsc   = kp_e($banner);

    $scoreHtml = $score ? '<span class="kp-trend-score"><i class="fas fa-star"></i> ' . kp_e($score) . '</span>' : '';

    // Air-status pill — pulsing dot for airing, hourglass for upcoming,
    // quiet check for finished. Unknown stays silent instead of lying.
    $stRaw = strtoupper((string)($item['status'] ?? ''));
    if (in_array($stRaw, ['RELEASING', 'AIRING'], true)) {
        $statusHtml = '<span class="kp-trend-status is-airing"><i class="fas fa-circle-play"></i> Airing</span>';
    } elseif (in_array($stRaw, ['NOT_YET_RELEASED', 'UPCOMING'], true)) {
        $statusHtml = '<span class="kp-trend-status is-upcoming"><i class="fas fa-hourglass-half"></i> Upcoming</span>';
    } elseif ($stRaw !== '') {
        $statusHtml = '<span class="kp-trend-status"><i class="fas fa-check"></i> Released</span>';
    } else {
        $statusHtml = '';
    }

    // Next-episode strip — only when a future air time is actually known, so
    // "EP 5 · 2d 4h" is always a promise the schedule keeps.
    $naTs = (int)($item['next_airing']['airingAt'] ?? 0);
    $naEp = (int)($item['next_airing']['episode'] ?? 0);
    $nextEpHtml = '';
    if ($naTs > time() && $naEp > 0) {
        $diff  = $naTs - time();
        $days  = (int)floor($diff / 86400);
        $hours = (int)floor(($diff % 86400) / 3600);
        $inTxt = $days > 0 ? $days . 'd ' . $hours . 'h'
               : ($hours > 0 ? $hours . 'h' : max(1, (int)floor($diff / 60)) . 'm');
        $nextEpHtml = '<span class="kp-trend-next"><i class="fas fa-tower-broadcast"></i> EP ' . $naEp . ' · ' . $inTxt . '</span>';
    }

    // Top-3 rows get medal treatment on the rank.
    $podium = $rank > 0 && $rank <= 3 ? ' is-top' : '';

    return <<<HTML
    <a href="{$link}" class="kp-trend-item{$podium}">
        <div class="kp-trend-bg"><img src="{$bannerEsc}" alt=""{$bannerAttrs}></div>
        <div class="kp-trend-overlay"></div>
        <span class="kp-trend-rank">{$rank}</span>
        <div class="kp-trend-info">
            <span class="kp-trend-title">{$title}</span>
            <div class="kp-trend-meta">
                {$scoreHtml}
                <span class="kp-trend-format">{$format}</span>
                {$statusHtml}
            </div>
            <div class="kp-trend-eps">
                <span><i class="fas fa-closed-captioning"></i> {$sub}</span>
                <span><i class="fas fa-microphone"></i> {$dub}</span>
                <span><i class="fas fa-layer-group"></i> {$total}</span>
                {$nextEpHtml}
            </div>
        </div>
        <div class="kp-trend-play"><i class="fas fa-play"></i></div>
    </a>
    HTML;
}

/**
 * Compact JSON blob describing a card, embedded as a data-kp attribute.
 *
 * JSON_HEX_* keeps the value safe inside a single-quoted attribute, and the
 * result is entity-encoded so quotes can never close the attribute early.
 */
function kp_preview_payload(array $item, $link, array $opts = []) {
    $desc = (string)($item['description'] ?? '');
    // Descriptions arrive as HTML from the provider; strip tags and entities.
    $desc = trim(html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    $genres = array_slice((array)($item['genres'] ?? []), 0, 4);

    $episodes = (int)(($item['episodes'] ?? 0) ?: ($item['aired_episodes'] ?? 0));

    $payload = [
        't'     => (string)($item['title'] ?? 'Unknown'),
        'i'     => (string)($item['id'] ?? ''),
        'm'     => !empty($item['mal_id']) ? (int)$item['mal_id'] : null,
        'u'     => $link,
        'p'     => (string)($item['poster'] ?? ''),
        'b'     => (string)($item['banner'] ?? ''),
        's'     => ($item['score'] ?? null) !== null ? (string)$item['score'] : '',
        'y'     => $item['year'] ?? null,
        'f'     => (string)($item['format'] ?? $item['type'] ?? ''),
        'st'    => (string)($item['status'] ?? ''),
        'e'     => $episodes,
        'd'     => (string)($item['duration'] ?? ''),
        'g'     => array_values(array_map('strval', $genres)),
        'x'     => mb_strlen($desc) > 220 ? mb_substr($desc, 0, 220) . '…' : $desc,
    ];

    /*
     * Accurate availability for the hover card:
     *   ct  content type ('movie' | 'tv') for Movie/TV badges
     *   na  next airing { episode, ts } unix — the "Episode N in 2d 4h" line
     * The aired count stays what is actually OUT so "12 EP" never promises
     * an episode that has not aired yet.
     */
    $contentType = (string)($item['content_type'] ?? '');
    if ($contentType !== '') $payload['ct'] = $contentType;
    // TMDB list rows carry no reliable status (tvq=1): the hover card shows a
    // neutral badge and resolves the real status from the detail endpoint on
    // first hover instead of guessing "Released" for a show that is airing.
    if (!empty($item['tvq'])) $payload['tvq'] = 1;
    $airedOut = (int)($item['aired_episodes'] ?? 0);
    if ($airedOut > 0) $payload['ae'] = $airedOut;
    // TMDB TV: `episodes` holds the season count and `total_episodes` the
    // series-wide episode total — pass the total so the hover card can show
    // “38S · 1179 EP” instead of a meaningless “38 EP”.
    if ($contentType === 'tv' && !empty($item['total_episodes']) && is_numeric($item['total_episodes'])) {
        $payload['ae'] = (int)$item['total_episodes'];
    }
    if (!empty($item['next_airing']['airingAt'])) {
        $nextTs = (int)$item['next_airing']['airingAt'];
        if ($nextTs > time()) {
            $payload['na'] = [
                'e'  => (int)($item['next_airing']['episode'] ?? 0),
                'ts' => $nextTs,
            ];
        }
    }

    // An episode row (schedule / continue watching) should link to that episode.
    if (!empty($opts['preview_episode'])) {
        $payload['ep'] = (int)$opts['preview_episode'];
    }

    return kp_e(json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ));
}

/** Render a row of cards with a heading, or a placeholder message. */
function render_card_row($heading, $icon, $items, array $opts = [], $emptyMessage = 'কোনো ডাটা পাওয়া যাচ্ছে না — পরে আবার চেষ্টা করুন।') {
    $heading = kp_e($heading);
    $count = is_array($items) ? count($items) : 0;
    $html = '<div class="anime-con show-container"><div class="head-show">'
          . '<i class="' . kp_e($icon) . '"></i><p>' . $heading . '</p>'
          . '<span class="kp-count">' . $count . '</span></div>';

    if ($count > 0) {
        $html .= '<div class="show-item-con">';
        $i = 0;
        foreach ($items as $item) {
            $i++;
            $cardOpts = $opts;
            if (!empty($opts['ranked'])) $cardOpts['rank'] = $i;
            $html .= render_anime_card($item, $cardOpts);
        }
        $html .= '</div>';
    } else {
        $html .= '<div style="justify-content:center;">'
               . '<p style="color:#aaa; padding:20px; margin:0;"><i class="fa-solid fa-ghost"></i> ' . kp_e($emptyMessage) . '</p></div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * One slide's inner markup, shared by the first (server-rendered) slide.
 *
 * Mirrors the featured-banner layout: eyebrow tag, stat chips, oversized
 * title, synopsis and two calls to action sitting straight on the artwork.
 */
function kp_hero_slide_body(array $item, $tag, $logo = null) {
    $title  = kp_e($item['title'] ?? 'Unknown');
    $poster = $item['poster'] ?: './uploads/thumbnails/default.png';
    $bg     = !empty($item['banner']) ? $item['banner'] : $poster;
    $link   = kp_watch_url($item);
    $detailLink = './watch.php?id=' . urlencode($item['id'] ?? '');

    $desc = trim(html_entity_decode(strip_tags((string)($item['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($desc) > 210) $desc = mb_substr($desc, 0, 210) . '…';
    $desc = kp_e($desc);

    $stats = [];
    if (!empty($item['score'])) {
        $stats[] = '<span class="kp-hero-badge kp-hero-badge-star"><i class="fa-solid fa-star"></i> ' . kp_e($item['score']) . '</span>';
    }
    if (!empty($item['format'])) $stats[] = '<span class="kp-hero-badge">' . kp_e($item['format']) . '</span>';
    $aired = (int)($item['aired_episodes'] ?? 0);
    if ($aired > 0) {
        $stats[] = '<span class="kp-hero-badge"><i class="fa-solid fa-closed-captioning"></i> ' . $aired . ' Ep</span>';
    }
    if (!empty($item['year']))   $stats[] = '<span class="kp-hero-badge">' . kp_e($item['year']) . '</span>';

    $statsHtml = $stats ? '<div class="kp-hero-stats">' . implode('', $stats) . '</div>' : '';
    $tagLabel  = ' ' . kp_e($tag);

    // A logo (hand-picked or TMDB) replaces the text title when we have one.
    $hasLogo  = is_string($logo) && $logo !== '';
    $logoHtml = $hasLogo ? '<img class="kp-hero-logo" src="' . kp_e($logo) . '" alt="' . $title . '" decoding="async" fetchpriority="low">' : '';
    $copyClass = $hasLogo ? 'kp-hero-copy has-logo' : 'kp-hero-copy';

    /*
     * Only the first slide is server-rendered, and it owns the viewport: the
     * banner is the LCP element, so it is eager with a high priority hint and
     * a 100vw sizes hint (banners have no CDN variants, posters do).
     */
    $bgAttrs = kp_img_attrs($bg, ['sizes' => '100vw', 'priority' => true]);

    return <<<HTML
        <img class="kp-hero-bg" src="{$bg}" alt=""{$bgAttrs}>
        <div class="kp-hero-shade"></div>
        <div class="kp-hero-body">
            <div class="{$copyClass}">
                <span class="kp-hero-tag"><i class="fa-solid fa-fire"></i>{$tagLabel}</span>
                {$statsHtml}
                {$logoHtml}
                <h2 class="kp-hero-title">{$title}</h2>
                <p class="kp-hero-desc">{$desc}</p>
                <div class="kp-hero-actions">
                    <a href="{$link}" class="kp-hero-btn kp-hero-watch"><i class="fa-solid fa-play"></i> Watch Now</a>
                    <a href="{$detailLink}" class="kp-hero-btn kp-hero-details"><i class="fa-solid fa-circle-info"></i> Details</a>
                </div>
            </div>
        </div>
    HTML;
}

/**
 * Full-width featured banner for the top of the home page.
 * Falls back gracefully when the item has no banner image.
 *
 * Not wrapped in an anchor: the slide already contains its own WATCH / DETAILS
 * links and nested anchors are invalid markup.
 */
function render_hero($item, $tag = 'Trending Now') {
    if (!is_array($item) || empty($item['id'])) return '';
    $link = kp_watch_url($item);

    // The slide payload keeps the single banner upgradable too (title logo),
    // which is why it is wrapped like a one-slide carousel.
    $logo = title_logo_override($item['mal_id'] ?? 0, $item['title'] ?? '');

    return '<div class="kp-hero kp-hero-single is-active kp-slide" data-kp-slider data-kp-index="0"'
         . ' data-kp-slide=' . "'" . kp_preview_payload($item, $link) . "'"
         . ($logo !== null ? ' data-kp-logo="' . kp_e($logo) . '"' : '')
         . ' role="group" aria-roledescription="slide"'
         . ' aria-label="' . kp_e($item['title'] ?? 'Anime') . '">'
         . kp_hero_slide_body($item, $tag, $logo) . '</div>';
}

/**
 * Rotating hero. Only the first slide is server-rendered; the rest are built
 * by assets/js/hero-slider.js from each slide's data-kp payload, so a long
 * carousel costs no extra markup.
 */
function render_hero_slider(array $items, $tag = 'Trending Now') {
    $items = array_values(array_filter($items, fn($i) => is_array($i) && !empty($i['id'])));
    if (!$items) return '';

    // A slider of one is just a banner — the plain markup already carries a
    // data-kp-slide payload, so the title logo still upgrades.
    if (count($items) === 1) return render_hero($items[0], $tag);

    $count = count($items);
    $total = str_pad((string)$count, 2, '0', STR_PAD_LEFT);

    $slides = '';
    foreach ($items as $i => $item) {
        $link  = kp_watch_url($item);
        $title = (string)($item['title'] ?? 'Anime');

        // Hand-picked logos are known up front; TMDB ones arrive later from
        // includes/title_logos.php and are swapped in by the slider script.
        $logo = title_logo_override($item['mal_id'] ?? 0, $title);

        $slides .= '<div class="kp-hero kp-slide' . ($i === 0 ? ' is-active' : '') . '"'
                 . ' data-kp-index="' . $i . '"'
                 . ' data-kp-slide=' . "'" . kp_preview_payload($item, $link) . "'"
                 . ($logo !== null ? ' data-kp-logo="' . kp_e($logo) . '"' : '')
                 . ' role="group" aria-roledescription="slide"'
                 . ($i === 0 ? '' : ' aria-hidden="true"')
                 . ' aria-label="' . kp_e(($i + 1) . ' / ' . $count . ' — ' . $title) . '">';

        // Only the first slide is rendered now; the rest stream in on demand.
        $slides .= $i === 0 ? kp_hero_slide_body($item, $tag, $logo) : '';
        $slides .= '</div>';
    }

    $dots = '';
    for ($i = 0; $i < $count; $i++) {
        $dots .= '<button type="button" class="kp-slider-dot' . ($i === 0 ? ' is-active' : '') . '"'
               . ' data-kp-goto="' . $i . '" aria-label="Slide ' . ($i + 1) . ' of ' . $count . '"></button>';
    }

    $label = kp_e($tag);

    return <<<HTML
    <div class="kp-hero-slider" data-kp-slider data-kp-tag="{$label}" role="region" aria-roledescription="carousel" aria-label="{$label}">
        <div class="kp-slider-track">{$slides}</div>
        <div class="kp-slider-nav">
            <button type="button" class="kp-slider-pause" data-kp-pause aria-label="Pause slideshow"><i class="fa-solid fa-pause"></i></button>
            <button type="button" class="kp-slider-arrow prev" data-kp-prev aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>
            <button type="button" class="kp-slider-arrow next" data-kp-next aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
        <div class="kp-slider-dots">{$dots}</div>
        <span class="kp-slider-counter"><b data-kp-current>01</b> / {$total}</span>
    </div>
    HTML;
}

/** Small notice bar used at the top of pages. */
function render_notice($html, $icon = 'fa-solid fa-circle-info') {
    return '<div class="show-container" style="margin-bottom:20px;">'
         . '<div class="head-show"><i class="' . kp_e($icon) . '"></i><p>' . $html . '</p></div>'
         . '</div>';
}

/**
 * Sidebar "Upcoming" rows grouped by arrival day.
 *
 *   today  → air time is later today (or already out within the last 12h)
 *   next   → tomorrow, up to 7 days out
 *   later  → beyond a week (or undated: premiere-pending titles)
 *
 * Every group is sorted by air time (undated last), so the list reads as a
 * mini schedule instead of a popularity dump.
 */
function kp_group_upcoming(array $items, $now = null) {
    $now = $now ?: time();
    $startOfDay = (int)strtotime('today', $now);
    $groups = ['today' => [], 'next' => [], 'later' => []];
    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id']) || isset($seen[$item['id']])) continue;
        $seen[$item['id']] = true;
        $ts = (int)($item['next_airing']['airingAt'] ?? 0);
        if ($ts >= $startOfDay && $ts < $startOfDay + 86400 + 43200) {
            // Later today (grace window covers episodes that just aired).
            $groups['today'][] = $item + ['_ts' => $ts];
        } elseif ($ts >= $startOfDay + 86400 && $ts < $startOfDay + 8 * 86400) {
            $groups['next'][] = $item + ['_ts' => $ts];
        } else {
            // Beyond a week, in the past, or no schedule at all.
            $groups['later'][] = $item + ['_ts' => $ts];
        }
    }
    foreach ($groups as $key => $rows) {
        usort($rows, fn($a, $b) => ($a['_ts'] ?: PHP_INT_MAX) <=> ($b['_ts'] ?: PHP_INT_MAX));
        $groups[$key] = $rows;
    }
    return $groups;
}

/**
 * One row of the sidebar Upcoming list — mirrors the old inline markup but
 * reads the enriched fields (episode number, countdown chip) too.
 */
function render_upcoming_item(array $item) {
    if (!is_array($item) || empty($item['id'])) return '';

    $title    = kp_e($item['title'] ?? 'Unknown');
    $poster   = $item['poster'] ?: './uploads/thumbnails/default.png';
    $format   = $item['format'] ?: ($item['type'] ?? 'TV');
    $meta     = $item['year'] ?: ($item['status'] ?? 'TBA');
    $airTs    = (int)($item['next_airing']['airingAt'] ?? 0);
    $airEp    = (int)($item['next_airing']['episode'] ?? 0);

    // Arrival line: "EP 4 · Fri, 25 Sep" when scheduled, else the premiere
    // date for not-yet-released titles.
    $airTxt = '';
    if ($airTs > 0) {
        $airTxt = ($airEp > 0 ? 'EP ' . $airEp . ' · ' : '') . date('D, d M', $airTs);
    } elseif (($item['status'] ?? '') === 'NOT_YET_RELEASED' && !empty($item['aired']) && $item['aired'] !== 'N/A') {
        $airTxt = 'Premieres ' . $item['aired'];
    }

    $epTotal = (int)(($item['episodes'] ?? 0) ?: ($item['aired_episodes'] ?? 0));
    $epHtml  = $epTotal > 0 ? '<span><i class="fas fa-layer-group"></i> ' . $epTotal . '</span>' : '';

    $link    = kp_e(kp_watch_url($item));
    $airHtml = '';
    if ($airTxt !== '') {
        $airHtml = '<div class="kp-side-air"><i class="fas fa-clock"></i><span>' . kp_e($airTxt) . '</span>'
                 . ($airTs > 0 ? kp_countdown_chip($airTs) : '') . '</div>';
    }

    return <<<HTML
    <a class="kp-side-item" href="{$link}">
        <img class="kp-side-thumb" src="{$poster}" alt="" loading="lazy" onerror="this.onerror=null;this.src='./uploads/thumbnails/default.png';">
        <div class="kp-side-info">
            <span class="kp-side-title">{$title}</span>
            <div class="kp-side-meta">
                <span class="kp-side-badge">{$format}</span>
                <span>{$meta}</span>
                {$epHtml}
            </div>
            {$airHtml}
        </div>
        <i class="fas fa-chevron-right kp-side-go"></i>
    </a>
    HTML;
}
