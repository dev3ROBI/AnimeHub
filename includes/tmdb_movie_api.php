<?php
/**
 * TMDB Movie API — fetching movie data for the catalog layer.
 *
 * Uses the shared tmdb_get() transport from tmdb_api.php.
 * Returns items in the same shape as catalog_fill_item() expects.
 */

include_once __DIR__ . '/tmdb_api.php';
include_once __DIR__ . '/http.php';

// ─── TMDB Genre ID → Name mapping ─────────────────────────────────────
$TMDB_MOVIE_GENRES = [
    28=>'Action',12=>'Adventure',16=>'Animation',35=>'Comedy',80=>'Crime',
    99=>'Documentary',18=>'Drama',10751=>'Family',14=>'Fantasy',36=>'History',
    27=>'Horror',10402=>'Music',9648=>'Mystery',10749=>'Romance',878=>'Science Fiction',
    10770=>'TV Movie',53=>'Thriller',10752=>'War',37=>'Western',
];
$TMDB_TV_GENRES = [
    10759=>'Action & Adventure',16=>'Animation',35=>'Comedy',80=>'Crime',
    99=>'Documentary',18=>'Drama',10751=>'Family',10762=>'Kids',
    9648=>'Mystery',10763=>'News',10764=>'Reality',10765=>'Sci-Fi & Fantasy',
    10766=>'Soap',10767=>'Talk',10768=>'War & Politics',37=>'Western',
];

function tmdb_movie_genre_names(array $ids): array {
    global $TMDB_MOVIE_GENRES;
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (isset($TMDB_MOVIE_GENRES[$id])) $out[] = $TMDB_MOVIE_GENRES[$id];
    }
    return $out;
}

function tmdb_tv_genre_names(array $ids): array {
    global $TMDB_TV_GENRES;
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (isset($TMDB_TV_GENRES[$id])) $out[] = $TMDB_TV_GENRES[$id];
    }
    return $out;
}

function tmdb_movie_genre_list(): array {
    global $TMDB_MOVIE_GENRES;
    return $TMDB_MOVIE_GENRES;
}

function tmdb_tv_genre_list(): array {
    global $TMDB_TV_GENRES;
    return $TMDB_TV_GENRES;
}

// ─── Helpers ──────────────────────────────────────────────────────────

/** Normalize a TMDB movie result into the catalog item shape. */
function tmdb_movie_normalize($m) {
    if (!is_array($m)) return null;

    $id = (int)($m['id'] ?? 0);
    if ($id <= 0) return null;

    $poster = !empty($m['poster_path']) ? TMDB_IMAGE_BASE . $m['poster_path'] : '';
    $banner = !empty($m['backdrop_path']) ? TMDB_IMAGE_BASE . $m['backdrop_path'] : '';
    $year = !empty($m['release_date']) ? (int)substr($m['release_date'], 0, 4) : null;

    return [
        'id'              => 'tmdb:movie:' . $id,
        'provider'        => 'tmdb',
        'provider_id'     => (string)$id,
        'anilist_id'      => null,
        'mal_id'          => null,
        'slug'            => 'tmdb-movie-' . $id,
        'title'           => $m['title'] ?? $m['original_title'] ?? 'Unknown',
        'title_romaji'    => null,
        'title_english'   => $m['title'] ?? null,
        'title_native'    => $m['original_title'] ?? null,
        'poster'          => $poster,
        'banner'          => $banner,
        'description'     => $m['overview'] ?? '',
        'type'            => 'Movie',
        'format'          => 'Movie',
        'status'          => $m['release_date'] ?? '' < date('Y-m-d') ? 'Released' : 'Upcoming',
        'episodes'        => 1,
        'aired_episodes'  => 1,
        'episode'         => 1,
        'duration'        => isset($m['runtime']) ? $m['runtime'] . 'm' : '',
        'duration_min'    => $m['runtime'] ?? null,
        'genres'          => !empty($m['genres'])
            ? array_map(function($g) { return $g['name'] ?? ''; }, $m['genres'])
            : tmdb_movie_genre_names($m['genre_ids'] ?? []),
        'genre_ids'       => $m['genre_ids'] ?? [],
        'studios'         => [],
        'studio'          => '',
        'score'           => $m['vote_average'] ?? null,
        'rating'          => $m['vote_average'] ?? 'N/A',
        'popularity'      => $m['popularity'] ?? 0,
        'year'            => $year,
        'season'          => null,
        'aired'           => $m['release_date'] ?? '',
        'relations'       => [],
        'recommendations' => [],
        'episodes_list'   => [],
        'external_links'  => [],
        'next_airing'     => null,
        'trailer'         => null,
        'is_adult'        => !empty($m['adult']),
        'has_dub'         => false,
        'content_type'    => 'movie',
    ];
}

