# Offpost MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A remote MCP endpoint at `/mcp` where an AI assistant, logged in as an Offpost user through Auth0, can list entities, start threads, list/read the user's threads and attachments, and queue replies.

**Architecture:** Offpost is an OAuth resource server only: it publishes RFC 9728 metadata pointing at Auth0 and verifies Auth0-issued RS256 JWT access tokens (`sub` = the same user id as the browser session). A small hand-written stateless JSON-RPC 2.0 handler serves MCP Streamable HTTP (JSON responses, no SSE, no sessions). Tools call shared services extracted from `start-thread.php` / `thread-reply.php`, so UI and MCP follow the same rules.

**Tech Stack:** PHP 8.2, PostgreSQL, PHPUnit 11, `firebase/php-jwt` ^7.0, Auth0 (prod), Node `oidc-provider` 8 (dev).

**Spec:** `docs/superpowers/specs/2026-11-21-mcp-server-design.md`

## Global Constraints

- PHP 8.2 (`php:8.2.35-apache`); only new composer dependency is `firebase/php-jwt` (^7.0 - 6.x is affected by CVE-2025-45769).
- Endpoint files are plain PHP like `organizer/src/api/np/`; routes go in `organizer/src/webroot/index.php`.
- Error text for a missing **or** inaccessible thread/attachment/extraction is exactly `Not found, or no access`.
- Reads (`get_thread`, `get_attachment_raw`, `get_attachment_extraction`) use `Thread::canUserAccess($sub)` (public threads readable).
- Writes (`send_reply`) and `list_my_threads` use `thread_authorizations` rows only (owner or granted).
- `start_thread` and `send_reply` queue with status `READY_FOR_SENDING`; no daily cap.
- History entries written through MCP carry `"via": "mcp"` in details, user id = token `sub`.
- `get_thread` never includes raw EML or `body_html`.
- Tests: `// :: Setup` / `// :: Act` / `// :: Assert`, deterministic, no skips; array-size assertions print the array with `json_encode(..., JSON_PRETTY_PRINT)`; `assertEquals` for deterministic output.
- After each task: update docs touched by the change; commit with a detailed message ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Stage files by name (`git add -A` is blocked by a hook).

## Review Focus

1. **Authorization header not reaching PHP** under Apache (some setups strip it) - every call would 401. Expect the bearer token to be read from `$_SERVER['HTTP_AUTHORIZATION']`, then `REDIRECT_HTTP_AUTHORIZATION`, then `getallheaders()`. Pinned by the e2e test in Task 7.
2. **Auth0 `aud` is an array** (`["https://offpost.no/mcp", "https://auth.offpost.no/userinfo"]`) - must be accepted when it contains the audience. Pinned in Task 1.
3. **Malformed JSON, a JSON-RPC batch array, or a missing `method`** - expect a JSON-RPC error object (-32700 / -32600), never a PHP error page. Pinned in Task 2.
4. **Non-UUID ids** passed to any tool - expect `Not found, or no access`, not a Postgres "invalid input syntax for type uuid" 500. Pinned in Tasks 4-6.
5. **Attachment whose `content` is NULL or larger than the cap** - expect a clear tool error pointing to `get_attachment_extraction`. Pinned in Task 5.

---

### Task 1: Dependency, test keys and token verifier

**Files:**
- Modify: `organizer/src/composer.json`, `organizer/src/composer.lock`
- Create: `organizer/src/class/Mcp/McpTokenVerifier.php`
- Create: `organizer/src/class/Mcp/McpJwksLoader.php`
- Create: `organizer/src/tests/fixtures/mcp/McpTestKeys.php`
- Test: `organizer/src/tests/Mcp/McpTokenVerifierTest.php`

**Interfaces:**
- Produces:
  - `class McpAuthException extends Exception` (in `McpTokenVerifier.php`)
  - `new McpTokenVerifier(string $issuer, string $audience, array $jwks)` - `$jwks` is a decoded JWKS (`['keys' => [...]]`)
  - `McpTokenVerifier::verify(string $jwt): string` - returns `sub`; throws `McpAuthException` on any failure
  - `McpJwksLoader::load(array $sources): array` - each source is an `https?://` URL or a local file path; merges all `keys` into one JWKS; URL results cached for 3600 s as `<md5(url)>.json` in a private per-uid dir `sys_get_temp_dir()/offpost-mcp-jwks-<uid>` (mode 0700, ownership checked, atomic temp-file + rename writes)
  - `McpTestKeys::PRIVATE_KEY` (PEM string), `McpTestKeys::KID` = `'offpost-mcp-test-1'`, `McpTestKeys::jwks(): array`, `McpTestKeys::token(array $claims): string` (RS256, header `kid`)

