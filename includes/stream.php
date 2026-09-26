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
 * code path per `mode`. On top of that every successful payload carries a
 * normalized `sources` queue (see stream_attach_sources) — the full ordered
 * list of fallback candidates the client walks through when playback fails:
 * primary direct source → other direct servers → embed iframes (last resort).
 */

include_once __DIR__ . '/catalog.php';
include_once __DIR__ . '/reanime_api.php';
include_once __DIR__ . '/eightstream_api.php';
include_once __DIR__ . '/cn_extract_api.php';
include_once __DIR__ . '/subtitles_api.php';

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
        if (!empty($result['ok'])) return stream_attach_sources($result, $info, $episode, $lang);
        $anikuroError = $result['message'] ?? null;
    } else {
        $anikuroError = null;
    }

    // 1 ── self-hosted scraper
    if (STREAM_TRY_SCRAPER && REANIME_ENABLED) {
        $result = stream_try_scraper($info, $episode, $lang);
        if (!empty($result['ok'])) return stream_attach_sources($result, $info, $episode, $lang);
        $scraperError = $result['message'] ?? null;
    } else {
        $scraperError = null;
    }

    // 1.5 ── 8Stream (IMDb-keyed, plays in our own player through the relay).
    // The scraper above is the purpose-built anime source (sub/dub + captions)
    // and stays first when it is running; on a host without the self-hosted
    // scraper its health check fails fast and 8Stream wins by default.
    // (Move this block above the scraper to make 8Stream the first pick.)
    if (EIGHTSTREAM_ENABLED) {
        $result = stream_try_eightstream($info, $episode, $lang);
        if (!empty($result['ok'])) return stream_attach_sources($result, $info, $episode, $lang);
        $eightstreamError = $result['message'] ?? null;
    } else {
        $eightstreamError = null;
    }

    // 1.6 ── Chinese platforms (keyed extractor). Same idea as 8Stream: a
    // playurl for our own player, but this one also brings the platform's own
    // CC track, which is the caption a C-drama actually ships. Inert while no
    // CNEXTRACT_KEY is configured.
    if (function_exists('cnx_enabled') && cnx_enabled()) {
        $result = stream_try_cn_extract($info, $episode, $lang);
        if (!empty($result['ok'])) return stream_attach_sources($result, $info, $episode, $lang);
        $cnError = $result['message'] ?? null;
    } else {
        $cnError = null;
    }

    // 2 ── embed players
    if (STREAM_TRY_EMBEDS) {
        $result = stream_embed_payload($info, $episode, $lang);
        if (!empty($result['ok'])) {
            if ($scraperError) $result['scraper_error'] = $scraperError;
            return stream_attach_sources($result, $info, $episode, $lang);
        }
    }

    if (!empty($anikuroError)) $scraperError = $anikuroError;
    if (!empty($eightstreamError)) $scraperError = $eightstreamError;
    if (!empty($cnError)) $scraperError = $cnError;

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
        'sources'   => [],
        'subtitles' => [],
        'intro'     => null,
        'outro'     => null,
        'thumbnails'=> null,
        'source'    => null,
        'message'   => $message,
    ];
}

// ─── Attempt 0.5: 8Stream ─────────────────────────────────────────────

/**
 * 8Stream is indexed by IMDb id (not AniList/MAL), so the item's id is
 * cross-referenced through TMDB first. A hit is a real HLS master playlist
 * that plays in our own player — the relay attaches the provider headers and
 * re-signs every child URL. A miss simply hands the episode to the next
 * attempt.
 */
