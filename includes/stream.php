<?php
/**
 * Hybrid stream resolver.
 *
 * Order of attempts for a given anime + episode:
 *
 *   1. Self-hosted ReAnime scraper (ReAnime.to-API) — real HLS m3u8 with
 *      subtitles, intro/outro chapters and seek thumbnails.
 *   2. Embed players configured in config/config.php — plain iframes, no
 *      backend needed and independent of the scraper.
 *
 * Every source returns the same payload shape so the player only has one
 * code path per `mode`.
 */

include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/reanime_api.php';

function stream_normalize_lang($lang) {
    $lang = strtolower(trim((string)$lang));
    return ($lang === 'dub') ? 'dub' : 'sub';
}

/** Episode number, clamped to a sane range. */
function stream_normalize_episode($episode) {
    $n = (int)$episode;
    return $n > 0 ? $n : 1;
}

/**
 * Resolve a playable source.
 *
 * @param array  $info    catalogue item (from catalog_info)
 * @param int    $episode
 * @param string $lang    'sub' | 'dub'
 * @return array
 */
function stream_resolve($info, $episode = 1, $lang = 'sub') {
    $episode = stream_normalize_episode($episode);
    $lang = stream_normalize_lang($lang);

    if (!is_array($info)) {
        return stream_failure('Anime not found', $episode, $lang);
    }

    // 0 ── Anikuro (its own self-hosted server, when enabled)
    if (($info['provider'] ?? '') === 'anikuro' && ANIKURO_ENABLED) {
        $result = stream_try_anikuro($info, $episode, $lang);
        if (!empty($result['ok'])) return $result;
        $anikuroError = $result['message'] ?? null;
    } else {
        $anikuroError = null;
    }

    // 1 ── self-hosted scraper
    if (STREAM_TRY_SCRAPER && REANIME_ENABLED) {
        $result = stream_try_scraper($info, $episode, $lang);
        if (!empty($result['ok'])) return $result;
        $scraperError = $result['message'] ?? null;
    } else {
        $scraperError = null;
    }

    // 2 ── embed players
    if (STREAM_TRY_EMBEDS) {
        $result = stream_embed_payload($info, $episode, $lang);
        if (!empty($result['ok'])) {
            if ($scraperError) $result['scraper_error'] = $scraperError;
            return $result;
        }
    }

    if (!empty($anikuroError)) $scraperError = $anikuroError;

    return stream_failure(
        'No playable source found for this episode. Add or reorder embed providers in config/config.php.',
        $episode,
        $lang
    );
}

function stream_failure($message, $episode, $lang) {
    return [
        'ok'        => false,
        'mode'      => null,
        'url'       => null,
        'lang'      => $lang,
        'episode'   => $episode,
        'servers'   => [],
        'subtitles' => [],
        'intro'     => null,
        'outro'     => null,
        'thumbnails'=> null,
        'source'    => null,
        'message'   => $message,
    ];
}

// ─── Attempt 0: Anikuro ────────────────────────────────────────────────

/**
 * Anikuro keeps its own episode sessions, so the episode session has to be
 * looked up before the stream can be requested.
 */
