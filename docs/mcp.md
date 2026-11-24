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
- No token: 401 with `WWW-Authenticate: Bearer resource_metadata="<metadata url>"`, plus
  `scope="<scopes>"` when `$mcp_scopes` is non-empty.
- Bad token: 401, same header plus `error="invalid_token"`.
- Scopes: `$mcp_scopes` is advertised as `scopes_supported` in the metadata and as `scope` in
  `WWW-Authenticate` (MCP clients pick the scope to request from these). Development: `['mcp']`.
  Production: `['offline_access']`. Auth0 issues tokens for the API audience without a resource
  scope, but issues a refresh token only when the login asks for `offline_access`. With nothing
  advertised, claude.ai requested no scope, got no refresh token, and was disconnected when the
  1-hour access token expired. Needs "Allow Offline Access" on the Auth0 API. To also advertise
  `mcp` there, first add `mcp` as a permission on the Auth0 API, then add it in `mcp-config.php`.
  The token's scope is not checked.
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
| `list_my_threads` | only threads with a `thread_authorizations` row for the user (owner or granted); public threads merely viewable are not listed. Each thread has `has_delivery_problem` (computed for the listed threads in one query) |
| `get_thread` | read rule: owner, granted **or public** (`Thread::canUserAccess`); no raw EML, no `body_html`. Returns `delivery_problems` (see below), `can_reply` (true only with a `thread_authorizations` row, the same check as `send_reply`); `reply_recipients` is empty unless `can_reply` |
| `get_attachment_link` | read rule. By default returns `{name, filename, mimeType, size, download_url, expires_in: 300}` and loads no content (no size cap). With `inline: true` it also returns the content (image content or embedded resource); attachments over 5 MB are then refused (use `get_attachment_extraction`). Attachments whose content was never stored give an error in both modes. Replaces `get_attachment_raw` |
| `get_attachment_extraction` | read rule |
| `send_reply` | owner or granted only (`thread_authorizations` row); a public thread without a row is refused. Recipient must be one of the thread's valid reply recipients. Queued `READY_FOR_SENDING`. Subject and body are stored trimmed |

Delivery problems: `ThreadScheduledEmailSender` records a failed send as status `READY_FOR_SENDING`
(it is retried) with the failure text in `thread_email_sendings.error_message`; a later successful
send sets `SENT` but does not clear `error_message`. A delivery problem is therefore a sending with
`error_message` not null **and** status not `SENT`. `get_thread` lists them as `delivery_problems`:
`[{sending_id, recipient, subject, status, error, updated_at}]` (empty list when none), and
`list_my_threads` sets `has_delivery_problem` with the same rule.

History entries written through MCP (`created`, `thread_reply_created_and_queued_sending`) use the
token `sub` as user and carry `"via": "mcp"` in the details.

`file.php` follows the same public-thread read rule as the MCP read tools (it uses
`ThreadAuthorizationManager::canUserAccessThread()`, which admits public threads). It now answers
403 for unauthorized file requests instead of a 500 error page.

## Attachment download links

`get_attachment_link` returns `download_url = <resource>/attachment/<token>`, for example
`https://offpost.no/mcp/attachment/<token>`. The tool description tells the assistant to fetch it
with `curl -o file.pdf "<download_url>"` so the file stays out of its context.

- Token: `base64url(json{sub, tid, aid, exp}) . '.' . base64url(hmac_sha256(payload_part, secret))`,
  `exp` = issue time + 300 s (`McpAttachmentLink`). Bad format, bad signature (`hash_equals`) or
  expiry give `McpAuthException`.
- `GET /mcp/attachment/<token>` (`api/mcp/attachment_download.php`, route in `webroot/index.php`)
  needs no session or bearer header: the link is the credential. GET only (405 otherwise).
  An invalid or expired token gives 403 `Link invalid or expired`. Access is then re-checked for
  the token's `sub` at download time (`Thread::canUserAccess`, attachment belongs to the thread,
  content stored); any failure gives 404 `Not found, or no access`. The response carries
  `Content-Type`, `Content-Disposition: attachment` (sanitised filename as in `np_attachment_get.php`),
  `Content-Length`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer` and
  `X-Content-Type-Options: nosniff`. Unexpected errors give a plain 500.
- Secret `$mcp_link_secret` (`mcp-config.php`, `McpLinkSecret`): production reads
  `/run/secrets/mcp_link_secret` (trimmed, at least 32 characters, otherwise an exception).
  Development/PHPUnit generates a random secret once into
  `sys_get_temp_dir()/offpost-mcp-link-secret-<uid>` (0600) when the file is missing; those links die
  when the container restarts. The host cannot read the container's dev secret, so the e2e tests
  cover access loss by removing the user's access after the link was issued; expiry is covered by
  unit tests with a fixed clock.

## Auth0 tenant setup (production, one-time)

Done for auth.offpost.no on 2026-11-23 (API "Offpost MCP"). Dashboard names as of then:

1. Applications -> APIs -> Create API: identifier exactly `https://offpost.no/mcp` (cannot be
   changed later), JWT profile Auth0, signing algorithm RS256.
