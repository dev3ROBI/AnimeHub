<?php
/**
 * VidCore (vidcore.org) resolver.
 *
 * The VidCore embed page is itself a multi-source player: it hardcodes the
 * JSON backends it queries (that is how their page finds streams), so
 * resolving is two ordinary GETs against those same backends:
 *
 *   1. VidZen  — https://vidzen.fun/api/sources?type=…&id=…
 *        { sources:[{url,type,…}], sourcePool:{…}, subtitles:[] }
 *        (relative /api/stream/… paths resolve against vidzen.fun)
 *   2. Rigel   — https://movish.to/player-sources/rigel/{movie|tv}/…
 *        { success, streams:[{url,label,quality,type}] }
 *
 * Both fire in one wave; every answer immediately queues Range probes for
 * its candidate URLs *into the same wave*, so the first response that
 * really serves media wins (mp4 candidates are held back until no HLS is
 * live — same discipline as the NHD resolver).
 *
 * Verified by hand: movie + tv answers with real #EXTM3U manifests and
 * `access-control-allow-origin: *`. When both backends are dead the caller
 * falls back to the iframe for this same embed URL.
 */
require_once __DIR__ . '/../../media_relay_lib.php';

class VidcoreResolver extends ResolverBase {

    /** Backend APIs answer fast; a job burning past this is a dead edge. */
    private const API_TIMEOUT = 6;

    /** Range probe against an m3u8 — quick, keep it tight. */
    private const VERIFY_HLS = 4;

    /** mp4 CDNs are slower to first byte. */
    private const VERIFY_MP4 = 6;

    /** VidZen's origin — also the base for its relative /api/stream paths. */
    private const ZEN = 'https://vidzen.fun';

    /** Rigel's path base (endpoint is BASE + movie/{id} | tv/{id}/{s}/{e}). */
    private const RIGEL = 'https://movish.to/player-sources/rigel/';

    public function key(): string {
        return 'vidcore';
    }

    public function supports(string $url): bool {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        if ($host !== 'vidcore.org') return false;
        return (bool)preg_match('#^/embed/(movie|tv)/#', $path);
    }