/** Normalize a TMDB TV result into the catalog item shape. */
function tmdb_tv_normalize($m) {
    if (!is_array($m)) return null;

    $id = (int)($m['id'] ?? 0);
    if ($id <= 0) return null;

    $poster = !empty($m['poster_path']) ? TMDB_IMAGE_BASE . $m['poster_path'] : '';
    $banner = !empty($m['backdrop_path']) ? TMDB_IMAGE_BASE . $m['backdrop_path'] : '';
    $year = !empty($m['first_air_date']) ? (int)substr($m['first_air_date'], 0, 4) : null;

    $genres = [];
    foreach (($m['genres'] ?? []) as $g) {
        if (!empty($g['name'])) $genres[] = $g['name'];
    }
    if (empty($genres) && !empty($m['genre_ids'])) {
        $genres = tmdb_tv_genre_names($m['genre_ids']);
    }

    $origin = $m['origin_country'] ?? [];
    $lang = !empty($origin) ? $origin[0] : ($m['original_language'] ?? '');

    return [
        'id'              => 'tmdb:tv:' . $id,
        'provider'        => 'tmdb',
        'provider_id'     => (string)$id,
        'anilist_id'      => null,
        'mal_id'          => null,
        'slug'            => 'tmdb-tv-' . $id,
        'title'           => $m['name'] ?? $m['original_name'] ?? 'Unknown',
        'title_romaji'    => null,
        'title_english'   => $m['name'] ?? null,
        'title_native'    => $m['original_name'] ?? null,
        'poster'          => $poster,
        'banner'          => $banner,
        'description'     => $m['overview'] ?? '',
        'type'            => 'TV',
        'format'          => 'TV',
        'status'          => $m['status'] ?? '',
        'episodes'        => $m['number_of_seasons'] ?? 0,
        'aired_episodes'  => $m['number_of_episodes'] ?? 0,
        'episode'         => null,
        'duration'        => isset($m['episode_run_time'][0]) ? $m['episode_run_time'][0] . 'm' : '',
        'duration_min'    => $m['episode_run_time'][0] ?? null,
        'genres'          => $genres,
        'genre_ids'       => $m['genre_ids'] ?? [],
        'studios'         => [],
        'studio'          => '',
        'score'           => $m['vote_average'] ?? null,
        'rating'          => $m['vote_average'] ?? 'N/A',
        'popularity'      => $m['popularity'] ?? 0,
        'year'            => $year,
        'season'          => null,
        'aired'           => $m['first_air_date'] ?? '',
        'relations'       => [],
        'recommendations' => [],
        'episodes_list'   => [],
        'external_links'  => [],
        'next_airing'     => null,
        // Newest aired episode as reported by TMDB — lets the notification
        // check spot a new episode without a request per season.
        'last_episode_to_air' => $m['last_episode_to_air'] ?? null,
        'trailer'         => null,
        'is_adult'        => !empty($m['adult']),
        'has_dub'         => false,
        'content_type'    => 'tv',
        'seasons_count'   => $m['number_of_seasons'] ?? 0,
        'total_episodes'  => $m['number_of_episodes'] ?? 0,
        'networks'        => array_map(function($n) { return $n['name'] ?? ''; }, $m['networks'] ?? []),
    ];
}

// ─── Movie endpoints ──────────────────────────────────────────────────

