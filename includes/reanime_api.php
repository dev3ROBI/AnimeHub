<?php
/**
 * ReAnime client.
 *
 * Metadata comes from the public API at REANIME_BASE_URL, which only exposes
 * a subset of routes. Verified working:
 *
 *   GET /search?q=&limit=          GET /home?limit=
 *   GET /top/anime?period=&limit=  GET /schedule?tz=&week=
 *   GET /anime/{slug}              GET /anime/{slug}/episodes?page=&limit=
 *   GET /anime/{slug}/recommendations
 *
 * NOT available upstream (returns 401): /info, /watch, /servers, /stream.
 * Streaming therefore goes through the self-hosted scraper in
 * ReAnime.to-API/ (REANIME_SCRAPER_URL), which also handles the flixcloud
 * decryption. See includes/stream.php for the full resolver.
 */

include_once __DIR__ . '/http.php';

// ─── Image helper ──────────────────────────────────────────────────────

function reanime_default_poster() {
    return './uploads/thumbnails/default.png';
}

function reanime_format_image($image, $size = 'large') {
    if (!$image) return reanime_default_poster();

    if (is_string($image)) {
        if (preg_match('/^https?:\/\//i', $image)) return $image;
        return $image !== '' ? $image : reanime_default_poster();
    }

    if (is_array($image)) {
        if ($size === 'smallest') {
            $order = ['medium', 'large', 'extra_large'];
        } elseif ($size === 'small') {
            $order = ['medium', 'large', 'extra_large'];
        } else {
            $order = ['extra_large', 'large', 'medium'];
        }
        foreach ($order as $k) {
            if (!empty($image[$k]) && is_string($image[$k]) && preg_match('/^https?:\/\//i', $image[$k])) {
                return $image[$k];
            }
        }
        foreach ($image as $v) {
            if (is_string($v) && preg_match('/^https?:\/\//i', $v)) return $v;
        }
    }

    return reanime_default_poster();
}

// ─── Transport ─────────────────────────────────────────────────────────

/**
 * GET against the public ReAnime API.
 *
 * @param bool $scraper  hit the self-hosted scraper instead
 */
function callReAnimeAPI($endpoint, $timeout = 30, $scraper = false) {
    $baseUrl = $scraper ? REANIME_SCRAPER_URL : REANIME_BASE_URL;
    if (empty($baseUrl)) return null;

    $url = rtrim($baseUrl, '/') . $endpoint;
    return api_http($url, [
        'timeout' => (int)$timeout,
        'label'   => $scraper ? 'ReAnimeScraper' : 'ReAnime',
        'headers' => ['Referer: https://reanime.to/'],
    ]);
}

// ─── Normalization ─────────────────────────────────────────────────────

/**
 * Flatten a ReAnime anime object into the catalogue's common shape.
 */
