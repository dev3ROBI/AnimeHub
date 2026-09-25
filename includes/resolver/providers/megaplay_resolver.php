<?php
/**
 * MegaPlay resolver.
 *
 * MegaPlay serves a JW page (/stream/ani/{anilist}/{ep}/{lang}); its own
 * player then calls /stream/getSources?id=<data-id> (AJAX-only) and
 * decrypts the `enc` payload with an AES-256-CBC key its shipped
 * e1-player.js carries in the clear (decryption is plain local crypto on a
 * payload the provider hands to every browser — verified against a real
 * payload: {"file":"https://…/master.m3u8"}).
 *
 * The catch: that CDN only answers with `Referer: https://megaplay.buzz/`,
 * which no browser on our origin can send. So the URL handed to the client
 * is never the raw CDN link — it is a signed relay URL
 * (includes/megaplay_relay.php) that attaches the referer upstream and
 * re-verifies signature + host/path allowlist on every request. The relay
 * also serves the subtitle tracks (same gated host family).
 */
class MegaplayResolver extends ResolverBase {

    /** Page fetch, then the AJAX API — both on megaplay.buzz itself. */
    private const PAGE_TIMEOUT = 6;
    private const API_TIMEOUT  = 6;

    /** Range probe against the extracted media (mp4) — tight. */
    private const VERIFY = 5;

    /** AES-256-CBC key/IV their player builds with TextEncoder (zero-padded). */
    private const ENC_KEY = 'i?LMTAx0Q6,:}50U';
    private const ENC_IV  = "W0;27ToaUpl_P%'c";

    public function key(): string {
        return 'megaplay';
    }

    public function supports(string $url): bool {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        if ($host !== 'megaplay.buzz') return false;
        return (bool)preg_match('#^/stream/(ani|mal)/[\d.]+/\d+/(sub|dub)$#', $path);
    }

    public function resolve(string $url): array {
        $page = $this->fetch($url, ['Referer: https://megaplay.buzz/'], self::PAGE_TIMEOUT);
        if ($page === null) return $this->fail('page-fetch-failed');

        // The JW mount carries the id its player will query (attributes may
        // wrap across lines, so pull the whole tag first, then the id):
        // <div class="fix-area" id="megaplay-player" data-id="36396" …>
        $tag = $this->match('#(<div\b[^>]*\bid="megaplay-player"[^>]*>)#', $page);
        if (!$tag) return $this->fail('parse-failed');
        $id = $this->match('#\bdata-id="(\d+)"#', $tag);
        if (!$id) return $this->fail('no-data-id');

        $api = $this->fetchJson(
            'https://megaplay.buzz/stream/getSources?id=' . rawurlencode($id),
            [
                'Referer: https://megaplay.buzz/',
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json',
            ],
            self::API_TIMEOUT
        );
        if ($api === null) return $this->fail('api-fetch-failed');

        $file = $this->extractFile($api);
        if ($file === null) return $this->fail('no-sources');
        if (!mp_cdn_url_ok($file)) return $this->fail('cdn-not-allowlisted');

        $path   = (string)(parse_url($file, PHP_URL_PATH) ?: '');
        $type   = (bool)preg_match('#\.mp4(?:$|\?)#i', $path) ? 'mp4' : 'hls';
        if (!$this->verifyUpstream($file, $type)) return $this->fail('verify-failed');

        $relay = mp_relay_sign($file);
        if ($relay === null) return $this->fail('sign-failed');

        // Subtitle tracks sit on the same gated CDN family — relay them too.
        $subs = [];
        foreach ($this->normalizeSubtitles(is_array($api['tracks'] ?? null) ? $api['tracks'] : []) as $s) {
            $signed = mp_relay_sign($s['url']);
            if ($signed !== null) {
                $s['url'] = $signed;
                $subs[] = $s;
            }
        }

        return $this->ok($type, $relay, [
            'subtitles'  => $subs,
            'expires_at' => time() + (int)MEGAPLAY_RELAY_TTL,
        ]);
    }

    /** Pull the playable file URL out of a getSources response. */
    private function extractFile(array $api): ?string {
        $enc = $api['enc'] ?? null;
        if (is_string($enc) && $enc !== '') {
            return $this->decrypt($enc);
        }
        $src = $api['sources'] ?? null;
        if (is_string($src)) return stripos($src, 'http') === 0 ? $src : null;
        if (is_array($src)) {
            foreach ($src as $s) {
                $u = is_array($s) ? ($s['file'] ?? $s['src'] ?? null) : $s;
                if (is_string($u) && stripos($u, 'http') === 0) return $u;
            }
        }
        return null;
    }

    /** base64url → AES-256-CBC (PKCS7) → {"file":…} (or a bare URL). */
    private function decrypt(string $enc): ?string {
        $b64 = strtr($enc, '-_', '+/');
        if (strlen($b64) % 4) $b64 .= str_repeat('=', 4 - strlen($b64) % 4);
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') return null;

        $pt = openssl_decrypt($raw, 'AES-256-CBC', str_pad(self::ENC_KEY, 32, "\0"), OPENSSL_RAW_DATA, self::ENC_IV);
        if ($pt === false || $pt === '') return null;

        $j = json_decode($pt, true);
        if (is_array($j) && is_string($j['file'] ?? null) && stripos($j['file'], 'http') === 0) {
            return $j['file'];
        }
        $pt = trim($pt);
        return stripos($pt, 'http') === 0 ? $pt : null;
    }

    /**
     * Probe the CDN directly — same idea as ResolverBase::verifyMedia, but
     * with the Referer this CDN demands (the base helper sends none and
     * would always 403 here). Host+path still go through the CDN allowlist.
     */
    private function verifyUpstream(string $url, string $type): bool {
        if ($this->outOfTime() || !mp_cdn_url_ok($url)) return false;

        $left = $this->remaining();
        $hdrs = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Referer: https://megaplay.buzz/',
        ];
        if ($type === 'mp4') $hdrs[] = 'Range: bytes=0-2047';   // playlists are tiny; read them whole

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)max(1, min(self::VERIFY, ceil($left))),
            CURLOPT_CONNECTTIMEOUT => (int)max(1, min(4, ceil($left))),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $body === '' || $code < 200 || $code >= 300) return false;
        if (stripos($ct, 'application/json') !== false) return false;
        if ($type === 'hls') return str_starts_with((string)$body, '#EXTM3U');
        return true;
    }
}
