<?php
/**
 * Jikan v4 client — MyAnimeList data, used as the last catalog fallback.
 *
 * Free, no key. Rate limited to ~3 requests/second, so everything goes
 * through the shared cache.
 *
 * Docs: https://docs.api.jikan.moe/
 */

include_once __DIR__ . '/http.php';

// ─── Transport ─────────────────────────────────────────────────────────

function jikan_request($path, array $params = [], $ttl = CACHE_TTL_LIST) {
    if (!JIKAN_ENABLED) return null;

    $path = '/' . ltrim($path, '/');
    $key = api_cache_key('jikan', ['p' => $path, 'q' => $params]);

    return api_cache_remember($key, 'jikan', $ttl, function () use ($path, $params) {
        $url = rtrim(JIKAN_BASE_URL, '/') . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $res = api_http($url, ['timeout' => 25, 'label' => 'Jikan']);
        if (!is_array($res) || !isset($res['data'])) {
            return null;
        }
        // Jikan wraps payloads as { data: ... , pagination: ... }
        return ['data' => $res['data'], 'pagination' => $res['pagination'] ?? null];
    });
}

// ─── ID helpers (jikan:{mal_id}) ───────────────────────────────────────

function jikan_id_from_mal($mal_id) {
    return 'jikan:' . (int)$mal_id;
}

function jikan_mal_from_id($id) {
    if (strpos((string)$id, 'jikan:') !== 0) return null;
    $n = (int)substr((string)$id, 6);
    return $n > 0 ? $n : null;
}

// ─── Normalization ─────────────────────────────────────────────────────

function jikan_names($list) {
    $out = [];
    if (!is_array($list)) return $out;
    foreach ($list as $entry) {
        if (is_array($entry) && !empty($entry['name'])) $out[] = $entry['name'];
        elseif (is_string($entry)) $out[] = $entry;
    }
    return $out;
}

function jikan_pick_title($a) {
    $candidates = [
        $a['title_english'] ?? null,
        $a['title'] ?? null,
        $a['title_japanese'] ?? null,
    ];
    foreach ((array)($a['titles'] ?? []) as $t) {
        if (!empty($t['title'])) $candidates[] = $t['title'];
    }
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
    }
    return 'Unknown';
}

function jikan_normalize($a) {
    if (!is_array($a) || empty($a['mal_id'])) return null;

    $malId = (int)$a['mal_id'];
    $images = $a['images'] ?? [];
    $poster = $images['webp']['large_image_url']
        ?? $images['jpg']['large_image_url']
        ?? $images['webp']['image_url']
        ?? $images['jpg']['image_url']
        ?? '';

    $episodes = !empty($a['episodes']) ? (int)$a['episodes'] : 0;
    $status = $a['status'] ?? '';
    $aired = $a['aired']['from'] ?? null;
    if ($aired) $aired = substr($aired, 0, 10);

    $genres = array_merge(jikan_names($a['genres'] ?? []), jikan_names($a['themes'] ?? []));
    $studios = jikan_names($a['studios'] ?? []);

    $score = null;
    if (!empty($a['score']) && is_numeric($a['score'])) {
        $score = number_format((float)$a['score'], 1);
    }

    $duration = $a['duration'] ?? null;
    if (is_string($duration) && preg_match('/(\d+)\s*min/', $duration, $m)) {
        $duration = $m[1] . 'm';
    }

    return [
        'id'              => jikan_id_from_mal($malId),
        'provider'        => 'jikan',
        'provider_id'     => (string)$malId,
        'anilist_id'      => null,
        'mal_id'          => $malId,
        'title'           => jikan_pick_title($a),
        'title_romaji'    => $a['title'] ?? null,
        'title_english'   => $a['title_english'] ?? null,
        'title_native'    => $a['title_japanese'] ?? null,
        'poster'          => $poster,
        'banner'          => '',
        'description'     => trim((string)($a['synopsis'] ?? '')),
        'type'            => $a['type'] ?? 'TV',
        'format'          => $a['type'] ?? null,
        'status'          => $status,
        'episodes'        => $episodes,
        'aired_episodes'  => $episodes,
        'episode'         => $episodes ?: null,
        'duration'        => $duration,
        'duration_min'    => null,
        'genres'          => $genres,
        'studios'         => $studios,
        'studio'          => $studios[0] ?? null,
        'score'           => $score,
        'rating'          => $score ?? 'N/A',
        'popularity'      => !empty($a['members']) ? (int)$a['members'] : 0,
        'season'          => !empty($a['season']) ? strtoupper($a['season']) : null,
        'year'            => !empty($a['year']) ? (int)$a['year'] : null,
        'aired'           => $aired,
        'next_airing'     => null,
        'trailer'         => null,
        'is_adult'        => !empty($a['rating']) && stripos($a['rating'], 'Rx') === 0,
        'relations'       => [],
        'recommendations' => [],
        'episodes_list'   => [],
        'external_links'  => [],
    ];
}

