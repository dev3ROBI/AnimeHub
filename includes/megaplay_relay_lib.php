<?php
/**
 * Shared helper for the MegaPlay CDN relay (sign half + allowlist).
 *
 * MegaPlay's media CDN only answers requests carrying `Referer:
 * https://megaplay.buzz/`. A browser on our watch page can never send that
 * header, so the bytes have to come through our own server, which attaches
 * the referer upstream. This file is the security half of that arrangement:
 *
 *   - mp_cdn_*      which URLs a relay target may ever be (signed, https,
 *                   public host, content-shaped path — the same bar the
 *                   resolver already applies to provider media URLs)
 *   - mp_relay_*    HMAC signing / verification of relay URLs (12h TTL)
 *   - mp_url_resolve RFC3986-style join for playlist-relative references
 *
 * The CDN rotates hosts freely (nexabloom, mikora, qeltrix, hiddenvertex,
 * lunarfrontier, …), so the gate is "provider-signed + public + content
 * path", never a hard-coded host list.
 *
 * Used by:
 *   includes/resolver/providers/megaplay_resolver.php   (sign)
 *   includes/megaplay_relay.php                         (verify + proxy)
 */

if (!defined('MEGAPLAY_RELAY_TTL')) define('MEGAPLAY_RELAY_TTL', 43200); // 12h

/**
 * A relay target host must be a public, non-reserved name (or address):
 * https alone is not enough — the provider could hand us an internal URL,
 * and DNS must not point at one either.
 */
function mp_cdn_host_ok(string $host): bool {
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || strlen($host) > 253) return false;

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host) !== 1) {
        return false;
    }
    if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.lan')) {
        return false;
    }

    static $dns = [];
    if (isset($dns[$host])) return $dns[$host];
    $ip = gethostbyname($host);
    $ok = $ip !== $host
        && filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    return $dns[$host] = $ok;
}

/**
 * Media object paths: /anime/{32hex}/{32hex}/… (master playlists, subtitles,
 * keys) or /{32hex}/{32hex}/… (the mikora.top shape). Anything else — admin
 * paths, unrelated files — is refused even on an allowed host.
 */function mp_cdn_path_ok(string $path): bool {
    return (bool)preg_match('#^/(?:anime/)?[a-f0-9]{32}/[a-f0-9]{32}(?:/|$)#i', $path);
}

function mp_cdn_url_ok(string $url): bool {
    if (strlen($url) > 2048) return false;
    $p = parse_url($url);
    if (!is_array($p)) return false;
    if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (!mp_cdn_host_ok((string)($p['host'] ?? ''))) return false;
    return mp_cdn_path_ok((string)($p['path'] ?? ''));
}

/** Signing secret — lives in config.local.php (gitignored). */
function mp_relay_key(): string {
    if (defined('MEGAPLAY_RELAY_KEY') && (string)MEGAPLAY_RELAY_KEY !== '') {
        return (string)MEGAPLAY_RELAY_KEY;
    }
    // Fallback keeps the endpoint working without config.local.php; it only
    // obfuscates, the mp_cdn_* allowlist is the real gate.
    $parts = ['mp-relay'];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $c) {
        $parts[] = defined($c) ? (string)constant($c) : '';
    }
    return hash('sha256', implode('|', $parts));
}

/** Directory of the calling script (resolver and relay both live in includes/). */
function mp_relay_endpoint(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = $script !== '' ? rtrim(dirname($script), '/\\') : '';
    return $dir . '/megaplay_relay.php';
}

/** Sign an allowlisted CDN URL into a relay URL on our own origin. */
function mp_relay_sign(string $url): ?string {
    if (!mp_cdn_url_ok($url)) return null;
    $p = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $e = time() + (int)MEGAPLAY_RELAY_TTL;
    $s = substr(hash_hmac('sha256', $p . '|' . $e, mp_relay_key()), 0, 32);
    return mp_relay_endpoint() . '?p=' . $p . '&e=' . $e . '&s=' . $s;
}

/**
 * Verify a relay URL's signature + expiry and decode it back to the CDN
 * target. Returns the target URL, or null when anything is off — the
 * endpoint then answers 403 without a single upstream byte.
 */
function mp_relay_unsign(string $p, string $e, string $s): ?string {
    if ($p === '' || $e === '' || $s === '') return null;
    if (!preg_match('/^\d{1,20}$/', $e)) return null;
    $exp = (int)$e;
    if ($exp < time() || $exp > time() + (int)MEGAPLAY_RELAY_TTL + 60) return null;

    $want = substr(hash_hmac('sha256', $p . '|' . $e, mp_relay_key()), 0, 32);
    if (!hash_equals($want, $s)) return null;

    $b64 = strtr($p, '-_', '+/');
    if (strlen($b64) % 4) $b64 .= str_repeat('=', 4 - strlen($b64) % 4);
    $url = base64_decode($b64, true);
    if ($url === false || $url === '') return null;
    if (!mp_cdn_url_ok($url)) return null;
    return $url;
}

/**
 * Join a (possibly relative) playlist reference against the playlist's own
 * URL — segments, variant playlists, EXT-X-KEY/EXT-X-MAP URIs.
 */
function mp_url_resolve(string $base, string $ref): ?string {
    $ref = trim($ref);
    if ($ref === '') return null;
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref)) return $ref;   // absolute with scheme

    $bp = parse_url($base);
    if (!is_array($bp) || empty($bp['host'])) return null;
    $scheme = strtolower((string)($bp['scheme'] ?? 'https'));
    $authority = $scheme . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');

    if (str_starts_with($ref, '//')) return $scheme . ':' . $ref;

    $frag = '';
    $pos = strcspn($ref, '#');
    if ($pos < strlen($ref)) { $frag = substr($ref, $pos); $ref = substr($ref, 0, $pos); }
    $query = '';
    $pos = strcspn($ref, '?');
    if ($pos < strlen($ref)) { $query = substr($ref, $pos); $ref = substr($ref, 0, $pos); }

    if (str_starts_with($ref, '/')) {
        $path = $ref;
    } else {
        $dir = (string)($bp['path'] ?? '/');
        $slash = strrpos($dir, '/');
        $dir = $slash === false ? '/' : substr($dir, 0, $slash + 1);
        $path = $dir . $ref;
    }

    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    return $authority . '/' . implode('/', $out) . $query . $frag;
}
