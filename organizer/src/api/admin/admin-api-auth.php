<?php
// organizer/src/api/admin/admin-api-auth.php
// Shared-secret auth for the offpost admin thread-export API. A separate token
// from organizer/src/api/np/np-api-auth.php's NP token: this API can read
// every thread (not just norske-postlister.no ones), so the NP token must
// never work here - each token is checked against its own secret file only.

require_once __DIR__ . '/../np/np-api-auth.php'; // reuses npApiIsAdminSession()

function adminApiGetToken(): string {
    $file = getenv('ADMIN_API_TOKEN_FILE');
    if ($file === false || $file === '') {
        $file = '/run/secrets/admin_api_token';
    }
    if (!file_exists($file)) {
        return '';
    }
    return trim(file_get_contents($file));
}

function adminApiCheckToken(?string $providedToken): bool {
    $expected = adminApiGetToken();
    // Empty expected token (missing file, empty file, or whitespace-only file,
    // which trims to '') must reject every provided value, including an empty one.
    if ($expected === '' || $providedToken === null || $providedToken === '') {
        return false;
    }
    return hash_equals($expected, $providedToken);
}

// GET endpoints only: accept a valid admin token OR an authenticated offpost
// admin session (lets the admin page link straight to a download without
// exposing the token in a URL). Returns which method succeeded ('token' or
// 'session:<sub>') so the caller can log it; denies with a 401 JSON body and
// exit()s otherwise - callers must invoke this before parsing any other input
// or touching the database.
function adminApiRequireTokenOrAdminSession(): string {
    $provided = $_SERVER['HTTP_X_ADMIN_API_TOKEN'] ?? null;
    if (adminApiCheckToken($provided)) {
        return 'token';
    }
    if (npApiIsAdminSession()) {
        return 'session:' . ($_SESSION['user']['sub'] ?? '');
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing X-Admin-Api-Token']);
    exit;
}

// POST endpoints only (the analysis queue API): token only, no admin-session
// fallback - these are POST, so no admin session cookie can be relied on to
// resist cross-site requests the way it can for a GET a link merely loads.
// Returns 'token' on success; denies with a 401 JSON body and exit()s
// otherwise - callers must invoke this before parsing any other input or
// touching the database.
function adminApiRequireToken(): string {
    $provided = $_SERVER['HTTP_X_ADMIN_API_TOKEN'] ?? null;
    if (adminApiCheckToken($provided)) {
        return 'token';
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing X-Admin-Api-Token']);
    exit;
}