- [ ] **Step 1:** In `organizer/src`, run `composer install`, then `composer require firebase/php-jwt:^7.0`. Expected: `vendor/firebase/php-jwt` exists and the lock file is updated.
- [ ] **Step 2:** Create `McpTestKeys.php`: a fixed 2048-bit RSA keypair generated once with `openssl genrsa 2048` and pasted in as a PHP heredoc constant. The file header says it is a **test-only key, trusted only when `$environment == 'development'` or under PHPUnit**. Do not use a `.pem` file (repo rule). `jwks()` derives n/e via `openssl_pkey_get_details`.
- [ ] **Step 3: Write failing tests** in `McpTokenVerifierTest` with issuer `https://issuer.test/`, audience `https://offpost.test/mcp`, and base claims `sub=user-1`, `iss`, `aud`, `exp=4102444800` (2100-01-01), `iat=1700000000`:
  - `testValidTokenReturnsSub` → `assertEquals('user-1', $verifier->verify($token))`
  - `testAudienceArrayContainingAudienceIsAccepted` (`aud` = `['https://offpost.test/mcp','https://issuer.test/userinfo']`) → `'user-1'`
  - `testExpiredTokenRejected` (`exp=1700000001`) → `expectException(McpAuthException::class)`
  - `testWrongAudienceRejected`, `testWrongIssuerRejected`, `testMissingSubRejected`
  - `testTokenSignedByOtherKeyRejected`: sign with a second fixed key held in the test file
  - `testHs256TokenRejected`: alg confusion; HS256 signed using the public key text as the secret
  - `testGarbageRejected` (`'not.a.jwt'`)
  - `testJwksLoaderMergesFileSources`: two fixture JWKS files written to a temp dir → `assertCount(2, $jwks['keys'], json_encode($jwks, JSON_PRETTY_PRINT))`
- [ ] **Step 4:** Run `./organizer/src/vendor/bin/phpunit organizer/src/tests/Mcp/McpTokenVerifierTest.php`. Expected: FAIL (class not found).
- [ ] **Step 5:** Implement `McpTokenVerifier` using `JWK::parseKeySet($jwks, 'RS256')` and `JWT::decode`. Allow RS256 only. Check `iss` exactly, check `aud` as a string or as an array containing the audience, require non-empty `sub`. Wrap every library exception in `McpAuthException`. Implement `McpJwksLoader`.
- [ ] **Step 6:** Run the tests again. Expected: PASS.
- [ ] **Step 7:** Commit (`composer.json`, `composer.lock`, the three class/fixture files, the test).

### Task 2: JSON-RPC / MCP protocol core

**Files:**
- Create: `organizer/src/class/Mcp/McpServer.php`
- Create: `organizer/src/class/Mcp/McpToolProvider.php`
- Test: `organizer/src/tests/Mcp/McpServerTest.php`

**Interfaces:**
- Produces:
  - `interface McpToolProvider { public function definitions(): array; public function call(string $name, array $args, string $userId): array; }`. `definitions()` returns MCP tool objects (`name`, `description`, `inputSchema`). `call()` returns a list of MCP content items.
  - `class McpToolException extends Exception`, and `class McpNotFoundException extends McpToolException`, whose constructor fixes the message to `Not found, or no access` (both in `McpToolProvider.php`)
  - `new McpServer(McpToolProvider $tools)`
  - `McpServer::handle(mixed $request, string $userId): ?array`. `$request` is the `json_decode(..., true)` result, or the sentinel `McpServer::PARSE_ERROR` when decoding failed. It returns the response array, or `null` for a notification (no `id`).
  - `McpServer::SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26']`

