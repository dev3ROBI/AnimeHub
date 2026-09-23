<?php
/**
 * AniList GraphQL client — the primary catalog provider.
 *
 * Free, no key, no Cloudflare, no self-hosted server. Gives catalog lists,
 * search, full anime metadata, episode counts, relations, recommendations
 * and the weekly airing schedule.
 *
 * Docs: https://docs.anilist.co/
 */

include_once __DIR__ . '/http.php';

const ANILIST_MEDIA_FIELDS = <<<'GQL'
    id
    idMal
    title { romaji english native userPreferred }
    description(asHtml: false)
    coverImage { extraLarge large medium color }
    bannerImage
    format
    status
    episodes
    duration
    genres
    averageScore
    meanScore
    popularity
    season
    seasonYear
    startDate { year month day }
    endDate { year month day }
    studios(isMain: true) { nodes { name } }
    nextAiringEpisode { airingAt episode timeUntilAiring }
    trailer { id site }
    isAdult
GQL;

// ─── Transport ─────────────────────────────────────────────────────────

/**
 * Run a GraphQL query against AniList. Cached by query+variables.
 *
 * @param string $query
 * @param array  $variables
 * @param int    $ttl
 * @return array|null  the `data` object, or null on failure
 */
function anilist_query($query, array $variables = [], $ttl = CACHE_TTL_LIST) {
    if (!ANILIST_ENABLED) return null;

    $key = api_cache_key('anilist', ['q' => $query, 'v' => $variables]);

    return api_cache_remember($key, 'anilist', $ttl, function () use ($query, $variables) {
        $res = api_http(ANILIST_BASE_URL, [
            'method'  => 'POST',
            'timeout' => 25,
            'label'   => 'AniList',
            'headers' => ['Content-Type: application/json'],
            'body'    => ['query' => $query, 'variables' => (object)$variables],
        ]);
        if (!is_array($res) || empty($res['data'])) {
            if (!empty($res['errors'][0]['message'])) {
                error_log('[AniList] GraphQL error: ' . $res['errors'][0]['message']);
            }
            return null;
        }
        return $res['data'];
    });
}

// ─── ID helpers (anilist:{id}) ─────────────────────────────────────────

function anilist_id_from_anilist($id) {
    return 'anilist:' . (int)$id;
}

function anilist_anilist_from_id($id) {
    if (strpos((string)$id, 'anilist:') !== 0) return null;
    $n = (int)substr((string)$id, 8);
    return $n > 0 ? $n : null;
}

// ─── Normalization ─────────────────────────────────────────────────────

/**
 * Flatten an AniList Media object into the catalogue's common shape.
 */
