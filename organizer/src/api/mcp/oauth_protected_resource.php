<?php
// OAuth 2.0 Protected Resource Metadata (RFC 9728) for the MCP endpoint. Public, no auth.
require_once __DIR__ . '/../../username-password.php';
require_once __DIR__ . '/../../mcp-config.php';

header('Content-Type: application/json');
echo json_encode([
    'resource' => $mcp_resource,
    'authorization_servers' => [$mcp_authorization_server],
    'bearer_methods_supported' => ['header'],
], JSON_UNESCAPED_SLASHES);
