# Offpost OAuth Authorization Server for MCP - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Offpost issues its own opaque, revocable MCP access tokens (valid until revoked or 180 days unused) through a small standards-following OAuth authorization server, replacing Auth0 for MCP.

**Architecture:** Plain-PHP endpoints under `organizer/src/api/oauth/`, logic in `organizer/src/class/OAuth/`, four new Postgres tables. Tokens, codes and client secrets are random and stored only as SHA-256 hashes. The consent page uses the existing Offpost web session. `/mcp` swaps its JWT verifier for a token-table lookup.

**Tech Stack:** PHP 8.2, PostgreSQL, PHPUnit 11. No new dependencies; `firebase/php-jwt` is removed.

**Spec:** `docs/superpowers/specs/2026-11-24-mcp-own-oauth-server-design.md`

## Global Constraints

- Standards: RFC 8414 metadata, RFC 7591 registration, OAuth 2.1 authorization code + PKCE **S256 only**, RFC 8707 `resource`, RFC 9207 `iss` in authorization responses, RFC 7009 revocation, RFC 9728 protected resource metadata, RFC 6749 section 5.2 error format `{"error","error_description"}`.
- Issuer: development `http://localhost:25081`, production `https://offpost.no` (no trailing slash). Resource: `<issuer>/mcp`.
- Redirect URIs: `https` only on hosts in `$mcp_redirect_host_allowlist` (`['claude.ai', 'claude.com']`, exact host, any path), or `http` on `localhost`, `127.0.0.1`, `[::1]` (any port, port ignored when matching). No fragment, no userinfo. Otherwise `invalid_redirect_uri`.
- Never redirect when `client_id` or `redirect_uri` is invalid (show an error page).
- Secrets: client ids 128-bit, client secrets, codes and tokens 256-bit, from `random_bytes`, base64url. Access tokens prefixed `opmcp_`. Only `hash('sha256', ...)` stored; compare with `hash_equals`.
- Codes: single use, 60 s, bound to client, user, redirect_uri, code_challenge, resource. Reuse revokes the tokens issued from that code.
- Access tokens: valid while the row exists, the resource matches, and `last_used_at` is within 180 days. `last_used_at` updated at most once per hour. Token response `{"access_token","token_type":"Bearer","expires_in":15552000,"scope":"mcp"}` + `Cache-Control: no-store`, `Pragma: no-cache`.
- Registration limits: 30 per client IP per hour, 200 in total per hour (429 `too_many_requests`). Client IP = `CF-Connecting-IP` only when `REMOTE_ADDR` is private or loopback, else `REMOTE_ADDR`.
- Consent page: any existing web session (no re-login). Headers `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`. CSRF token + single-use pending-request id (10 min). No remembered consent.
- Scope advertised everywhere: `mcp`. `offline_access` no longer advertised.
- Time and randomness injectable for tests (clock as `callable(): int` epoch seconds, default `time()`).
- Tests: `// :: Setup` / `// :: Act` / `// :: Assert`, deterministic, no skips; array-size assertions print the array with `json_encode(..., JSON_PRETTY_PRINT)`; `assertEquals` for deterministic output.
- No key material in the repo. Commits end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; stage files by explicit path.

## Review Focus

1. **Open redirect / token theft via redirect URI:** a URI that differs only in case, a trailing slash, a fragment, userinfo (`https://claude.ai@evil.com/`), or an IDN look-alike must not match or register. Pinned in Task 2.
2. **Concurrent or repeated code redemption:** two redemptions of one code must yield at most one token, and the second must revoke it. Pinned in Task 4.
3. **Client confusion:** a code issued to client A redeemed by client B (even with the right verifier), or a confidential client authenticating with `none`, must fail `invalid_client`/`invalid_grant`. Pinned in Task 4.
4. **Consent CSRF / replay:** POST without the session's CSRF token, with another session's pending id, or reusing a pending id must not create a code. Pinned in Task 3.
5. **HTML injection on the consent and connected-apps pages via `client_name`** (`<script>`, quotes): must be escaped. Pinned in Tasks 3 and 6.

---

### Task 1: Migration, crypto helpers, errors, HTTP helpers

