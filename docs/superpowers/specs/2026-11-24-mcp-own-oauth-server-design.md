# Offpost as its own OAuth authorization server for MCP - design

Date: 2026-11-24. Status: draft for owner review. Builds on
`docs/superpowers/specs/2026-11-21-mcp-server-design.md` (the MCP server itself) and `docs/mcp.md`.

## Intent and why

The MCP server (`/mcp`) currently accepts Auth0-issued JWT access tokens (1 h lifetime). claude.ai's
connector never requests `offline_access`, so it never gets a refresh token, and the connection dies
one hour after every sign-in (verified 2026-11-24 in the Auth0 log: grant scope "", no refresh
attempts; known claude.ai bugs anthropics/claude-ai-mcp #840, #1077; #1344/#228 show it also drops
tokens instead of refreshing). Nothing on Offpost's side can make claude.ai request the scope.

So Offpost becomes the OAuth 2.1 authorization server for MCP clients itself and issues its own
long-lived, revocable access tokens. The owner logs in to approve as today (the Offpost web session,
which still logs in through Auth0). Owner requirement: **a connection stays valid until revoked, or
until it has gone 6 months without use** (sliding; every use restarts the 6 months; no absolute cap).
Owner requirement: **keep the login server safe and following standards.**

## Goals

- Standards-following authorization server for MCP clients (claude.ai custom connector, Claude Code):
  - RFC 8414 authorization server metadata
  - RFC 7591 dynamic client registration (open, as MCP requires)
  - OAuth 2.1 authorization code grant with PKCE (S256 only), RFC 8707 resource indicators,
    RFC 9207 `iss` in the authorization response
  - RFC 7009 token revocation
  - RFC 9728 protected resource metadata pointing at Offpost itself
- Opaque access tokens valid until revoked or 6 months unused.
- A "Connected apps" page in Offpost where a user sees and revokes their connections.
- The same code path in development and production (no dev-only OAuth server for MCP).
- Fix the web-login weaknesses the consent page relies on.

## Non-goals

- No OpenID Connect (no id_token, no userinfo); MCP needs plain OAuth.
- No refresh tokens (claude.ai doesn't use them; a sliding long-lived access token covers the need).
- No Client ID Metadata Documents (CIMD) - claude.ai uses dynamic registration; can be added later.
- No scopes/permissions model: a token grants the user's full MCP access (as today).
- No change to the MCP tools, their access rules, or the web UI's Auth0 login itself (beyond the
  security fixes below).

## Anti-goals (failure even if it works)

- A token, code or client secret usable from a database or log leak.
- Any way for a third party to get a code or token for a user without that user clicking Approve
  on Offpost's consent page while logged in (open redirect, CSRF, clickjacking, code injection).
- A code or token for one client usable by another.
- Revocation that doesn't take effect immediately.

## Approaches considered

| | Approach | Trade-off |
|---|---|---|
| **A (chosen)** | Small authorization server written in Offpost, plain PHP endpoints, opaque tokens hashed in Postgres | No dependencies, exactly the needed scope, sliding expiry and per-token revocation are natural; we own correctness (mitigated by per-rule tests and a security review) |
| B | `league/oauth2-server` | Mature grant logic, but no DCR/metadata (still hand-written), PSR-7 adapters + ~10 repository/entity classes + key management, fixed-expiry JWTs fit sliding expiry badly |
| C | Separate AS service (oidc-provider / Keycloak) in production, login delegated to Auth0 | Most complete standards coverage, but a new production service and session federation; too heavy |

Owner picked **A**.

## Design

### Identifiers

- Issuer = the Offpost base URL: production `https://offpost.no`, development `http://localhost:25081`
  (no trailing slash). Metadata at `<issuer>/.well-known/oauth-authorization-server`.
- Resource (unchanged): `<issuer>/mcp`.
- `$mcp_issuer`, `$mcp_authorization_server` in `mcp-config.php` become the issuer above; protected
  resource metadata `authorization_servers` = `[issuer]`.

