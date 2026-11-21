# Offpost MCP server - design

Date: 2026-11-21. Status: draft for owner review.

## Intent

Let an AI assistant (primarily a claude.ai custom connector, also Claude Code) work a user's
innsyn threads through Offpost: find public bodies, start requests, read threads and their
attachments, and send replies. The AI acts **as the logged-in user** and can do exactly what
that user can do in the Offpost web UI today - no more, no less.

## Goals

- A remote MCP endpoint at `https://offpost.no/mcp` that claude.ai can add as a connector.
- OAuth login through the existing Auth0 tenant (`auth.offpost.no`), so the MCP session is
  the same user (same `sub`) as the browser session.
- Seven tools (below), each reusing the existing service/class code the web UI uses, with the
  same authorization checks and the same history logging.

## Non-goals

- No admin-only capabilities (export of all threads, analysis endpoints, NP API actions).
- No draft (`STAGING`) replies, no cancelling a queued send, no classification, archiving,
  labelling or sharing tools in this first version.
- No MCP resources or prompts - tools only.
- No change to how sending works: the scheduled sender still does the actual SMTP send.

## Anti-goals (would make this a failure even if it works)

- The AI sees or touches a thread the user could not open in the UI.
- A reply or new request goes out with a recipient or sender identity the UI would not allow.
- Sends via MCP can't be told apart from UI sends in thread history.
- Tool responses so large (raw EML, huge attachment lists) that the assistant becomes useless.

## Constraints

- PHP 8.2 (`php:8.2.35-apache`), plain-PHP endpoint files like `organizer/src/api/np/`,
  routes registered in `organizer/src/webroot/index.php`. Minimal dependencies.
- Production identity provider is Auth0 (`auth.offpost.no`, see `username-password.php`);
  dev uses the Node `oidc-provider` service in `auth/`.
- Users are identified by OIDC `sub`; thread access is `ThreadAuthorizationManager`.
- Project test rules (CLAUDE.md): deterministic tests, Setup/Act/Assert, no skips.

## Approaches considered

| | Approach | Trade-off |
|---|---|---|
| **A (chosen)** | Auth0 is the authorization server; Offpost `/mcp` is only a resource server that verifies Auth0-issued JWT access tokens | Least PHP code, Auth0 owns the risky flow. Needs Auth0 tenant config (API audience, Dynamic Client Registration) and one JWT library; revocation is in Auth0 |
| B | Offpost runs its own OAuth server (`/oauth/authorize`, `/token`, `/register`), login delegated to the existing session | Same in dev and prod, revocation page in Offpost, no Auth0 changes - but several hundred lines of security-sensitive code, or the large `league/oauth2-server` dependency |
| - | Personal access tokens | Rejected: claude.ai connectors need OAuth |

Owner picked **A**.

