<?php
// OAuth 2.0 token revocation (RFC 7009). No session, JSON errors only.
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
        $token = $_POST['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OAuthError('invalid_request', 'Missing token');
        }
        // token_type_hint is accepted and ignored: there is only one kind of token.
        (new OAuthTokenService($mcp_resource))->revoke($client, $token);
        // 200 with an empty body whether or not the token existed or was the client's own.
        http_response_code(200);
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
    } catch (OAuthError $e) {
        OAuthHttp::error($e, $_SERVER);
    }
} catch (Throwable $e) {
    error_log('OAuth revoke endpoint error: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'server_error', 'error_description' => 'Internal error']);
}