    public function resolve(string $url): array {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');

        if (preg_match('#^/embed/movie/(\d+)#', $path, $m)) {
            $zenUrl   = self::ZEN . '/api/sources?type=movie&id=' . $m[1];
            $rigelUrl = self::RIGEL . 'movie/' . $m[1];
        } elseif (preg_match('#^/embed/tv/(\d+)/(\d+)/(\d+)#', $path, $m)) {
            $zenUrl   = self::ZEN . '/api/sources?type=tv&id=' . $m[1]
                      . '&season=' . $m[2] . '&episode=' . $m[3];
            $rigelUrl = self::RIGEL . 'tv/' . $m[1] . '/' . $m[2] . '/' . $m[3];
        } else {
            return $this->fail('bad-path');
        }

        $jobs = [
            ['url' => $zenUrl,   'headers' => ['Referer: https://vidcore.org/'],
             'timeout' => self::API_TIMEOUT, 'guard' => 'host', 'tag' => ['api', 'vidzen']],
            ['url' => $rigelUrl, 'headers' => ['Referer: https://vidcore.org/'],
             'timeout' => self::API_TIMEOUT, 'guard' => 'host', 'tag' => ['api', 'rigel']],
        ];

        $last     = 'upstream-dead';
        $found    = null;
        $deferred = [];      // mp4 candidates — probed only if no HLS comes back live
        $seenUrl  = [];      // candidates already queued or ruled out
        $subs     = [];

        $onResult = function ($tag, $body, $meta) use (&$last, &$found, &$deferred, &$seenUrl, &$subs) {
            if (($tag[0] ?? '') === 'api') {
                if ($body === null) { $last = 'upstream-failed'; return false; }

                $data = json_decode($body, true);
                if (!is_array($data)) { $last = 'upstream-failed'; return false; }

                $which = (string)($tag[1] ?? '');
                if ($which === 'vidzen' && isset($data['subtitles']) && is_array($data['subtitles'])) {
                    $subs = $this->normalizeSubtitles($data['subtitles']);
                }

                $cands = $which === 'rigel'
                    ? $this->rigelCandidates($data)
                    : $this->zenCandidates($data);
                if (!$cands) { $last = 'no-sources'; return false; }

                $more = [];
                foreach ($cands as $c) {
                    if (isset($seenUrl[$c['url']])) continue;
                    $seenUrl[$c['url']] = true;

                    if ($c['type'] === 'mp4') { $deferred[] = $c; continue; }

                    $more[] = [
                        'url'     => $c['url'],
                        'headers' => ['Range: bytes=0-2047'],
                        'timeout' => self::VERIFY_HLS,
                        'guard'   => 'media',
                        'tag'     => ['probe', $c],
                    ];
                }
                return $more ?: false;
            }

            // A probe answer. Playlists must prove their *segment tree* is
            // live before a candidate is trusted: providers happily serve
            // healthy manifests whose segments 404 or 429 (VidZen proxies
            // them onto *.workers.dev, whose free-plan quota Cloudflare
            // cuts off daily), and only the browser would see that breakage.
            // Each successful stage queues the next fetch into this same
            // wave — a healthy tree costs two extra round trips inside the
            // deadline, a dead one fails the provider in ~1s instead of
            // letting the player walk through timeouts later.
            $kind = (string)($tag[0] ?? '');
            $c    = $tag[1] ?? null;
            if (!is_array($c)) { $last = 'upstream-dead'; return false; }

            if ($kind === 'tree' && ($tag[2] ?? '') === 's') {
                if ($body === null || stripos((string)($meta['ct'] ?? ''), 'application/json') !== false) {
                    $last = 'segment-dead';
                    return false;
                }
                $found = $c;
                ResolverManager::log('vidcore ' . ($c['src'] ?? '?') . ' LIVE tree-ok');
                return true;
            }

            if ($body === null || stripos((string)($meta['ct'] ?? ''), 'application/json') !== false) {
                $last = 'upstream-dead';
                return false;
            }

            if (($c['type'] ?? 'hls') !== 'hls') {   // mp4 — the Range probe already proved it
                $found = $c;
                ResolverManager::log('vidcore ' . ($c['src'] ?? '?') . ' LIVE');
                return true;
            }

            if (strpos((string)$body, '#EXTM3U') !== 0) {
                $last = 'upstream-dead';
                return false;
            }

            // Walk one level down: master → variant, or straight to segments.
            $base = (string)($meta['url'] ?: $c['url']);
            $uri  = $this->firstPlaylistUri((string)$body);
            $abs  = $uri !== null ? $this->absolutize($base, $uri) : null;
            if ($abs === null) {
                $found = $c;   // nothing to walk — trust the manifest itself
                ResolverManager::log('vidcore ' . ($c['src'] ?? '?') . ' LIVE (leaf)');
                return true;
            }
            $nextStage = (stripos((string)$body, '#EXTINF') !== false) ? 's' : 'v';
            return [[
                'url'     => $abs,
                'headers' => ['Range: bytes=0-2047'],
                'timeout' => self::VERIFY_HLS,
                'guard'   => 'media',
                'tag'     => ['tree', $c, $nextStage],
            ]];
        };

        $this->fetchWave($jobs, $onResult);

        // One cheap re-run: both APIs answer in well under a second when they
        // answer at all — an all-dead first wave is usually one dead window,
        // not a dead service. The deadline still caps everything. A dead
        // segment tree is a quota outage, not a bad window: no retry.
        if ($found === null && $last !== 'segment-dead' && !$this->outOfTime()) {
            ResolverManager::log('vidcore api-wave-empty → retry');
            $this->fetchWave($jobs, $onResult);
        }

        // Nothing live in HLS? Now the deferred mp4s get their turn.
        if ($found === null && $deferred && !$this->outOfTime()) {
            $mp4jobs = [];
            foreach ($deferred as $c) {
                $mp4jobs[] = [
                    'url'     => $c['url'],
                    'headers' => ['Range: bytes=0-2047'],
                    'timeout' => self::VERIFY_MP4,
                    'guard'   => 'media',
                    'tag'     => ['probe', $c],
                ];
            }
            $this->fetchWave($mp4jobs, $onResult);
        }

        if ($found === null) return $this->fail($last);

        ResolverManager::log('vidcore src=' . ($found['src'] ?? '?') . ' type=' . $found['type']);

        // VidZen redirects its stream URLs onto *.workers.dev, which never
        // sends CORS headers — the browser blocks every hls.js fetch while
        // our own probe succeeds. Those hosts (and only those) go out as
        // same-origin relay URLs; plain CORS-clean backends stay direct.
        $mediaUrl = (string)$found['url'];
        if (function_exists('mr_relay_sign')) {
            $mediaUrl = mr_relay_sign($mediaUrl) ?: $mediaUrl;
        }

        return $this->ok($found['type'], $mediaUrl, [
            'subtitles' => $this->outOfTime() ? [] : $subs,
            'quality'   => [],
        ]);
    }