**Files:**
- Create: `organizer/src/migrations/sql/036_add_oauth_tables.sql`; update `organizer/src/migrations/sql/99999-database-schema-after-migrations.sql` (via `organizer/src/migrations/dump-db-schema.php` if it works from the host, otherwise by hand in the same style)
- Create: `organizer/src/class/OAuth/OAuthCrypto.php`, `organizer/src/class/OAuth/OAuthError.php`, `organizer/src/class/OAuth/OAuthHttp.php`
- Test: `organizer/src/tests/OAuth/OAuthCryptoTest.php`, `organizer/src/tests/OAuth/OAuthHttpTest.php`

Tables:
```sql
oauth_clients(id serial PK, client_id varchar(64) UNIQUE NOT NULL, client_secret_hash char(64) NULL,
  client_name varchar(100) NULL, redirect_uris jsonb NOT NULL, token_endpoint_auth_method varchar(32) NOT NULL,
  registration_ip varchar(64) NULL, created_at timestamptz NOT NULL DEFAULT now())
oauth_authorization_codes(id bigserial PK, code_hash char(64) UNIQUE NOT NULL,
  client_id varchar(64) NOT NULL REFERENCES oauth_clients(client_id) ON DELETE CASCADE, user_id varchar(255) NOT NULL,
  redirect_uri text NOT NULL, code_challenge varchar(128) NOT NULL, resource text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz NOT NULL, used_at timestamptz NULL)
oauth_access_tokens(id bigserial PK, token_hash char(64) UNIQUE NOT NULL,
  client_id varchar(64) NOT NULL REFERENCES oauth_clients(client_id) ON DELETE CASCADE, user_id varchar(255) NOT NULL,
  resource text NOT NULL, code_id bigint NULL REFERENCES oauth_authorization_codes(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now(), last_used_at timestamptz NOT NULL DEFAULT now())
oauth_registration_attempts(id bigserial PK, ip varchar(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT now())
```
Add indexes: tokens(user_id), attempts(created_at), attempts(ip, created_at).

**Interfaces (produces):**
- `OAuthCrypto::randomToken(int $bytes = 32): string`: base64url, no padding
- `OAuthCrypto::hash(string $value): string`: sha256 hex
- `OAuthCrypto::isValidCodeVerifier(string $v): bool`: 43-128 chars of `[A-Za-z0-9\-._~]`
- `OAuthCrypto::pkceS256Matches(string $verifier, string $challenge): bool`: `hash_equals`
- `class OAuthError extends Exception`: `__construct(string $error, string $description, int $httpStatus = 400)`, with `public readonly string $error` and `public readonly int $httpStatus`
- `OAuthHttp::json(int $status, array $body, bool $noStore = false): void`: also sets `Pragma: no-cache` when `$noStore`
- `OAuthHttp::error(OAuthError $e): void`
- `OAuthHttp::clientIp(array $server): string`: `CF-Connecting-IP` (`HTTP_CF_CONNECTING_IP`) when `REMOTE_ADDR` is private (10/8, 172.16/12, 192.168/16, fc00::/7) or loopback; else `REMOTE_ADDR`

- [ ] **Step 1: Write failing tests:**
  - `testPkceRfc7636AppendixB`: verifier `dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk`, challenge `E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM` → true; wrong challenge → false
  - `testInvalidVerifierLengthsAndChars` (42 chars, 129 chars, `+`)
  - `testRandomTokenIsBase64UrlWithoutPadding` (regex `^[A-Za-z0-9_-]{43}$` for 32 bytes)
  - `testHashIsSha256Hex`
  - `testClientIpUsesCfHeaderOnlyBehindPrivateProxy` (`172.30.0.1` + CF header → CF value; `203.0.113.5` + CF header → `203.0.113.5`; `127.0.0.1` + CF header → CF value; no CF header → `REMOTE_ADDR`)
- [ ] **Step 2:** Run them (`./organizer/src/vendor/bin/phpunit organizer/src/tests/OAuth/`). Expected: FAIL.
- [ ] **Step 3:** Implement. Apply the migration to the dev DB from the host: `php organizer/src/migrations/migrate.php`. If the host can't run it, apply the SQL with `sg docker -c "docker exec -i offpost_postgres_1 psql -U offpost -d offpost" < file` and also insert the `migrations` row the runner would write; check how `migrate.php` records them first.
- [ ] **Step 4:** Run the tests. Expected: PASS. Commit.

### Task 2: Config, client registration, metadata endpoints

