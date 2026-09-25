<?php
/**
 * Registry + dispatcher for embed-provider resolvers.
 *
 * Everything funnels through resolve(): URL validation (allowlist, https,
 * no userinfo, no private address) → provider dispatch → per-resolver
 * timeout → short-lived cache → one normalized result for the client.
 *
 * There is deliberately no generic "fetch this URL" capability here: the
 * endpoint only ever answers for hosts listed in RESOLVER_ALLOWLIST, so it
 * can never be pointed at an arbitrary (or internal) address.
 */

include_once __DIR__ . '/../http.php';
include_once __DIR__ . '/../megaplay_relay_lib.php';
include_once __DIR__ . '/resolver_base.php';
include_once __DIR__ . '/providers/nhdapi_resolver.php';
include_once __DIR__ . '/providers/vidcore_resolver.php';
include_once __DIR__ . '/providers/zokoanime_resolver.php';
include_once __DIR__ . '/providers/megaplay_resolver.php';

class ResolverManager {

    /** host (lowercase) => resolver instance */
    private static array $resolvers = [];

    /** Per-request DNS cache for the private-address check. */
    private static array $resolvedIps = [];

    // ─── Registry ────────────────────────────────────────────────────

    private static function registry(): array {
        if (self::$resolvers) return self::$resolvers;

        $map = [
            'nhdapi'    => NhdapiResolver::class,
            'vidcore'   => VidcoreResolver::class,
            'zokoanime' => ZokoanimeResolver::class,
            'megaplay'  => MegaplayResolver::class,
        ];

        foreach (self::allowlist() as $host => $provider) {
            $class = $map[$provider] ?? null;
            if ($class && class_exists($class)) {
                self::$resolvers[$host] = new $class();
            }
        }
        return self::$resolvers;
    }

    private static function allowlist(): array {
        $list = $GLOBALS['RESOLVER_ALLOWLIST'] ?? [];
        return is_array($list) ? $list : [];
    }

    // ─── Validation ──────────────────────────────────────────────────

    /** Exact-match allowlist check, https only, no userinfo, no odd port. */
    public static function host_allowed(string $host): bool {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return false;
        return array_key_exists($host, self::allowlist());
    }

    /**
     * Full URL gate. Returns the lowercase host on success, null otherwise.
     * Private/loopback targets are rejected even if someone adds them to the
     * allowlist by mistake.
     */
    private static function validate_url(string $url): ?string {
        if (strlen($url) > 2000) return null;

        $parts = parse_url($url);
        if (!is_array($parts)) return null;
        if (strtolower($parts['scheme'] ?? '') !== 'https') return null;
        if (isset($parts['user']) || isset($parts['pass'])) return null;

        $port = $parts['port'] ?? 0;
        if ($port && $port !== 443) return null;

        $host = strtolower($parts['host'] ?? '');
        if (!self::host_allowed($host)) return null;
        if (self::host_is_private($host)) return null;

        return $host;
    }

    private static function host_is_private(string $host): bool {
        if (isset(self::$resolvedIps[$host])) return self::$resolvedIps[$host];

        $ip = gethostbyname($host);
        $private = true;
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            $private = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }
        return self::$resolvedIps[$host] = $private;
    }

    // ─── Logging (never tokens, keys or signed URLs) ─────────────────

    public static function log(string $msg): void {
        error_log('[resolver] ' . $msg);
    }

    // ─── Public entry ────────────────────────────────────────────────

    /**
     * @return array normalized result (success true/false)
     */
    public static function resolve(string $url, bool $useCache = true): array {
        if (!defined('RESOLVER_ENABLED') || !RESOLVER_ENABLED) {
            return self::static_fail('disabled');
        }

        $host = self::validate_url($url);
        if ($host === null) {
            self::log('reject url=' . self::safe_url($url));
            return self::static_fail('blocked');
        }

        $resolver = self::registry()[$host] ?? null;
        if (!$resolver || !$resolver->supports($url)) {
            return self::static_fail('unsupported');
        }

        $cacheKey = 'resolver:' . md5($url);
        if ($useCache) {
            $hit = api_cache_get($cacheKey);
            if (is_array($hit) && !empty($hit['success'])) {
                self::log('cache-hit provider=' . $resolver->key() . ' host=' . $host);
                return $hit;
            }
        }

        $started = (int)(microtime(true) * 1000);
        try {
            $resolver->begin();
            $result = $resolver->resolve($url);
        } catch (Throwable $e) {
            self::log('exception provider=' . $resolver->key() . ' ' . get_class($e));
            $result = self::static_fail('exception');
        }
        $elapsed = max(0, (int)(microtime(true) * 1000) - $started);

        if (!is_array($result) || !array_key_exists('success', $result)) {
            $result = self::static_fail('invalid-result');
        }

        $budget = (int)RESOLVER_TIMEOUT * 1000;
        if (!empty($result['success']) && $elapsed > $budget) {
            $result = self::static_fail('timeout');
        }

        self::log('provider=' . ($result['provider'] ?? $resolver->key())
            . ' host=' . $host
            . ' ok=' . (!empty($result['success']) ? 1 : 0)
            . ' err=' . ($result['error'] ?? '-')
            . ' ms=' . $elapsed);

        if (!empty($result['success']) && $useCache) {
            $ttl = (int)RESOLVER_CACHE_TTL;
            if (!empty($result['expires_at'])) {
                $ttl = min($ttl, max(1, (int)$result['expires_at'] - time()));
            }
            api_cache_set($cacheKey, 'resolver', $result, $ttl);
        }

        return $result;
    }

    /** Failure result shared by validation/dispatch problems. */
    private static function static_fail(string $code): array {
        return [
            'success'    => false,
            'error'      => $code,
            'type'       => 'embed',
            'url'        => '',
            'provider'   => '',
            'headers'    => [],
            'subtitles'  => [],
            'quality'    => [],
            'expires_at' => null,
        ];
    }

    /** Scheme + host only — safe to write to the log. */
    private static function safe_url(string $url): string {
        $p = parse_url($url);
        if (!is_array($p)) return '(unparseable)';
        return ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . ($p['path'] ?? '');
    }
}