function reanime_normalize_item($item) {
    if (!is_array($item)) return null;

    // slug: anime_id is the canonical key on this API.
    if (empty($item['slug'])) {
        if (!empty($item['anime_id'])) {
            $item['slug'] = $item['anime_id'];
        } elseif (!empty($item['route'])) {
            $item['slug'] = $item['route'];
        }
    }

    // anilist id: explicit field, else scraped out of the cover URL (/bx21-...).
    if (empty($item['anilist_id']) && !empty($item['anilist'])) {
        $item['anilist_id'] = (int)$item['anilist'];
    }
    if (empty($item['anilist_id']) || (int)$item['anilist_id'] <= 0) {
        $cover = $item['cover_image'] ?? null;
        $urls = [];
        if (is_array($cover)) {
            foreach (['extra_large', 'large', 'medium'] as $ck) {
                if (!empty($cover[$ck])) $urls[] = $cover[$ck];
            }
        } elseif (is_string($cover)) {
            $urls[] = $cover;
        }
        foreach ($urls as $u) {
            if (preg_match('#/bx(\d+)-#', $u, $m)) {
                $item['anilist_id'] = (int)$m[1];
                break;
            }
        }
    }
    if (!empty($item['anilist_id'])) $item['anilist'] = (int)$item['anilist_id'];

    // Title.
    $titles = $item['title'] ?? null;
    if (is_array($titles)) {
        $pick = $titles['english'] ?? $titles['user_preferred'] ?? $titles['romaji'] ?? $titles['native'] ?? null;
        $title = (is_string($pick) && $pick !== '') ? $pick
            : (!empty($item['slug']) ? ucwords(str_replace(['-', '_'], ' ', $item['slug'])) : 'Unknown');
    } elseif (is_string($titles) && trim($titles) !== '') {
        $title = $titles;
    } else {
        $title = !empty($item['slug']) ? ucwords(str_replace(['-', '_'], ' ', $item['slug'])) : 'Unknown';
    }

    $poster = reanime_format_image($item['cover_image'] ?? $item['poster'] ?? null, 'large');

    $description = $item['description'] ?? ($item['synopsis'] ?? '');
    if (is_string($description) && $description !== '') {
        $description = trim(html_entity_decode(
            strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $description)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    } else {
        $description = '';
    }

    // Score is on a 0-100 scale here.
    $score = null;
    foreach (['average_score', 'mean_score', 'mal_score'] as $k) {
        if (!empty($item[$k]) && is_numeric($item[$k]) && (float)$item[$k] > 0) {
            $score = number_format(((float)$item[$k]) / 10, 1);
            break;
        }
    }

    $episodes = 0;
    foreach (['episodes_total', 'last_episode', 'episodes'] as $k) {
        if (!empty($item[$k]) && is_numeric($item[$k])) {
            $episodes = max($episodes, (int)$item[$k]);
        }
    }

    $studios = [];
    foreach ((array)($item['studios'] ?? []) as $s) {
        if (is_array($s) && !empty($s['name'])) $studios[] = $s['name'];
        elseif (is_string($s) && $s !== '') $studios[] = $s;
    }

    $aired = null;
    $start = $item['start_date'] ?? null;
    if (is_array($start) && !empty($start['year'])) {
        $aired = $start['year']
            . (!empty($start['month']) ? '-' . str_pad((string)$start['month'], 2, '0', STR_PAD_LEFT) : '')
            . (!empty($start['day']) ? '-' . str_pad((string)$start['day'], 2, '0', STR_PAD_LEFT) : '');
    } elseif (is_string($start) && $start !== '') {
        $aired = substr($start, 0, 10);
    }

    $nextAiring = null;
    if (!empty($item['next_airing_episode']['episode'])) {
        $nextAiring = [
            'episode'  => (int)$item['next_airing_episode']['episode'],
            'airingAt' => !empty($item['next_airing_episode']['airing_at'])
                            ? strtotime($item['next_airing_episode']['airing_at'])
                            : null,
        ];
    }

    $episode = !empty($item['episode']) && is_numeric($item['episode']) ? (int)$item['episode'] : null;
    if ($episode === null && $nextAiring) $episode = max(1, $nextAiring['episode'] - 1);

    $genres = $item['genres'] ?? [];
    if (!is_array($genres)) $genres = [];

    return [
        'id'              => !empty($item['slug']) ? reanime_id_from_slug($item['slug']) : null,
        'provider'        => 'reanime',
        'provider_id'     => !empty($item['slug']) ? (string)$item['slug'] : null,
        'slug'            => $item['slug'] ?? null,
        'anilist_id'      => !empty($item['anilist_id']) ? (int)$item['anilist_id'] : null,
        'mal_id'          => !empty($item['mal_id']) ? (int)$item['mal_id'] : null,
        'title'           => $title,
        'title_romaji'    => is_array($titles) ? ($titles['romaji'] ?? null) : null,
        'title_english'   => is_array($titles) ? ($titles['english'] ?? null) : null,
        'title_native'    => is_array($titles) ? ($titles['native'] ?? null) : null,
        'poster'          => $poster,
        'banner'          => $item['banner_image'] ?? '',
        'description'     => $description,
        'type'            => $item['format'] ?? 'TV',
        'format'          => $item['format'] ?? null,
        'status'          => $item['status'] ?? '',
        'episodes'        => $episodes,
        'aired_episodes'  => $episodes,
        'episode'         => $episode,
        'episode_number'  => $episode,
        'duration'        => !empty($item['duration']) ? ((int)$item['duration'] . 'm') : null,
        'duration_min'    => !empty($item['duration']) ? (int)$item['duration'] : null,
        'genres'          => array_values($genres),
        'studios'         => $studios,
        'studio'          => $studios[0] ?? null,
        'score'           => $score,
        'rating'          => $item['rating'] ?? ($score ?? 'N/A'),
        'popularity'      => !empty($item['popularity']) ? (int)$item['popularity'] : 0,
        'season'          => $item['season'] ?? null,
        'year'            => !empty($item['season_year']) ? (int)$item['season_year'] : null,
        'aired'           => $aired,
        'next_airing'     => $nextAiring,
        'trailer'         => null,
        'is_adult'        => !empty($item['is_adult']),
        // ReAnime exposes no country/language fields → N/A (never hardcode JP).
        'language'        => null,
        'country'         => null,
        'relations'       => [],
        'recommendations' => [],
        'episodes_list'   => [],
        'external_links'  => [],
    ];
}

/** Normalize a list of raw items, dropping the unusable ones. */
function reanime_normalize_list($items) {
    $out = [];
    foreach ((array)$items as $it) {
        $n = reanime_normalize_item($it);
        if ($n && !empty($n['slug'])) $out[] = $n;
    }
    return $out;
}

// ─── Search ────────────────────────────────────────────────────────────

function reanime_search($query, $limit = 20) {
    $query = trim((string)$query);
    if ($query === '') return null;

    $data = callReAnimeAPI('/search?q=' . urlencode($query) . '&limit=' . intval($limit), 20);
    if (!is_array($data)) return null;

    $results = $data['results'] ?? [];
    if (!is_array($results)) $results = [];

    return array_merge($data, ['results' => reanime_normalize_list($results)]);
}

/**
 * Resolve a title to a ReAnime slug. Used to hand AniList metadata over to
 * the scraper, which needs a slug + episode number.
 */
function reanime_find_slug($title, $year = null) {
    $title = trim((string)$title);
    if ($title === '') return null;

    $key = api_cache_key('reanime-slug', ['t' => $title, 'y' => $year]);
    return api_cache_remember($key, 'reanime', CACHE_TTL_INFO, function () use ($title, $year) {
        $res = reanime_search($title, 10);
        $results = $res['results'] ?? [];
        if (!$results) return null;

        $needle = strtolower(preg_replace('/[^a-z0-9]+/i', '', $title));
        $best = null;
        $bestScore = -1;

        foreach ($results as $r) {
            $candidates = array_filter([
                $r['title'] ?? null,
                $r['title_romaji'] ?? null,
                $r['title_english'] ?? null,
            ]);
            $score = 0;
            foreach ($candidates as $c) {
                $hay = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$c));
                if ($hay === '') continue;
                if ($hay === $needle) { $score = max($score, 100); continue; }
                if (str_contains($hay, $needle) || str_contains($needle, $hay)) $score = max($score, 70);
                similar_text($hay, $needle, $pct);
                $score = max($score, (int)$pct);
            }
            if ($year && !empty($r['year']) && (int)$r['year'] === (int)$year) $score += 10;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }

        if ($best && $bestScore >= 55 && !empty($best['slug'])) {
            return ['slug' => $best['slug'], 'anilist_id' => $best['anilist_id'] ?? null, 'score' => $bestScore];
        }
        return null;
    });
}

