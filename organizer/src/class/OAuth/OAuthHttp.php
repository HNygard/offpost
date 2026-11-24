<?php

require_once __DIR__ . '/OAuthError.php';

/**
 * Small HTTP helpers for the OAuth endpoints.
 */
class OAuthHttp {
    public static function json(int $status, array $body, bool $noStore = false): void {
        http_response_code($status);
        header('Content-Type: application/json');
        if ($noStore) {
            header('Cache-Control: no-store');
            header('Pragma: no-cache');
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** RFC 6749 section 5.2 error response. Never cached. */
    public static function error(OAuthError $e): void {
        self::json($e->httpStatus, [
            'error' => $e->error,
            'error_description' => $e->getMessage(),
        ], true);
    }

    /**
     * Client IP for rate limiting. CF-Connecting-IP is only trusted when the
     * direct peer is a private or loopback address (i.e. our own proxy);
     * otherwise any client could spoof it. The header must also be a single
     * valid IP address, so junk cannot end up in the rate limit table. An
     * invalid REMOTE_ADDR counts as non-private. An IPv4-mapped IPv6 peer
     * (::ffff:a.b.c.d) is treated as its IPv4 address.
     */
    public static function clientIp(array $server): string {
        $remote = self::unmapIpv4((string) ($server['REMOTE_ADDR'] ?? ''));
        $cf = $server['HTTP_CF_CONNECTING_IP'] ?? null;
        if (is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP) !== false && self::isPrivateOrLoopback($remote)) {
            return $cf;
        }
        return $remote;
    }

    /** "::ffff:a.b.c.d" becomes "a.b.c.d"; anything else is returned unchanged. */
    private static function unmapIpv4(string $ip): string {
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;
        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($packed, 12));
        }
        return $ip;
    }

    private static function isPrivateOrLoopback(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // Private ranges (10/8, 172.16/12, 192.168/16, fc00::/7) or loopback (127/8, ::1).
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            || self::isLoopback($ip);
    }

    private static function isLoopback(string $ip): bool {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }
        return $packed === inet_pton('::1');
    }
}