    /**
     * First URI line of an HLS playlist (children sit on bare lines; tags
     * start with '#'). The body is a 2KB Range window, so the trailing line
     * may be cut mid-URL — it is dropped before scanning.
     */
    private function firstPlaylistUri(string $body): ?string {
        $lines = preg_split('/\r\n|\n|\r/', $body);
        if (strlen($body) >= 2048) array_pop($lines);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#') continue;
            return $ln;
        }
        return null;
    }

    /** Resolve a playlist child URI against the playlist's own URL. */
    private function absolutize(string $base, string $ref): ?string {
        if (stripos($ref, 'http://') === 0 || stripos($ref, 'https://') === 0) return $ref;
        $p = parse_url($base);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return null;
        if (strpos($ref, '//') === 0) return $p['scheme'] . ':' . $ref;
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (isset($ref[0]) && $ref[0] === '/') return $origin . $ref;
        $dir = str_replace('\\', '/', dirname((string)($p['path'] ?? '/')));
        if ($dir === '' || $dir === '.' || $dir === '\\') $dir = '/';
        return $origin . rtrim($dir, '/') . '/' . $ref;
    }

    /**
     * VidZen payload → candidate media URLs. The top-level `sources` and
     * every `sourcePool` bucket are the same shape; relative paths belong
     * to vidzen.fun (their own player resolves them that way).
     */
    private function zenCandidates(array $data): array {
        $list = [];
        foreach (($data['sources'] ?? []) as $s) $list[] = $s;
        foreach (($data['sourcePool'] ?? []) as $pool) {
            if (!is_array($pool)) continue;
            foreach (($pool['sources'] ?? []) as $s) $list[] = $s;
        }

        $out = [];
        foreach ($list as $s) {
            if (!is_array($s)) continue;
            $u = (string)($s['url'] ?? '');
            if ($u === '') continue;
            if (isset($u[0]) && $u[0] === '/') $u = self::ZEN . $u;
            if (stripos($u, 'http') !== 0) continue;

            $type = strtolower((string)($s['type'] ?? 'hls'));
            if ($type === 'dash' || $type === 'mpd') continue;   // no dash.js here

            $out[] = [
                'url'  => $u,
                'type' => $type === 'mp4' ? 'mp4' : 'hls',
                'src'  => 'vidzen ' . (string)($s['server'] ?? ''),
            ];
        }
        return $out;
    }

    /** Rigel payload → candidate media URLs (all of them; probes decide). */
    private function rigelCandidates(array $data): array {
        $out = [];
        foreach (($data['streams'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $u = (string)($s['url'] ?? '');
            if ($u === '' || stripos($u, 'http') !== 0) continue;

            $type = strtolower((string)($s['type'] ?? 'hls'));
            if ($type === 'dash' || $type === 'mpd') continue;

            $label = (string)($s['quality'] ?? $s['label'] ?? '');
            $out[] = [
                'url'  => $u,
                'type' => $type === 'mp4' ? 'mp4' : 'hls',
                'src'  => 'rigel' . ($label !== '' ? ' ' . $label : ''),
            ];
        }
        return $out;
    }
}