function stream_try_anikuro($info, $episode, $lang) {
    $remote = $info['provider_id'] ?? null;
    if (empty($remote)) return stream_failure('Missing Anikuro session.', $episode, $lang);

    $episodes = anikuro_episodes($remote);
    if (!is_array($episodes) || !$episodes) {
        return stream_failure('Anikuro episode list unavailable (is the Anikuro server running?).', $episode, $lang);
    }

    $epSession = null;
    foreach ($episodes as $ep) {
        if ((int)($ep['number'] ?? 0) === $episode) {
            $epSession = $ep['session'] ?? null;
            break;
        }
    }
    if (empty($epSession)) {
        return stream_failure('Episode ' . $episode . ' is not available on Anikuro.', $episode, $lang);
    }

    $data = anikuro_stream($remote, $epSession, false);
    $sources = $data['sources'] ?? [];
    if (!is_array($sources) || !$sources) {
        return stream_failure('Anikuro returned no sources for this episode.', $episode, $lang);
    }

    $servers = [];
    foreach ($sources as $i => $src) {
        if (!is_array($src) || empty($src['url'])) continue;
        $srcLang = !empty($src['isDub']) ? 'dub' : 'sub';
        $servers[] = [
            'key'      => 'anikuro-' . $i,
            'label'    => ($src['resolution'] ?? 'HD') . 'p · ' . strtoupper($srcLang),
            'lang'     => $srcLang,
            'mode'     => 'hls',
            'url'      => $src['url'],
            'dataLink' => null,
        ];
    }
    if (!$servers) return stream_failure('Anikuro returned no playable sources.', $episode, $lang);

    usort($servers, function ($a, $b) use ($lang) {
        return (($a['lang'] === $lang) ? 0 : 1) <=> (($b['lang'] === $lang) ? 0 : 1);
    });

    return [
        'ok'         => true,
        'mode'       => 'hls',
        'url'        => $servers[0]['url'],
        'lang'       => $servers[0]['lang'],
        'episode'    => $episode,
        'servers'    => $servers,
        'subtitles'  => stream_normalize_subtitles($data['subtitles'] ?? []),
        'intro'      => stream_chapter($data['intro_chapter'] ?? null),
        'outro'      => stream_chapter($data['outro_chapter'] ?? null),
        'thumbnails' => $data['thumbnails_vtt'] ?? null,
        'source'     => 'anikuro',
        'message'    => null,
    ];
}

// ─── Attempt 1: self-hosted scraper ────────────────────────────────────

function stream_try_scraper($info, $episode, $lang) {
    if (!reanime_scraper_health()) {
        return stream_failure(
            'Streaming server offline. Start it with: cd ReAnime.to-API && uvicorn reanime:app --port 8000',
            $episode,
            $lang
        );
    }

    $slug = $info['slug'] ?? null;
    if (empty($slug) && !empty($info['title'])) {
        $match = reanime_find_slug($info['title'], $info['year'] ?? null);
        if ($match) $slug = $match['slug'];
    }
    if (empty($slug)) {
        return stream_failure('No matching source slug for this anime.', $episode, $lang);
    }

    $anilistId = !empty($info['anilist_id']) ? (int)$info['anilist_id'] : null;
    $data = reanime_servers($slug, $episode, $anilistId);
    if (!$data) {
        return stream_failure('Streaming server returned no sources.', $episode, $lang);
    }

    $servers = stream_collect_scraper_servers($data, $lang);
    if (!$servers) {
        return stream_failure('This episode has no servers available yet.', $episode, $lang);
    }

    // Play the first server of the requested language (sub first by default).
    $first = $servers[0];
    $stream = !empty($first['dataLink']) ? reanime_stream_from_link($first['dataLink']) : null;

    if (!$stream || empty($stream['url'])) {
        // Could not decrypt the first pick — try the rest before giving up.
        foreach (array_slice($servers, 1) as $candidate) {
            if (empty($candidate['dataLink'])) continue;
            $stream = reanime_stream_from_link($candidate['dataLink']);
            if (!empty($stream['url'])) {
                $first = $candidate;
                break;
            }
        }
    }

    if (!$stream || empty($stream['url'])) {
        return stream_failure('Could not decrypt the stream. The source may have rotated its keys.', $episode, $lang);
    }

    return [
        'ok'         => true,
        'mode'       => 'hls',
        'url'        => $stream['url'],
        'lang'       => $first['lang'] ?? $lang,
        'episode'    => $episode,
        'servers'    => $servers,
        'subtitles'  => stream_normalize_subtitles($stream['subtitles'] ?? []),
        'intro'      => stream_chapter($stream['intro_chapter'] ?? null),
        'outro'      => stream_chapter($stream['outro_chapter'] ?? null),
        'thumbnails' => $stream['thumbnails_vtt'] ?? null,
        'title'      => $stream['video_title'] ?? null,
        'source'     => 'reanime-scraper',
        'message'    => null,
    ];
}

