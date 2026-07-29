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