// ─── Home / Top ────────────────────────────────────────────────────────

/**
 * Home rows. The upstream /home endpoint returns flat arrays, so this
 * normalizes them into the same key names the rest of the app expects:
 *   latest_aired, top_weekly, trending, upcoming, new_on_site
 */
function reanime_home($limit = 20) {
    $data = callReAnimeAPI('/home?limit=' . intval($limit), 25);
    if (!is_array($data)) return null;

    $out = [
        'latest_aired' => reanime_normalize_list($data['latest_aired'] ?? []),
        'top_weekly'   => reanime_normalize_list($data['trending'] ?? []),
        'trending'     => reanime_normalize_list($data['trending'] ?? []),
        'new_on_site'  => reanime_normalize_list($data['new_on_site'] ?? []),
        'upcoming'     => reanime_normalize_list($data['upcoming'] ?? []),
    ];

    return $out;
}

/** Top anime. NOTE: the route is /top/anime — plain /top returns 401. */
function reanime_top($period = 'week', $limit = 20) {
    if (!in_array($period, ['day', 'today', 'week', 'month'], true)) $period = 'week';

    $data = callReAnimeAPI('/top/anime?period=' . urlencode($period) . '&limit=' . intval($limit), 25);
    if (!is_array($data)) return null;

    $results = $data['data'] ?? ($data['results'] ?? []);
    if (!is_array($results)) $results = [];

    return ['results' => reanime_normalize_list($results), 'period' => $period];
}

/** Latest episodes across the site. */
function reanime_latest_aired($limit = 20) {
    $data = callReAnimeAPI('/home/latest-aired?limit=' . intval($limit), 25);
    if (!is_array($data)) return [];

    $list = $data['data'] ?? (array_is_list($data) ? $data : []);
    return reanime_normalize_list($list);
}