**Files:**
- Modify: `organizer/src/mcp-config.php`:
  - `$mcp_issuer` = issuer per the Global Constraints
  - `$mcp_authorization_server = $mcp_issuer`
  - `$mcp_redirect_host_allowlist = ['claude.ai', 'claude.com']`
  - `$mcp_scopes = ['mcp']` in both environments
  - keep `$mcp_jwks_sources` until Task 5
- Create: `organizer/src/class/OAuth/OAuthClientRegistry.php`, `organizer/src/api/oauth/register.php`, `organizer/src/api/oauth/metadata.php`
- Modify: `organizer/src/webroot/index.php`: routes `/oauth/register` and `/.well-known/oauth-authorization-server`
- Test: `organizer/src/tests/OAuth/OAuthClientRegistryTest.php`, e2e `organizer/src/e2e-tests/pages/OAuthServerTest.php` (metadata + register over HTTP)

**Interfaces (produces):**
- `new OAuthClientRegistry(array $redirectHostAllowlist, ?callable $clock = null)`
- `register(array $metadata, string $clientIp): array`: returns the RFC 7591 response: `client_id`, `client_secret` (only for confidential methods), `client_id_issued_at`, `client_secret_expires_at: 0`, `client_name`, `redirect_uris`, `token_endpoint_auth_method`, `grant_types: ["authorization_code"]`, `response_types: ["code"]`. Throws `OAuthError` with `invalid_redirect_uri` / `invalid_client_metadata` (400) or `too_many_requests` (429).
- `find(string $clientId): ?array`: row with `redirect_uris` decoded
- `redirectUriMatches(array $client, string $uri): bool`: exact match; for loopback `http` URIs the port is ignored
- `authenticate(array $post, array $server): array`: client row. `client_secret_basic` reads `PHP_AUTH_USER`/`PHP_AUTH_PW`, or a parsed `Authorization: Basic` header (form-urlencoded per RFC 6749 2.3.1). `client_secret_post` reads `client_id`/`client_secret` from the body. `none` reads `client_id` only. Any mismatch with the registered method, or a wrong secret, throws `OAuthError('invalid_client', ..., 401)`.

Metadata document per spec section "Endpoints" (all URLs built from `$mcp_issuer`).

- [ ] **Step 1: Write failing tests:**
  - **Valid registrations:** `testRegisterClaudeAiClientSecretPost` (the exact body claude.ai sent, from docs: `client_name` "Claude", `redirect_uris` `["https://claude.ai/api/mcp/auth_callback"]`, `grant_types` `["authorization_code","refresh_token"]`, `token_endpoint_auth_method` `client_secret_post`) → 201 fields; secret stored hashed (DB row ≠ secret, = sha256). `testRegisterLoopbackNone` (`http://localhost:57706/callback`, `none`) → no `client_secret`.
  - **Rejected redirect URIs** (one data-provider test): `http://claude.ai/x`, `https://evil.com/cb`, `https://claude.ai.evil.com/cb`, `https://claude.ai@evil.com/cb`, `https://CLAUDE.AI/cb`, `https://claude.ai/cb#frag`, `https://xn--clude-...`, `javascript:alert(1)`, `http://localhost.evil.com/cb`, `http://192.168.1.10/cb` → `invalid_redirect_uri`. Accept the uppercase host only if you normalise host to lowercase before the allowlist check; decide and assert accordingly.
  - **Rejected metadata:** `testRegisterRejectsBadMetadata` (no `redirect_uris`, 11 URIs, `response_types ["token"]`, `grant_types ["client_credentials"]`, unknown auth method, `client_name` over 100 chars, `scope` "admin") → `invalid_client_metadata`.
  - **Rate limits:** `testRegisterRateLimitPerIp` (30 OK, the 31st from the same IP → 429; another IP OK, with clock fixed). `testRegisterRateLimitGlobal` (200 from varied IPs, then 429).
  - **Redirect matching:** `testRedirectUriMatchesExactOrLoopbackAnyPort`.
  - **Client authentication:** `testAuthenticateEachMethod`, plus wrong secret, a secret client using `none`, and a `none` client sending a secret → `invalid_client` 401.
  - **E2E:** metadata exact JSON; register over HTTP returns 201; a disallowed redirect gives 400 JSON.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement. `register.php` accepts only POST with a JSON body; the response is 201 + `Cache-Control: no-store`.
- [ ] **Step 4:** Run the tests. Expected: PASS (e2e deferred). Commit.

### Task 3: Authorization endpoint and consent page

