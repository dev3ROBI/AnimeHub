<?php
/**
 * Zokoanime (zokoanime.video) resolver — NHD's own upstream for anime.
 *
 * NHD's extraction API hands back embed URLs on this host (verified: its
 * `embedUrl` for One Piece is https://zokoanime.video/stream/ani/21/1/sub and
 * the m3u8 it returns matches the one decoded from this page), and the page
 * itself ships its whole player config in `window.__P`. The payload is just
 * base64 XOR'd with a fixed public key (the same algorithm their own player
 * core, zokoanime3.pages.dev/core/obfuscate.js, uses to mount JW) — decoding
 * it is ordinary local JSON parsing, no bypassing of anything.
 *
 * This is how DUB works on our Auto HD chip: NHD's API always answers with
 * the /sub sibling, but the /dub page on the very same upstream carries its
 * own distinct stream (verified: sub 09qmw7k6mi5nzd / dub ygbldzohdz5zs7).
 * The lang part of the path is what selects the language.
 */
class ZokoanimeResolver extends ResolverBase {

    /** Page fetch — the embed shell has to arrive before anything else. */
    private const PAGE_TIMEOUT = 6;

    /** Range probe against the extracted media: quick, so keep it tight. */
    private const VERIFY = 5;

    /** Fixed obfuscation key their player core ships in plain sight. */
    private const XOR_KEY = 'otaku-embed-v1';

    public function key(): string {
        return 'zokoanime';
    }

    public function supports(string $url): bool {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        if ($host !== 'zokoanime.video') return false;
        return (bool)preg_match('#^/stream/ani/\d+/\d+/(sub|dub)$#', $path);
    }

    public function resolve(string $url): array {
        $page = $this->fetch($url, ['Referer: https://zokoanime.video/'], self::PAGE_TIMEOUT);
        if ($page === null) return $this->fail('page-fetch-failed');

        $blob = $this->match('#window\.__P\s*=\s*"([^"]+)"#', $page);
        if (!$blob) return $this->fail('parse-failed');

        $data = $this->decode($blob);
        if ($data === null) return $this->fail('decode-failed');

        $src = $data['src'] ?? '';
        if (!is_string($src) || stripos($src, 'http') !== 0) return $this->fail('no-src');

        // No dash.js in this project — hand MPD-only results to the iframe.
        $type = (stripos($src, '.mp4') !== false) ? 'mp4' : 'hls';
        if (!$this->verifyMedia($src, $type, self::VERIFY)) return $this->fail('verify-failed');

        $subs = $this->normalizeSubtitles(
            is_array($data['subtitles'] ?? null) ? $data['subtitles'] : []
        );

        return $this->ok($type, $src, ['subtitles' => $subs]);
    }

    /** base64 + XOR → the decoded player-config JSON. */
    private function decode(string $blob): ?array {
        $bin = base64_decode($blob, true);
        if ($bin === false || $bin === '') return null;

        $key = self::XOR_KEY;
        $kl  = strlen($key);
        $len = strlen($bin);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($bin[$i]) ^ ord($key[$i % $kl]));
        }

        $json = json_decode($out, true);
        return is_array($json) ? $json : null;
    }
}