- [ ] **Step 1: Write failing tests** with a fake provider (one tool `echo`, which returns `[['type'=>'text','text'=>$args['x']]]` and throws `McpNotFoundException` when `x === 'missing'`):
  - `testInitializeEchoesSupportedVersion`: request `protocolVersion '2025-06-18'` → `result.protocolVersion == '2025-06-18'`, `result.capabilities == ['tools' => new stdClass]` (encodes as `{}`), `result.serverInfo.name == 'offpost'`
  - `testInitializeUnknownVersionGetsLatest`: `'1999-01-01'` → `'2025-11-25'`
  - `testPing`: `result == []`
  - `testToolsListReturnsDefinitions`
  - `testToolsCallReturnsContent`: `result == ['content'=>[['type'=>'text','text'=>'hi']], 'isError'=>false]`
  - `testToolExceptionBecomesIsError`: `result == ['content'=>[['type'=>'text','text'=>'Not found, or no access']], 'isError'=>true]`
  - `testUnknownToolIsInvalidParams`: `error.code == -32602`
  - `testUnknownMethod`: `error.code == -32601`
  - `testNotificationReturnsNull`: `{"jsonrpc":"2.0","method":"notifications/initialized"}` → `null`
  - `testParseError`: `McpServer::PARSE_ERROR` → `error.code == -32700`, `id === null`
  - `testBatchArrayRejected`, `testMissingMethodRejected`: `error.code == -32600`
  - `testUnexpectedThrowableIsInternalError`: provider throws `RuntimeException('db down')` → `error.code == -32603`, `error.message == 'Internal error'` (no leak)
- [ ] **Step 2:** Run the tests. Expected: FAIL.
- [ ] **Step 3:** Implement `McpServer`. Log unexpected throwables with `error_log`.
- [ ] **Step 4:** Run the tests. Expected: PASS.
- [ ] **Step 5:** Commit.

### Task 3: Extract thread creation and reply into shared services

**Files:**
- Create: `organizer/src/class/ThreadCreationService.php`
- Create: `organizer/src/class/ThreadReplyService.php`
- Modify: `organizer/src/start-thread.php:254-310` (loop body calls the service)
- Modify: `organizer/src/thread-reply.php:63-114` (validation, create and history call the service)
- Modify: `organizer/src/class/ThreadDatabaseOperations.php:429` and `class/ThreadStorageManager.php:32` (optional `array $historyDetails = []`, passed to `logAction($uuid, 'created', $userId, $historyDetails ?: null)`)
- Test: `organizer/src/tests/ThreadCreationServiceTest.php`, `organizer/src/tests/ThreadReplyServiceTest.php`

**Interfaces:**
- Produces:
  - `ThreadCreationService::createThread(string $entityId, string $title, string $body, string $userId, string $lawBasis, ?string $followUpPlan, bool $sendNow, bool $public, array $labels, array $historyDetails = []): Thread`. This is exactly what the page does today for one entity: random profile, `initial_request`, sending_status, `createThread`, `addUser($userId, true)`, and `ThreadEmailSending::create` with the body plus the signature delimiter and name, at `READY_FOR_SENDING` when `$sendNow`, else `STAGING`. Throws `InvalidArgumentException` for an unknown entity (via `Entity::getById`). Does not open a transaction; the caller owns it.
  - `class ThreadReplyValidationException extends Exception`
  - `ThreadReplyService::queueReply(Thread $thread, string $subject, string $body, string $recipient, string $userId, bool $send, array $historyDetails = []): ThreadEmailSending`. Behaves as `thread-reply.php` does today: empty subject, body or recipient, or a recipient (lower-cased, trimmed) not in `getThreadReplyRecipients($thread)`, throws `ThreadReplyValidationException`. The status is `READY_FOR_SENDING` if `$send`, else `STAGING`. History action `thread_reply_created_and_queued_sending` / `thread_reply_draft` with details `['email_sending_ids'=>[id], 'recipient'=>..., 'subject'=>...] + $historyDetails`. **No authorization check**; callers do that.

- [ ] **Step 1: Write failing tests.** Use the `NpApiServiceClassifyReplyTest` pattern (transaction in setUp, rollback in tearDown), entity `'000000000-test-entity-development'`, and user `'mcp-test-user'`:
  - `testCreateThreadQueuesRequestAndMakesOwner`: `sending_status == READY_FOR_SENDING`, `ThreadAuthorizationManager::isThreadOwner($id,'mcp-test-user')`, one `thread_email_sendings` row with status `READY_FOR_SENDING` and `email_to` equal to the entity email
  - `testCreateThreadStagingWhenNotSendNow`
  - `testCreateThreadHistoryCarriesDetails`: `['via'=>'mcp']` → the `created` row's details decode to `['via'=>'mcp']`
  - `testCreateThreadUnknownEntityThrows`
  - `testQueueReplyReadyForSending`, `testQueueReplyDraft`
  - `testQueueReplyInvalidRecipientThrows`, `testQueueReplyEmptyBodyThrows`
  - `testQueueReplyHistoryMergesDetails`: details `== ['email_sending_ids'=>[$id],'recipient'=>...,'subject'=>...,'via'=>'mcp']`
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement both services and switch the two pages to use them. Page behaviour (redirects, session messages, group label, transaction) stays as it is.
- [ ] **Step 4:** Run the new tests plus `organizer/src/tests/ThreadReplyTest.php`. Then run `e2e-tests/pages/ThreadStartPageTest.php` and `ThreadReplyPageTest.php` against the dev stack. Expected: all PASS.
- [ ] **Step 5:** Commit.