function stream_try_eightstream($info, $episode, $lang) {
    if (!eightstream_enabled()) {
        return stream_failure('8Stream is disabled.', $episode, $lang);
    }

    $imdb = eightstream_imdb_for($info);
    if (empty($imdb)) {
        return stream_failure('No IMDb id for this title.', $episode, $lang);
    }

    $season = eightstream_season_for($info);
    $res = eightstream_resolve($imdb, $season, $episode, $lang);
    if (empty($res['ok']) || empty($res['url'])) {
        return stream_failure('8Stream has no source for this title.', $episode, $lang);
    }

    // Every audio language the provider offers, self-describing so the
    // player's Audio row can fetch the picked one on demand.
    $audio = [];
    foreach (($res['languages'] ?? []) as $title) {
        $audio[] = [
            'label'  => (string)$title,
            'lang'   => (string)$title,
            'imdb'   => $imdb,
            'season' => (int)$season,
            'ep'     => (int)$episode,
        ];
    }

    $server = [
        'key'      => 'eightstream',
        'label'    => '8Stream',
        'lang'     => 'any',
        'mode'     => 'hls',
        'url'      => $res['url'],
        'dataLink' => null,
        'provider' => 'eightstream',
        'primary'  => true,
        'audio'    => $audio,
        'audio_lang' => $res['lang'] ?? null,
    ];

    return [
        'ok'         => true,
        'mode'       => 'hls',
        'url'        => $res['url'],
        'lang'       => 'any',
        'episode'    => $episode,
        'servers'    => [$server],
        'server_key' => 'eightstream',
        'audio'      => $audio,
        'audio_lang' => $res['lang'] ?? null,
        'subtitles'  => [],
        'intro'      => null,
        'outro'      => null,
        'thumbnails' => null,
        'source'     => 'eightstream',
        'message'    => null,
    ];
}

// ─── Attempt 1.6: Chinese platforms (keyed extractor) ─────────────────

/**
 * The extractor is keyed by title, not by AniList/MAL id, so the catalogue
 * title (and its year, when the provider carries one) is what gets searched.
 * A hit is a direct HLS/mp4 URL plus the platform's own captions, already
 * signed through our subtitle relay.
 */
