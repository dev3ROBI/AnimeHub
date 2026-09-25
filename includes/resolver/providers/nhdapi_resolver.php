<?php
/**
 * NHD (nhdapi.com) resolver.
 *
 * The embed page ships its own extraction endpoint, key and server list to the
 * browser (that is how their player works), so resolving is ordinary GETs:
 *   1. the embed page  → API_PATH + API_KEY + PROVIDER_LIST
 *   2. API_PATH?key=…[&provider=…] → { success, playUrl, kind, m3u8, … }
 *
 * NHD fans one title out to a whole list of upstream servers and the page
 * prefers them in PROVIDER_LIST order. We fire their race winner plus every
 * server they name in one concurrent wave; each API answer immediately queues
 * a Range probe for its playUrl *into the same wave*, so nothing ever waits
 * on anything else — the first response that really serves media wins.
 * Serially the dead ends alone cost 30s+, so running it all together is what
 * keeps the resolve inside its deadline.
 *
 * Verified: playUrl answers with a real #EXTM3U manifest and
 * `access-control-allow-origin: *`, no Referer gate — i.e. the browser can
 * actually play it. When no server in the list is alive, the caller falls
 * back to the iframe.
 */
class NhdapiResolver extends ResolverBase {

    /** Page fetch — the embed shell has to arrive before anything else. */
    private const PAGE_TIMEOUT = 6;

    /**
     * One API call. The API answers in ~650ms when it is healthy, but the
     * edge regularly stretches to ~4s (measured 522ms–3.8s) — so a job must
     * be allowed past 4s or real answers get thrown away as timeouts.
     */
    private const API_TIMEOUT = 5;

    /** Range probe against an m3u8: quick to answer, so keep it tight. */
    private const VERIFY_HLS = 4;

    /** The mp4 CDN is slow to first byte — patience, or good files get written off. */
    private const VERIFY_MP4 = 7;

    /** Captions are a bonus, never worth waiting for. */
    private const SUBS_TIMEOUT = 3;

    /** Servers probed concurrently per wave — keeps a huge list polite. */
    private const WAVE = 12;

    /** When this resolve() started, for the timing lines in the log. */
    private float $t0 = 0.0;

    public function key(): string {
        return 'nhdapi';
    }

    public function supports(string $url): bool {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        if ($host !== 'nhdapi.com') return false;
        return (bool)preg_match('#^/(movie|tv|anime)/#', $path);
    }

    public function resolve(string $url): array {
        $this->t0 = microtime(true);
        $page = $this->fetch($url, ['Referer: https://nhdapi.com/'], self::PAGE_TIMEOUT);
        if ($page === null) return $this->fail('page-fetch-failed');

        $apiPath = $this->match('#(?:const|var|let)\s+API_PATH\s*=\s*["\']([^"\']+)["\']#', $page);
        $apiKey  = $this->match('#(?:const|var|let)\s+API_KEY\s*=\s*["\']([^"\']+)["\']#', $page);
        if (!$apiPath || !$apiKey) return $this->fail('parse-failed');

        // API_PATH is relative — never trust a scheme/host the page invents.
        if ($apiPath[0] !== '/' || strpos($apiPath, '//') === 0) return $this->fail('bad-api-path');

        $base = 'https://nhdapi.com' . $apiPath
              . (strpos($apiPath, '?') === false ? '?' : '&')
              . '_ts=' . time() . '&key=' . urlencode($apiKey);

        // Their race winner first (the fast path), then every server they name.
        $jobs = [];
        foreach (array_merge([null], $this->providerList($page)) as $provider) {
            $u = $provider === null ? $base : ($base . '&provider=' . urlencode($provider));
            $jobs[] = [
                'url'     => $u,
                'headers' => ['Referer: https://nhdapi.com/'],
                'timeout' => self::API_TIMEOUT,
                'guard'   => 'host',
                'tag'     => ['api', (string)($provider ?? ''), $u],
            ];
        }

        $last     = 'upstream-dead';
        $seen     = [];      // providers already ruled out (race + pinned overlap)
        $seenUrl  = [];      // playUrls already queued for a probe
        $found    = null;
        $deferred = [];      // mp4 candidates — probed only if no HLS comes back live
        $retryMe  = [];      // jobs that never answered (code 0) — worth one re-run

        $onResult = function ($tag, $body, $meta) use (&$last, &$seen, &$seenUrl, &$found, &$deferred, &$retryMe) {
            $ms = (int)round((microtime(true) - $this->t0) * 1000);

            if (($tag[0] ?? '') === 'api') {
                if ($body === null) {
                    // code 0 = never answered (a dead window) → re-run it.
                    // A 4xx/5xx is a definitive "no such title here" — that
                    // provider is ruled out, retrying it only wastes budget.
                    if ((int)($meta['code'] ?? 0) === 0 && !empty($tag[2])) {
                        $retryMe[(string)$tag[2]] = true;
                        $last = 'upstream-failed';
                    } else {
                        $last = 'upstream-no-title';
                    }
                    return false;
                }

                $data = json_decode($body, true);
                if (!is_array($data)) { $last = 'upstream-failed'; return false; }

                $up = (string)($data['provider'] ?? '');
                if ($up !== '' && isset($seen[$up])) return false;   // already ruled out
                if ($up !== '') $seen[$up] = true;

                if (empty($data['success'])) { $last = 'upstream-rejected'; return false; }

                $media = $this->pick($data);
                if ($media === null) { $last = 'no-play-url'; return false; }

                if (isset($seenUrl[$media['url']])) return false;
                $seenUrl[$media['url']] = true;

                $cand = ['media' => $media, 'data' => $data, 'up' => $up];
                if ($media['type'] === 'mp4') {
                    $deferred[] = $cand;
                    return false;
                }

                return [[
                    'url'     => $media['url'],
                    'headers' => ['Range: bytes=0-2047'],
                    'timeout' => self::VERIFY_HLS,
                    'guard'   => 'media',
                    'tag'     => ['probe', $cand, 'hls'],
                ]];
            }

            // A probe answer — does it really serve media?
            $kind = (string)($tag[2] ?? '');
            if ($body !== null
                && stripos((string)($meta['ct'] ?? ''), 'application/json') === false
                && ($kind !== 'hls' || strpos($body, '#EXTM3U') === 0)) {
                $found = $tag[1];
                ResolverManager::log("{$ms}ms probe " . ($tag[1]['up'] ?: '?') . ' LIVE');
                return true;
            }

            $last = 'upstream-dead';
            ResolverManager::log("{$ms}ms probe " . (($tag[1]['up'] ?? '') ?: '?') . ' dead');
            return false;
        };

        foreach (array_chunk($jobs, self::WAVE) as $waveJobs) {
            if ($found !== null || $this->outOfTime()) break;
            $this->fetchWave($waveJobs, $onResult);
        }

        // One re-run, but only for the jobs that never answered at all
        // (code 0). A 4xx/5xx provider already gave its verdict; re-firing
        // the whole fanout would just buy more dead windows. Verified: the
        // edge goes through black-hole stretches where every concurrent job
        // dies, then answers the very same URLs a moment later.
        if ($found === null && $retryMe && !$this->outOfTime()) {
            ResolverManager::log('nhd api-timeout retry n=' . count($retryMe));
            $retryJobs = [];
            foreach ($jobs as $j) {
                if (isset($retryMe[$j['url']])) {
                    $j['timeout'] = self::API_TIMEOUT;
                    $retryJobs[] = $j;
                }
            }
            if ($retryJobs) {
                foreach (array_chunk($retryJobs, self::WAVE) as $waveJobs) {
                    if ($found !== null || $this->outOfTime()) break;
                    $this->fetchWave($waveJobs, $onResult);
                }
            }
        }

        // Nothing live in HLS? Now the deferred mp4s get their (parallel) turn.
        if ($found === null && $deferred) {
            $mp4jobs = [];
            foreach ($deferred as $cand) {
                $mp4jobs[] = [
                    'url'     => $cand['media']['url'],
                    'headers' => ['Range: bytes=0-2047'],
                    'timeout' => self::VERIFY_MP4,
                    'guard'   => 'media',
                    'tag'     => ['probe', $cand, 'mp4'],
                ];
            }
            $this->fetchWave($mp4jobs, $onResult);
        }

        if ($found === null) return $this->fail($last);

        ResolverManager::log('nhd upstream=' . ($found['up'] ?: '?') . ' type=' . $found['media']['type']);
        $subs = $this->outOfTime() ? [] : $this->subtitles($url);

        return $this->ok($found['media']['type'], $found['media']['url'], [
            'subtitles'  => $subs,
            'quality'    => [],
            'expires_at' => $this->expiry($found['data']),
        ]);
    }