// ─── Schedule ──────────────────────────────────────────────────────────

/**
 * Weekly airing schedule. Upstream returns { schedule: [...], timezone, ... }
 * grouped by date.
 */
function reanime_schedule($tz = 'Asia/Dhaka', $week = 0) {
    $endpoint = '/schedule?tz=' . urlencode($tz) . '&week=' . intval($week);
    $raw = callReAnimeAPI($endpoint, 45);
    if (!is_array($raw)) return null;

    $buckets = $raw['schedule'] ?? ($raw['entries'] ?? []);
    if (!is_array($buckets)) $buckets = [];

    $entries = [];
    foreach ($buckets as $bucket) {
        if (!is_array($bucket)) continue;
        $episodes = $bucket['episodes'] ?? [];
        $bucket['episodes'] = reanime_normalize_list($episodes);

        // Normalize the timing fields the templates read.
        foreach ($bucket['episodes'] as &$ep) {
            if (empty($ep['airingAt']) && !empty($ep['episode_date'])) {
                $ts = strtotime($ep['episode_date']);
                if ($ts > 0) $ep['airingAt'] = $ts;
            }
            if (!empty($ep['airingAt'])) {
                $ep['airing_time'] = date('g:i A', (int)$ep['airingAt']);
            }
            $ep['air_type'] = 'sub';
            $ep['airing_status'] = (!empty($ep['airingAt']) && (int)$ep['airingAt'] <= time()) ? 'aired' : 'upcoming';
        }
        unset($ep);
        $entries[] = $bucket;
    }

    $meta = [];
    foreach (['timezone', 'week', 'week_start', 'week_end', 'year'] as $k) {
        if (isset($raw[$k])) $meta[$k] = $raw[$k];
    }

    return array_merge(['entries' => $entries], $meta);
}

// ─── Detail ────────────────────────────────────────────────────────────

/**
 * Full detail for one slug.
 *
 * Upstream /info/{slug} does not exist. The equivalent is /anime/{slug},
 * plus /anime/{slug}/episodes for the episode list.
 */
function reanime_info($slug) {
    $slug = trim((string)$slug);
    if ($slug === '') return null;

    $key = api_cache_key('reanime-info', $slug);
    $cached = api_cache_get($key);
    if ($cached !== null) return $cached;

    $data = callReAnimeAPI('/anime/' . rawurlencode($slug), 35);
    if (!is_array($data)) {
        // Fall back to matching the slug against a search.
        $guess = ucwords(str_replace(['-', '_'], ' ', preg_replace('/-[a-z0-9]{5,8}$/i', '', $slug)));
        $res = reanime_search($guess, 10);
        foreach (($res['results'] ?? []) as $item) {
            if (($item['slug'] ?? '') === $slug) {
                $item['episodes_list'] = reanime_episodes($slug);
                api_cache_set($key, 'reanime', $item, CACHE_TTL_INFO);
                return $item;
            }
        }
        return null;
    }

    $item = reanime_normalize_item($data);
    if (!$item) return null;
    $item['slug'] = $slug;
    $item['id'] = reanime_id_from_slug($slug);

    if (!empty($data['relations'])) {
        foreach ((array)$data['relations'] as $rel) {
            $n = reanime_normalize_item($rel);
            if (!$n) continue;
            $n['relation_type'] = $rel['relation_type'] ?? ($rel['relation'] ?? '');
            $item['relations'][] = $n;
        }
    }

    if (!empty($data['recommendations'])) {
        foreach ((array)$data['recommendations'] as $rec) {
            $n = reanime_normalize_item($rec);
            if ($n) $item['recommendations'][] = $n;
        }
    } else {
        $recs = callReAnimeAPI('/anime/' . rawurlencode($slug) . '/recommendations', 25);
        foreach ((array)($recs['recommendations'] ?? []) as $rec) {
            $n = reanime_normalize_item($rec);
            if ($n) $item['recommendations'][] = $n;
        }
    }

    if (!empty($data['external_links'])) {
        foreach ((array)$data['external_links'] as $l) {
            if (!empty($l['url'])) {
                $item['external_links'][] = [
                    'site' => $l['site'] ?? '',
                    'url'  => $l['url'],
                    'type' => $l['type'] ?? '',
                ];
            }
        }
    }

    $item['episodes_list'] = reanime_episodes($slug);
    if (empty($item['episodes_list']) && !empty($item['episodes'])) {
        $item['episodes_list'] = reanime_synthetic_episodes($item['episodes']);
    }

    api_cache_set($key, 'reanime', $item, CACHE_TTL_INFO);
    return $item;
}

