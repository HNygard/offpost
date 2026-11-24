<?php
// Non-secret configuration for the MCP endpoint. Required after username-password.php,
// which defines $environment.

if (defined('PHPUNIT_RUNNING') || $environment === 'development') {
    $mcp_resource = 'http://localhost:25081/mcp';
    // Offpost is its own OAuth authorization server (docs/superpowers/specs/2026-11-24-mcp-own-oauth-server-design.md).
    $mcp_issuer = 'http://localhost:25081';
    $mcp_authorization_server = $mcp_issuer;
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
    $mcp_issuer = 'https://offpost.no';
    $mcp_authorization_server = $mcp_issuer;
    $mcp_jwks_sources = ['https://auth.offpost.no/.well-known/jwks.json'];
    $mcp_scopes = ['mcp'];
} else {
    throw new Exception('Unknown environment for MCP config: ' . var_export($environment, true));
}

// Hosts (exact match) that dynamically registered OAuth clients may use as https redirect URIs.
$mcp_redirect_host_allowlist = ['claude.ai', 'claude.com'];

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