    /** NHD's own ordered server list, straight out of the page. */
    private function providerList(string $page): array {
        if (!preg_match('#var\s+PROVIDER_LIST\s*=\s*(\[[^\]]*\])#', $page, $m)) return [];
        $list = json_decode($m[1], true);
        if (!is_array($list)) return [];
        return array_values(array_filter(array_map('strval', $list), function ($p) {
            return (bool)preg_match('#^[a-z0-9_]+$#i', $p);
        }));
    }

    /** API payload → {type,url}, or null when there is nothing playable. */
    private function pick(array $data): ?array {
        $playUrl = $data['playUrl'] ?? '';
        $kind    = strtolower((string)($data['kind'] ?? 'hls'));
        if (!is_string($playUrl) || $playUrl === '') return null;
        if (stripos($playUrl, 'http') !== 0) return null;

        // No dash.js in this project — hand MPD-only results back to the iframe.
        if ($kind === 'dash' || $kind === 'mpd') return null;

        return ['type' => $kind === 'mp4' ? 'mp4' : 'hls', 'url' => $playUrl];
    }

    /** Caption list for the same media (optional — empty list on any hiccup). */
    private function subtitles(string $pageUrl): array {
        $path = (string)(parse_url($pageUrl, PHP_URL_PATH) ?: '');

        if (preg_match('#^/movie/(\d+)#', $path, $m)) {
            $qs = 'mediaType=movie&tmdbId=' . $m[1];
        } elseif (preg_match('#^/tv/(\d+)/(\d+)/(\d+)#', $path, $m)) {
            $qs = 'mediaType=tv&tmdbId=' . $m[1] . '&season=' . $m[2] . '&episode=' . $m[3];
        } else {
            return [];
        }

        $data = $this->fetchJson('https://nhdapi.com/api/subtitles?' . $qs,
            ['Referer: https://nhdapi.com/'], self::SUBS_TIMEOUT);
        if (!$data || !isset($data['subtitles'])) return [];
        return $this->normalizeSubtitles($data['subtitles']);
    }

    /**
     * NHD's raw m3u8 carries `:<unix>:<client ip>:` inside the token — that
     * number is the signed window. Falls back to null (caller then caches for
     * RESOLVER_CACHE_TTL only) when the shape is not there.
     */
    private function expiry(array $data): ?int {
        $m3u8 = (string)($data['m3u8'] ?? '');
        if (preg_match('#:(\d{10}):[0-9a-fA-F.]{7,}:#', $m3u8, $m)) {
            $ts = (int)$m[1];
            $now = time();
            if ($ts > $now && $ts < $now + 86400) return $ts;
        }
        return null;
    }
}