/**
 * Episode list. Upstream route: /anime/{slug}/episodes?offset=&limit=
 *
 * NOTE: upstream ignores `page` and pages via `offset` instead (it caps a
 * response at 1000 items), so this walks offsets until it has everything.
 */
function reanime_episodes($slug, $offset = 0, $limit = 1000, $maxRequests = 5) {
    $slug = trim((string)$slug);
    if ($slug === '') return [];

    $limit = min(1000, max(1, (int)$limit));
    $offset = max(0, (int)$offset);
    $collected = [];

    for ($i = 0; $i < $maxRequests; $i++) {
        $data = callReAnimeAPI(
            '/anime/' . rawurlencode($slug) . '/episodes?offset=' . $offset . '&limit=' . $limit,
            45
        );
        if (!is_array($data)) break;

        $list = $data['data'] ?? ($data['episodes'] ?? ($data['results'] ?? []));
        if (!is_array($list) || !$list) break;

        foreach ($list as $ep) {
            if (!is_array($ep)) continue;
            $number = $ep['episode_number'] ?? ($ep['number'] ?? ($ep['episode'] ?? null));
            if ($number === null) continue;
            $collected[(int)$number] = [
                'number'   => (int)$number,
                'title'    => $ep['title'] ?? '',
                'image'    => $ep['thumbnail'] ?? '',
                'aired'    => !empty($ep['aired']) ? substr($ep['aired'], 0, 10) : '',
                'filler'   => !empty($ep['is_filler']),
                'recap'    => !empty($ep['is_recap']),
                'playable' => !empty($ep['playable']),
                'subbed'   => !empty($ep['subbed']),
                'dubbed'   => !empty($ep['dubbed']),
            ];
        }

        $total = (int)($data['total'] ?? 0);
        $offset += count($list);
        if (count($list) < $limit) break;
        if ($total && count($collected) >= $total) break;
    }

    $out = array_values($collected);
    usort($out, fn($a, $b) => $a['number'] <=> $b['number']);
    return $out;
}

function reanime_synthetic_episodes($total) {
    $total = min((int)$total, 2000);
    $out = [];
    for ($i = 1; $i <= $total; $i++) {
        $out[] = ['number' => $i, 'title' => '', 'image' => '', 'aired' => ''];
    }
    return $out;
}

// ─── Streaming (self-hosted scraper) ───────────────────────────────────

/**
 * Is the local scraper (ReAnime.to-API) reachable? Cached briefly so a
 * down server doesn't cost a timeout on every page load.
 */
function reanime_scraper_health($force = false) {
    $key = 'reanime:scraper-health';
    if (!$force) {
        $cached = api_cache_get($key);
        if ($cached !== null) return !empty($cached['ok']);
    }
    $res = callReAnimeAPI('/', 5, true);
    $ok = is_array($res) && (($res['status'] ?? '') === 'ok' || !empty($res['endpoints']));
    api_cache_set($key, 'reanime', ['ok' => $ok], CACHE_TTL_HEALTH);
    return $ok;
}

/**
 * Streaming servers for an episode.
 *
 * Upstream /servers/{slug}/{ep} is auth gated, so this always goes to the
 * self-hosted scraper. Returns ['sub' => [...], 'dub' => [...], ...] or null.
 */
function reanime_servers($slug, $episode, $anilist_id = null) {
    if (empty($slug) || !is_numeric($episode)) return null;
    if (!REANIME_ENABLED) return null;

    $endpoint = '/servers/' . rawurlencode($slug) . '/' . intval($episode);
    if ($anilist_id !== null && (int)$anilist_id > 0) {
        $endpoint .= '?anilist_id=' . intval($anilist_id);
    }

    $data = callReAnimeAPI($endpoint, STREAM_SCRAPER_TIMEOUT, true);
    if (!is_array($data)) return null;

    if (!isset($data['sub']) || !is_array($data['sub'])) $data['sub'] = [];
    if (!isset($data['dub']) || !is_array($data['dub'])) $data['dub'] = [];
    return $data;
}

/** Decrypt one flixcloud access id into an m3u8 URL. */
function reanime_stream($access_id, $v = 2) {
    if (empty($access_id)) return null;
    $data = callReAnimeAPI(
        '/stream/' . rawurlencode($access_id) . '?v=' . intval($v),
        STREAM_SCRAPER_TIMEOUT,
        true
    );
    return is_array($data) ? $data : null;
}

