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

## Tools

All tools return JSON text content. Errors are MCP tool errors (`isError: true`). A missing **or**
inaccessible thread, attachment or extraction gives the same text, `Not found, or no access`, so
existence is not leaked. IDs are validated as UUIDs first.

| Tool | Access rule |
|---|---|
| `list_entities` | any authenticated user (public bodies) |
| `start_thread` | any authenticated user; creates a thread owned by the token `sub`, queued `READY_FOR_SENDING`, no daily cap |
| `list_my_threads` | only threads with a `thread_authorizations` row for the user (owner or granted); public threads merely viewable are not listed |
| `get_thread` | read rule: owner, granted **or public** (`Thread::canUserAccess`); no raw EML, no `body_html` |
| `get_attachment_raw` | read rule; attachments over 5 MB raw are refused (use `get_attachment_extraction`) |
| `get_attachment_extraction` | read rule |
| `send_reply` | owner or granted only (`thread_authorizations` row); a public thread without a row is refused. Recipient must be one of the thread's valid reply recipients. Queued `READY_FOR_SENDING` |

History entries written through MCP (`created`, `thread_reply_created_and_queued_sending`) use the
token `sub` as user and carry `"via": "mcp"` in the details.

`file.php` follows the same public-thread read rule as the MCP read tools (it uses
`ThreadAuthorizationManager::canUserAccessThread()`, which admits public threads). It now answers
403 for unauthorized file requests instead of a 500 error page.

## Auth0 tenant setup (production, one-time)

1. Create an API with identifier `https://offpost.no/mcp`, signing algorithm RS256.
2. Enable OIDC Dynamic Application Registration (tenant settings, Advanced).
3. Promote the login connection(s) to domain level so dynamically registered clients can use them.
4. Enable the Resource Parameter Compatibility Profile so the `resource` parameter maps to the audience.
5. In the API settings, allow user access for dynamically registered (third-party) clients.

## Adding the connector in claude.ai

Settings -> Connectors -> Add custom connector -> URL `https://offpost.no/mcp`. Claude discovers
the authorization server from the metadata, registers itself and opens the normal Offpost login.

## Development setup

`auth/server.js` (Node `oidc-provider`) has issuer `http://localhost:25083/oidc`, dynamic
registration, and resource indicators issuing RS256 JWT access tokens for `http://localhost:25081/mcp`
(these must match `organizer/src/mcp-config.php`). Public clients must use PKCE; the confidential
`organizer` client keeps PKCE optional. The web UI login is unaffected: it uses the provider
endpoints under `/oidc` directly, not discovery.

E2E tests sign tokens with a committed test-only key (`organizer/src/tests/fixtures/mcp/`),
which is trusted only when `$environment` is `development` (or under PHPUnit).

Manual OAuth check with Claude Code (after rebuilding the dev stack so the auth service picks up the change):

```
claude mcp add --transport http offpost-dev http://localhost:25081/mcp
```

Then run `/mcp` in Claude Code and authenticate; a browser login follows and the seven tools are
listed. Call `list_my_threads` to see the dev user's threads.

## Configuration

`organizer/src/mcp-config.php` (non-secret) sets `$mcp_resource`, `$mcp_authorization_server`,
`$mcp_issuer` and `$mcp_jwks_sources` per `$environment`; an unknown environment throws.
The test-only JWKS `organizer/src/tests/fixtures/mcp/test-jwks.json` is trusted in development only.

## Code

`api/mcp/mcp.php`, `api/mcp/oauth_protected_resource.php`, `class/Mcp/` (`McpHttp`, `McpTokenVerifier`,
`McpJwksLoader`, `McpServer`, `McpTools`). Routes in `webroot/index.php`.