### Task 4: Read tools - `list_entities`, `list_my_threads`, `get_thread`

**Files:**
- Create: `organizer/src/class/Mcp/McpTools.php` (implements `McpToolProvider`)
- Modify: `organizer/src/class/NpApiService.php:689`. Make `threadStateTypesByThreadId` public static (reused; no behaviour change).
- Test: `organizer/src/tests/Mcp/McpToolsReadTest.php`

**Interfaces:**
- Consumes: `McpToolProvider`, `McpToolException`, `McpNotFoundException` (Task 2).
- Produces: `McpTools` with tool names exactly `list_entities`, `list_my_threads`, `get_thread`, `get_attachment_raw`, `get_attachment_extraction`, `start_thread`, `send_reply` (the last four are added in Tasks 5-6). Private helper `requireUuid(mixed $v): string`, which throws `McpNotFoundException` when the value isn't a UUID (regex from `np_attachment_get.php`). Each tool returns one `text` item holding `json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)`.

Inputs and outputs (`inputSchema` uses JSON Schema `type: object` and `additionalProperties: false`):
- `list_entities {search?: string, limit?: int}`: case-insensitive substring of `name` or exact `org_num`, sorted by name. `limit` defaults to 50, clamped to 1..200. Output `{entities:[{entity_id,name,type,org_num,email}], truncated: bool}`.
- `list_my_threads {search?: string, include_archived?: bool, limit?: int}`: one SQL query over `thread_authorizations ta JOIN threads t` where `ta.user_id = ?`, plus entity names from `Entity::getAll()`. `search` matches title or entity name, case-insensitive. Archived threads are excluded unless `include_archived`. Sorted by last activity, newest first. Same limit rule. Output `{threads:[{thread_id,title,entity_id,entity_name,sending_status,thread_state_type,email_count_in,email_count_out,last_activity,archived,is_owner}], truncated}`, with `last_activity` in ISO-8601.
- `get_thread {thread_id}`: `requireUuid`, then `Thread::loadFromDatabaseOrNone`. If the thread is null or `!canUserAccess($userId)`, throw `McpNotFoundException`. Otherwise return `ThreadExportService::exportThread($id, false)`, keeping `thread`, `entity`, `emails`, `sendings`. In each email, drop `body_html`, `imap_headers` and `history`. Every `extractions` list (on emails and attachments) is reduced to `{extraction_id,prompt_id,prompt_service,has_text,error_message}`. Attachments drop `location`. Add `thread_url` (`NpApiService::threadUrl`).