function stream_try_cn_extract($info, $episode, $lang) {
    $title = trim((string)($info['title'] ?? $info['name'] ?? ''));
    if ($title === '') {
        return stream_failure('No title to look up.', $episode, $lang);
    }

    $year = (int)($info['year'] ?? 0);
    if ($year <= 0 && !empty($info['aired'])) {
        $year = (int)substr((string)$info['aired'], 0, 4);
    }

    $res = cnx_resolve($title, $year);
    if (empty($res['url'])) {
        return stream_failure('The Chinese extractor has no source for this title.', $episode, $lang);
    }

    $subs = [];
    foreach ((array)($res['subtitles'] ?? []) as $s) {
        $subs[] = [
            'url'      => $s['url'],
            'language' => $s['language'] ?? 'Chinese',
            'lang'     => $s['lang'] ?? '',
            'format'   => 'vtt',
            'source'   => 'bilibili',
            'default'  => !empty($s['default']),
        ];
    }
    if ($subs && empty($subs[0]['default'])) $subs[0]['default'] = true;

    $server = [
        'key'      => 'cn_extract',
        'label'    => 'Bilibili',
        'lang'     => 'any',
        'mode'     => 'hls',
        'url'      => $res['url'],
        'dataLink' => null,
        'provider' => 'cn_extract',
        'primary'  => true,
        'subtitles'=> $subs,
    ];

    return [
        'ok'         => true,
        'mode'       => 'hls',
        'url'        => $res['url'],
        'lang'       => 'any',
        'episode'    => $episode,
        'servers'    => [$server],
        'server_key' => 'cn_extract',
        'subtitles'  => $subs,
        'intro'      => null,
        'outro'      => null,
        'thumbnails' => null,
        'source'     => 'cn_extract',
        'message'    => null,
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
        'server_key' => $servers[0]['key'],
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

    // Stamp the decrypted URL onto the server that actually resolved it, so
    // the client queue can dedupe the primary source against this chip and
    // re-play it without paying for a second decrypt.
    foreach ($servers as $i => $candidate) {
        if (($candidate['key'] ?? '') === ($first['key'] ?? '')) {
            $servers[$i]['url'] = $stream['url'];
            break;
        }
    }

    return [
        'ok'         => true,
        'mode'       => 'hls',
        'url'        => $stream['url'],
        'lang'       => $first['lang'] ?? $lang,
        'episode'    => $episode,
        'servers'    => $servers,
        'server_key' => $first['key'] ?? ($servers[0]['key'] ?? null),
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

// ─── Anime: resolver-backed NHD server ────────────────────────────────

/**
 * The NHD anime server for one episode — an embed URL our level-1 resolver
 * turns into a real HLS/mp4 stream inside our own player.
 *
 * NHD keys its anime pages by AniList id (verified: /anime/{anilist}/ep —
 * MAL/TMDB ids land on an empty "Player" shell), so the caller passes the
 * id it already resolved for the embed list. Returns null without one.
 *
 * Language aware: NHD's extraction API always answers with the /sub sibling,
 * so DUB is served from zokoanime.video — NHD's own upstream, verified to
 * carry a distinct dub stream on the very same hls CDN
 * (/stream/ani/{anilist}/{ep}/dub). Both variants resolve through our
 * resolver into the ArtPlayer; same chip, same key, so a remembered server
 * pick survives a SUB↔DUB switch.
 */
function stream_nhd_anime_server($anilistId, $episode, $lang = 'sub') {
    $anilistId = (int)$anilistId;
    if ($anilistId <= 0) return null;
    $episode = (int)$episode;

    if (strtolower((string)$lang) === 'dub') {
        return [
            'key'      => 'nhd-anime',
            'label'    => 'Auto HD',
            'lang'     => 'dub',
            'mode'     => 'embed',
            'url'      => 'https://zokoanime.video/stream/ani/' . $anilistId . '/' . $episode . '/dub',
            'dataLink' => null,
            'primary'  => true,
        ];
    }

    return [
        'key'      => 'nhd-anime',
        'label'    => 'Auto HD',
        'lang'     => 'any',
        'mode'     => 'embed',
        'url'      => 'https://nhdapi.com/anime/' . $anilistId . '/' . $episode,
        'dataLink' => null,
        'primary'  => true,
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
        'server_key' => $pick['key'],
        'subtitles'  => [],
        'intro'      => null,
        'outro'      => null,
        'thumbnails' => null,
        'source'     => 'embed:' . $pick['key'],
        'message'    => null,
    ];
}

/**
 * @param bool $healthCheck Run the HEAD-request health filter. Set false when
 *                          these embeds are only a fallback appended behind a
 *                          source that already resolved — the browser is the
 *                          real health check there, and a HEAD round-trip
 *                          per provider would stall every successful resolve.
 */
function stream_embed_servers($info, $episode, $lang, $healthCheck = true) {
    $anilistId = !empty($info['anilist_id']) ? (int)$info['anilist_id'] : 0;
    $malId = !empty($info['mal_id']) ? (int)$info['mal_id'] : 0;

    // Embeds need an AniList id. If we only have a MAL id, look it up.
    if ($anilistId <= 0 && $malId > 0 && REANIME_ENABLED) {
        $match = reanime_find_slug($info['title'] ?? '', $info['year'] ?? null);
        if (!empty($match['anilist_id'])) $anilistId = (int)$match['anilist_id'];
    }
    if ($anilistId <= 0) return [];

    // MegaPlay leads the list: it answers in well under a second through our
    // own relay and is the provider flagged primary, so it wins the first
    // pick below. NHD comes next (also primary — the health filter must
    // never drop it), then the plain fallbacks in config order.
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

    $nhd = stream_nhd_anime_server($anilistId, $episode, $lang);
    if ($nhd) $out[] = $nhd;

    if ($healthCheck) {
        $out = stream_filter_active_embeds($out);
    }

    usort($out, fn($a, $b) => (int)!empty($b['primary']) <=> (int)!empty($a['primary']));

    return $out;
}

// ─── Normalized source queue ──────────────────────────────────────────

/**
 * Build the ordered fallback queue for one resolved payload.
 *
 * Entry shape — identical for every provider so the client walks one list:
 *   {type, mode, url, dataLink, provider, key, label, lang, headers,
 *    expires_at, subtitles, intro, outro}
 *
 * `type` and `mode` carry the same value ('hls' | 'embed'); `type` is the
 * queue vocabulary, `mode` kept so existing client checks still work.
 *
 * Order: primary source → other direct servers → embed iframes (last
 * resort, built without HEAD health checks — behind a source that already
 * resolved the browser itself is the real health check, and a per-provider
 * HEAD round-trip would stall every successful resolve).
 */
function stream_build_sources($payload, $info, $episode, $lang) {
    if (empty($payload['ok']) || empty($payload['url'])) return [];

    $mode     = $payload['mode'] ?? 'embed';
    $servers  = is_array($payload['servers'] ?? null) ? $payload['servers'] : [];
    $primaryKey = $payload['server_key'] ?? null;

    // Inherit label/provider from the server chip the primary came from.
    $label     = $payload['source'] ?? 'Primary';
    $provider  = null;
    $audio     = $payload['audio'] ?? null;
    $audioLang = $payload['audio_lang'] ?? null;
    foreach ($servers as $server) {
        if (($server['key'] ?? '') === $primaryKey) {
            $label    = $server['label'] ?? $label;
            $provider = $server['provider'] ?? null;
            if (!empty($server['audio'])) $audio = $server['audio'];
            if (!empty($server['audio_lang'])) $audioLang = $server['audio_lang'];
            break;
        }
    }

    $sources    = [];
    $seenUrls   = [];
    $seenLinks  = [];
    $push = function (array $entry) use (&$sources, &$seenUrls, &$seenLinks) {
        $u = (string)($entry['url'] ?? '');
        $d = (string)($entry['dataLink'] ?? '');
        if ($u !== '') {
            if (isset($seenUrls[$u])) return;
            $seenUrls[$u] = true;
        }
        if ($d !== '') {
            if (isset($seenLinks[$d])) return;
            $seenLinks[$d] = true;
        }
        $sources[] = $entry;
    };

    // 1 ── primary (the payload's own playable URL)
    $push([
        'type'       => $mode,
        'mode'       => $mode,
        'url'        => (string)$payload['url'],
        'dataLink'   => null,
        'provider'   => $provider,
        'key'        => $primaryKey ?: 'primary',
        'label'      => $label,
        'lang'       => $payload['lang'] ?? $lang,
        'headers'    => null,
        'expires_at' => null,
        'subtitles'  => $payload['subtitles'] ?? [],
        'intro'      => $payload['intro'] ?? null,
        'outro'      => $payload['outro'] ?? null,
        'audio'      => $audio,
        'audio_lang' => $audioLang,
    ]);

    // 2 ── every other server the payload offers
    foreach ($servers as $server) {
        if (($server['key'] ?? '') === $primaryKey) continue;
        $push([
            'type'       => $server['mode'] ?? 'hls',
            'mode'       => $server['mode'] ?? 'hls',
            'url'        => (string)($server['url'] ?? ''),
            'dataLink'   => $server['dataLink'] ?? null,
            'provider'   => $server['provider'] ?? null,
            'key'        => $server['key'] ?? null,
            'label'      => $server['label'] ?? null,
            'lang'       => $server['lang'] ?? 'any',
            'headers'    => null,
            'expires_at' => null,
            'subtitles'  => [],
            'intro'      => null,
            'outro'      => null,
            'audio'      => $server['audio'] ?? null,
            'audio_lang' => $server['audio_lang'] ?? null,
        ]);
    }

    // 3 ── embed iframes as the last resort behind a direct source
    if ($mode === 'hls' && STREAM_TRY_EMBEDS) {
        foreach (stream_embed_servers($info, $episode, $lang, false) as $server) {
            $push([
                'type'       => 'embed',
                'mode'       => 'embed',
                'url'        => (string)($server['url'] ?? ''),
                'dataLink'   => null,
                'provider'   => 'embed',
                'key'        => $server['key'] ?? null,
                'label'      => $server['label'] ?? null,
                'lang'       => $server['lang'] ?? 'any',
                'headers'    => null,
                'expires_at' => null,
                'subtitles'  => [],
                'intro'      => null,
                'outro'      => null,
            ]);
        }
    }

    return $sources;
}

/** Attach the normalized queue to a payload and return it. */
function stream_attach_sources($payload, $info, $episode, $lang) {
    $payload['sources'] = stream_build_sources($payload, $info, $episode, $lang);
    stream_attach_subtitles($payload, $info, $episode);
    return $payload;
}

/**
 * Captions of last resort for anime.
 *
 * The scraper and Anikuro ship their own subtitle tracks, so this only fires
 * when the winning payload arrived without any: the show's IMDb id is resolved
 * through the same TMDB cross-lookup the 8Stream attempt uses (cached for a
 * week), then includes/subtitles_api.php looks English captions up online.
 * A miss changes nothing — the episode plays as it would have.
 */
function stream_attach_subtitles(&$payload, $info, $episode) {
    if (!subtitles_enabled() || !is_array($info)) return 0;
    $imdb = eightstream_imdb_for($info);
    if (empty($imdb)) return 0;
    return subtitles_attach($payload, $imdb, eightstream_season_for($info), $episode);
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
