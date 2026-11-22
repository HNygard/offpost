<?php
// Non-secret configuration for the MCP endpoint. Required after username-password.php,
// which defines $environment.

if (defined('PHPUNIT_RUNNING') || $environment === 'development') {
    $mcp_resource = 'http://localhost:25081/mcp';
    $mcp_authorization_server = 'http://localhost:25083/oidc';
    $mcp_issuer = $mcp_authorization_server;
    // The fixture JWKS holds a public TEST key. It must be trusted in development only.
    $mcp_jwks_sources = [
        'http://auth:3000/oidc/jwks',
        __DIR__ . '/tests/fixtures/mcp/test-jwks.json',
    ];
} elseif ($environment === 'production') {
    $mcp_resource = 'https://offpost.no/mcp';
    $mcp_authorization_server = 'https://auth.offpost.no/';
    $mcp_issuer = $mcp_authorization_server;
    $mcp_jwks_sources = ['https://auth.offpost.no/.well-known/jwks.json'];
} else {
    throw new Exception('Unknown environment for MCP config: ' . var_export($environment, true));
}
