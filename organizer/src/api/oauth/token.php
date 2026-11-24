<?php
// OAuth 2.1 token endpoint: authorization code (+ PKCE) for an access token. No session, JSON only.
try {
    require_once __DIR__ . '/../../username-password.php';
    require_once __DIR__ . '/../../mcp-config.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthHttp.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthClientRegistry.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthTokenService.php';

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new OAuthError('invalid_request', 'Use POST with an application/x-www-form-urlencoded body', 405);
        }

        $registry = new OAuthClientRegistry($mcp_redirect_host_allowlist);
        $client = $registry->authenticate($_POST, $_SERVER);
        $service = new OAuthTokenService($mcp_resource);
        OAuthHttp::json(200, $service->redeemCode($client, $_POST), true);
    } catch (OAuthError $e) {
        OAuthHttp::error($e, $_SERVER);
    }
} catch (Throwable $e) {
    error_log('OAuth token endpoint error: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    echo json_encode(['error' => 'server_error', 'error_description' => 'Internal error']);
}
