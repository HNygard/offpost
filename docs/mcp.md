# MCP server

Offpost exposes a Model Context Protocol server (streamable HTTP, POST only) at `/mcp`.
Design: `docs/superpowers/specs/2026-11-21-mcp-server-design.md`.

## Authentication

OAuth bearer JWT (RS256), verified against the authorization server's JWKS. Issuer must equal
`$mcp_issuer` and audience must equal `$mcp_resource`; the token `sub` is the Offpost user id.

- `GET /.well-known/oauth-protected-resource` serves the RFC 9728 metadata.
- No token: 401 with `WWW-Authenticate: Bearer resource_metadata="<metadata url>"`.
- Bad token: 401, same header plus `error="invalid_token"`.
- Non-POST: 405 with `Allow: POST`. Notifications: 202, empty body.
- Unexpected errors (including JWKS fetch failure): 500 `{"error":"Internal error"}`.

The endpoint starts no PHP session and does not use `auth.php`.

## Configuration

`organizer/src/mcp-config.php` (non-secret) sets `$mcp_resource`, `$mcp_authorization_server`,
`$mcp_issuer` and `$mcp_jwks_sources` per `$environment`; an unknown environment throws.
The test-only JWKS `organizer/src/tests/fixtures/mcp/test-jwks.json` is trusted in development only.

## Code

`api/mcp/mcp.php`, `api/mcp/oauth_protected_resource.php`, `class/Mcp/` (`McpHttp`, `McpTokenVerifier`,
`McpJwksLoader`, `McpServer`, `McpTools`). Routes in `webroot/index.php`.
