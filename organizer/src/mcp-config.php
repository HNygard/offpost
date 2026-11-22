<?php
// Non-secret configuration for the MCP endpoint. Required after username-password.php,
// which defines $environment.

if (defined('PHPUNIT_RUNNING') || $environment === 'development') {
    $mcp_resource = 'http://localhost:25081/mcp';
    $mcp_authorization_server = 'http://localhost:25083/oidc';
    $mcp_issuer = $mcp_authorization_server;
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
} else {
    throw new Exception('Unknown environment for MCP config: ' . var_export($environment, true));
}

// HMAC secret for attachment download links. Production reads /run/secrets/mcp_link_secret;
// development/PHPUnit generates one into the temp dir when the file is missing.
require_once __DIR__ . '/class/Mcp/McpLinkSecret.php';
$mcp_link_secret = McpLinkSecret::load(
    McpLinkSecret::PRODUCTION_FILE,
    defined('PHPUNIT_RUNNING') || $environment === 'development'
);