/** Same, but from a full flixcloud embed URL. */
function reanime_stream_from_link($dataLink) {
    if (empty($dataLink)) return null;

    $key = api_cache_key('reanime-stream-link', $dataLink);
    $cached = api_cache_get($key);
    if ($cached !== null) return $cached;

    $data = callReAnimeAPI(
        '/stream/from-link?link=' . urlencode($dataLink),
        STREAM_SCRAPER_TIMEOUT,
        true
    );
    if (!is_array($data) || empty($data['url'])) return null;

    api_cache_set($key, 'reanime', $data, CACHE_TTL_STREAM);
    return $data;
}

// ─── Legacy cache helpers (anime_cache table) ──────────────────────────

function reanime_get_cache($slug) {
    global $pdo;
    if (empty($slug) || !$pdo) return null;
    try {
        $stmt = $pdo->prepare("SELECT slug, title, anilist_id, episodes_json, updated_at FROM anime_cache WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (time() - strtotime($row['updated_at']) > REANIME_CACHE_TTL) return null;
        $eps = json_decode($row['episodes_json'] ?? '[]', true);
        return [
            'slug'         => $row['slug'],
            'title'        => $row['title'],
            'anilist'      => (int)($row['anilist_id'] ?? 0),
            'anilist_id'   => (int)($row['anilist_id'] ?? 0),
            'episodes'     => is_array($eps) ? $eps : [],
            '__from_cache' => true,
        ];
    } catch (Exception $e) {
        error_log('[ReAnime] Cache read error: ' . $e->getMessage());
        return null;
    }
}

function reanime_set_cache($slug, $data) {
    global $pdo;
    if (empty($slug) || !$pdo || !is_array($data)) return false;
    try {
        $title = $data['title'] ?? null;
        if (is_array($title)) $title = $title['english'] ?? $title['romaji'] ?? null;

        $anilistId = null;
        if (!empty($data['anilist_id'])) $anilistId = (int)$data['anilist_id'];
        elseif (!empty($data['anilist'])) $anilistId = (int)$data['anilist'];

        $episodes = $data['episodes'] ?? [];
        $episodesJson = is_array($episodes)
            ? json_encode($episodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '[]';

        $stmt = $pdo->prepare("
            INSERT INTO anime_cache (slug, title, anilist_id, episodes_json)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                anilist_id = VALUES(anilist_id),
                episodes_json = VALUES(episodes_json),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$slug, $title, $anilistId, $episodesJson]);
        return true;
    } catch (Exception $e) {
        error_log('[ReAnime] Cache write error: ' . $e->getMessage());
        return false;
    }
}

// ─── Watch history / ids ───────────────────────────────────────────────

function reanime_save_watch_history($user_id, $anime_slug, $episode_number) {
    global $pdo;
    if (!$pdo || empty($user_id) || empty($anime_slug) || !is_numeric($episode_number)) return false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO watch_history (user_id, anime_slug, episode_number)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE episode_number = VALUES(episode_number), watched_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([(int)$user_id, (string)$anime_slug, (int)$episode_number]);
        return true;
    } catch (Exception $e) {
        error_log('[ReAnime] Watch history save error: ' . $e->getMessage());
        return false;
    }
}

function reanime_id_from_slug($slug) {
    return 'reanime:' . (string)$slug;
}

function reanime_slug_from_id($id) {
    if (strpos((string)$id, 'reanime:') !== 0) return null;
    return substr((string)$id, 8);
}

/**
 * Total episode count from the free AniList GraphQL API.
 * Kept for callers that only have a MAL/AniList id.
 */
function reanime_anilist_episode_count($anilist_id) {
    if (empty($anilist_id) || $anilist_id <= 0) return null;
    $data = api_http(ANILIST_BASE_URL, [
        'method'  => 'POST',
        'timeout' => 10,
        'label'   => 'AniList',
        'headers' => ['Content-Type: application/json'],
        'body'    => [
            'query'     => 'query ($id: Int) { Media(id: $id, type: ANIME) { episodes nextAiringEpisode { episode } } }',
            'variables' => ['id' => (int)$anilist_id],
        ],
    ]);
    $media = $data['data']['Media'] ?? null;
    if (!is_array($media)) return null;

    if (!empty($media['episodes']) && (int)$media['episodes'] > 0) return (int)$media['episodes'];
    if (!empty($media['nextAiringEpisode']['episode'])) return (int)$media['nextAiringEpisode']['episode'] - 1;
    return null;
}