- [ ] **Step 1: Write failing tests.** Create threads with `ThreadCreationService` for user A, grant user B on one of them via `ThreadAuthorizationManager::addUserToThread`, and make one public thread owned by A. Then:
  - `testListEntitiesSearchByName`: assert the exact entity_id list for a fixed search
  - `testListEntitiesLimitAndTruncated` (`limit: 1`) → `assertCount(1, ...)` and `truncated === true`
  - `testListMyThreadsOnlyAuthorized`: B sees only the granted thread, and not A's public thread; assert exact thread_id list
  - `testListMyThreadsHidesArchivedByDefault`, `testListMyThreadsIncludeArchived`
  - `testGetThreadOwn`: fields present; `body_html`, `imap_headers` and `eml_base64` absent
  - `testGetThreadPublicReadableByOther` (B reads A's public thread)
  - `testGetThreadOtherUsersPrivateIsNotFound`: tool result text `Not found, or no access`, `isError` true (call through `McpServer` for the full result shape)
  - `testGetThreadNonUuidIsNotFound` (`'abc'`)
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS. Also rerun `tests/NpApiServiceListTest.php`, which must still PASS.
- [ ] **Step 5:** Commit.

### Task 5: Attachment tools - `get_attachment_raw`, `get_attachment_extraction`

**Files:**
- Modify: `organizer/src/class/Mcp/McpTools.php`
- Test: `organizer/src/tests/Mcp/McpToolsAttachmentTest.php`

**Interfaces:**
- Consumes: `McpTools` (Task 4), `ThreadStorageManager::getThreadEmailAttachment(Thread, $attachmentId)`, `ThreadEmailExtractionService::getExtractionsForAttachment(string)`, `NpApiService::attachmentContentType(?string)`.
- Produces:
  - `McpTools::MAX_RAW_ATTACHMENT_BYTES = 5242880`
  - `get_attachment_raw {thread_id, attachment_id}`: the access check is the same as `get_thread`, and the attachment must belong to one of that thread's emails, otherwise `McpNotFoundException`. NULL content raises `McpToolException('Attachment content was never stored; try get_attachment_extraction')`. Content over the cap raises `McpToolException('Attachment is larger than 5 MB; use get_attachment_extraction for its text')`. For an `image/*` type, return `[{type:'image', data: base64, mimeType}]`. Otherwise return `[{type:'resource', resource:{uri:'offpost://thread/<tid>/attachment/<aid>', mimeType, blob: base64}}]`, preceded by a `text` item with `{name, filename, mimeType, size}`.
  - `get_attachment_extraction {thread_id, attachment_id, prompt_id?}`: same access check. Output `{attachment_id, extractions:[{extraction_id,prompt_id,prompt_service,extracted_text,error_message,created_at,updated_at}]}`, filtered by `prompt_id` when given. An empty list is a valid result, not an error.

- [ ] **Step 1: Write failing tests.** Insert attachment rows directly with SQL, following the `ThreadEmailAttachmentTest` style, using fixed bytes `'%PDF-1.4 test'` and a 1x1 PNG constant:
  - `testRawPdfReturnsResourceBlob`: `assertEquals(base64_encode('%PDF-1.4 test'), $content[1]['resource']['blob'])`
  - `testRawImageReturnsImageContent`
  - `testRawNullContentIsError`, `testRawOverCapIsError`: exact messages as above; for the cap, lower the cap via a protected test-only override property, not real 5 MB data
  - `testRawAttachmentFromOtherThreadIsNotFound` (valid thread you can access, attachment id from another thread)
  - `testRawPublicThreadReadableByOther`, `testRawPrivateThreadOtherUserNotFound`, `testRawNonUuidNotFound`
  - `testExtractionReturnsAll`: two extractions → `assertCount(2, ..., json_encode(..., JSON_PRETTY_PRINT))`
  - `testExtractionFilterByPromptId`, `testExtractionNoneIsEmptyList`
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit.

### Task 6: Write tools - `start_thread`, `send_reply`

**Files:**
- Modify: `organizer/src/class/Mcp/McpTools.php`
- Test: `organizer/src/tests/Mcp/McpToolsWriteTest.php`

**Interfaces:**
- Consumes: `ThreadCreationService::createThread`, `ThreadReplyService::queueReply` (Task 3).
- Produces:
  - `start_thread {entity_id, title, body, law_basis?, follow_up_plan?}`: `law_basis` is one of `offentleglova` (default) or `other`. `follow_up_plan` is one of `speedy` (default), `slow`, `postliste` or `none` (which stores null). The call is `createThread(..., sendNow: true, public: false, labels: [], historyDetails: ['via'=>'mcp'])` inside a `Database` transaction. An unknown entity or an invalid enum raises `McpToolException('Unknown entity_id: <id>')` or `McpToolException('Invalid law_basis')` / `McpToolException('Invalid follow_up_plan')`. Empty title or body raises `McpToolException('title and body are required')`. Output `{thread_id, thread_url, sending_status:'READY_FOR_SENDING'}`. The UI's `group-…` label is not added, because MCP creates one thread per call.
  - `send_reply {thread_id, subject, body, recipient}`: `requireUuid`, load the thread, and require `ThreadAuthorizationManager::canUserAccessThread($id, $userId)`; otherwise `McpNotFoundException`. Then call `queueReply(..., send: true, historyDetails: ['via'=>'mcp'])`. A `ThreadReplyValidationException` becomes `McpToolException` with the same message. Output `{email_sending_id, thread_id, recipient, status:'READY_FOR_SENDING'}`. The tool description lists how to find valid recipients: they are the thread's entity email and the addresses seen in the thread, as `getThreadReplyRecipients` does. Also add `reply_recipients` to the `get_thread` output, so the AI can see them.

- [ ] **Step 1: Write failing tests:**
  - `testStartThreadDefaults`: law basis `offentleglova`, plan `speedy`, owner is the caller, sending row `READY_FOR_SENDING`, `created` history details `['via'=>'mcp']`
  - `testStartThreadPlanNoneStoresNull`, `testStartThreadUnknownEntity`, `testStartThreadInvalidPlan`, `testStartThreadEmptyTitle`
  - `testSendReplyOwnerQueues`: sending row status, subject, recipient; history action `thread_reply_created_and_queued_sending` with `via: mcp`
  - `testSendReplyGrantedUserQueues`
  - `testSendReplyPublicNotGrantedIsNotFound`: and no sending row is created
  - `testSendReplyInvalidRecipientIsError`, `testSendReplyNonUuidNotFound`
  - `testGetThreadIncludesReplyRecipients`
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit.

### Task 7: HTTP endpoint, metadata, routing, config, e2e

**Files:**
- Create: `organizer/src/api/mcp/mcp.php`
- Create: `organizer/src/api/mcp/oauth_protected_resource.php`
- Create: `organizer/src/class/Mcp/McpHttp.php`
- Modify: `organizer/src/webroot/index.php` (add `'/mcp'` and `'/.well-known/oauth-protected-resource'` next to `'/api/np/threads'`, line ~71)
- Create: `organizer/src/mcp-config.php` (not `username-password.php`, which holds credentials and is off-limits). Required by the MCP endpoint files after `username-password.php`; defines these non-secret variables per `$environment` (PHPUnit uses the development values). Test key trust (the fixture JWKS) must only ever be in the development branch:
  - `$mcp_resource`: dev `http://localhost:25081/mcp`, prod `https://offpost.no/mcp`
  - `$mcp_authorization_server`: dev `http://localhost:25083/oidc`, prod `https://auth.offpost.no/`
  - `$mcp_issuer`: same as `$mcp_authorization_server`
  - `$mcp_jwks_sources`: dev `['http://auth:3000/oidc/jwks', __DIR__ . '/tests/fixtures/mcp/test-jwks.json']`, prod `['https://auth.offpost.no/.well-known/jwks.json']`
- Create: `organizer/src/tests/fixtures/mcp/test-jwks.json` (public half of `McpTestKeys`, dev-only trust)
- Test: `organizer/src/tests/Mcp/McpHttpTest.php`, `organizer/src/e2e-tests/pages/McpApiTest.php`

**Interfaces:**
- Consumes: `McpTokenVerifier`, `McpJwksLoader` (Task 1), `McpServer` (Task 2), `McpTools` (Tasks 4-6).
- Produces:
  - `McpHttp::bearerToken(array $server, array $headers): ?string`. Reads `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then a case-insensitive `Authorization` in `$headers`. The scheme `Bearer` is matched case-insensitively.
  - `McpHttp::wwwAuthenticate(string $metadataUrl, bool $invalidToken): string`. Returns `Bearer resource_metadata="<url>"`, followed by `, error="invalid_token"` when `$invalidToken` is true.
  - `GET /.well-known/oauth-protected-resource` returns `{"resource": $mcp_resource, "authorization_servers": [$mcp_authorization_server], "bearer_methods_supported": ["header"]}`.
  - `/mcp`:
    - Only POST is accepted; anything else returns 405 with `Allow: POST`.
    - A missing token returns 401 with `WWW-Authenticate` and no `error`.
    - A bad token returns 401 with `error="invalid_token"`.
    - Otherwise, decode the body and call `McpServer::handle`. A `null` response gives 202 with an empty body; any other response gives 200 with `application/json`.
    - No session is started, and `auth.php` is not required.

- [ ] **Step 1: Write failing unit tests** in `McpHttpTest`: `bearerToken` covering each of the three sources, lower-case `bearer`, `Basic` returning null; and the two `wwwAuthenticate` strings asserted exactly.
- [ ] **Step 2: Write failing e2e tests** in `McpApiTest`. Use base `http://localhost:25081` and tokens from `McpTestKeys::token()` with `iss` `http://localhost:25083/oidc`, `aud` `http://localhost:25081/mcp`, `sub` `dev-user-id` (`DEV_USER.id` in `auth/server.js:33`):
  - `testProtectedResourceMetadata`: exact JSON
  - `testNoTokenIs401WithMetadataHeader`: exact header value
  - `testBadTokenIs401InvalidToken`
  - `testGetIs405`
  - `testInitializeAndToolsList`: real `Authorization` header (Review Focus 1); assert the seven tool names exactly, in order
  - `testListMyThreadsThroughHttp`: returns a `text` result that decodes to an array with `threads`
- [ ] **Step 3:** Run them. Expected: FAIL.
- [ ] **Step 4:** Implement the endpoint files, `McpHttp`, routes and config.
- [ ] **Step 5:** Rebuild/restart the dev stack (`docker-compose -f docker-compose.dev.yaml down && docker-compose -f docker-compose.dev.yaml up --build -d`, using `sg docker -c` if needed). Run unit and e2e tests. Expected: PASS.
- [ ] **Step 6:** Commit.

### Task 8: `file.php` follows the read rule

**Files:**
- Modify: `organizer/src/file.php:39`. Change to `if (!$thread->canUserAccess($_SESSION['user']['sub']))`. Keep the 403 path as it is.
- Test: `organizer/src/e2e-tests/pages/FilePageTest.php`

- [ ] **Step 1: Write failing e2e tests:**
  - `testFilePublicThreadOtherUserCanDownload`: a public thread owned by another user, fetched with the logged-in e2e user's session → 200, body equals the attachment bytes
  - `testFilePrivateThreadOtherUserForbidden` → 403
- [ ] **Step 2:** Run them. Expected: the first FAILS (403) and the second PASSES.
- [ ] **Step 3:** Make the one-line change.
- [ ] **Step 4:** Run the whole `FilePageTest.php`. Expected: PASS.
- [ ] **Step 5:** Commit. The message cites `30662102` (when the public exception was added) and `ac5904c7` (the check that skipped it).

### Task 9: Dev OAuth provider, docs, README

**Files:**
- Modify: `auth/server.js`
- Create: `docs/mcp.md`
- Modify: `README.md`

- [ ] **Step 1:** In `auth/server.js`:
  - Set the issuer to `http://localhost:25083/oidc`. The provider is mounted under `/oidc`, so discovery then sits at `{issuer}/.well-known/openid-configuration`.
  - Set `features.registration: { enabled: true }`.
  - Set `features.resourceIndicators` to:
    - `enabled: true`
    - `defaultResource: () => 'http://localhost:25081/mcp'`
    - `useGrantedResource: () => true`
    - `getResourceServerInfo: () => ({ scope: 'openid', audience: 'http://localhost:25081/mcp', accessTokenFormat: 'jwt', jwt: { sign: { alg: 'RS256' } } })`
  - Set `pkce.required: () => true` for public clients only. Keep the `organizer` client working: it is confidential, so allow PKCE to be optional when a `client_secret` is used.
  - Do not edit `username-password.php`. Check that the existing dev login at `http://localhost:25081` still works after the issuer change; if it breaks, report it to the controller.
- [ ] **Step 2:** Manual verification:
  1. Run `claude mcp add --transport http offpost-dev http://localhost:25081/mcp`.
  2. In Claude Code, run `/mcp` and authenticate. Expected: a browser login, then the seven tools are listed.
  3. Call `list_my_threads`. Expected: the dev user's threads.
  4. Record the outcome in the commit message.
- [ ] **Step 3:** Write `docs/mcp.md`. It covers:
  - endpoint and metadata URLs
  - the auth flow
  - Auth0 tenant setup:
    - an API with identifier `https://offpost.no/mcp`, RS256
    - OIDC Dynamic Application Registration enabled
    - login connection(s) promoted to domain level
    - Resource Parameter Compatibility Profile enabled
    - the API allows user access for dynamically registered clients
  - adding the connector in claude.ai (Settings → Connectors → Add custom connector → `https://offpost.no/mcp`)
  - a reference for the seven tools, with the access rule for each
  - error text
  - the 5 MB raw cap
  - history `via: mcp`
  - the dev setup and the test-only key note
  - the `file.php` public-thread change

  Add a link to `docs/mcp.md` in CLAUDE.md's docs list, and a capability line in README.md.
- [ ] **Step 4:** Run all tests: `./organizer/src/vendor/bin/phpunit organizer/src/tests/ organizer/src/e2e-tests/`. Expected: PASS.
- [ ] **Step 5:** Commit.