### Endpoints

| Endpoint | Purpose |
|---|---|
| `GET /.well-known/oauth-authorization-server` | RFC 8414 metadata: `issuer`, `authorization_endpoint`, `token_endpoint`, `registration_endpoint`, `revocation_endpoint`, `response_types_supported: ["code"]`, `grant_types_supported: ["authorization_code"]`, `code_challenge_methods_supported: ["S256"]`, `token_endpoint_auth_methods_supported: ["none","client_secret_post","client_secret_basic"]`, `revocation_endpoint_auth_methods_supported` (same), `scopes_supported: ["mcp"]`, `authorization_response_iss_parameter_supported: true` |
| `POST /oauth/register` | RFC 7591 dynamic client registration (JSON) |
| `GET /oauth/authorize` | validates the request, requires the web login, shows the consent page |
| `POST /oauth/authorize` | Approve / Deny from the consent page |
| `POST /oauth/token` | authorization_code grant |
| `POST /oauth/revoke` | RFC 7009 |
| `GET /connected-apps`, `POST /connected-apps/revoke` | user's connections and revoke |

All OAuth endpoints except `/oauth/authorize` start no session and don't use `auth.php`. JSON errors
follow RFC 6749 section 5.2 / RFC 7591 section 3.2.2 (`{"error": "...", "error_description": "..."}`), with
`Cache-Control: no-store` on token responses. Never an HTML error page.

### Registration (RFC 7591)

