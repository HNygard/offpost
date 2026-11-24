<?php
// Non-secret configuration for the MCP endpoint. Required after username-password.php,
// which defines $environment.

// Offpost is its own OAuth authorization server (docs/mcp.md): the issuer is Offpost itself and
// the protected resource is its /mcp endpoint.
if (defined('PHPUNIT_RUNNING') || $environment === 'development') {
    $mcp_resource = 'http://localhost:25081/mcp';
    $mcp_issuer = 'http://localhost:25081';
} elseif ($environment === 'production') {
    $mcp_resource = 'https://offpost.no/mcp';
    $mcp_issuer = 'https://offpost.no';
} else {
    throw new Exception('Unknown environment for MCP config: ' . var_export($environment, true));
}

// Scopes advertised to MCP clients (metadata scopes_supported + WWW-Authenticate scope).
// MCP clients send no scope unless the server advertises one.
$mcp_scopes = ['mcp'];

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