2. In that API's Settings:
   - Application Access Policy: User-delegated Access **All apps allowed** (claude.ai registers a
     new app per connection, so per-app authorization would block it); Client Access **No apps
     allowed** (Offpost rejects machine-to-machine tokens anyway).
   - Default Permissions for third-party applications: User-delegated Access **All**. Dynamically
     registered clients are third-party apps and need permissions even when the policy above allows
     all apps; with "Unauthorized" they register but never get a token.
   - Maximum Access Token Lifetime short, e.g. 3600 s. Offpost cannot revoke issued JWTs, so the
     lifetime is the window in which a leaked or withdrawn token stays usable.
   - Allow Offline Access **on**, so clients get refresh tokens instead of a new login every hour.
   - Token Sender-Constraining: None / Never (Offpost does not support DPoP).
3. Tenant Settings (gear, bottom of the left sidebar) -> Advanced:
   - **Dynamic Client Registration (DCR)** on. Discovery then shows
     `registration_endpoint: https://auth.offpost.no/oidc/register`.
   - **Resource Parameter Compatibility Profile** on, so the `resource` parameter MCP clients send
     selects the API (otherwise the token gets the wrong audience and Offpost answers 401).
4. Authentication -> the login connection(s) -> **Promote Connection to Domain Level**, so the
   login page of dynamically registered clients offers them.

## Deploying

One-time, before the first deploy of attachment links, on the production host:
`openssl rand -hex 32 > /opt/offpost/secrets/mcp_link_secret` (readable by the container; it is
mounted by `docker-compose.prod.yaml`). The file must exist before `up`: if it is missing, docker
creates a directory at that path, and then you must remove that directory and create the file.
A missing, short or unusable secret is logged and disables attachment links only:
`get_attachment_link` answers the tool error `Attachment links are not configured` and the download
endpoint answers a plain-text 500; the rest of the MCP endpoints (including OAuth discovery) work.

After pulling this branch, run `docker-compose -f <compose file> down && docker-compose -f <compose file> up --build -d`.
The image installs `firebase/php-jwt`, and the anonymous vendor volume would otherwise keep the old
vendor directory.

## Adding the connector in claude.ai

Settings -> Connectors -> Add custom connector -> URL `https://offpost.no/mcp`. Claude discovers
the authorization server from the metadata, registers itself and opens the normal Offpost login.

## Development setup

`auth/server.js` (Node `oidc-provider`) has issuer `http://localhost:25083/oidc`, dynamic
registration, and resource indicators issuing RS256 JWT access tokens for `http://localhost:25081/mcp`
(these must match `organizer/src/mcp-config.php`). The MCP resource's scope is `mcp` (matching
`$mcp_scopes`); it must be a resource scope, not an OIDC one such as `openid`: with `openid` there,
a client that requests only the resource gets nothing granted and the provider redirects back with
`error=access_denied`. The provider's `scopes` list includes `mcp` (plus the defaults `openid`,
`offline_access`, and `profile` from claims): MCP clients put the advertised scope in their dynamic
registration, which is otherwise rejected with `invalid_client_metadata` ("scope must only contain
Authorization Server supported scope values"; Claude Code shows "This service doesn't accept
sign-ins from Claude Code"). Dynamic client registrations are persisted by `auth/dev-adapter.js` to the
gitignored `auth/.oidc-store/clients.json` (ignored by nodemon), so MCP clients that cache their
`client_id` don't get `invalid_client` after an auth restart; sessions and grants stay in memory,
so you log in again. Consent grants the requested OIDC scopes too (e.g. `offline_access`, so the
client gets a refresh token). Public clients must use PKCE; the confidential
`organizer` client keeps PKCE optional. The `organizer` client is excluded from the MCP
resource so that its userinfo-based web login keeps working (userinfo rejects tokens that have an
audience). The web login uses the provider
endpoints under `/oidc` directly, not discovery. The dev issuer has a path, so its discovery
metadata is at `http://localhost:25083/oidc/.well-known/openid-configuration`.

E2E tests sign tokens with test-only RSA keys that `McpTestKeys` generates on first use into the
gitignored `organizer/src/tests/fixtures/mcp/generated/` (`test-keys.json`, mode 0600, with both
private PEMs; and the public `test-jwks.json`). No key material is committed. The public JWKS is
trusted only when `$environment` is `development` (or under PHPUnit). The server reads the JWKS
file on every request (file sources are not cached), and a missing file is skipped by
`McpJwksLoader` while another source loads, so run any test that uses `McpTestKeys` before
calling the dev server. Key generation is random on purpose; tests must not depend on key values.

Manual OAuth check with Claude Code (after rebuilding the dev stack, or `docker-compose -p offpost -f docker-compose.dev.yaml restart auth` after changing `auth/server.js`):

```
claude mcp add --transport http offpost-dev http://localhost:25081/mcp
```

Then run `/mcp` in Claude Code and authenticate; a browser login follows and the seven tools are
listed. Call `list_my_threads` to see the dev user's threads.

## Configuration

`organizer/src/mcp-config.php` (non-secret) sets `$mcp_resource`, `$mcp_authorization_server`,
`$mcp_issuer`, `$mcp_jwks_sources` and `$mcp_scopes` per `$environment`; an unknown environment throws.
The generated test-only JWKS `organizer/src/tests/fixtures/mcp/generated/test-jwks.json` is trusted in development only.

## Code

`api/mcp/mcp.php`, `api/mcp/oauth_protected_resource.php`, `api/mcp/attachment_download.php`,
`class/Mcp/` (`McpHttp`, `McpTokenVerifier`, `McpJwksLoader`, `McpServer`, `McpTools`,
`McpAttachmentLink`, `McpLinkSecret`). Routes in `webroot/index.php`.
