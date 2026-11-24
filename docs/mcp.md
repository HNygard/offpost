# MCP server

Offpost exposes a Model Context Protocol server (streamable HTTP, POST only) at `/mcp`.
Design: `docs/superpowers/specs/2026-11-21-mcp-server-design.md`.

## Authentication

Offpost is its own OAuth 2.1 authorization server for MCP clients (design:
`docs/superpowers/specs/2026-11-24-mcp-own-oauth-server-design.md`). The web login (`/` and
`/callback`) still goes through Auth0 in production and the dev `auth` service in development; MCP
tokens have nothing to do with either. `$mcp_issuer` is Offpost itself (dev `http://localhost:25081`,
production `https://offpost.no`, no trailing slash) and `$mcp_resource` is `<issuer>/mcp`.

`/mcp` accepts only Offpost's own opaque access tokens (`opmcp_...`):
`OAuthTokenService::userIdForAccessToken` looks up the SHA-256 hash, requires the token's resource to
equal `$mcp_resource` and returns the Offpost user id (`null` gives 401 `invalid_token`). A database
failure is a 500, not a 401. The endpoint starts no PHP session and does not use `auth.php`.

- No token: 401 with `WWW-Authenticate: Bearer resource_metadata="<metadata url>"`, plus
  `scope="<scopes>"` when `$mcp_scopes` is non-empty.
- Bad token: 401, same header plus `error="invalid_token"`.
- Scopes: `$mcp_scopes` (`['mcp']` in both environments) is advertised as `scopes_supported` in the
  metadata and as `scope` in `WWW-Authenticate` (MCP clients pick the scope to request from these).
  The token's scope is not checked. `offline_access` is not advertised: there are no refresh tokens.
- Non-POST: 405 with `Allow: POST`. Notifications: 202, empty body.
- Unexpected errors (including database failures and configuration errors such as an unknown
  environment; config is loaded inside the try, for the metadata endpoint too): 500
  `{"error":"Internal error"}`, the real error is logged.

### Endpoints

| Endpoint | Code | Notes |
|---|---|---|
| `GET /.well-known/oauth-protected-resource` and `.../oauth-protected-resource/mcp` (RFC 9728) | `api/mcp/oauth_protected_resource.php` | same metadata on both paths; `authorization_servers` is `[$mcp_issuer]` |
| `GET /.well-known/oauth-authorization-server` (RFC 8414) | `api/oauth/metadata.php` | |
| `POST /oauth/register` (RFC 7591) | `api/oauth/register.php`, `OAuthClientRegistry` | JSON; 201 + `Cache-Control: no-store` |
| `GET\|POST /oauth/authorize` | `api/oauth/authorize.php`, `OAuthAuthorizationService`, `consent-page.php` | PKCE S256 only |
| `POST /oauth/token` | `api/oauth/token.php`, `OAuthTokenService` | form-encoded |
| `POST /oauth/revoke` (RFC 7009) | `api/oauth/revoke.php` | client-authenticated |
| `POST /mcp` | `api/mcp/mcp.php` | the MCP endpoint |
| `GET /connected-apps`, `POST /connected-apps/revoke` | `connected-apps.php` | web session; link in the page header |
| `GET /scheduled-oauth-cleanup` | `system-pages/scheduled-oauth-cleanup.php` | called by cron, see below |

### Client registration

`POST /oauth/register` is open (MCP clients register themselves) and therefore restricted:

- Redirect URIs: `https` on a host in `$mcp_redirect_host_allowlist` (`claude.ai`, `claude.com`;
  host lowercased, exact match, no port, any path) or `http` on `localhost`, `127.0.0.1`, `[::1]`
  (any port; the port is ignored when matching, which is what native clients such as Claude Code
  need). No fragment, userinfo, whitespace, control characters or backslash; anything else is
  `invalid_redirect_uri`. At most 10 URIs.
- `client_name` at most 100 characters, `scope` only `mcp`, `response_types` only `code`,
  `grant_types` must include `authorization_code` (`refresh_token` is tolerated, not echoed).
- Rate limits: 30 attempts per client IP (an IPv6 address counts as its /64 prefix) and 200 in total per hour, answered 429
  `too_many_requests`. Every attempt with a JSON object body counts; bodies that are not a JSON
  object (arrays, scalars, invalid JSON) are rejected earlier and not counted.
