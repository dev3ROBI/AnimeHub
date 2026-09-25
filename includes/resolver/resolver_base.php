<?php
/**
 * Base class every embed-provider resolver extends.
 *
 * A resolver takes the *embed page URL* the site would otherwise show in an
 * iframe and returns a normalized, directly playable media result. It only
 * ever performs ordinary GET requests against its own provider — no proxying,
 * no auth/DRM/CORS circumvention. Providers that can't be resolved legitimately
 * simply have no resolver and keep the existing iframe fallback.
 *
 * Normalized result shape (the contract the client plays against):
 *   success   bool
 *   type      'hls' | 'mp4' | 'dash' | 'embed'
 *   url       string   media URL (or the embed URL when type=embed)
 *   provider  string
 *   headers   array    informational only — never used to spoof Referer
 *   subtitles array    [{url, label, lang}]
 *   quality   array    [{label, url}] — empty when unknown
 *   expires_at int|null unix time the URL stops being valid
 */
abstract class ResolverBase {

    /** Wall-clock moment this resolve() must stop by (RESOLVER_TIMEOUT). */
    protected float $deadline = 0.0;

    /** Registry key, must match the allowlist value in config. */
    abstract public function key(): string;

    /** Can this resolver handle $url? (host + path shape) */
    abstract public function supports(string $url): bool;

    /**
     * Resolve. Returns the normalized array (success true/false).
     * Must not throw — the manager catches anyway, but clean codes are nicer.
     */
    abstract public function resolve(string $url): array;

    // ─── Helpers subclasses share ────────────────────────────────────

    /**
     * Called by the manager right before resolve(): starts the overall
     * deadline so a slow page + a slow API + a slow verify can never add up
     * past RESOLVER_TIMEOUT (the client is waiting with a timer of its own).
     */
    public function begin(): void {
        $this->deadline = microtime(true) + max(1, (int)RESOLVER_TIMEOUT);
    }

    /** Seconds left before the deadline (never negative). */
    protected function remaining(): float {
        if ($this->deadline <= 0) return (float)max(1, (int)RESOLVER_TIMEOUT);
        return max(0.0, $this->deadline - microtime(true));
    }

    /** True once the deadline has passed (or is within arm's reach). */
    protected function outOfTime(): bool {
        return $this->remaining() < 0.4;
    }

    /**
     * GET a URL and return the raw body, or null on any failure.
     *
     * Redirects are capped and re-checked against the allowlist so a provider
     * can never bounce the server onto an internal address.
     */
    protected function fetch(string $url, array $headers = [], ?int $timeout = null): ?string {
        $left = $this->remaining();
        if ($left < 0.4) return null;
        $timeout = (int)max(1, min($timeout ?? (int)RESOLVER_TIMEOUT, ceil($left)));

        if (!$this->host_ok($url)) return null;

        $ch = curl_init();
        curl_setopt_array($ch, $this->curlOptions($url, $this->headerList($headers), $timeout));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $eff  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300 || $body === '') return null;

