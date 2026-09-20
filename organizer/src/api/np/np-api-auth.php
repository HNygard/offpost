<?php
// organizer/src/api/np/np-api-auth.php
// Shared-secret auth for the norske-postlister.no server-to-server API.
// Not session based: requireAuth() 302-redirects to OIDC, which breaks API clients.

function npApiGetToken(): string {
    $file = getenv('NP_API_TOKEN_FILE');
    if ($file === false || $file === '') {
        $file = '/run/secrets/np_api_token';
    }
    if (!file_exists($file)) {
        return '';
    }
    return trim(file_get_contents($file));
}

function npApiCheckToken(?string $providedToken): bool {
    $expected = npApiGetToken();
    if ($expected === '' || $providedToken === null || $providedToken === '') {
        return false;
    }
    return hash_equals($expected, $providedToken);
}

function npApiRequireToken(): void {
    $provided = $_SERVER['HTTP_X_NP_API_TOKEN'] ?? null;
    if (!npApiCheckToken($provided)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or missing X-Np-Api-Token']);
        exit;
    }
}

// Header value the norske-postlister `offpost-email` downloader sends on every
// write request it makes with an admin session instead of the token.
const NP_API_OFFPOST_EMAIL_REQUESTED_WITH = 'offpost-email';

// Is the current request from an authenticated offpost admin session?
function npApiIsAdminSession(): bool {
    global $environment, $admins;
    require_once __DIR__ . '/../../auth.php';
    require_once __DIR__ . '/../../username-password.php';
    return isAuthenticated() && isset($_SESSION['user']['sub'])
        && is_array($admins) && in_array($_SESSION['user']['sub'], $admins);
}

// Does the request carry `X-Requested-With: offpost-email`? A browser cannot
// attach a custom header to a form post or a simple cross-site request, so a
// session-authed write endpoint gated on this header is not reachable by CSRF.
function npApiIsOffpostEmailRequest(array $server): bool {
    return isset($server['HTTP_X_REQUESTED_WITH'])
        && $server['HTTP_X_REQUESTED_WITH'] === NP_API_OFFPOST_EMAIL_REQUESTED_WITH;
}

function npApiDenyUnauthorized(): void {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing X-Np-Api-Token']);
    exit;
}

// GET endpoints only: accept a valid NP token OR an authenticated offpost admin
// session (browser convenience for inspecting the API). POST endpoints must not
// use this - a session-authed write endpoint would be open to CSRF; they use
// npApiRequireTokenOrOffpostEmailAdminSession() below instead.
function npApiRequireTokenOrAdminSession(): void {
    $provided = $_SERVER['HTTP_X_NP_API_TOKEN'] ?? null;
    if (npApiCheckToken($provided)) {
        return;
    }
    if (npApiIsAdminSession()) {
        return;
    }
    npApiDenyUnauthorized();
}

// POST endpoints: accept a valid NP token, OR an admin session when the request
// also carries `X-Requested-With: offpost-email`. The header is what keeps a
// browser form post from ever triggering the write (see
// npApiIsOffpostEmailRequest()).
function npApiRequireTokenOrOffpostEmailAdminSession(): void {
    $provided = $_SERVER['HTTP_X_NP_API_TOKEN'] ?? null;
    if (npApiCheckToken($provided)) {
        return;
    }
    if (npApiIsOffpostEmailRequest($_SERVER) && npApiIsAdminSession()) {
        return;
    }
    npApiDenyUnauthorized();
}