/**
 * Does this scraper link point at Flixcloud?
 *
 * Flixcloud is the CDN behind most scraper sources, but it is also its own
 * switchable server, so its links get named instead of showing a generic
 * "HD-1" label the user cannot tell apart from the next one.
 */
function stream_is_flixcloud_link($link) {
    $link = strtolower((string)$link);
    if ($link === '') return false;

    if (strpos($link, 'flixcloud') !== false) return true;

    $host = parse_url($link, PHP_URL_HOST);
    return is_string($host) && strpos($host, 'flixcloud') !== false;
}

/**
 * Flatten the scraper's sub/dub arrays into one ordered server list.
 * Servers of the requested language come first.
 */
function stream_collect_scraper_servers($data, $lang) {
    $order = ['HD-2' => 0, 'HD-1' => 1];
    $out = [];

    foreach (['sub', 'dub'] as $type) {
        $list = $data[$type] ?? [];
        if (!is_array($list)) continue;
        usort($list, function ($a, $b) use ($order) {
            $an = is_array($a) ? ($a['serverName'] ?? '') : '';
            $bn = is_array($b) ? ($b['serverName'] ?? '') : '';
            return ($order[$an] ?? 9) <=> ($order[$bn] ?? 9);
        });
        foreach ($list as $s) {
            if (!is_array($s) || empty($s['dataLink'])) continue;

            $isFlixcloud = stream_is_flixcloud_link($s['dataLink']);
            $name = trim((string)($s['serverName'] ?? ''));
            if ($isFlixcloud) {
                $name = 'Flixcloud';
            } elseif ($name === '') {
                $name = 'HD';
            }

            $out[] = [
                'key'      => strtolower(($isFlixcloud ? 'flixcloud' : $type . '-' . $name) . '-' . count($out)),
                'label'    => $name . ' · ' . strtoupper($type),
                'lang'     => $type,
                'mode'     => 'hls',
                'provider' => $isFlixcloud ? 'flixcloud' : null,
                'dataLink' => $s['dataLink'],
                'url'      => null,
            ];
        }
    }

    usort($out, function ($a, $b) use ($lang) {
        $aw = ($a['lang'] === $lang) ? 0 : 1;
        $bw = ($b['lang'] === $lang) ? 0 : 1;
        return $aw <=> $bw;
    });

    return $out;
}

function stream_normalize_subtitles($subs) {
    $out = [];
    if (!is_array($subs)) return $out;
    foreach ($subs as $s) {
        if (!is_array($s) || empty($s['url'])) continue;
        $out[] = [
            'url'      => $s['url'],
            'language' => $s['language'] ?? ($s['lang'] ?? 'Subtitle'),
            'format'   => strtolower($s['format'] ?? 'vtt'),
            'default'  => !empty($s['default']),
        ];
    }
    return $out;
}

function stream_chapter($chapter) {
    if (!is_array($chapter)) return null;
    if (!isset($chapter['start'])) return null;
    return [
        'start' => (float)$chapter['start'],
        'end'   => isset($chapter['end']) ? (float)$chapter['end'] : null,
        'title' => $chapter['title'] ?? null,
    ];
}

// ─── Attempt 2: embed players ──────────────────────────────────────────

/**
 * Build the embed payload. Providers are keyed by AniList id, so an item
 * that only has a MAL id is resolved through AniList first.
 */
function stream_embed_payload($info, $episode, $lang) {
    $servers = stream_embed_servers($info, $episode, $lang);

    if (!$servers) {
        return stream_failure(
            'All embed servers are currently offline. Please try again later.',
            $episode,
            $lang
        );
    }

    // Play the provider flagged as primary, else the first configured one.
    $pick = $servers[0];
    foreach ($servers as $server) {
        if (!empty($server['primary'])) {
            $pick = $server;
            break;
        }
    }

    return [
        'ok'         => true,
        'mode'       => 'embed',
        'url'        => $pick['url'],
        'lang'       => $lang,
        'episode'    => $episode,
        'servers'    => $servers,
        'subtitles'  => [],
        'intro'      => null,
        'outro'      => null,
        'thumbnails' => null,
        'source'     => 'embed:' . $pick['key'],
        'message'    => null,
    ];
}

