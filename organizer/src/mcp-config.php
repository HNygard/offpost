<?php
// Non-secret configuration for the MCP endpoint. Required after username-password.php,
// which defines $environment.

if (defined('PHPUNIT_RUNNING') || $environment === 'development') {
    $mcp_resource = 'http://localhost:25081/mcp';
    $mcp_authorization_server = 'http://localhost:25083/oidc';
    $mcp_issuer = $mcp_authorization_server;
    // Scopes advertised to MCP clients (metadata scopes_supported + WWW-Authenticate scope).
    // MCP clients send no scope without them, and the dev oidc-provider then grants nothing
    // and answers access_denied. auth/server.js gives the MCP resource the 'mcp' scope.
    $mcp_scopes = ['mcp'];
    // The generated JWKS holds a public TEST key (McpTestKeys creates it on first use; gitignored).
    // It must be trusted in development only. McpJwksLoader skips it while the file is missing.
    $mcp_jwks_sources = [
        'http://auth:3000/oidc/jwks',
        __DIR__ . '/tests/fixtures/mcp/generated/test-jwks.json',
    ];
} elseif ($environment === 'production') {
    $mcp_resource = 'https://offpost.no/mcp';
    $mcp_authorization_server = 'https://auth.offpost.no/';
    $mcp_issuer = $mcp_authorization_server;
    $mcp_jwks_sources = ['https://auth.offpost.no/.well-known/jwks.json'];
    // Auth0 issues tokens for the API audience without a resource scope, but it only issues a
    // refresh token when the login asks for offline_access. Clients take the scope to request
    // from what is advertised here; with nothing advertised, claude.ai asked for no scope, got no
    // refresh token and was disconnected when the 1 h access token expired (2026-11-24).
    // Requires "Allow Offline Access" on the Auth0 API. To also advertise a resource scope such
    // as 'mcp', first add it as a permission on the Auth0 API (docs/mcp.md).
    $mcp_scopes = ['offline_access'];
} else {
    throw new Exception('Unknown environment for MCP config: ' . var_export($environment, true));
}

// HMAC secret for attachment download links. Production reads /run/secrets/mcp_link_secret;
// development/PHPUnit generates one into the temp dir when the file is missing.
require_once __DIR__ . '/class/Mcp/McpLinkSecret.php';
// A missing or unusable secret must not take down the other MCP endpoints (OAuth discovery,
// the JSON-RPC endpoint): it is logged and links are disabled ($mcp_link_secret = null).
try {
    $mcp_link_secret = McpLinkSecret::load(
        McpLinkSecret::PRODUCTION_FILE,
        defined('PHPUNIT_RUNNING') || $environment === 'development'
    );
} catch (RuntimeException $e) {
    error_log('MCP attachment links disabled: ' . $e->getMessage());
    $mcp_link_secret = null;
}