        if ($eff !== '' && !$this->host_ok($eff)) return null;
        return (string)$body;
    }

    /**
     * Fire a wave of GETs and judge every answer the moment it lands.
     *
     * $jobs: [['url','headers','timeout','guard','tag']] where guard is
     *   'host'  → URL must be on the provider allowlist (API calls)
     *   'media' → a media URL handed to us by the API: https, no userinfo,
     *             never a private/reserved address (SSRF guard for Range probes)
     *
     * $onResult($tag, $body|null, ['code'=>..,'ct'=>..,'url'=>..]) returns:
     *   true  → stop the wave, drop what is still in flight
     *   array → append those jobs — this is how a freshly learned playUrl gets
     *           probed *inside* the same wave, so an m3u8 probe never stalls
     *           the API answers that arrived while it was in flight
     *   else  → keep going until the deadline or every handle is done
     */
    protected function fetchWave(array $jobs, callable $onResult): void {
        $mh = curl_multi_init();
        $pending = [];   // (int)handle => ['job' => job, 'ch' => ch]

        $add = function (array $job) use ($mh, &$pending, $onResult) {
            $url = (string)($job['url'] ?? '');
            $guard = (string)($job['guard'] ?? 'host');
            $ok = $guard === 'media' ? $this->media_ok($url) : $this->host_ok($url);

            if (!$ok || $url === '' || $this->outOfTime()) {
                $onResult($job['tag'] ?? $url, null, ['code' => 0, 'ct' => '', 'url' => $url]);
                return;
            }

            $left = $this->remaining();
            $timeout = (int)max(1, min((int)($job['timeout'] ?? (int)RESOLVER_TIMEOUT), ceil($left)));
            $ch = curl_init();
            curl_setopt_array($ch, $this->curlOptions($url, $this->headerList($job['headers'] ?? []), $timeout));
            curl_multi_add_handle($mh, $ch);
            $pending[(int)$ch] = ['job' => $job, 'ch' => $ch];
        };

        foreach ($jobs as $job) $add($job);

        $stop = false;
        while ($pending && !$stop) {
            $status = curl_multi_exec($mh, $running);

            while (!$stop && ($info = curl_multi_info_read($mh))) {
                $ch   = $info['handle'];
                $key  = (int)$ch;
                $slot = $pending[$key] ?? null;
                if ($slot === null) { curl_multi_remove_handle($mh, $ch); curl_close($ch); continue; }

                $job  = $slot['job'];
                $body = curl_multi_getcontent($ch);
                $meta = [
                    'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                    'ct'   => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                    'url'  => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                ];
                $guard = (string)($job['guard'] ?? 'host');

                $ok = is_string($body) && $body !== '' && $meta['code'] >= 200 && $meta['code'] < 300;
                if ($ok) {
                    $ok = $guard === 'media'
                        ? $this->media_ok($meta['url'] ?: $job['url'])
                        : $this->host_ok($meta['url'] ?: (string)$job['url']);
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($pending[$key]);

                $more = $onResult($job['tag'] ?? '', $ok ? $body : null, $meta);
                if ($more === true) {
                    $stop = true;
                } elseif (is_array($more)) {
                    foreach ($more as $next) $add($next);
                }
            }

            if ($stop || !$pending) break;
            if ($this->outOfTime()) break;
            if ($status === CURLM_OK && $running) curl_multi_select($mh, 0.2);
        }

        foreach ($pending as $slot) {
            curl_multi_remove_handle($mh, $slot['ch']);
            curl_close($slot['ch']);
        }
        curl_multi_close($mh);
    }

    /**
     * Guard for a *media* URL the provider just handed us (Range probe or
     * redirect target): https only in practice, no credentials in the URL,
     * and never loopback/private/link-local — otherwise a hostile provider
     * payload could aim our server at its own network.
     */
    private function media_ok(string $url): bool {
        $p = parse_url($url);
        if (!is_array($p)) return false;

        $scheme = strtolower((string)($p['scheme'] ?? ''));
        if ($scheme !== 'https' && $scheme !== 'http') return false;
        if (isset($p['user']) || isset($p['pass'])) return false;

        $host = strtolower((string)($p['host'] ?? ''));
        if ($host === '') return false;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                ResolverManager::log('blocked private-ip=' . $host);
                return false;
            }
            return true;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal') || str_ends_with($host, '.lan')) {
            ResolverManager::log('blocked host=' . $host);
            return false;
        }
        return true;
    }

    /**
     * Allowlist check for a URL we are about to request (or a redirect
     * target) — covers host, scheme and IP literals in one place.
     */
    private function host_ok(string $url): bool {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if (!ResolverManager::host_allowed($host)) {
            ResolverManager::log('blocked host=' . $host);
            return false;
        }
        return true;
    }

    /** UA + caller headers as a cURL header array. */
    private function headerList(array $headers): array {
        $hdrs = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'];
        foreach ($headers as $k => $v) $hdrs[] = is_int($k) ? $v : ($k . ': ' . $v);
        return $hdrs;
    }

    /** Shared cURL option set: bounded, http(s) only, redirects capped. */
    private function curlOptions(string $url, array $hdrs, int $timeout): array {
        return [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
    }

    /** Fetch a URL and json_decode it. */
    protected function fetchJson(string $url, array $headers = [], ?int $timeout = null): ?array {
        $body = $this->fetch($url, $headers, $timeout);
        if ($body === null) return null;
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** First regex capture of $pattern in $subject, or null. */
    protected function match(string $pattern, string $subject): ?string {
        if (preg_match($pattern, $subject, $m)) return $m[1] ?? null;
        return null;
    }

    /** Success result with every optional field defaulted. */
    protected function ok(string $type, string $url, array $extra = []): array {
        return array_merge([
            'success'    => true,
            'type'       => $type,
            'url'        => $url,
            'provider'   => $this->key(),
            'headers'    => [],
            'subtitles'  => [],
            'quality'    => [],
            'expires_at' => null,
        ], $extra);
    }

    /** Failure result. $code stays server-side; the client shows a generic line. */
    protected function fail(string $code): array {
        return [
            'success'    => false,
            'error'      => $code,
            'type'       => 'embed',
            'url'        => '',
            'provider'   => $this->key(),
            'headers'    => [],
            'subtitles'  => [],
            'quality'    => [],
            'expires_at' => null,
        ];
    }

    /**
     * Confirm a media URL actually serves media before we hand it to the
     * player: providers routinely return 200 JSON bodies that are really
     * upstream errors. Cheap — a 2KB window is all we read.
     */
    protected function verifyMedia(string $url, string $type, ?int $timeout = null): bool {
        $left = $this->remaining();
        if ($left < 0.4) return false;
        if (!$this->media_ok($url)) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)max(1, min($timeout ?? 8, ceil($left))),
            CURLOPT_CONNECTTIMEOUT => (int)max(1, min($timeout ?? 5, ceil($left))),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Range: bytes=0-2047',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $body === '' || $code < 200 || $code >= 300) return false;
        // An upstream failure dressed up as JSON.
        if (stripos($ct, 'application/json') !== false) return false;
        if ($type === 'hls') return strpos((string)$body, '#EXTM3U') === 0;
        return true;
    }

    /**
     * Normalize whatever subtitle payload a provider hands back into
     * [{url,label,lang}]. Unknown shapes collapse to an empty list.
     */
    protected function normalizeSubtitles($raw): array {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $i => $s) {
            if (is_string($s)) {
                $u = $s;
                $label = 'Subtitle ' . ((int)$i + 1);
                $lang = null;
            } elseif (is_array($s)) {
                $u = $s['url'] ?? $s['file'] ?? $s['src'] ?? null;
                if (!$u || !is_string($u)) continue;
                $label = $s['label'] ?? $s['display'] ?? $s['name'] ?? $s['language'] ?? ('Subtitle ' . ((int)$i + 1));
                $lang  = $s['language'] ?? $s['lang'] ?? null;
            } else {
                continue;
            }
            if (strpos($u, '//') !== 0 && stripos($u, 'http') !== 0) continue;
            $out[] = [
                'url'   => $u,
                'label' => (string)$label,
                'lang'  => $lang !== null ? (string)$lang : null,
            ];
        }
        return $out;
    }
}