- To allow another https host (for example another MCP client's hosted callback), add it to
  `$mcp_redirect_host_allowlist` in `organizer/src/mcp-config.php` and deploy. Exact host only,
  there are no wildcards. Already registered clients are not re-validated.
- Client authentication (`OAuthClientRegistry::authenticate`) uses the registered method (`none`,
  `client_secret_post`, `client_secret_basic`); any mismatch is `invalid_client` (401), with
  `WWW-Authenticate: Basic realm="offpost"` when the client used Basic. Basic credentials are
  urldecoded, and a body `client_id` that differs from the Basic one is rejected.
  A `client_id` that is not valid UTF-8 or is longer than 64 characters is never looked up: `invalid_client`
  at the token and revoke endpoints, the non-redirecting "unknown client" page at `/oauth/authorize`.

#### Client IP behind Cloudflare

The rate limit needs the real client IP. `OAuthHttp::clientIp` uses the `CF-Connecting-IP` header
only when it is a valid IP **and** the peer (`REMOTE_ADDR`) is private or loopback (IPv4-mapped IPv6
peers are unmapped first); otherwise it uses `REMOTE_ADDR`. Production runs behind a Cloudflare
Tunnel, so the peer is the tunnel connector on a private address. If the origin port can be reached
directly from a private network, anyone there can forge `CF-Connecting-IP` and sidestep the per-IP
limit. **Deploy advice:** publish the organizer port on loopback only, `127.0.0.1:25081:80` instead of
the current `25081:80` in `docker-compose.prod.yaml`, so that only the tunnel reaches the origin. The
200 per hour total limit still bounds the damage if the header is forged.

### Authorization and consent

`/oauth/authorize`: PKCE S256 only, `resource` must equal `$mcp_resource` (or be absent), `scope`
only `mcp`. An unknown `client_id` or unregistered `redirect_uri` gives a 400 page, never a
redirect; other errors redirect to the client with `error`, `state` and `iss` (RFC 9207). Valid
requests then require an Offpost web session (any existing session, no re-login) and show the
consent page. The request is kept in `$_SESSION['oauth_pending']` (10 min, single use) with a
per-session CSRF token; a POST without the session's CSRF token or with an unknown or reused
pending id creates nothing. Approve creates a 60 s single-use code (only its hash stored) and
redirects with `code`, `state`, `iss`; deny redirects with `error=access_denied`. Consent is not
remembered: every authorization shows the page. The page is sent with `X-Frame-Options: DENY`, CSP
`frame-ancestors 'none'`, `Referrer-Policy: no-referrer` and `Cache-Control: no-store`; the client
name is HTML-escaped.

### Tokens

`POST /oauth/token`: only `grant_type=authorization_code` (else `unsupported_grant_type`). The code is
burned by the first redemption attempt even when it fails (`invalid_grant` for expired, wrong
client, `redirect_uri` or PKCE verifier; `invalid_target` for a `resource` mismatch, RFC 8707);
presenting a used code again deletes the tokens issued from it. Response
`{"access_token":"opmcp_...","token_type":"Bearer","scope":"mcp"}` (no `expires_in`: the token has no fixed
expiry, owner decision 2026-11-25) with
`Cache-Control: no-store` and `Pragma: no-cache`.

Lifetime: a token is valid until it is revoked or has been idle for 180 days (each use extends it). `last_used_at` is refreshed at most once per hour so that busy
clients do not write on every request. Tokens are opaque and only their SHA-256 hash is stored.
There are no refresh tokens.

Revocation: `POST /oauth/revoke` (client-authenticated) deletes the token. It answers 200 with an
empty body also for unknown tokens and for tokens of other clients (the latter are not deleted). This
deliberately deviates from RFC 7009 2.1 so that the response does not reveal whether a token
exists. `token_type_hint` is ignored. Users can also disconnect an app on `/connected-apps`: the
user's connections (client name escaped, created, last used) with a Disconnect button.
`POST /connected-apps/revoke` takes `token_id` and the session's `oauth_csrf` token (400 without),
deletes only the session user's own token and redirects back (303) with a flash message. Same
anti-framing/no-store headers as the consent page.

Errors from the OAuth endpoints use RFC 6749 section 5.2: `{"error","error_description"}`.

### Cleanup job

`OAuthTokenService::cleanup()` deletes idle tokens (180 days), unused codes expired over 1 day, used
codes older than 30 days, old registration attempts, clients that never got a token (24 h) and
token-less clients whose last activity is over 365 days ago. `oauth_clients.last_active_at` (migration
037) is set when a token is issued, used (hourly touch), revoked, or deleted by this job as idle, so a
client survives a year after its last activity and claude.ai / Claude Code can reconnect with their
cached `client_id` after a long-ago connection ended. Codes no longer affect clients. It runs daily (03:00-03:09 UTC) from
`cron-scheduler/curl-cron.sh` via `/scheduled-oauth-cleanup`, which prints the counts. It is
idempotent.

### Web login hardening

The consent page trusts the Offpost web session, so the web login is hardened:

- `auth.php` stores the login `state` in `$_SESSION['oauth_login_state']`; `callback.php` answers 400
  `Invalid login state` (no token exchange) unless it matches (`hash_equals`). The state is single use.
- `handleCallback` calls `session_regenerate_id(true)` after setting the user (session fixation).
- Sessions are started through `offpostStartSession()` (`session-bootstrap.php`): cookie `HttpOnly`,
  `SameSite=Lax`, path `/`, `Secure` when `$environment === 'production'` (the origin is plain http
  behind the Cloudflare Tunnel, so `$_SERVER['HTTPS']` cannot be used), and
  `session.use_strict_mode=1` so the server never adopts a session id it did not issue.

## Tools

All tools return JSON text content. Errors are MCP tool errors (`isError: true`). A missing **or**
inaccessible thread, attachment or extraction gives the same text, `Not found, or no access`, so
existence is not leaked. IDs are validated as UUIDs first: a malformed id gives
`Invalid <field> (expected UUID)` (for example `Invalid thread_id (expected UUID)`), while a valid
UUID that is missing or inaccessible gives exactly `Not found, or no access`.

| Tool | Access rule |
|---|---|
| `list_entities` | any authenticated user (public bodies). Search is a case-insensitive substring of name or `org_num`. Entities without an email address and entities that no longer exist (`entity_existed_to_and_including` in the past) are left out |
| `start_thread` | any authenticated user; creates a thread owned by the token `sub`, queued `READY_FOR_SENDING`, no daily cap. Refuses entities without email (`Entity has no email address: <id>`) and defunct ones (`Entity no longer exists: <id>`). Returns `thread_id`, `thread_url`, `sending_status` and the new thread's generated identity `my_name`, `my_email` (see Sender identity and signatures) |
| `list_my_threads` | only threads with a `thread_authorizations` row for the user (owner or granted); public threads merely viewable are not listed. Each thread has `has_delivery_problem` (computed for the listed threads in one query). Ordered by last activity newest first, then thread id. Returns `total` (threads matching the filters) and `next_cursor` (null on the last page; pass it back as `cursor`). Paging is keyset-based: the next page continues strictly after the last thread returned, so threads archived between pages (e.g. a bulk archive run) do not shift or skip the rest. An unparseable cursor gives `Invalid cursor`. `truncated` is true when more pages exist |
| `get_thread` | read rule: owner, granted **or public** (`Thread::canUserAccess`); no raw EML, no `body_html`. Returns `delivery_problems` (see below), `can_reply` (true only with a `thread_authorizations` row, the same check as `send_reply`); `reply_recipients` is empty unless `can_reply` |
| `get_attachment_link` | read rule. By default returns `{name, filename, mimeType, size, download_url, expires_in: 300}` and loads no content (no size cap). With `inline: true` it also returns the content (image content or embedded resource); attachments over 5 MB are then refused (use `get_attachment_extraction`). Attachments whose content was never stored give an error in both modes. Replaces `get_attachment_raw` |
| `get_attachment_extraction` | read rule |
| `send_reply` | owner or granted only (`thread_authorizations` row); a public thread without a row is refused. Recipient must be one of the thread's valid reply recipients. Queued `READY_FOR_SENDING`. Subject and body are stored trimmed; the thread signature is appended to the body |
| `archive_thread` | owner or granted only (`thread_authorizations` row), like `send_reply`; a public thread without a row is refused. `archived` (default true) archives, false unarchives. Same path as the web UI's archive action (`updateThread`), which logs `archived`/`unarchived` to thread history, here with `via: mcp`. Already in that state: `changed: false`, no history entry. Archiving only hides the thread from default lists; nothing is sent and incoming emails are still received. Batch: `thread_ids` (1-100, instead of `thread_id`; both together is an error) returns `results` with one entry per distinct id (`{thread_id, changed}` or `{thread_id, error}`, where error is `Invalid thread_id (expected UUID)` or `Not found, or no access`), plus `changed_count`, `unchanged_count` and `error_count`; a failing id does not stop the others |

### Sender identity and signatures

Every thread has its own sender identity, a generated name and email address (`threads.my_name`,
`threads.my_email`, picked by `getRandomNameAndEmail()` in `ThreadCreationService`). It is never the
user's real name. `start_thread` always creates a new identity. A new thread is a separate request:
it is not connected to any earlier thread, neither in Offpost nor for the public body, even when it
is on the same subject. A follow-up on a request is a `send_reply` in that request's thread.

Offpost signs every outgoing email, so the agent must not:

- `start_thread`: `ThreadCreationService` appends `--` or `---` plus `my_name` to the request body.
- `send_reply`: `ThreadReplyService` appends `--` plus the thread's `my_name` (the web reply form
  uses the same service and shows a note that the signature is added; its "Suggested Reply" text
  therefore has no signature of its own).