**Files:**
- Create: `organizer/src/class/OAuth/OAuthAuthorizationService.php`, `organizer/src/api/oauth/authorize.php` (GET + POST), `organizer/src/api/oauth/consent-page.php` (template)
- Modify: `organizer/src/webroot/index.php`: route `/oauth/authorize`
- Test: `organizer/src/tests/OAuth/OAuthAuthorizationServiceTest.php`, e2e additions in `OAuthServerTest.php`

**Interfaces:**
- Consumes: `OAuthClientRegistry::find`, `redirectUriMatches`; `OAuthCrypto`.
- Produces:
  - `new OAuthAuthorizationService(string $issuer, string $resource, OAuthClientRegistry $registry, ?callable $clock = null)`
  - `validate(array $query): array`: returns `client` (row), `redirect_uri`, `state` (nullable), `code_challenge`, `resource` (the request's, or `$resource` when absent), `scope`. Throws:
    - `OAuthError` (not redirectable) for an unknown client or an unregistered redirect URI
    - `OAuthRedirectError extends OAuthError` (with `redirectUri`, `state`) for everything else: `unsupported_response_type`, `invalid_request` (missing challenge / method not S256), `invalid_target`, `invalid_scope`
  - `redirectUrl(string $redirectUri, array $params): string`: appends params with `?` or `&` correctly, always including `iss`
  - `createCode(array $validated, string $userId): string`: returns the plain code

Page flow per spec "Authorization" and "Approve / Deny":
- **Pending requests:** stored in `$_SESSION['oauth_pending'][$id] = [...validated, 'expires' => now+600]`.
- **CSRF token:** `$_SESSION['oauth_csrf']`, created once per session.
- **Approve / Deny POST:** reads `pending_id`, `csrf`, `decision` (`approve` | `deny`), then unsets the pending entry before acting.
- **Headers:** the page sends the headers from the Global Constraints.
- **Escaping:** all output is escaped with `htmlspecialchars(..., ENT_QUOTES)`.
- **Page text, in English:**
  - "<client name> wants to access your Offpost account"
  - "It will be able to read your threads, start new requests and send emails in your name."
  - "Redirects to: <host>"
  - "Connected as: <user name/email from session>"
  - "Access lasts until you revoke it under Connected apps, or after 6 months without use."

- [ ] **Step 1: Write failing tests:**
  - Unit, one test per validation rule: unknown client; redirect mismatch (both not redirectable); `response_type=token`; missing challenge; `plain` method; wrong resource → `invalid_target`; `scope=admin` → `invalid_scope`; a valid request returns the exact array.
  - Unit: `redirectUrl` with and without an existing query.
  - Unit: `createCode` stores a hash with 60 s expiry, using a fixed clock.
  - E2E, with the e2e session (see `E2EPageTestCase` for how a logged-in curl is made; reuse its cookie for a manual flow):
    - GET shows the page; headers present; `client_name` `<script>` is escaped.
    - POST deny → 302 to the redirect with `error=access_denied&state=…&iss=…`.
    - POST approve → 302 with `code`, `state`, `iss`.
    - POST with a wrong CSRF → 400 and no code.
    - Reusing a `pending_id` → 400.
    - GET with an unknown client → 400 page, no `Location` header.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. Expected: PASS (e2e deferred). Commit.

### Task 4: Token endpoint, token store, revocation endpoint

**Files:**
- Create: `organizer/src/class/OAuth/OAuthTokenService.php`, `organizer/src/api/oauth/token.php`, `organizer/src/api/oauth/revoke.php`
- Modify: `organizer/src/webroot/index.php`: routes `/oauth/token`, `/oauth/revoke`
- Test: `organizer/src/tests/OAuth/OAuthTokenServiceTest.php`, e2e additions

**Interfaces:**
- Consumes: `OAuthClientRegistry::authenticate`, `OAuthAuthorizationService::createCode` (in tests).
- Produces:
  - `new OAuthTokenService(string $resource, ?callable $clock = null)`
  - `redeemCode(array $client, array $post): array`: the token response body
  - `userIdForAccessToken(string $token): ?string`: checks prefix, hash lookup, resource and the 180-day idle window; touches `last_used_at` if it is older than 3600 s
  - `revoke(array $client, string $token): void`
  - `listForUser(string $userId): array`: rows `{id, client_name, created_at, last_used_at}`, newest first
  - `revokeForUser(string $userId, int $tokenId): bool`
  - `cleanup(): array`: deletes tokens idle over 180 days, codes expired over 1 day, clients with no token older than 24 h that never got a token, clients without tokens for 30 days, and registration attempts older than 1 day; returns counts

Redemption, inside a transaction:
1. `UPDATE oauth_authorization_codes SET used_at = now() WHERE code_hash = ? AND used_at IS NULL AND expires_at > now() RETURNING *`.
2. If no row: if the code exists and is already used, delete the tokens with that `code_id`; then throw `invalid_grant`.
3. Check the client, `redirect_uri`, PKCE, and the resource if sent. Any failure: `invalid_grant`, and roll back the code use as well? **No.** Keep the code burned, per OAuth 2.1 (a failed redemption must not leave the code reusable).

The token endpoint accepts POST, form-encoded, `grant_type=authorization_code`; anything else is `unsupported_grant_type`. Revoke per RFC 7009: always 200 after client authentication.

- [ ] **Step 1: Write failing tests:**
  - **Successful redemption:** `testRedeemIssuesOpaqueToken` (prefix `opmcp_`; the DB holds only the hash; exact response body except the token).
  - **Expired or reused codes:** `testCodeExpiresAfter60s` (fixed clock). `testCodeReuseFailsAndRevokesIssuedToken` (the second redeem → `invalid_grant`, and the first token is no longer valid).
  - **Client and binding mismatches:** `testCodeForOtherClientFails`. `testRedirectUriMismatchFails`. `testWrongVerifierFails`, and the code is burned afterwards. `testResourceMismatchFails`.
  - **Concurrent redemption:** `testConcurrentRedeemOnlyOneSucceeds`. Two PDO connections are not available in unit tests, so simulate: set `used_at` between validation and update and assert the `UPDATE ... RETURNING` guard. If simulation isn't meaningful, explain why in the report and cover it with the reuse test.
  - **Token lookup and idle window:** `testUserIdForAccessTokenSlidingWindow` (valid at day 179, invalid at day 181 without use; use at day 100 extends to day 280). `testLastUsedTouchedAtMostHourly`. `testWrongResourceTokenRejected`. `testGarbageTokenRejected` (no prefix, empty, unknown).
  - **Revocation:** `testRevokeByClientOnlyOwnTokens`. `testRevokeForUserOnlyOwnTokens`.
  - **Cleanup:** `testCleanupDeletesExpiredAndStale`.
  - **E2E:** the full code → token flow. Token without client auth for a `client_secret_post` client → 401 `invalid_client`. `grant_type=password` → `unsupported_grant_type`. Revoke → 200.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. Expected: PASS. Commit.

### Task 5: Switch `/mcp` to Offpost tokens; remove the Auth0 MCP path

**Files:**
- Modify: `organizer/src/api/mcp/mcp.php`: `$userId = (new OAuthTokenService($mcp_resource))->userIdForAccessToken($token)`; `null` → 401 `invalid_token` (as today)
- Modify: `organizer/src/api/mcp/oauth_protected_resource.php`: `authorization_servers` `[$mcp_issuer]`, `scopes_supported` `['mcp']`
- Modify: `organizer/src/mcp-config.php`: remove `$mcp_jwks_sources` and all Auth0 values
- Delete: `organizer/src/class/Mcp/McpTokenVerifier.php`, `McpJwksLoader.php`, `organizer/src/tests/Mcp/McpTokenVerifierTest.php`, `organizer/src/tests/fixtures/mcp/McpTestKeys.php` (and its generated dir handling, plus the `.gitignore` line if it becomes unused)
- Modify: `organizer/src/composer.json` / `composer.lock`: `composer remove firebase/php-jwt`
- Modify: `organizer/src/e2e-tests/pages/McpApiTest.php`: get the token with a new helper `OAuthTestFlow::accessTokenFor(string $user = 'dev-user-id'): string` in `organizer/src/e2e-tests/pages/common/OAuthTestFlow.php`. The helper runs: register (loopback `none` client) → GET authorize with the e2e session → POST approve → token. Replace every `McpTestKeys` use; a "bad token" is `opmcp_` plus random characters.
- Modify: `organizer/src/tests/Mcp/McpHttpTest.php` if it depended on the verifier
- Test: e2e `McpApiTest`, plus a new e2e test: a token revoked via `/oauth/revoke` gives `/mcp` 401 `invalid_token`

- [ ] **Step 1:** Update or add tests first: the metadata exact JSON (`authorization_servers` `["http://localhost:25081"]`, `scopes_supported` `["mcp"]`), WWW-Authenticate `scope="mcp"`, and `/mcp` with a real token → 200 with tools/list.
- [ ] **Step 2:** Implement and delete. `grep -rn "McpTokenVerifier\|McpJwksLoader\|McpTestKeys\|php-jwt\|mcp_jwks" organizer/src docs` is empty, outside the old specs and plans under `docs/superpowers/`.
- [ ] **Step 3:** Run the unit tests. Expected: PASS. Commit.

### Task 6: Connected apps page and cleanup job

**Files:**
- Create: `organizer/src/connected-apps.php` (GET list, POST revoke with CSRF, `requireAuth()`, the page style of existing pages incl. `header.php`), `organizer/src/system-pages/scheduled-oauth-cleanup.php` (calls `cleanup()`, prints counts)
- Modify: `organizer/src/webroot/index.php` (`/connected-apps` in the regular pages, `/scheduled-oauth-cleanup` in the scheduled pages); `organizer/src/header.php`: a "Connected apps" link next to the existing user links
- Modify: the cron scheduler config (find how the other `/scheduled-*` pages are called, in `cron-scheduler/`), to call the cleanup once a day
- Test: e2e `organizer/src/e2e-tests/pages/ConnectedAppsPageTest.php`

- [ ] **Step 1: Write failing e2e tests:**
  - It lists a token created by `OAuthTestFlow`, and a `client_name` `<b>x</b>` is escaped.
  - Revoke POST removes it, and `/mcp` with that token → 401.
  - POST without CSRF → 400.
  - Another user's token id can't be revoked.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** `php -l` the new files and run the unit suite. Commit.

### Task 7: Web login hardening

**Files:**
- Modify: `organizer/src/auth.php`:
  - store the generated `state` in `$_SESSION['oauth_login_state']`
  - before `session_start()`, `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => <production>, 'path' => '/'])`
  - in `handleCallback`, `session_regenerate_id(true)` after setting the user
- Modify: `organizer/src/callback.php`: reject (400 text) when `$_GET['state']` doesn't `hash_equals` the stored one; then unset it
- `$environment` must be known before `session_start()`. Check include order (`auth.php` starts the session at the top). If `username-password.php` isn't loaded yet, read the environment the same way other early code does, or move the cookie setup into a small `session-bootstrap.php` included first. Never read `username-password.php`'s contents into reports.
- Also update `header.php`'s bare `session_start()` (it starts a session when none exists) to use the same cookie params via that bootstrap.
- Test: e2e `CallbackPageTest.php`: callback with a mismatching `state` → 400. The existing e2e login (`?test-authenticate`) must keep working; confirm and report how it logs in.

- [ ] **Step 1:** Write the failing e2e test and a unit test for the cookie-params helper, if one is extracted.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run the unit tests and `php -l`. Commit.

### Task 8: Dev auth server, docs, README

**Files:**
- Modify: `auth/server.js`: remove `MCP_RESOURCE`, the `mcp` scope, `resourceIndicators`, `registration`, the `DevAdapter` import and adapter config, and the consent-grant additions for resource scopes. Keep `organizer` client login working, PKCE optional for it; `node --check`.
- Delete: `auth/dev-adapter.js`; remove the `nodemonConfig` ignore and the `.gitignore` `auth/.oidc-store/` lines.
- Modify: `docs/mcp.md`:
  - rewrite the Authentication, Development setup, Auth0 and Deploying sections for the new server
  - endpoints, token lifetime, revocation, Connected apps, cleanup job
  - the redirect allowlist and how to extend it
  - Cloudflare `CF-Connecting-IP`, and binding the port to `127.0.0.1:25081:80`
  - deploy: run migration 036 (happens on container start), reconnect claude.ai once, then in Auth0 turn off Dynamic Client Registration and delete the "Offpost MCP" API
  - Claude Code: `claude mcp add --transport http offpost-dev http://localhost:25081/mcp`
- Modify: `README.md` (one line on the MCP login), `CLAUDE.md` (no change unless the docs list changes)

- [ ] **Step 1:** Make the changes. Check that `docs/mcp.md` mentions no removed class or config.
- [ ] **Step 2:** Run the unit tests and `node --check auth/server.js`. Commit.
