<?php
/**
 * 8Stream relay — signing half.
 *
 * 8Stream's master playlist only answers requests that carry the provider
 * player's own `Referer`/`Origin`, and the URL token embeds the *requesting*
 * IP. A browser on our watch page can send neither, so the bytes have to come
 * through our own origin (includes/eightstream_relay.php), which attaches the
 * headers and — because our server is the one that asked for the token — is
 * already the right IP.
 *
 * This file is the security half of that arrangement:
 *   - es_cdn_*      which URLs a relay target may ever be (signed + https +
 *                   public host + a media-shaped path)
 *   - es_relay_*    HMAC signing / verification of relay URLs (proof that the
 *                   resolver — the only signer — vetted the URL)
 *
 * The media hosts rotate freely (i-arch-400.hunts439kow.com, …), so the gate is
 * "provider-signed + public + media path", never a hard-coded host list.
 *
 * Used by:
 *   includes/eightstream_api.php    (sign)
 *   includes/eightstream_relay.php  (verify + proxy)
 */

include_once __DIR__ . '/megaplay_relay_lib.php';   // mp_cdn_host_ok, mp_url_resolve

if (!defined('EIGHTSTREAM_RELAY_TTL')) define('EIGHTSTREAM_RELAY_TTL', 21600); // 6h

/** Media object paths the relay will ever fetch. */
function es_cdn_path_ok($path) {
    $path = (string)$path;
    if ($path === '') return false;
    if (stripos($path, '/stream2/') !== false) return true;
    return (bool)preg_match('#\.(m3u8|ts|m4s|mp4|key|vtt|aac|m4a|jpg|jpeg|png|webp|bin)(?:$|\?)#i', $path);
}

/** https + public host + media-shaped path. */
function es_cdn_url_ok($url) {
    if (!is_string($url) || $url === '' || strlen($url) > 4096) return false;
    $p = parse_url($url);
    if (!is_array($p)) return false;
    if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (!mp_cdn_host_ok((string)($p['host'] ?? ''))) return false;
    return es_cdn_path_ok((string)($p['path'] ?? ''));
}

/** The upstream Referer/Origin we must send must be a plain public https origin. */
function es_referer_ok($referer) {
    if (!is_string($referer) || $referer === '' || strlen($referer) > 300) return false;
    $p = parse_url($referer);
    if (!is_array($p)) return false;
    if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    return mp_cdn_host_ok((string)($p['host'] ?? ''));
}

/** Signing secret — lives in config.local.php (gitignored). */
function es_relay_key() {
    if (defined('EIGHTSTREAM_RELAY_KEY') && (string)EIGHTSTREAM_RELAY_KEY !== '') {
        return (string)EIGHTSTREAM_RELAY_KEY;
    }
    // Fallback keeps the endpoint working without config.local.php; it only
    // obfuscates, the es_cdn_* gate is the real protection.
    $parts = ['es-relay'];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $c) {
        $parts[] = defined($c) ? (string)constant($c) : '';
    }
    return hash('sha256', implode('|', $parts));
}

/** Directory of the calling script (resolver and relay both live in includes/). */
function es_relay_endpoint() {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = $script !== '' ? rtrim(dirname($script), '/\\') : '';
    return $dir . '/eightstream_relay.php';
}

/** base64url without padding. */
function es_b64($s) {
    return rtrim(strtr(base64_encode((string)$s), '+/', '-_'), '=');
}

/** base64url decode (strict). */
function es_unb64($s) {
    $b64 = strtr((string)$s, '-_', '+/');
    if (strlen($b64) % 4) $b64 .= str_repeat('=', 4 - strlen($b64) % 4);
    $out = base64_decode($b64, true);
    return ($out === false || $out === '') ? null : $out;
}

/** Sign an allowlisted media URL (with its required referer) into a relay URL. */
function es_relay_sign($url, $referer) {
    if (!es_cdn_url_ok($url)) return null;
    if (!es_referer_ok($referer)) return null;

    $p = es_b64($url);
    $r = es_b64($referer);
    $e = time() + (int)EIGHTSTREAM_RELAY_TTL;
    $s = substr(hash_hmac('sha256', $p . '|' . $r . '|' . $e, es_relay_key()), 0, 32);

    return es_relay_endpoint() . '?p=' . $p . '&r=' . $r . '&e=' . $e . '&s=' . $s;
}

/**
 * Verify a relay URL's signature + expiry and decode it back to
 * ['url' => …, 'referer' => …]. Returns null when anything is off — the
 * endpoint then answers 403 without a single upstream byte.
 */
function es_relay_unsign($p, $r, $e, $s) {
    if ($p === '' || $r === '' || $e === '' || $s === '') return null;
    if (!preg_match('/^\d{1,20}$/', (string)$e)) return null;

    $exp = (int)$e;
    if ($exp < time() || $exp > time() + (int)EIGHTSTREAM_RELAY_TTL + 60) return null;

    $want = substr(hash_hmac('sha256', $p . '|' . $r . '|' . $e, es_relay_key()), 0, 32);
    if (!hash_equals($want, $s)) return null;

    $url = es_unb64($p);
    $referer = es_unb64($r);
    if ($url === null || $referer === null) return null;
    if (!es_cdn_url_ok($url)) return null;
    if (!es_referer_ok($referer)) return null;

    return ['url' => $url, 'referer' => $referer];
}