Protocol layer: a small hand-written stateless JSON-RPC 2.0 handler (Streamable HTTP, JSON
responses, no SSE, no sessions) rather than the official PHP MCP SDK, to avoid pulling in its
Symfony dependency tree for seven tools. (Builder's call - stated here, owner did not decide.)

## Auth design (approach A)

1. `GET /.well-known/oauth-protected-resource` (RFC 9728) returns
   `{"resource": "https://offpost.no/mcp", "authorization_servers": ["https://auth.offpost.no/"]}`.
2. `/mcp` without a valid bearer token answers 401 with
   `WWW-Authenticate: Bearer resource_metadata="https://offpost.no/.well-known/oauth-protected-resource"`.
3. The client discovers Auth0, registers itself via Dynamic Client Registration, runs
   authorization code + PKCE with `resource`/`audience` = `https://offpost.no/mcp`, and the user
   logs in exactly as they do for the web UI.
4. Offpost verifies the access token: RS256 signature against Auth0 JWKS (cached), `iss`,
   `aud` = `https://offpost.no/mcp`, `exp`/`nbf`. The `sub` claim is the user id used for every
   authorization check and history entry.

Auth0 tenant setup (one-time, documented in `docs/mcp.md`, done by the owner):
- Create an API with identifier `https://offpost.no/mcp`, RS256.
- Enable OIDC Dynamic Application Registration.
- Promote the login connection(s) to domain level so dynamically registered clients can use them.
- Enable the resource parameter compatibility profile so `resource` maps to the audience.

Dev: configure `auth/server.js` (`oidc-provider`) with `features.registration`,
`features.resourceIndicators` issuing JWT access tokens for `http://localhost:25081/mcp`, so the
same verification code runs locally. Issuer, audience and JWKS URL come from
`username-password.php`-style config, not hardcoded.

## Tools

All tools: validate UUIDs before querying (400-style tool error, like `np_attachment_get.php`),
return JSON text content, and answer a missing or inaccessible thread/attachment/extraction
with the same error text, `Not found, or no access` (owner's wording), so existence isn't leaked
but the user isn't misled either. Errors are MCP tool errors (`isError: true`) with a short message.

| Tool | Inputs | Returns | Reuses |
|---|---|---|---|
| `list_entities` | `search?` (name or org number, case-insensitive substring), `limit?` (default 50, max 200) | `entity_id`, `name`, `type`, `org_num`, `email` | `Entity::getAll()` |
| `start_thread` | `entity_id`, `title`, `body`, `law_basis?` (`offentleglova` default, or `other`), `follow_up_plan?` (`speedy` default, `slow`, `postliste`, or none) | `thread_id`, `thread_url`, `sending_status` | `start-thread.php` logic (fresh random profile, owner authorization, request queued `READY_FOR_SENDING`) |
| `list_my_threads` | `search?` (title or entity name), `include_archived?` (default false), `limit?` (default 50, max 200) | per thread: id, title, entity, sending_status, thread_state_type, email counts, last activity, archived | threads where the user has a `ThreadAuthorization` row (owner or granted) - **not** public threads they merely can view |
| `get_thread` | `thread_id` | thread fields, entity, emails (headers, `body_plain`, classification, thread state), attachments metadata + extraction ids, sendings; **no raw EML, no `body_html`** | `ThreadExportService` (trimmed) |
| `get_attachment_raw` | `thread_id`, `attachment_id` | file as MCP embedded resource (`blob` base64 + `mimeType`); images as `image` content | `ThreadStorageManager::getThreadEmailAttachment` |
| `get_attachment_extraction` | `thread_id`, `attachment_id`, `prompt_id?` | all extractions for the attachment (`prompt_id`, `prompt_service`, `extracted_text`, `error_message`, timestamps), or the one matching `prompt_id` | `ThreadEmailExtractionService` |
| `send_reply` | `thread_id`, `subject`, `body`, `recipient` | `email_sending_id`, status `READY_FOR_SENDING` | `thread-reply.php` logic: `getThreadReplyRecipients()` check, `ThreadEmailSending::create(... READY_FOR_SENDING)` |

Access rules (owner decisions, 2026-11-21):

- **Reading** (`get_thread`, `get_attachment_raw`, `get_attachment_extraction`):
  `Thread::canUserAccess($sub)` - owner, granted, or a public thread. A public thread is
  readable, including its files and extractions.
- **Sync the UI with this rule:** `file.php:39` calls
  `ThreadAuthorizationManager::canUserAccessThread()` directly, which skips the public-thread
  exception, so the UI refuses raw files on public threads today. History shows no reason: the
  public exception has lived in `Thread::canUserAccess()` since `30662102` (2024-12-30), and the
  check in `file.php` was added later in `ac5904c7` (2025-02-16) without mentioning public
  threads - an oversight. Change `file.php` to `$thread->canUserAccess($sub)`, and add an e2e
  test in `e2e-tests/pages/FilePageTest.php` that a logged-in non-granted user can download a
  file from a public thread (and still gets refused on a non-public one).
  `classify-email.php:46` uses the same direct check, but classifying is a write, so the
  stricter rule is correct there and stays.
- **Writing** (`send_reply`): `ThreadAuthorizationManager::canUserAccessThread($threadId, $sub)` -
  owner or granted only. A public thread the user has no authorization row for is not
  replyable through MCP, even though the UI allows it today. This keeps an assistant from
  sending mail under another user's sender identity.
- `list_my_threads`: authorization rows only (owner or granted), as above.

History: write the same `ThreadHistory` actions as the UI (`thread_reply_created_and_queued_sending`,
thread creation) with the user's `sub`, plus `"via": "mcp"` in the details so MCP actions are
distinguishable.

`start_thread` has **no daily cap** (owner decision), same as the UI. The NP API's cap
(`NpApiService::DAILY_CAP`) only counts the `norske-postlister-api` user and is unaffected.

The thread-creation history row (written in `ThreadDatabaseOperations` with action `created`)
should also carry `"via": "mcp"` when created through MCP; the builder threads that through the
extracted creation service.

The thread URL returned by `start_thread` uses the same form as the NP API
(`/thread-view?threadId=...`).

## Refactoring

The logic in `start-thread.php` and `thread-reply.php` is inline in the page scripts. Extract
it into service methods (e.g. `ThreadCreationService::create(...)`, `ThreadReplyService::queueReply(...)`)
used by both the page and the MCP tool, so the rules can't drift apart. Page behaviour stays
unchanged.

## Testing

- Unit: JSON-RPC handling (`initialize`, `tools/list`, `tools/call`, malformed requests,
  unknown tool), token verification (good, expired, wrong audience, wrong issuer, bad
  signature - with a fixed test keypair), each tool's authorization (own thread, granted,
  other user's, public - reads allowed on public, `send_reply` refused on public-not-granted),
  recipient validation in `send_reply`.
- E2E: an `/mcp` call with a token from the dev `oidc-provider` lists threads and queues a reply.

## Docs

New `docs/mcp.md` (endpoint, Auth0 setup, tool reference, how to add the connector in
claude.ai). README gets a line under capabilities.

## Left to the builder

- Exact JSON field names in tool outputs, beyond what's listed.
- JWT library choice (`firebase/php-jwt` suggested) and JWKS cache mechanism.
- Size cap for `get_attachment_raw` (reject over a sensible limit with a clear error,
  pointing to `get_attachment_extraction`).
- Service class names and where the extracted logic lives.
- Whether to require a token scope in addition to the audience check.

## Calls made by the designer (not decided by the owner - check these)

- Hand-written JSON-RPC handler instead of the official PHP MCP SDK.
- `get_thread` omits raw EML and `body_html`.
- `list_entities` / `list_my_threads`: optional search, default limit 50, max 200;
  `list_my_threads` hides archived threads unless `include_archived` is true.
- `start_thread` defaults: law basis `offentleglova`, follow-up plan `speedy` (the UI defaults).
- One entity per `start_thread` call.
- History entries tagged `"via": "mcp"`.
- Inline logic in `start-thread.php` / `thread-reply.php` extracted into shared services.
