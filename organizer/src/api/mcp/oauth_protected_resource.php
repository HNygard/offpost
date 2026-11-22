<?php
// OAuth 2.0 Protected Resource Metadata (RFC 9728) for the MCP endpoint. Public, no auth.
try {
    require_once __DIR__ . '/../../username-password.php';
    require_once __DIR__ . '/../../mcp-config.php';

    $metadata = [
        'resource' => $mcp_resource,
        'authorization_servers' => [$mcp_authorization_server],
        'bearer_methods_supported' => ['header'],
    ];
    if (count($mcp_scopes) > 0) {
        $metadata['scopes_supported'] = $mcp_scopes;
    }
    $body = json_encode($metadata, JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json');
    echo $body;
} catch (Throwable $e) {
    error_log('MCP metadata endpoint error: ' . $e);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal error']);
}
