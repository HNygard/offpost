<?php
// OAuth 2.0 Authorization Server Metadata (RFC 8414). Public: no session, no auth.php, JSON only.
try {
    require_once __DIR__ . '/../../username-password.php';
    require_once __DIR__ . '/../../mcp-config.php';
    require_once __DIR__ . '/../../class/OAuth/OAuthHttp.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        header('Allow: GET, HEAD');
        OAuthHttp::json(405, ['error' => 'invalid_request', 'error_description' => 'Use GET']);
        exit;
    }

    $authMethods = ['none', 'client_secret_post', 'client_secret_basic'];
    OAuthHttp::json(200, [
        'issuer' => $mcp_issuer,
        'authorization_endpoint' => $mcp_issuer . '/oauth/authorize',
        'token_endpoint' => $mcp_issuer . '/oauth/token',
        'registration_endpoint' => $mcp_issuer . '/oauth/register',
        'revocation_endpoint' => $mcp_issuer . '/oauth/revoke',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => $authMethods,
        'revocation_endpoint_auth_methods_supported' => $authMethods,
        'scopes_supported' => $mcp_scopes,
        'authorization_response_iss_parameter_supported' => true,
    ]);
} catch (Throwable $e) {
    error_log('OAuth metadata endpoint error: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'server_error', 'error_description' => 'Internal error']);
}