The same rules are in the `start_thread` and `send_reply` tool descriptions and in the server
`instructions` returned by `initialize` (`McpServer::INSTRUCTIONS`), which MCP clients pass to the
model. This was added after an agent started a follow-up as a new thread and signed it with the
previous thread's name, so the entity got an email signed with two different names.

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

## Deploying

One-time, before the first deploy of attachment links, on the production host:
`openssl rand -hex 32 > /opt/offpost/secrets/mcp_link_secret` (readable by the container; it is
mounted by `docker-compose.prod.yaml`). The file must exist before `up`: if it is missing, docker
creates a directory at that path, and then you must remove that directory and create the file.
A missing, short or unusable secret is logged and disables attachment links only:
`get_attachment_link` answers the tool error `Attachment links are not configured` and the download
endpoint answers a plain-text 500; the rest of the MCP endpoints (including OAuth) work.

Moving MCP login from Auth0 to Offpost's own server:

1. Deploy: `docker-compose -f <compose file> down && docker-compose -f <compose file> up --build -d`.
   Migration 036 (OAuth tables) runs on container start. Consider binding the organizer port to
   loopback (`127.0.0.1:25081:80`, see Client IP behind Cloudflare).
2. Reconnect the claude.ai connector once (Settings -> Connectors -> Offpost -> disconnect and
   connect): tokens minted by Auth0 are no longer accepted, so the old connection gives 401 until
   then.
