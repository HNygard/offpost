<?php
// OAuth 2.0 Dynamic Client Registration (RFC 7591). Public: no session, no auth.php, JSON only.
try {
    require_once __DIR__ . '/../../username-password.php';
    require_once __DIR__ . '/../../mcp-config.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthHttp.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthClientRegistry.php';

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new OAuthError('invalid_request', 'Use POST with a JSON body', 405);
        }

        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $metadata = $raw === false || strlen($raw) > 65536 ? null : json_decode($raw, true);
        // Must be a JSON object ({} decodes to []).
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new OAuthError('invalid_client_metadata', 'Body must be a JSON object of at most 64 KB');
        }

        $registry = new OAuthClientRegistry($mcp_redirect_host_allowlist);
        $response = $registry->register($metadata, OAuthHttp::clientIp($_SERVER));
        OAuthHttp::json(201, $response, true);
    } catch (OAuthError $e) {
        OAuthHttp::error($e);
    }
} catch (Throwable $e) {
    error_log('OAuth register endpoint error: ' . $e);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'server_error', 'error_description' => 'Internal error']);
}
