<?php
// OAuth 2.1 authorization endpoint with the user consent page. Needs an Offpost web session.
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../username-password.php';
require_once __DIR__ . '/../../mcp-config.php';
require_once __DIR__ . '/../../class/OAuth/OAuthAuthorizationService.php';

const OAUTH_PENDING_TTL_SECONDS = 600;

function oauthPlainErrorPage(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Authorization error</title></head><body><h1>Authorization error</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}

try {
    // Never framed, cached or leaked through Referer (the URL carries the request parameters).
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');

    $registry = new OAuthClientRegistry($mcp_redirect_host_allowlist);
    $service = new OAuthAuthorizationService($mcp_issuer, $mcp_resource, $registry);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // The pending request lives in the session, so an unauthenticated POST cannot be valid.
        if (!isAuthenticated()) {
            oauthPlainErrorPage(400, 'Your session has expired. Start the connection again from the app.');
            return;
        }
        $pendingId = $_POST['pending_id'] ?? null;
        $csrf = $_POST['csrf'] ?? null;
        $decision = $_POST['decision'] ?? null;
        $sessionCsrf = $_SESSION['oauth_csrf'] ?? null;
        if (!is_string($csrf) || !is_string($sessionCsrf) || !hash_equals($sessionCsrf, $csrf)) {
            oauthPlainErrorPage(400, 'Invalid request (CSRF check failed). Start the connection again from the app.');
            return;
        }
        if (!is_string($pendingId) || !isset($_SESSION['oauth_pending'][$pendingId])) {
            oauthPlainErrorPage(400, 'This authorization request is unknown or already used. Start the connection again from the app.');
            return;
        }
        // Single use: removed before acting, so a replay or double submit finds nothing.
        $pending = $_SESSION['oauth_pending'][$pendingId];
        unset($_SESSION['oauth_pending'][$pendingId]);
        if ($pending['expires'] < time()) {
            oauthPlainErrorPage(400, 'This authorization request has expired. Start the connection again from the app.');
            return;
        }
        if ($decision !== 'approve' && $decision !== 'deny') {
            oauthPlainErrorPage(400, 'Invalid request: decision must be approve or deny.');
            return;
        }

        if ($decision === 'approve') {
            $code = $service->createCode($pending, $_SESSION['user']['sub']);
            $params = ['code' => $code];
        } else {
            $params = ['error' => 'access_denied'];
        }
        if ($pending['state'] !== null) {
            $params['state'] = $pending['state'];
        }
        header('Location: ' . $service->redirectUrl($pending['redirect_uri'], $params));
        http_response_code(302);
        return;
    }

    // GET. Client and redirect URI are validated first and never redirected on failure;
    // the other errors go to the client; only then is a login required.
    try {
        $validated = $service->validate($_GET);
    } catch (OAuthRedirectError $e) {
        $params = ['error' => $e->error, 'error_description' => $e->getMessage()];
        if ($e->state !== null) {
            $params['state'] = $e->state;
        }
        header('Location: ' . $service->redirectUrl($e->redirectUri, $params));
        http_response_code(302);
        return;
    } catch (OAuthError $e) {
        oauthPlainErrorPage(400, $e->getMessage());
        return;
    }

    requireAuth();

    $_SESSION['oauth_csrf'] ??= bin2hex(random_bytes(32));
    // Drop expired pending requests, then store this one under an unguessable id.
    foreach ($_SESSION['oauth_pending'] ?? [] as $id => $other) {
        if ($other['expires'] < time()) {
            unset($_SESSION['oauth_pending'][$id]);
        }
    }
    $pendingId = bin2hex(random_bytes(16));
    // Only what the page and the code need: not the client's secret hash.
    $validated['client'] = ['client_id' => $validated['client']['client_id'], 'client_name' => $validated['client']['client_name']];
    $_SESSION['oauth_pending'][$pendingId] = $validated + ['expires' => time() + OAUTH_PENDING_TTL_SECONDS];

    $user = $_SESSION['user'];
    $clientName = $validated['client']['client_name'] ?? 'An application';
    $redirectHost = parse_url($validated['redirect_uri'], PHP_URL_HOST) ?: $validated['redirect_uri'];
    $userDisplay = $user['email'] ?? $user['name'] ?? $user['sub'];
    $csrf = $_SESSION['oauth_csrf'];
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/consent-page.php';
} catch (Throwable $e) {
    error_log('OAuth authorize endpoint error: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    oauthPlainErrorPage(500, 'Internal error');
}