function anilist_normalize($media) {
    if (!is_array($media) || empty($media['id'])) return null;

    $id = (int)$media['id'];
    $titles = $media['title'] ?? [];
    $title = $titles['english'] ?? $titles['userPreferred'] ?? $titles['romaji'] ?? $titles['native'] ?? 'Unknown';

    $desc = $media['description'] ?? '';
    if (is_string($desc) && $desc !== '') {
        $desc = trim(html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $score = null;
    foreach (['averageScore', 'meanScore'] as $k) {
        if (!empty($media[$k]) && is_numeric($media[$k]) && (float)$media[$k] > 0) {
            $score = number_format(((float)$media[$k]) / 10, 1);
            break;
        }
    }

    $studios = [];
    foreach (($media['studios']['nodes'] ?? []) as $s) {
        if (!empty($s['name'])) $studios[] = $s['name'];
    }

    $totalEpisodes = !empty($media['episodes']) ? (int)$media['episodes'] : 0;
    $nextAiring = $media['nextAiringEpisode'] ?? null;
    // Episodes actually OUT. AniList knows the total from day one (12/12), so
    // the old `aired = total` marked every row playable — watch.php's lock
    // check (aired < total) could never fire. Correct semantics:
    //   nextAiring known → next-1 (next one hasn't aired), capped by total
    //   NOT_YET_RELEASED → 0
    //   otherwise        → total (finished / no schedule = all out)
    if ($nextAiring && !empty($nextAiring['episode'])) {
        $airedEpisodes = max(0, (int)$nextAiring['episode'] - 1);
        if ($totalEpisodes > 0) {
            $airedEpisodes = min($airedEpisodes, $totalEpisodes);
        }
    } elseif (($media['status'] ?? '') === 'NOT_YET_RELEASED') {
        $airedEpisodes = 0;
    } else {
        $airedEpisodes = $totalEpisodes;
    }

    $startDate = $media['startDate'] ?? [];
    $aired = null;
    if (!empty($startDate['year'])) {
        $aired = $startDate['year']
            . (!empty($startDate['month']) ? '-' . str_pad($startDate['month'], 2, '0', STR_PAD_LEFT) : '')
            . (!empty($startDate['day']) ? '-' . str_pad($startDate['day'], 2, '0', STR_PAD_LEFT) : '');
    }

    return [
        'id'              => anilist_id_from_anilist($id),
        'provider'        => 'anilist',
        'provider_id'     => (string)$id,
        'anilist_id'      => $id,
        'mal_id'          => !empty($media['idMal']) ? (int)$media['idMal'] : null,
        'title'           => $title,
        'title_romaji'    => $titles['romaji'] ?? null,
        'title_english'   => $titles['english'] ?? null,
        'title_native'    => $titles['native'] ?? null,
        'poster'          => $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? $media['coverImage']['medium'] ?? '',
        'banner'          => $media['bannerImage'] ?? '',
        'description'     => $desc,
        'type'            => $media['format'] ?? 'TV',
        'format'          => $media['format'] ?? null,
        'status'          => $media['status'] ?? '',
        'episodes'        => $totalEpisodes,
        'aired_episodes'  => $airedEpisodes,
        'episode'         => anilist_card_episode($media),
        'duration'        => !empty($media['duration']) ? ($media['duration'] . 'm') : null,
        'duration_min'    => !empty($media['duration']) ? (int)$media['duration'] : null,
        'genres'          => is_array($media['genres'] ?? null) ? $media['genres'] : [],
        'studios'         => $studios,
        'studio'          => $studios[0] ?? null,
        'score'           => $score,
        'rating'          => $score ?? 'N/A',
        'popularity'      => !empty($media['popularity']) ? (int)$media['popularity'] : 0,
        'season'          => $media['season'] ?? null,
        'year'            => !empty($media['seasonYear']) ? (int)$media['seasonYear'] : ($startDate['year'] ?? null),
        'aired'           => $aired,
        'next_airing'     => $nextAiring ? [
            'airingAt' => !empty($nextAiring['airingAt']) ? (int)$nextAiring['airingAt'] : null,
            'episode'  => !empty($nextAiring['episode']) ? (int)$nextAiring['episode'] : null,
        ] : null,
        'trailer'         => !empty($media['trailer']['site']) && !empty($media['trailer']['id'])
                                ? ['site' => $media['trailer']['site'], 'id' => $media['trailer']['id']]
                                : null,
        'is_adult'        => !empty($media['isAdult']),
        'has_dub'         => !empty($titles['english']) && (($media['popularity'] ?? 0) > 5000),
        'relations'       => [],
        'recommendations' => [],
        'episodes_list'   => [],
        'external_links'  => [],
    ];
}

/** Attach the current airing episode number for home/schedule cards. */
function anilist_card_episode($media) {
    if (!empty($media['nextAiringEpisode']['episode'])) {
        // next-1 = episodes out. null while waiting for the premiere (ep 1
        // still upcoming) so cards show "?" instead of a phantom current EP.
        $aired = (int)$media['nextAiringEpisode']['episode'] - 1;
        return $aired > 0 ? $aired : null;
    }
    if (($media['status'] ?? '') === 'NOT_YET_RELEASED') return null;
    if (!empty($media['episodes'])) return (int)$media['episodes'];
    return null;
}

/** Shared "page of media" request. */
function anilist_media_page($variables, $ttl = CACHE_TTL_LIST) {
    $fields = ANILIST_MEDIA_FIELDS;
    $data = anilist_query("
        query (\$page: Int, \$perPage: Int, \$search: String, \$sort: [MediaSort], \$status: MediaStatus, \$season: MediaSeason, \$seasonYear: Int) {
          Page(page: \$page, perPage: \$perPage) {
            pageInfo { currentPage lastPage hasNextPage }
            media(type: ANIME, isAdult: false, search: \$search, sort: \$sort, status: \$status, season: \$season, seasonYear: \$seasonYear) {
              $fields
            }
          }
        }
    ", $variables, $ttl);

    $list = $data['Page']['media'] ?? null;
    if (!is_array($list)) return [];

    $out = [];
    foreach ($list as $m) {
        $n = anilist_normalize($m);
        if (!$n) continue;
        $n['episode'] = anilist_card_episode($m);
        $n['episode_number'] = $n['episode'];
        $out[] = $n;
    }
    return $out;
}

// ─── Public catalogue calls ────────────────────────────────────────────

/** Currently airing, most popular first — the home page hero row. */
function anilist_airing($page = 1, $perPage = 24) {
    return anilist_media_page([
        'page'    => max(1, (int)$page),
        'perPage' => min(50, max(1, (int)$perPage)),
        'sort'    => ['POPULARITY_DESC'],
        'status'  => 'RELEASING',
    ]);
}

/** Trending across all anime (not just airing). */
function anilist_trending($page = 1, $perPage = 24) {
    return anilist_media_page([
        'page'    => max(1, (int)$page),
        'perPage' => min(50, max(1, (int)$perPage)),
        'sort'    => ['TRENDING_DESC'],
    ]);
}

/** All-time popular — used as the "more" row. */
function anilist_popular($page = 1, $perPage = 24) {
    return anilist_media_page([
        'page'    => max(1, (int)$page),
        'perPage' => min(50, max(1, (int)$perPage)),
        'sort'    => ['POPULARITY_DESC'],
    ]);
}

function anilist_search($term, $limit = 20) {
    $term = trim((string)$term);
    if ($term === '') return [];
    return anilist_media_page([
        'page'    => 1,
        'perPage' => min(50, max(1, (int)$limit)),
        'search'  => $term,
        'sort'    => ['SEARCH_MATCH'],
    ], CACHE_TTL_LIST);
}

/** Unreleased titles (next season / premiere pending) — homepage Upcoming sidebar. */
function anilist_upcoming_media($limit = 12) {
    return anilist_media_page([
        'page'    => 1,
        'perPage' => min(50, max(1, (int)$limit)),
        'sort'    => ['POPULARITY_DESC'],
        'status'  => 'NOT_YET_RELEASED',
    ], CACHE_TTL_LIST);
}

/** This season's top anime. */
function anilist_season($season = null, $year = null, $limit = 20) {
    $now = time();
    $season = $season ?: strtoupper(['WINTER','WINTER','SPRING','SPRING','SPRING','SUMMER','SUMMER','SUMMER','FALL','FALL','FALL','WINTER'][(int)date('n', $now) - 1]);
    return anilist_media_page([
        'page'       => 1,
        'perPage'    => min(50, max(1, (int)$limit)),
        'sort'       => ['POPULARITY_DESC'],
        'season'     => $season,
        'seasonYear' => (int)($year ?: date('Y', $now)),
    ]);
}

/**
 * Allowed AniList genre values, used by the genre browser.
 */
function anilist_genres() {
    return [
        'Action', 'Adventure', 'Comedy', 'Drama', 'Ecchi', 'Fantasy', 'Horror',
        'Mahou Shoujo', 'Mecha', 'Music', 'Mystery', 'Psychological', 'Romance', 'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural', 'Thriller',
        'Isekai', 'Harem', 'Josei', 'Seinen', 'Shoujo', 'Shounen',
        'Military', 'Vampire', 'Space', 'Demons', 'Award Winning', 'Suspense',
    ];
}

/** Allowed sort keys for genre browsing, mapped to AniList MediaSort values. */
function anilist_sort_options() {
    return [
        'popular' => 'POPULARITY_DESC',
        'score'   => 'SCORE_DESC',
        'trending'=> 'TRENDING_DESC',
        'newest'  => 'START_DATE_DESC',
    ];
}

/**
 * AniList genres (matched via the `genre` filter).
 */
const ANILIST_GENRES = [
    'Action', 'Adventure', 'Comedy', 'Drama', 'Ecchi', 'Fantasy', 'Horror',
    'Mahou Shoujo', 'Mecha', 'Music', 'Mystery', 'Psychological', 'Romance',
    'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural', 'Thriller',
];

/**
 * Browse anime by genre (or tag) with pagination.
 *
 * Items in ANILIST_GENRES use the GraphQL `genre` filter; everything else
 * (e.g. Isekai, Josei, Seinen …) is treated as an AniList `tag`.
 *
 * @return array ['items' => [...], 'page' => int, 'has_next' => bool]
 */
function anilist_by_genre($genre, $page = 1, $perPage = 24, $sort = 'popular') {
    $genre = trim((string)$genre);
    if ($genre === '') return ['items' => [], 'page' => 1, 'has_next' => false];

    $sorts = anilist_sort_options();
    $sortKey = array_key_exists($sort, $sorts) ? $sort : 'popular';

    $fields = ANILIST_MEDIA_FIELDS;
    $isTag = !in_array($genre, ANILIST_GENRES, true);

    if ($isTag) {
        $data = anilist_query("
            query (\$page: Int, \$perPage: Int, \$tag: String, \$sort: [MediaSort]) {
              Page(page: \$page, perPage: \$perPage) {
                pageInfo { currentPage hasNextPage }
                media(type: ANIME, isAdult: false, tag: \$tag, sort: \$sort) {
                  $fields
                }
              }
            }
        ", [
            'page'    => max(1, (int)$page),
            'perPage' => min(50, max(1, (int)$perPage)),
            'tag'     => $genre,
            'sort'    => [$sorts[$sortKey]],
        ]);
    } else {
        $data = anilist_query("
            query (\$page: Int, \$perPage: Int, \$genre: String, \$sort: [MediaSort]) {
              Page(page: \$page, perPage: \$perPage) {
                pageInfo { currentPage hasNextPage }
                media(type: ANIME, isAdult: false, genre: \$genre, sort: \$sort) {
                  $fields
                }
              }
            }
        ", [
            'page'    => max(1, (int)$page),
            'perPage' => min(50, max(1, (int)$perPage)),
            'genre'   => $genre,
            'sort'    => [$sorts[$sortKey]],
        ]);
    }

    $out = [];
    foreach (($data['Page']['media'] ?? []) as $m) {
        $n = anilist_normalize($m);
        if ($n) $out[] = $n;
    }

    return [
        'items'    => $out,
        'page'     => max(1, (int)$page),
        'has_next' => !empty($data['Page']['pageInfo']['hasNextPage']),
    ];
}

/**
 * Full detail for one anime, including relations and recommendations.
 */
function anilist_info($anilist_id) {
    $anilist_id = (int)$anilist_id;
    if ($anilist_id <= 0) return null;
    return anilist_media_detail(['id' => $anilist_id], CACHE_TTL_INFO);
}

/**
 * Same detail lookup keyed by MyAnimeList id. Lets a MAL/Jikan id resolve
 * through AniList when Jikan is down.
 */
function anilist_info_by_mal($mal_id) {
    $mal_id = (int)$mal_id;
    if ($mal_id <= 0) return null;
    return anilist_media_detail(['idMal' => $mal_id], CACHE_TTL_INFO);
}

function anilist_media_detail(array $selector, $ttl = CACHE_TTL_INFO) {
    // AniList rejects a request supplying both `id` and `idMal`, so only the
    // relevant argument is declared in the query.
    $idParam = array_key_exists('id', $selector) ? 'id' : 'idMal';
    $idValue = (int)reset($selector);
    if ($idValue <= 0) return null;

    $fields = ANILIST_MEDIA_FIELDS;
    $data = anilist_query("
        query (\$$idParam: Int) {
          Media(type: ANIME, $idParam: \$$idParam) {
            $fields
            streamingEpisodes { title thumbnail url site }
            externalLinks { site url type }
            characters(sort: [ROLE, RELEVANCE, FAVOURITES_DESC], perPage: 15) {
              edges { role node { name { full } image { large medium } } }
            }
            staff(sort: [RELEVANCE, FAVOURITES_DESC], perPage: 12) {
              edges { role node { name { full } image { large medium } } }
            }
            relations {
              edges {
                relationType
                node {
                  id
                  isAdult
                  title { romaji english userPreferred }
                  coverImage { large }
                  format
                  status
                  episodes
                  season
                  seasonYear
                  startDate { year month day }
                }
              }
            }
            recommendations(sort: RATING_DESC, perPage: 12) {
              nodes { mediaRecommendation { id title { romaji english userPreferred } coverImage { large } averageScore format } }
            }
          }
        }
    ", [$idParam => $idValue], $ttl);

    $media = $data['Media'] ?? null;
    if (!is_array($media)) return null;

    $item = anilist_normalize($media);
    if (!$item) return null;

    // Streaming episodes give us real titles we can map onto episode numbers.
    $titles = [];
    foreach (($media['streamingEpisodes'] ?? []) as $se) {
        if (empty($se['title'])) continue;
        $seTitle = trim((string)$se['title']);
        // Titles look like "Episode 12 - Name" or "12 - Name".
        if (preg_match('/^(?:episode\s*)?(\d+)\s*[-–:]\s*(.+)$/i', $seTitle, $m)) {
            $titles[(int)$m[1]] = trim($m[2]);
        }
    }

    $total = max((int)($item['episodes'] ?: 0), (int)($item['aired_episodes'] ?: 0));
    if ($total > 2000) $total = 2000; // safety net for long runners
    $list = [];
    for ($i = 1; $i <= $total; $i++) {
        $list[] = [
            'number' => $i,
            'title'  => $titles[$i] ?? '',
            'image'  => '',
            'aired'  => '',
        ];
    }
    // If AniList has no count but streaming episodes exist, fall back to those.
    if (!$list && $titles) {
        foreach (array_keys($titles) as $n) {
            $list[] = ['number' => $n, 'title' => $titles[$n], 'image' => '', 'aired' => ''];
        }
        sort($list);
    }
    $item['episodes_list'] = $list;

    $item['cast_list'] = [];
    foreach (($media['characters']['edges'] ?? []) as $ce) {
        $node = $ce['node'] ?? null;
        if (empty($node['name']['full'])) continue;
        if (count($item['cast_list']) >= 12) break;
        $item['cast_list'][] = [
            'name'      => (string)$node['name']['full'],
            'character' => (string)($ce['role'] ?? ''),
            'photo'     => !empty($node['image']['large'])
                ? (string)$node['image']['large']
                : (!empty($node['image']['medium']) ? (string)$node['image']['medium'] : ''),
        ];
    }

    $item['external_links'] = [];
    foreach (($media['externalLinks'] ?? []) as $l) {
        if (!empty($l['url'])) {
            $item['external_links'][] = ['site' => $l['site'] ?? '', 'url' => $l['url'], 'type' => $l['type'] ?? ''];
        }
    }

    $item['relations'] = [];
    foreach (($media['relations']['edges'] ?? []) as $edge) {
        $node = $edge['node'] ?? null;
        if (!is_array($node) || empty($node['id'])) continue;
        $n = anilist_normalize($node);
        if (!$n) continue;
        $n['relation_type'] = $edge['relationType'] ?? '';
        $item['relations'][] = $n;
    }

    $item['recommendations'] = [];
    foreach (($media['recommendations']['nodes'] ?? []) as $rec) {
        $node = $rec['mediaRecommendation'] ?? null;
        if (!is_array($node) || empty($node['id'])) continue;
        $n = anilist_normalize($node);
        if ($n) $item['recommendations'][] = $n;
    }

    return $item;
}

/**
 * Weekly airing schedule between two unix timestamps, grouped by day.
 */
function anilist_schedule($fromTs, $toTs, $tz = 'Asia/Dhaka') {
    $fields = ANILIST_MEDIA_FIELDS;
    $entries = [];
    $page = 1;

    while ($page <= 8) {
        $data = anilist_query("
            query (\$page: Int, \$from: Int, \$to: Int) {
              Page(page: \$page, perPage: 50) {
                pageInfo { hasNextPage }
                airingSchedules(airingAt_greater: \$from, airingAt_lesser: \$to, sort: TIME) {
                  airingAt episode
                  media { $fields }
                }
              }
            }
        ", ['page' => $page, 'from' => (int)$fromTs, 'to' => (int)$toTs], CACHE_TTL_SCHEDULE);

        $schedules = $data['Page']['airingSchedules'] ?? null;
        if (!is_array($schedules) || !$schedules) break;
        foreach ($schedules as $s) {
            $entries[] = $s;
        }
        if (empty($data['Page']['pageInfo']['hasNextPage'])) break;
        $page++;
    }

    if (!$entries) return [];

    $byTz = new DateTimeZone($tz);
    $buckets = [];
    foreach ($entries as $s) {
        if (empty($s['airingAt'])) continue;
        $n = anilist_normalize($s['media'] ?? []);
        if (!$n) continue;
        $dt = new DateTime('@' . (int)$s['airingAt']);
        $dt->setTimezone($byTz);
        $dayKey = $dt->format('Y-m-d');

        $n['episode'] = !empty($s['episode']) ? (int)$s['episode'] : null;
        $n['episode_number'] = $n['episode'];
        $n['airingAt'] = (int)$s['airingAt'];
        $n['airing_time'] = $dt->format('g:i A');
        $n['air_type'] = 'sub';
        $n['airing_status'] = ((int)$s['airingAt'] <= time()) ? 'aired' : 'upcoming';

        if (!isset($buckets[$dayKey])) {
            $buckets[$dayKey] = [
                'date'     => $dayKey,
                'day'      => $dt->format('l'),
                'sort_key' => (int)$dt->format('N'),
                'episodes' => [],
            ];
        }
        $buckets[$dayKey]['episodes'][] = $n;
    }

    usort($buckets, fn($a, $b) => strcmp($a['date'], $b['date']));
    foreach ($buckets as &$b) {
        usort($b['episodes'], fn($a, $c) => ($a['airingAt'] ?? 0) <=> ($c['airingAt'] ?? 0));
    }
    unset($b);

    return array_values($buckets);
}

/**
 * Exact future airing timestamps for a title, keyed by episode number.
 *
 * Uses Media.airingSchedule(notYetAired: true) — verified live against the
 * AniList API: returns future nodes with airingAt (unix) + episode. AniList
 * only knows a limited window ahead, so callers must fall back to a weekly
 * estimate for episodes beyond the last returned node.
 *
 * @param int $anilist_id
 * @return array<int,int> episode => unix ts ([] on failure)
 */
function anilist_upcoming_airing($anilist_id) {
    $anilist_id = (int)$anilist_id;
    if ($anilist_id <= 0) return [];

    $data = anilist_query("
        query (\$id: Int) {
            Media(id: \$id, type: ANIME) {
                airingSchedule(notYetAired: true) {
                    nodes { airingAt episode }
                }
            }
        }
    ", ['id' => $anilist_id], CACHE_TTL_SCHEDULE);

    $nodes = $data['Media']['airingSchedule']['nodes'] ?? [];
    $map = [];
    foreach ($nodes as $node) {
        if (empty($node['airingAt']) || empty($node['episode'])) continue;
        $map[(int)$node['episode']] = (int)$node['airingAt'];
    }
    ksort($map);
    return $map;
}

// ─── Franchise season chain (SEQUEL / PREQUEL) ────────────────────────

/**
 * Lightweight Media payload used only to walk SEQUEL/PREQUEL edges.
 * Cached by anilist_query (CACHE_TTL_INFO).
 */
function anilist_sequel_prequel_media($anilistId) {
    $id = (int)$anilistId;
    if ($id <= 0) return null;

    $data = anilist_query("
        query (\$id: Int) {
          Media(type: ANIME, id: \$id) {
            id
            title { romaji english userPreferred }
            coverImage { large }
            format
            episodes
            season
            seasonYear
            startDate { year month day }
            isAdult
            relations {
              edges {
                relationType
                node {
                  id
                  title { romaji english userPreferred }
                  coverImage { large }
                  format
                  episodes
                  season
                  seasonYear
                  startDate { year month day }
                  isAdult
                }
              }
            }
          }
        }
    ", ['id' => $id], CACHE_TTL_INFO);

    $media = $data['Media'] ?? null;
    return is_array($media) ? $media : null;
}

/**
 * Ordered Season 1..N tabs for an AniList franchise.
 *
 * Walks SEQUEL/PREQUEL from the current title (page relations first, then a
 * small BFS with cached fetches). Only TV-like nodes are kept so OVAs/movies
 * do not steal season numbers. Returns [] when fewer than 2 seasons.
 *
 * @return array<int, array{id:string,anilist_id:int,title:string,label:string,
 *                          episodes:int,year:int,active:bool,url:string}>
 */
function anilist_season_chain(array $current, array $relations = []): array {
    $curId = (int)($current['anilist_id'] ?? 0);
    if ($curId <= 0) {
        $curId = (int)(anilist_anilist_from_id($current['id'] ?? '') ?: 0);
    }
    if ($curId <= 0) return [];

    $meta = [];
    $seq  = [];
    $pre  = [];
    $expanded = [];

    $put = function (array $item) use (&$meta) {
        $id = (int)($item['anilist_id'] ?? 0);
        if ($id <= 0 || isset($meta[$id])) return;
        $meta[$id] = [
            'id'          => $item['id'] ?? ('anilist:' . $id),
            'anilist_id'  => $id,
            'title'       => (string)($item['title'] ?? ''),
            'poster'      => (string)($item['poster'] ?? ''),
            'format'      => strtoupper((string)($item['format'] ?? ($item['type'] ?? ''))),
            'episodes'    => max((int)($item['episodes'] ?? 0), (int)($item['aired_episodes'] ?? 0)),
            'year'        => (int)($item['year'] ?? 0),
            'aired'       => (string)($item['aired'] ?? ''),
            'is_adult'    => !empty($item['is_adult']),
        ];
    };

    $link = function ($fromId, $toId, $type) use (&$seq, &$pre) {
        $fromId = (int)$fromId;
        $toId   = (int)$toId;
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) return;
        if ($type === 'SEQUEL') {
            if (!in_array($toId, $seq[$fromId] ?? [], true)) $seq[$fromId][] = $toId;
        } else {
            if (!in_array($toId, $pre[$fromId] ?? [], true)) $pre[$fromId][] = $toId;
        }
    };

    $put($current);

    $hasChainRel = false;
    foreach ($relations as $rel) {
        $type = strtoupper((string)($rel['relation_type'] ?? ''));
        if ($type !== 'SEQUEL' && $type !== 'PREQUEL') continue;
        $hasChainRel = true;
        $rid = (int)($rel['anilist_id'] ?? 0);
        if ($rid <= 0 || $rid === $curId) continue;
        if (!empty($rel['is_adult'])) continue;
        $put($rel);
        $link($curId, $rid, $type);
    }
    // No sequel/prequel edges on this title → single-season, skip network walk.
    if (!$hasChainRel) return [];

    // Current title's relations already came from the page detail query.
    $expanded[$curId] = true;

    // Expand neighbors until the chain is closed (bounded — each hit is cached).
    $queue   = array_keys($meta);
    $budget  = 10;
    $maxSeen = 32;
    while ($queue && $budget > 0 && count($meta) < $maxSeen) {
        $id = (int)array_shift($queue);
        if ($id <= 0 || isset($expanded[$id])) continue;
        $expanded[$id] = true;
        $budget--;

        $media = anilist_sequel_prequel_media($id);
        if (!$media) continue;

        // Re-seed the hub node itself (title/format may only exist here).
        $hub = anilist_normalize($media);
        if ($hub && empty($media['isAdult'])) $put($hub);

        foreach (($media['relations']['edges'] ?? []) as $edge) {
            $type = strtoupper((string)($edge['relationType'] ?? ''));
            if ($type !== 'SEQUEL' && $type !== 'PREQUEL') continue;
            $node = $edge['node'] ?? null;
            if (!is_array($node) || empty($node['id'])) continue;
            if (!empty($node['isAdult'])) continue;
            $n = anilist_normalize($node);
            if (!$n) continue;
            $nid = (int)$n['anilist_id'];
            $put($n);
            $link($id, $nid, $type);
            if (!isset($expanded[$nid])) $queue[] = $nid;
        }
    }

    // SEQUEL/PREQUEL component containing the current title.
    $component = [];
    $visited   = [];
    $bfs       = [$curId];
    while ($bfs) {
        $id = (int)array_shift($bfs);
        if ($id <= 0 || isset($visited[$id]) || !isset($meta[$id])) continue;
        $visited[$id] = true;
        $component[]  = $id;
        foreach (array_merge($seq[$id] ?? [], $pre[$id] ?? []) as $nid) {
            if (!isset($visited[$nid])) $bfs[] = (int)$nid;
        }
    }

    // Keep mainline seasons (+ always the current title). Drop adult extras.
    $keep = [];
    foreach ($component as $id) {
        if (!isset($meta[$id])) continue;
        if (!empty($meta[$id]['is_adult']) && $id !== $curId) continue;
        $fmt = $meta[$id]['format'];
        if ($id === $curId || in_array($fmt, ['TV', 'TV_SHORT', 'ONA'], true)) {
            $keep[] = $id;
        }
    }
    if (count($keep) < 2) return [];

    $keepSet = array_flip($keep);
    $subSeq  = [];
    $subPre  = [];
    foreach ($keep as $id) {
        foreach ($seq[$id] ?? [] as $n) {
            if (isset($keepSet[$n])) $subSeq[$id][] = (int)$n;
        }
        foreach ($pre[$id] ?? [] as $n) {
            if (isset($keepSet[$n])) $subPre[$id][] = (int)$n;
        }
    }

    $byDate = function ($a, $b) use ($meta) {
        return [$meta[$a]['year'] ?: 0, $meta[$a]['aired'] ?: '', $a]
            <=> [$meta[$b]['year'] ?: 0, $meta[$b]['aired'] ?: '', $b];
    };

    // Root = node with no prequel inside the kept set.
    $roots = array_values(array_filter($keep, fn($id) => empty($subPre[$id])));
    $ordered = [];
    if ($roots) {
        // Prefer the root that can reach the current title.
        $start = $roots[0];
        foreach ($roots as $r) {
            $seenR = [];
            $qR    = [$r];
            while ($qR) {
                $x = (int)array_shift($qR);
                if ($x <= 0 || isset($seenR[$x])) continue;
                $seenR[$x] = true;
                if ($x === $curId) { $start = $r; break; }
                foreach ($subSeq[$x] ?? [] as $n) $qR[] = $n;
            }
        }

        $seen = [];
        $id   = $start;
        while ($id && isset($meta[$id]) && !isset($seen[$id])) {
            $seen[$id]  = true;
            $ordered[]  = $id;
            $nexts      = $subSeq[$id] ?? [];
            // Branching sequels: take the first, rest are appended by date below.
            $id = $nexts[0] ?? null;
        }
        // Interleave orphans by air date so season numbers stay stable after reload.
        $orphans = array_values(array_filter($keep, fn($x) => !isset($seen[$x])));
        usort($orphans, $byDate);
        foreach ($orphans as $x) {
            $at = count($ordered);
            for ($i = 0, $c = count($ordered); $i < $c; $i++) {
                if ($byDate($x, $ordered[$i]) < 0) { $at = $i; break; }
            }
            array_splice($ordered, $at, 0, [$x]);
        }
    } else {
        $ordered = $keep;
        usort($ordered, $byDate);
    }

    // Guarantee current is present (broken edge cases).
    if (!in_array($curId, $ordered, true)) {
        $ordered[] = $curId;
        usort($ordered, $byDate);
    }

    // Group split-cour parts ("Part 2", "Cour 2", …) into the previous season.
    $out        = [];
    $seasonNum  = 0;
    foreach ($ordered as $id) {
        if (!isset($meta[$id])) continue;
        $m     = $meta[$id];
        $title = $m['title'];
        $isContinuation = $seasonNum > 0
            && (bool)preg_match('/\b(?:part|cour)\s*[2-9]\b/i', $title);
        if (!$isContinuation) {
            $seasonNum++;
            $out[] = [
                'id'         => $m['id'],
                'anilist_id' => $id,
                'title'      => $m['title'],
                'label'      => 'Season ' . $seasonNum,
                'season_num' => $seasonNum,
                'episodes'   => $m['episodes'],
                'year'       => $m['year'],
                'active'     => ($id === $curId),
                'url'        => './watch.php?id=' . rawurlencode($m['id']),
                'members'    => [[
                    'id'         => $m['id'],
                    'anilist_id' => $id,
                    'title'      => $m['title'],
                    'episodes'   => $m['episodes'],
                    'current'    => ($id === $curId),
                ]],
            ];
        } else {
            $idx = count($out) - 1;
            $out[$idx]['episodes'] = (int)$out[$idx]['episodes'] + (int)$m['episodes'];
            if ($id === $curId) $out[$idx]['active'] = true;
            $out[$idx]['members'][] = [
                'id'         => $m['id'],
                'anilist_id' => $id,
                'title'      => $m['title'],
                'episodes'   => $m['episodes'],
                'current'    => ($id === $curId),
            ];
        }
    }

    return count($out) >= 2 ? $out : [];
}