3. Afterwards, in the Auth0 tenant, turn off Dynamic Client Registration and delete the "Offpost MCP"
   API. The web login application stays.

## Adding the connector in claude.ai

Settings -> Connectors -> Add custom connector -> URL `https://offpost.no/mcp`. Claude discovers
the authorization server from the metadata, registers itself and opens the Offpost login and
consent page.

## Development setup

In development Offpost is its own authorization server at `http://localhost:25081`. The `auth`
service (`auth/server.js`, an oidc-provider) is only the stand-in for Auth0 for the web login and
knows nothing about MCP. E2E tests get real tokens through `OAuthTestFlow::accessTokenFor()`
(register a loopback `none` client, log in with the e2e session, approve, exchange the code).

Manual OAuth check with Claude Code (after rebuilding the dev stack):

```
claude mcp add --transport http offpost-dev http://localhost:25081/mcp
```

Then run `/mcp` in Claude Code and authenticate; a browser login follows and the eight tools are
listed. Call `list_my_threads` to see the dev user's threads. Connected clients are listed on
`http://localhost:25081/connected-apps`.

## Configuration

`organizer/src/mcp-config.php` (non-secret) sets `$mcp_resource`, `$mcp_issuer`, `$mcp_scopes`,
`$mcp_redirect_host_allowlist` and `$mcp_link_secret`; an unknown environment throws.

## Code

`api/mcp/mcp.php`, `api/mcp/oauth_protected_resource.php`, `api/mcp/attachment_download.php`,
`class/Mcp/` (`McpHttp`, `McpServer`, `McpTools`, `McpAttachmentLink`, `McpLinkSecret`),
`api/oauth/*`, `class/OAuth/` (`OAuthAuthorizationService`, `OAuthClientRegistry`, `OAuthCrypto`,
`OAuthError`, `OAuthHttp`, `OAuthTokenService`), `connected-apps.php`, `session-bootstrap.php`.
Routes in `webroot/index.php`.
