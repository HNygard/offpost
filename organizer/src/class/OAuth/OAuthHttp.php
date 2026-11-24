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
     * otherwise any client could spoof it. An invalid REMOTE_ADDR counts as
     * non-private.
     */
    public static function clientIp(array $server): string {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $cf = $server['HTTP_CF_CONNECTING_IP'] ?? null;
        if ($cf !== null && $cf !== '' && self::isPrivateOrLoopback($remote)) {
            return (string) $cf;
        }
        return $remote;
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
