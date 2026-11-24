<?php
/**
 * Session start with hardened cookie flags.
 *
 * Production runs behind a Cloudflare Tunnel to plain http, so the origin request is not
 * https; the Secure flag is therefore driven by $environment, not by $_SERVER['HTTPS'].
 */

/**
 * @return array{lifetime: int, path: string, httponly: bool, samesite: string, secure: bool}
 */
function offpostSessionCookieParams(?string $environment): array {
    return [
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $environment === 'production',
    ];
}

function offpostStartSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    if (!headers_sent()) {
        // Never adopt a session id the browser sends that the server did not create (fixation).
        ini_set('session.use_strict_mode', '1');
        // $environment is set by username-password.php, which index.php requires before routing.
        session_set_cookie_params(offpostSessionCookieParams($GLOBALS['environment'] ?? null));
    }
    session_start();
}