// ─── Public catalogue calls ────────────────────────────────────────────

function jikan_search($term, $limit = 20) {
    $term = trim((string)$term);
    if ($term === '') return [];

    $res = jikan_request('/anime', [
        'q'     => $term,
        'limit' => min(25, max(1, (int)$limit)),
        'sfw'   => 'true',
        'order_by' => 'members',
        'sort'  => 'desc',
    ]);
    if (empty($res['data']) || !is_array($res['data'])) return [];

    $out = [];
    foreach ($res['data'] as $a) {
        $n = jikan_normalize($a);
        if ($n) $out[] = $n;
    }
    return $out;
}

function jikan_airing($limit = 20) {
    $res = jikan_request('/top/anime', [
        'filter' => 'airing',
        'limit'  => min(25, max(1, (int)$limit)),
        'sfw'    => 'true',
    ]);
    if (empty($res['data']) || !is_array($res['data'])) return [];

    $out = [];
    foreach ($res['data'] as $a) {
        $n = jikan_normalize($a);
        if ($n) $out[] = $n;
    }
    return $out;
}

function jikan_top($limit = 20) {
    $res = jikan_request('/top/anime', [
        'limit' => min(25, max(1, (int)$limit)),
        'sfw'   => 'true',
    ]);
    if (empty($res['data']) || !is_array($res['data'])) return [];

    $out = [];
    foreach ($res['data'] as $a) {
        $n = jikan_normalize($a);
        if ($n) $out[] = $n;
    }
    return $out;
}

function jikan_info($mal_id) {
    $mal_id = (int)$mal_id;
    if ($mal_id <= 0) return null;

    $res = jikan_request('/anime/' . $mal_id . '/full', [], CACHE_TTL_INFO);
    $a = $res['data'] ?? null;
    if (!is_array($a)) return null;

    $item = jikan_normalize($a);
    if (!$item) return null;

    $item['relations'] = [];
    foreach ((array)($a['relations'] ?? []) as $rel) {
        foreach ((array)($rel['entry'] ?? []) as $entry) {
            if (empty($entry['mal_id'])) continue;
            $item['relations'][] = [
                'id'            => jikan_id_from_mal($entry['mal_id']),
                'provider'      => 'jikan',
                'provider_id'   => (string)$entry['mal_id'],
                'mal_id'        => (int)$entry['mal_id'],
                'title'         => $entry['name'] ?? 'Unknown',
                'type'          => $entry['type'] ?? null,
                'poster'        => '',
                'relation_type' => $rel['relation'] ?? '',
            ];
        }
    }

    $item['external_links'] = [];
    foreach ((array)($a['streaming'] ?? []) as $s) {
        if (!empty($s['url'])) {
            $item['external_links'][] = ['site' => $s['name'] ?? '', 'url' => $s['url'], 'type' => 'streaming'];
        }
    }

    return $item;
}

/** Episode list from MAL. */
function jikan_episodes($mal_id, $page = 1) {
    $mal_id = (int)$mal_id;
    if ($mal_id <= 0) return [];

    $res = jikan_request('/anime/' . $mal_id . '/episodes', ['page' => max(1, (int)$page)], CACHE_TTL_INFO);
    if (empty($res['data']) || !is_array($res['data'])) return [];

    $out = [];
    foreach ($res['data'] as $ep) {
        $out[] = [
            'number' => !empty($ep['mal_id']) ? (int)$ep['mal_id'] : null,
            'title'  => $ep['title'] ?? '',
            'image'  => '',
            'aired'  => !empty($ep['aired']) ? substr($ep['aired'], 0, 10) : '',
            'filler' => !empty($ep['filler']),
            'recap'  => !empty($ep['recap']),
        ];
    }
    return $out;
}

/** Schedule for one weekday name (e.g. "monday"). */
function jikan_schedule($day, $limit = 25) {
    $day = strtolower(trim((string)$day));
    if (!in_array($day, ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'], true)) {
        return [];
    }
    $res = jikan_request('/schedules', [
        'filter' => $day,
        'limit'  => min(25, max(1, (int)$limit)),
        'sfw'    => 'true',
    ], CACHE_TTL_SCHEDULE);
    if (empty($res['data']) || !is_array($res['data'])) return [];

    $out = [];
    foreach ($res['data'] as $a) {
        $n = jikan_normalize($a);
        if (!$n) continue;
        $broadcast = $a['broadcast']['string'] ?? '';
        $time = null;
        if (preg_match('/(\d{1,2}):(\d{2})/', (string)$broadcast, $m)) {
            $time = $m[1] . ':' . $m[2];
        }
        $n['airing_time'] = $time;
        $n['air_type'] = 'sub';
        $n['airing_status'] = 'upcoming';
        $out[] = $n;
    }
    return $out;
}