- Accepts `redirect_uris` (required, 1-10), `client_name` (optional, max 100 chars, stored as text,
  always HTML-escaped when shown), `token_endpoint_auth_method` (`none`, `client_secret_post`
  (default when omitted, as claude.ai asks; differs from RFC 7591's `client_secret_basic` default, the response echoes the method used), `client_secret_basic`), `grant_types` (must include
  `authorization_code`; `refresh_token` tolerated and ignored, but not echoed back as supported),
  `response_types` (`code` only), `scope` (ignored apart from validation that it's `mcp` or absent).
  Unknown metadata is ignored.
- Redirect URI rules (OAuth 2.1 / RFC 8252): absolute, no fragment, no userinfo. **Allowlist (owner
  decision 2026-11-24):** `https` only on hosts in `$mcp_redirect_host_allowlist`
  (`mcp-config.php`, initially `claude.ai` and `claude.com`, exact host match, any path; the owner confirmed "any path" on 2026-11-25), or `http`
  on loopback hosts `localhost`, `127.0.0.1`, `[::1]` (any port; Claude Code). Anything else:
  `invalid_redirect_uri`. This closes the main attack on open registration (a fake "Claude" client
  redirecting to an attacker's site plus a phishing link to the real consent page). Matching at
  authorize time is exact string match, except that for loopback `http` URIs the port is ignored
  (RFC 8252 section 7.3).
- Response 201: `client_id` (random, 128 bits, URL-safe), `client_secret` (256 bits) for
  confidential methods, `client_id_issued_at`, `client_secret_expires_at: 0`, and the stored metadata.
  The secret is stored only as SHA-256 hash.
- Abuse limits (owner: production runs behind a Cloudflare Tunnel, `cloudflared` -> 
  `http://127.0.0.1:25081`, so `REMOTE_ADDR` is the docker gateway for every request): the client
  IP is `CF-Connecting-IP`, trusted only when `REMOTE_ADDR` is a private or loopback address (the
  tunnel); otherwise `REMOTE_ADDR`. Max 30 registrations per client IP per hour, and max 200 in total
  per hour as a backstop (429 `{"error":"too_many_requests"}` otherwise).
  Deploy note for `docs/mcp.md`: bind the published port to loopback (`127.0.0.1:25081:80`) so the
  container can't be reached around Cloudflare with a forged `CF-Connecting-IP`. Clients that never obtained a
  token are deleted after 24 h; clients whose tokens are all gone are deleted 365 days after their last activity
  (`oauth_clients.last_active_at`, migration 037: set on token issue, use, revoke and idle-expiry;
  revised 2026-11-25 after review, the original 30 days deleted a client the night a months-old
  connection was revoked, breaking reconnects with the cached client_id) (cleanup in an existing scheduled job or a new `/scheduled-oauth-cleanup` system page).

### Authorization (GET /oauth/authorize)

1. Validate `client_id` and `redirect_uri` first. If either is unknown/mismatched, show an error page
   and **never redirect** (RFC 6749 section 4.1.2.1) - prevents open redirects.
2. Then validate the rest; errors redirect to `redirect_uri` with `error`, `error_description`,
   `state`, `iss`: `response_type` must be `code`; `code_challenge` required with method `S256`
   (plain rejected); `resource`, if given, must equal `$mcp_resource` (RFC 8707, `invalid_target`);
   `scope`, if given, only `mcp`.
3. Require the web login (`requireAuth()`, which returns to this URL after login). **Any existing
   session is enough (owner decision 2026-11-24)**; no re-login or max-age check.
4. Store the validated request server-side in the session under a random id, and render the
   consent page: client name (escaped), redirect host, the user, what access means (full access to
   their Offpost threads through the MCP tools, including sending emails), and Approve / Deny
   buttons posting the request id plus a CSRF token.
   Headers: `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`,
   `Referrer-Policy: no-referrer`, `Cache-Control: no-store`.
5. No "remember consent": every authorization shows the consent page.

### Approve / Deny (POST /oauth/authorize)

- Requires the logged-in session, a valid CSRF token (per-session, `hash_equals`), and the stored
  request id (single use, expires after 10 min).
- Deny: redirect with `error=access_denied`, `state`, `iss`.
- Approve: create an authorization code (256 bits, stored hashed, bound to client, user `sub`,
  redirect_uri, code_challenge, resource; valid 60 s; single use) and redirect with `code`, `state`,
  `iss`.

### Token (POST /oauth/token)

- `application/x-www-form-urlencoded`; `grant_type=authorization_code` only (others:
  `unsupported_grant_type`).
- Client authentication per the client's registered method (`client_secret_basic` header,
  `client_secret_post` body, or `none` with `client_id` in the body); secrets compared as hashes with
  `hash_equals`; failure 401 `invalid_client`.
- Code checks: exists, unused, unexpired, same client, same `redirect_uri`, PKCE
  `BASE64URL(SHA256(code_verifier)) == code_challenge` (verifier 43-128 chars of the RFC 7636 set),
  `resource` if sent equals the code's. Any failure `invalid_grant`. A **reused** code also revokes
  every token issued from it (RFC 6749 section 4.1.2).
- Marking the code used and inserting the token happen in one transaction (`UPDATE ... WHERE used_at
  IS NULL` so two concurrent redemptions can't both succeed).
- Response: `{"access_token", "token_type": "Bearer", "scope": "mcp"}` (`expires_in` omitted, owner decision 2026-11-25) with
  `Cache-Control: no-store`, `Pragma: no-cache`.

### Access tokens

- 256-bit random, base64url, prefixed `opmcp_` (helps secret scanners). Stored as SHA-256 hash only.
- Row: `client_id`, `user_id` (`sub`), `resource`, `created_at`, `last_used_at`, `code_id`.
- Valid while the row exists, `resource` matches `$mcp_resource`, and `last_used_at` is within 6
  months (180 days). On each successful use, `last_used_at` is updated, at most once per hour per
  token (avoids a write per request). Expired rows are deleted by the cleanup.
- `/mcp` replaces the JWT verifier with this lookup; a missing/invalid/expired/revoked token gives the
  existing 401 with `WWW-Authenticate` (`error="invalid_token"` when a token was sent).
- Advertised scope stays `mcp` (`scopes_supported` in both metadata documents and the
  `WWW-Authenticate` `scope`); `offline_access` is no longer advertised.

### Revocation

- `POST /oauth/revoke` (RFC 7009): client authentication as at the token endpoint; `token` param;
  deletes the token if it belongs to that client; always 200 for unknown tokens.
- `/connected-apps` (logged-in web page): lists the user's tokens (client name escaped, created,
  last used) with Revoke buttons (POST + CSRF). Revoking deletes the row: immediate effect.
- History: no thread history entries (connections aren't thread events); revocations are logged with
  `error_log` only.

### Database

New migration `036_add_oauth_tables.sql`: `oauth_clients`, `oauth_authorization_codes`,
`oauth_access_tokens` (hashes as `char(64)` unique; foreign keys with `ON DELETE CASCADE` from codes
and tokens to clients), and `oauth_registration_attempts` (ip, created_at) for the rate limit.
Update `99999-database-schema-after-migrations.sql` as the project does.

### Web login hardening (the consent page relies on the session)

- `auth.php`: store the `state` sent to Auth0 in the session; `callback.php` rejects a callback whose
  `state` doesn't match (login CSRF fix), then clears it.
- Session cookie: `HttpOnly`, `SameSite=Lax`, and `Secure` in production (`$environment ===
  'production'`); `session_regenerate_id(true)` after a successful login (session fixation).

### Removal of the Auth0 MCP path

- Remove `McpTokenVerifier`, `McpJwksLoader`, `firebase/php-jwt`, `McpTestKeys` and the generated
  key handling, and the JWKS/issuer config. The MCP e2e tests get tokens by running the real OAuth
  flow (register, authorize with the e2e web session, token).
- `auth/server.js` (dev oidc-provider) goes back to serving only the web login: remove the MCP
  resource indicators, registration, `mcp` scope and `dev-adapter.js`.
- After deploy, the owner reconnects claude.ai once, and can switch off Auth0's Dynamic Client
  Registration and delete the "Offpost MCP" API (documented in `docs/mcp.md`).

## Testing

- Unit tests per rule: registration validation (redirect URI rules, auth methods, limits), PKCE,
  code single use and expiry, client authentication, reuse revoking tokens, resource matching,
  token lookup with sliding expiry (fixed clock), revocation, cleanup. Deterministic: inject the
  clock and the random source.
- E2E: the full flow over HTTP (register, authorize GET with session, approve POST, token, `/mcp`
  tools/list, revoke, `/mcp` 401); consent page error cases (unknown client: no redirect; bad PKCE
  method: redirect with error); `/connected-apps` lists and revokes; web login `state` check.
- Security review of the finished branch on the most capable model.

## Owner decisions after the builder check (2026-11-24)

- Redirect URIs: allowlist (`claude.ai`, `claude.com`, loopback), not any https host.
- Approving needs only an existing web session, no fresh login.
- Rate limiting keyed on `CF-Connecting-IP` behind the Cloudflare Tunnel, plus a site-wide cap.

## Calls made by the designer (owner did not decide - check these)

- The e2e tests run the real flow with the existing e2e web login (`?test-authenticate` in
  `E2EPageTestCase`); the new `state` check in `callback.php` must not affect that path (it doesn't go
  through the callback) - the builder verifies this and adjusts the test helper if needed.

- No refresh tokens; `expires_in` omitted from the token response (owner decision 2026-11-25): the token has no fixed expiry, only a 6-month idle window.
- No remembered consent: every authorization shows the consent page.
- Registration rate limit 30/IP/hour; unused-client cleanup after 24 h / 365 days after last activity.
- Client secrets for `client_secret_post` / `client_secret_basic` clients (claude.ai registers as
  `client_secret_post`).
- `last_used_at` written at most once per hour per token.
- Token prefix `opmcp_`.
- Auth0's MCP configuration is removed from code; the dev oidc-provider returns to web login only.

## Left to the builder

- File and class layout (suggested: `organizer/src/class/OAuth/` for the server logic,
  `organizer/src/api/oauth/` for endpoints, a consent page template next to the existing pages).
- Exact HTML/CSS of the consent and connected-apps pages (follow existing page style).
- Where the cleanup runs (existing scheduled page or a new one).