function tmdb_movie_trending($page = 1) {
    $key = api_cache_key('tmdb_movie', ['trending', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/trending/movie/week', ['page' => $page]);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_popular($page = 1) {
    $key = api_cache_key('tmdb_movie', ['popular', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/movie/popular', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_now_playing($page = 1) {
    $key = api_cache_key('tmdb_movie', ['now_playing', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/movie/now_playing', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_upcoming($page = 1) {
    $key = api_cache_key('tmdb_movie', ['upcoming', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/movie/upcoming', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_top_rated($page = 1) {
    $key = api_cache_key('tmdb_movie', ['top_rated', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/movie/top_rated', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_search($term, $page = 1) {
    $term = trim((string)$term);
    if ($term === '') return [];

    $key = api_cache_key('tmdb_movie', ['search', strtolower($term), $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/search/movie', ['query' => $term, 'page' => $page, 'include_adult' => 'false']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_detail($id) {
    $id = (int)$id;
    if ($id <= 0) return null;

    $key = api_cache_key('tmdb_movie', ['detail:v2', $id]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $m = tmdb_get('/movie/' . $id, ['append_to_response' => 'credits,videos,external_ids,similar,recommendations']);
    if (!$m || empty($m['id'])) return null;

    $item = tmdb_movie_normalize($m);
    if (!$item) return null;

    // Title logo (same artwork the hero slider uses)
    $imgs = tmdb_get('/movie/' . $id . '/images', ['include_image_language' => 'en,ja,null']);
    $item['title_logo'] = tmdb_pick_logo($imgs['logos'] ?? []);

    // Enrich with credits
    $credits = $m['credits'] ?? [];
    $directors = [];
    $cast = [];
    foreach (($credits['crew'] ?? []) as $c) {
        if (($c['job'] ?? '') === 'Director') $directors[] = $c['name'] ?? '';
    }
    $item['cast_list'] = [];
    foreach (($credits['cast'] ?? []) as $c) {
        if (empty($c['name'])) continue;
        $cast[] = $c['name'];
        if (count($item['cast_list']) < 12) {
            $item['cast_list'][] = [
                'name'      => $c['name'],
                'character' => (string)($c['character'] ?? ''),
                'photo'     => !empty($c['profile_path']) ? TMDB_IMAGE_BASE . $c['profile_path'] : '',
            ];
        }
    }
    $item['studios'] = [];
    $item['studio'] = implode(', ', array_slice($directors, 0, 3));
    $item['actors'] = implode(', ', array_slice($cast, 0, 5));

    // Enrich with trailer
    foreach (($m['videos']['results'] ?? []) as $v) {
        if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer') {
            $item['trailer'] = 'https://www.youtube.com/watch?v=' . $v['key'];
            break;
        }
    }

    // Enrich with similar
    foreach (($m['similar']['results'] ?? []) as $s) {
        $n = tmdb_movie_normalize($s);
        if ($n) $item['recommendations'][] = $n;
    }

    // External IDs
    $ext = $m['external_ids'] ?? [];
    if (!empty($ext['imdb_id'])) $item['imdb_id'] = $ext['imdb_id'];

    $item['episodes_list'] = [
        ['number' => 1, 'title' => $item['title'], 'image' => $item['poster'], 'aired' => $item['aired']]
    ];

    api_cache_set($key, 'tmdb_movie', $item, CACHE_TTL_TMDB_INFO);
    return $item;
}

// ─── TV endpoints ─────────────────────────────────────────────────────

function tmdb_tv_trending($page = 1) {
    $key = api_cache_key('tmdb_tv', ['trending', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/trending/tv/week', ['page' => $page]);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_popular($page = 1) {
    $key = api_cache_key('tmdb_tv', ['popular', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/tv/popular', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_airing_today($page = 1) {
    $key = api_cache_key('tmdb_tv', ['airing_today', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/tv/airing_today', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_on_the_air($page = 1) {
    $key = api_cache_key('tmdb_tv', ['on_the_air', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/tv/on_the_air', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_top_rated($page = 1) {
    $key = api_cache_key('tmdb_tv', ['top_rated', $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/tv/top_rated', ['page' => $page, 'language' => 'en-US']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_search($term, $page = 1) {
    $term = trim((string)$term);
    if ($term === '') return [];

    $key = api_cache_key('tmdb_tv', ['search', strtolower($term), $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/search/tv', ['query' => $term, 'page' => $page, 'include_adult' => 'false']);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

// ─── Genre-filtered browsing ───────────────────────────────────────────

function tmdb_movie_by_genre($genreId, $page = 1) {
    $genreId = (int)$genreId;
    if ($genreId <= 0) return [];

    $key = api_cache_key('tmdb_movie', ['genre', $genreId, $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/discover/movie', [
        'with_genres' => $genreId,
        'sort_by'     => 'popularity.desc',
        'page'        => $page,
        'language'    => 'en-US',
    ]);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_by_genre($genreId, $page = 1) {
    $genreId = (int)$genreId;
    if ($genreId <= 0) return [];

    $key = api_cache_key('tmdb_tv', ['genre', $genreId, $page]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $res = tmdb_get('/discover/tv', [
        'with_genres' => $genreId,
        'sort_by'     => 'popularity.desc',
        'page'        => $page,
        'language'    => 'en-US',
    ]);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_movie_discover($page = 1, array $opts = []) {
    $key = api_cache_key('tmdb_movie', ['discover', $page, md5(json_encode($opts))]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $params = ['sort_by' => 'popularity.desc', 'page' => $page, 'language' => 'en-US'];
    foreach ($opts as $k => $v) $params[$k] = $v;
    $res = tmdb_get('/discover/movie', $params);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_movie_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_movie', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_discover($page = 1, array $opts = []) {
    $key = api_cache_key('tmdb_tv', ['discover', $page, md5(json_encode($opts))]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $params = ['sort_by' => 'popularity.desc', 'page' => $page, 'language' => 'en-US'];
    foreach ($opts as $k => $v) $params[$k] = $v;
    $res = tmdb_get('/discover/tv', $params);
    $items = [];
    foreach (($res['results'] ?? []) as $m) {
        $n = tmdb_tv_normalize($m);
        if ($n) $items[] = $n;
    }
    if ($items) api_cache_set($key, 'tmdb_tv', $items, CACHE_TTL_TMDB_LIST);
    return $items;
}

function tmdb_tv_detail($id) {
    $id = (int)$id;
    if ($id <= 0) return null;

    // v3: the cached payload gained last_episode_to_air (the notification check
    // reads it). v4: the placeholder episode titles became '' — the S/E label is
    // built by the UI, and a placeholder title printed as a junk "· S1 E1"
    // suffix once the real season data started overriding it.
    $key = api_cache_key('tmdb_tv', ['detail:v4', $id]);
    $hit = api_cache_get($key);
    if (is_array($hit)) return $hit;

    $m = tmdb_get('/tv/' . $id, ['append_to_response' => 'credits,videos,external_ids,similar,recommendations']);
    if (!$m || empty($m['id'])) return null;

    $item = tmdb_tv_normalize($m);
    if (!$item) return null;

    // Title logo (same artwork the hero slider uses)
    $imgs = tmdb_get('/tv/' . $id . '/images', ['include_image_language' => 'en,ja,null']);
    $item['title_logo'] = tmdb_pick_logo($imgs['logos'] ?? []);

    // Build episodes list from seasons
    $episodesList = [];
    foreach (($m['seasons'] ?? []) as $s) {
        $sNum = (int)($s['season_number'] ?? 0);
        if ($sNum <= 0) continue; // skip specials
        for ($e = 1; $e <= (int)($s['episode_count'] ?? 0); $e++) {
            $episodesList[] = [
                'number' => $e,
                'season' => $sNum,
                'title'  => '',   // placeholder: the real name comes from tmdb_tv_season()
                'image'  => !empty($s['poster_path']) ? TMDB_IMAGE_BASE . $s['poster_path'] : $item['poster'],
                'aired'  => '',   // placeholder: no air date known from the season summary
            ];
        }
    }
    $item['episodes_list'] = $episodesList;

    // Credits
    $credits = $m['credits'] ?? [];
    $creators = [];
    $cast = [];
    foreach (($credits['crew'] ?? []) as $c) {
        if (($c['job'] ?? '') === 'Creator' || ($c['job'] ?? '') === 'Executive Producer') {
            $creators[] = $c['name'] ?? '';
        }
    }
    $item['cast_list'] = [];
    foreach (($credits['cast'] ?? []) as $c) {
        if (empty($c['name'])) continue;
        $cast[] = $c['name'];
        if (count($item['cast_list']) < 12) {
            $item['cast_list'][] = [
                'name'      => $c['name'],
                'character' => (string)($c['character'] ?? ''),
                'photo'     => !empty($c['profile_path']) ? TMDB_IMAGE_BASE . $c['profile_path'] : '',
            ];
        }
    }
    $item['studio'] = implode(', ', array_slice($creators, 0, 3));
    $item['actors'] = implode(', ', array_slice($cast, 0, 5));

    // Trailer
    foreach (($m['videos']['results'] ?? []) as $v) {
        if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer') {
            $item['trailer'] = 'https://www.youtube.com/watch?v=' . $v['key'];
            break;
        }
    }

    // Similar
    foreach (($m['similar']['results'] ?? []) as $s) {
        $n = tmdb_tv_normalize($s);
        if ($n) $item['recommendations'][] = $n;
    }

    // External
    $ext = $m['external_ids'] ?? [];
    if (!empty($ext['imdb_id'])) $item['imdb_id'] = $ext['imdb_id'];

    api_cache_set($key, 'tmdb_tv', $item, CACHE_TTL_TMDB_INFO);
    return $item;
}

/**
 * One season with real episode data: name, still, air date, runtime.
 *
 * /tv/{id}/season/{n} is the only endpoint that carries those, so the watch
 * page's episode list (and its season switcher) reads it instead of the bare
 * per-season summary used for the tab counts. Rows keep the `episodes_list`
 * shape and arrive lock-tagged by tmdb_tv_lock_state().
 *
 * Returns null only when TMDB has nothing for the season.
 */
function tmdb_tv_season($tvId, $season) {
    $tvId = (int)$tvId;
    $season = (int)$season;
    if ($tvId <= 0 || $season <= 0) return null;

    // v2: rows gained runtime/overview, so older cached entries are refetched.
    $key = api_cache_key('tmdb_tv', ['season:v2', $tvId, $season]);
    $hit = api_cache_get($key);

    if (!is_array($hit)) {
        $data = tmdb_get('/tv/' . $tvId . '/season/' . $season);
        if (!$data || empty($data['episodes'])) return null;

        $hit = [];
        foreach ($data['episodes'] as $ep) {
            $num = (int)($ep['episode_number'] ?? 0);
            if ($num <= 0) continue;
            $hit[] = [
                'number'   => $num,
                'season'   => (int)($ep['season_number'] ?? $season),
                'title'    => (string)($ep['name'] ?? ''),
                'image'    => !empty($ep['still_path']) ? TMDB_IMAGE_BASE . $ep['still_path'] : '',
                'aired'    => (string)($ep['air_date'] ?? ''),
                'runtime'  => isset($ep['runtime']) && $ep['runtime'] ? (int)$ep['runtime'] : 0,
                'overview' => (string)($ep['overview'] ?? ''),
            ];
        }

        api_cache_set($key, 'tmdb_tv', $hit, CACHE_TTL_TMDB_INFO);
    }

    // Lock state is applied AFTER the cache read, so an episode that aired while
    // the entry was cached still unlocks on its own date instead of waiting for
    // the TTL to expire.
    return tmdb_tv_lock_state($hit);
}

/**
 * Tag episodes that have not aired yet.
 *
 * TMDB only knows the broadcast DAY, so an episode counts as aired from local
 * midnight of that date — locking a same-day episode would hide something the
 * viewer can already watch. Locked rows get `lk`, `a` (unix ts) and `ad`
 * (date-level, no air time known), which is what the arrival text and the
 * `.kp-cd` countdown chip read. Mirrors the AniList lock fields so watch.php and
 * the client can treat both providers identically.
 */
function tmdb_tv_lock_state(array $rows) {
    $today = strtotime('today');
    if ($today === false) $today = time();

    foreach ($rows as &$row) {
        unset($row['lk'], $row['a'], $row['ax'], $row['ad']);

        $air = trim((string)($row['aired'] ?? ''));
        if ($air === '') continue;

        $ts = strtotime($air . ' 00:00:00');
        if ($ts === false || $ts <= $today) continue;

        $row['lk'] = 1;
        $row['a']  = $ts;
        $row['ad'] = 1;
    }
    unset($row);

    return $rows;
}
