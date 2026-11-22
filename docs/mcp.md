# MCP server

Offpost exposes a Model Context Protocol server (streamable HTTP, POST only) at `/mcp`.
Design: `docs/superpowers/specs/2026-11-21-mcp-server-design.md`.

## Authentication

OAuth bearer JWT (RS256), verified against the authorization server's JWKS. Issuer must equal
`$mcp_issuer` and audience must equal `$mcp_resource`; the token `sub` is the Offpost user id.

- `GET /.well-known/oauth-protected-resource` and the path-suffixed
  `GET /.well-known/oauth-protected-resource/mcp` (RFC 9728) serve the same metadata.
- Clock leeway: `exp`, `nbf` and `iat` are checked with 60 s leeway (`JWT::$leeway` is set only
  around the decode and restored afterwards).
- Machine-to-machine tokens are rejected (401): claim `gty` = `client-credentials`, or a `sub`
  ending in `@clients`. They do not represent an Offpost user.
- JWKS sources: a source that cannot be loaded is logged and skipped; only when no source gives
  any keys is the request a 500.
- No token: 401 with `WWW-Authenticate: Bearer resource_metadata="<metadata url>"`.
- Bad token: 401, same header plus `error="invalid_token"`.
- Non-POST: 405 with `Allow: POST`. Notifications: 202, empty body.
- Unexpected errors (including JWKS fetch failure and configuration errors such as an unknown
  environment; config is loaded inside the try, for the metadata endpoint too): 500
  `{"error":"Internal error"}`, the real error is logged.

The endpoint starts no PHP session and does not use `auth.php`.

## Tools

All tools return JSON text content. Errors are MCP tool errors (`isError: true`). A missing **or**
inaccessible thread, attachment or extraction gives the same text, `Not found, or no access`, so
existence is not leaked. IDs are validated as UUIDs first: a malformed id gives
`Invalid <field> (expected UUID)` (for example `Invalid thread_id (expected UUID)`), while a valid
UUID that is missing or inaccessible gives exactly `Not found, or no access`.

| Tool | Access rule |
|---|---|
| `list_entities` | any authenticated user (public bodies). Search is a case-insensitive substring of name or `org_num`. Entities without an email address and entities that no longer exist (`entity_existed_to_and_including` in the past) are left out |
| `start_thread` | any authenticated user; creates a thread owned by the token `sub`, queued `READY_FOR_SENDING`, no daily cap. Refuses entities without email (`Entity has no email address: <id>`) and defunct ones (`Entity no longer exists: <id>`) |
| `list_my_threads` | only threads with a `thread_authorizations` row for the user (owner or granted); public threads merely viewable are not listed |
| `get_thread` | read rule: owner, granted **or public** (`Thread::canUserAccess`); no raw EML, no `body_html`. Returns `can_reply` (true only with a `thread_authorizations` row, the same check as `send_reply`); `reply_recipients` is empty unless `can_reply` |
| `get_attachment_raw` | read rule; attachments over 5 MB raw are refused (use `get_attachment_extraction`) |
| `get_attachment_extraction` | read rule |
| `send_reply` | owner or granted only (`thread_authorizations` row); a public thread without a row is refused. Recipient must be one of the thread's valid reply recipients. Queued `READY_FOR_SENDING`. Subject and body are stored trimmed |

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
6. Set the API's Token Expiration (Access Token lifetime) short, for example 3600 s. Offpost
   cannot revoke issued JWTs, so the lifetime is the window in which a leaked or withdrawn token
   stays usable.

## Deploying

After pulling this branch, run `docker-compose -f <compose file> down && docker-compose -f <compose file> up --build -d`.
The image installs `firebase/php-jwt`, and the anonymous vendor volume would otherwise keep the old
vendor directory.

## Adding the connector in claude.ai

Settings -> Connectors -> Add custom connector -> URL `https://offpost.no/mcp`. Claude discovers
the authorization server from the metadata, registers itself and opens the normal Offpost login.

## Development setup

`auth/server.js` (Node `oidc-provider`) has issuer `http://localhost:25083/oidc`, dynamic
registration, and resource indicators issuing RS256 JWT access tokens for `http://localhost:25081/mcp`
(these must match `organizer/src/mcp-config.php`). Public clients must use PKCE; the confidential
`organizer` client keeps PKCE optional. The `organizer` client is excluded from the MCP
resource so that its userinfo-based web login keeps working (userinfo rejects tokens that have an
audience); this must be confirmed in the manual check below. The web login uses the provider
endpoints under `/oidc` directly, not discovery. The dev issuer has a path, so its discovery
metadata is at `http://localhost:25083/oidc/.well-known/openid-configuration`.

E2E tests sign tokens with test-only RSA keys that `McpTestKeys` generates on first use into the
gitignored `organizer/src/tests/fixtures/mcp/generated/` (`test-keys.json`, mode 0600, with both
private PEMs; and the public `test-jwks.json`). No key material is committed. The public JWKS is
trusted only when `$environment` is `development` (or under PHPUnit). The server reads the JWKS
file on every request (file sources are not cached), and a missing file is skipped by
`McpJwksLoader` while another source loads, so run any test that uses `McpTestKeys` before
calling the dev server. Key generation is random on purpose; tests must not depend on key values.

Manual OAuth check with Claude Code (after rebuilding the dev stack so the auth service picks up the change):

```
claude mcp add --transport http offpost-dev http://localhost:25081/mcp
```

Then run `/mcp` in Claude Code and authenticate; a browser login follows and the seven tools are
listed. Call `list_my_threads` to see the dev user's threads.

## Configuration

`organizer/src/mcp-config.php` (non-secret) sets `$mcp_resource`, `$mcp_authorization_server`,
`$mcp_issuer` and `$mcp_jwks_sources` per `$environment`; an unknown environment throws.
The generated test-only JWKS `organizer/src/tests/fixtures/mcp/generated/test-jwks.json` is trusted in development only.

## Code

`api/mcp/mcp.php`, `api/mcp/oauth_protected_resource.php`, `class/Mcp/` (`McpHttp`, `McpTokenVerifier`,
`McpJwksLoader`, `McpServer`, `McpTools`). Routes in `webroot/index.php`.