function stream_embed_servers($info, $episode, $lang) {
    $anilistId = !empty($info['anilist_id']) ? (int)$info['anilist_id'] : 0;
    $malId = !empty($info['mal_id']) ? (int)$info['mal_id'] : 0;

    // Embeds need an AniList id. If we only have a MAL id, look it up.
    if ($anilistId <= 0 && $malId > 0 && REANIME_ENABLED) {
        $match = reanime_find_slug($info['title'] ?? '', $info['year'] ?? null);
        if (!empty($match['anilist_id'])) $anilistId = (int)$match['anilist_id'];
    }
    if ($anilistId <= 0) return [];

    $out = [];
    foreach (embed_providers() as $key => $provider) {
        $url = (string)($provider['url'] ?? '');
        if ($url === '') continue;

        $supportsLang = !empty($provider['lang']);
        $dubValue = ($lang === 'dub') ? 'true' : 'false';
        $resolved = str_replace(
            ['{anilist}', '{mal}', '{ep}', '{lang}', '{dub}'],
            [$anilistId, $malId ?: $anilistId, $episode, $lang, $dubValue],
            $url
        );

        $out[] = [
            'key'      => $key,
            'label'    => $provider['label'] ?? ucfirst($key),
            'lang'     => $supportsLang ? $lang : 'any',
            'mode'     => 'embed',
            'url'      => $resolved,
            'dataLink' => null,
            'primary'  => !empty($provider['primary']),
        ];
    }

    $out = stream_filter_active_embeds($out);

    usort($out, fn($a, $b) => (int)!empty($b['primary']) <=> (int)!empty($a['primary']));

    return $out;
}

// ─── Embed health-check ──────────────────────────────────────────────

/**
 * In-memory cache for embed health results.
 * Keyed by provider key, value = ['ok' => bool, 'ts' => timestamp].
 */
$_STREAM_EMBED_HEALTH_CACHE = [];

/**
 * Check if an embed provider endpoint is reachable via a quick HEAD request.
 * Returns true if the server responds with 2xx within the timeout.
 */
function stream_check_embed_health($url, $timeout = 3) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return false;
    return $code >= 200 && $code < 400;
}

/**
 * Filter embed server list to only include providers that respond to a HEAD
 * request. Results are cached for CACHE_TTL_HEALTH seconds to avoid hammering
 * every provider on every resolve call.
 *
 * If ALL providers fail the health check, return them all as a fallback so
 * the user still has something to try.
 */
function stream_filter_active_embeds($servers) {
    $ttl = defined('CACHE_TTL_HEALTH') ? CACHE_TTL_HEALTH : 60;
    $now = time();
    $active = [];
    $failed = [];

    foreach ($servers as $server) {
        // Never block the primary provider — let the browser decide.
        if (!empty($server['primary'])) {
            $active[] = $server;
            continue;
        }

        $key = $server['key'] . ':' . ($server['lang'] ?? 'any');
        $cached = $_STREAM_EMBED_HEALTH_CACHE[$key] ?? null;

        if ($cached && ($now - $cached['ts']) < $ttl) {
            if ($cached['ok']) {
                $active[] = $server;
            } else {
                $failed[] = $server;
            }
            continue;
        }

        $ok = stream_check_embed_health($server['url']);
        $_STREAM_EMBED_HEALTH_CACHE[$key] = ['ok' => $ok, 'ts' => $now];

        if ($ok) {
            $active[] = $server;
        } else {
            $failed[] = $server;
        }
    }

    // If every non-primary provider failed, include them all as last resort.
    if (empty($active) && count($failed) > 0) {
        return $servers;
    }

    return $active;
}
