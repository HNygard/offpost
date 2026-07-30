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

// GET endpoints only: accept a valid NP token OR an authenticated offpost admin
// session (browser convenience for inspecting the API). POST endpoints must stay
// token-only - a session-authed write endpoint would be open to CSRF.
function npApiRequireTokenOrAdminSession(): void {
    $provided = $_SERVER['HTTP_X_NP_API_TOKEN'] ?? null;
    if (npApiCheckToken($provided)) {
        return;
    }
    global $environment, $admins;
    require_once __DIR__ . '/../../auth.php';
    require_once __DIR__ . '/../../username-password.php';
    if (isAuthenticated() && isset($_SESSION['user']['sub'])
        && is_array($admins) && in_array($_SESSION['user']['sub'], $admins)) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing X-Np-Api-Token']);
    exit;
}
