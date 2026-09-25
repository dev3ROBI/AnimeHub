<?php
/**
 * Signed media relay — the generic half of the MegaPlay relay arrangement.
 *
 * Some provider CDNs answer their own embed origin with CORS headers but
 * 302 their variant/segment URLs onto hosts that send none (VidZen's
 * `*.workers.dev` targets, for one), so the browser blocks every hls.js
 * fetch while a server-side probe happily succeeds. Those URLs have to be
 * fetched through our origin, exactly like MegaPlay's referer-gated CDN.
 *
 *   - mr_media_*   which URLs a relay target may ever be (signed + https +
 *                  public host + an allowlisted media host)
 *   - mr_relay_*   HMAC signing / verification of relay URLs (6h TTL)
 *
 * The gate is host-based and small on purpose: only hosts known to break
 * CORS (or to sit behind them) may be relayed, so this endpoint can never
 * be used as a general proxy.
 *
 * Used by:
 *   includes/resolver/providers/vidcore_resolver.php   (sign)
 *   includes/media_relay.php                          (verify + proxy)
 */

include_once __DIR__ . '/megaplay_relay_lib.php';   // mp_cdn_host_ok, mp_url_resolve

if (!defined('MEDIA_RELAY_TTL')) define('MEDIA_RELAY_TTL', 21600); // 6h

/**
 * Hosts whose media the browser cannot fetch directly (CORS-dead redirect
 * targets included) — matched exactly or as a parent-domain suffix.
 */
function mr_media_host_ok(string $host): bool {
    $host = strtolower(rtrim($host, '.'));
    if ($host === '') return false;

    // Only hosts the browser genuinely cannot fetch on its own. Healthy
    // CORS-clean CDNs (finepulfe, cdn.dlproxy segments, …) stay direct —
    // relaying them would burn our bandwidth for nothing. api.dlproxy.com
    // is on the list for its AES key endpoint: it answers 403 "origin not
    // allowed" to every browser origin except its own player's, and only
    // a server-side fetch carrying that origin can redeem the key.
    static $exact = ['vidzen.fun', 'api.dlproxy.com'];
    if (in_array($host, $exact, true)) return true;

    // VidZen streams land on random *.workers.dev subdomains.
    if (str_ends_with($host, '.workers.dev')) return true;

    return false;
}

function mr_media_url_ok(string $url): bool {
    if (strlen($url) > 4096) return false;
    $p = parse_url($url);
    if (!is_array($p)) return false;
    if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (!mp_cdn_host_ok((string)($p['host'] ?? ''))) return false;   // public + not reserved
    return mr_media_host_ok((string)($p['host'] ?? ''));
}

/** Signing secret — same material as the MegaPlay relay (config.local.php). */
function mr_relay_key(): string {
    if (function_exists('mp_relay_key')) return mp_relay_key();
    return hash('sha256', 'media-relay');
}

/** Directory of the calling script (resolvers run under includes/). */
function mr_relay_endpoint(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = $script !== '' ? rtrim(dirname($script), '/\\') : '';
    return $dir . '/media_relay.php';
}

/** Sign an allowlisted media URL into a relay URL on our own origin. */
function mr_relay_sign(string $url): ?string {
    if (!mr_media_url_ok($url)) return null;
    $p = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $e = time() + (int)MEDIA_RELAY_TTL;
    $s = substr(hash_hmac('sha256', $p . '|' . $e, mr_relay_key()), 0, 32);
    return mr_relay_endpoint() . '?p=' . $p . '&e=' . $e . '&s=' . $s;
}

/**
 * Verify a relay URL's signature + expiry and decode it back to the media
 * target. Returns the target URL, or null when anything is off — the
 * endpoint then answers 403 without a single upstream byte.
 */
function mr_relay_unsign(string $p, string $e, string $s): ?string {
    if ($p === '' || $e === '' || $s === '') return null;
    if (!preg_match('/^\d{1,20}$/', $e)) return null;
    $exp = (int)$e;
    if ($exp < time() || $exp > time() + (int)MEDIA_RELAY_TTL + 60) return null;

    $want = substr(hash_hmac('sha256', $p . '|' . $e, mr_relay_key()), 0, 32);
    if (!hash_equals($want, $s)) return null;

    $b64 = strtr($p, '-_', '+/');
    if (strlen($b64) % 4) $b64 .= str_repeat('=', 4 - strlen($b64) % 4);
    $url = base64_decode($b64, true);
    if ($url === false || $url === '') return null;
    if (!mr_media_url_ok($url)) return null;
    return $url;
}
