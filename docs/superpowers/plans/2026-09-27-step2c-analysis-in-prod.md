# Step 2c: analysis stored and shown in prod

Plan for step 2c of
`docs/superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md`.
It is split into six changes, one commit each. This file is concrete for
change 1; later changes get their detail when they start.

## Design, agreed with the owner

- **Who does what:** a worker on the owner's machine makes the model calls
  with headless Claude Code. Prod stores everything and shows it. The worker
  talks to prod with the admin token.
- **Queue:** on request only for now. Automatic queueing comes later.
- **History:** every run of every thread is kept.
- **Anthropic-specific data** goes in its own table. `openai_request_log` is
  left unchanged, for OpenAI.

### Tables

**`thread_analysis_runs`**: one analysis of one thread; this is also the queue.

| Column | Type | Meaning |
|---|---|---|
| `id` | bigserial PK | |
| `thread_id` | uuid FK threads | |
| `status` | varchar | `requested`, `claimed`, `done`, `failed`, `cancelled` |
| `mode` | varchar | `incremental` (after the last analysed email, from its state) or `full` (from the start) |
| `requested_by` | varchar | admin sub, or `token` |
| `requested_at` | timestamptz | |
| `claimed_at`, `lease_expires_at` | timestamptz | an expired lease makes the run claimable again |
| `worker` | varchar | worker host name |
| `model` | varchar | model requested by the worker |
| `system_prompt_sha256` | char(64) FK thread_analysis_system_prompts | |
| `schema_version` | int | |
| `finished_at` | timestamptz | |
| `error` | text | |

**`thread_analysis_system_prompts`**: the system prompt, stored once per version.

| Column | Type |
|---|---|
| `sha256` | char(64) PK |
| `text` | text |
| `created_at` | timestamptz |

**`thread_analysis_events`**: the provider-neutral result for one event in a run.

| Column | Type | Meaning |
|---|---|---|
| `id` | bigserial PK | |
| `run_id` | bigint FK runs | |
| `email_id` | uuid FK thread_emails | |
| `position` | int | 1-based order within the run |
| `email_type` | varchar | a `ThreadEmailStatusType` value |
| `email_note` | text | |
| `email_type_gap` | text | |
| `thread_state` | jsonb | the validated blob after this email, in this run |
| `derived_thread_state_type` | varchar | derived by prod with `ThreadStateTypeDeriver` |
| `attempts` | int | |
| `error` | text | |
| `created_at` | timestamptz | |

Unique (`run_id`, `position`).

**`thread_analysis_claude_code_calls`**: everything Anthropic-specific, one row per call, retries included.

| Column | Type |
|---|---|
| `id` | bigserial PK |
| `run_id` | bigint FK runs |
| `event_id` | bigint FK events |
| `attempt` | int |
| `input_text` | text: the full input as sent |
| `json_schema` | jsonb |
| `response` | jsonb: Claude Code's full JSON reply |
| `model`, `model_resolved`, `session_id`, `claude_code_version` | varchar |
| `input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`, `output_tokens`, `thinking_tokens` | int |
| `cost_usd` | numeric(12,6) |
| `duration_ms`, `duration_api_ms` | int |
| `is_error` | boolean |
| `stop_reason` | varchar |
| `created_at` | timestamptz |

### Applying a run

When a run is saved as `done`, each event's state is copied onto its email:

- `thread_emails.thread_state` = the event's `thread_state`;
- `thread_emails.thread_state_type` = its `derived_thread_state_type`;
- `thread_emails.thread_state_source` = `auto`.

An email whose `thread_state_source` is `manual` is never changed. A `failed` run applies nothing.

## Change 1: storage and the service (this change)

- **Migration `034_add_thread_analysis_tables.sql`**, plus the regenerated schema dump.
  - The four tables with FKs, CHECK constraints on `status` and `mode`, and indexes: runs (`status`, `requested_at`) and (`thread_id`); events (`run_id`) and (`email_id`); calls (`run_id`) and (`event_id`).
- **`organizer/src/class/ThreadAnalysis/ThreadAnalysisRepository.php`**, database operations only:
  - `requestRun(string $threadId, string $mode, string $requestedBy): int`. If the thread already has a run in `requested` or `claimed`, return that run's id and create nothing.
  - `claimNext(string $worker, int $leaseSeconds): ?array`. Takes the oldest `requested` run, or a `claimed` run whose lease has expired, with `FOR UPDATE SKIP LOCKED` so two workers never get the same run. Sets `claimed`, `claimed_at`, `lease_expires_at` and `worker`, and returns the run row.
  - `claimThread(string $threadId, string $worker, int $leaseSeconds): ?array`. The same, for the given thread's open run.
  - `saveSystemPrompt(string $text): string`. Upserts by sha256 and returns the sha.
  - `saveResult(int $runId, string $worker, array $result): void`. The format is below. It runs in one transaction:
    - validates;
    - stores the events and calls;
    - sets the run to `done` or `failed`, with `finished_at`, `model`, `system_prompt_sha256`, `schema_version` and `error`;
    - on `done`, applies the run to `thread_emails`.
  - `getRun(int $runId): ?array`, `getRunsForThread(string $threadId): array`, and `getEventsForRun(int $runId): array`, for the later changes.
- **Validation in `saveResult`.** Each failure throws an `InvalidArgumentException` with a precise message:
  - The run must exist, be `claimed`, and be claimed by `$worker`.
  - Every `email_id` must belong to the run's thread.
  - `position` starts at 1 and has no gaps.
  - On an event without an `error`, `thread_state` must pass `ThreadState::fromArray`. Prod derives `derived_thread_state_type` itself and ignores anything the worker sends for it.
  - `email_type` must be a `ThreadEmailStatusType` value. On an event with an `error` it may be null instead, because the model's answer was invalid.
  - Counts are non-negative ints, `cost_usd` a number, and every call has an `attempt`.

### Result format (`$result`)

This is the same array the result endpoint in change 2 will receive as JSON.

```json
{
  "status": "done",
  "error": null,
  "model": "claude-opus-5-5",
  "system_prompt": "<full text of tools/analysis/event-prompt.md>",
  "schema_version": 1,
  "events": [
    {
      "email_id": "<uuid>", "position": 1,
      "email_type": "OUR_REQUEST", "email_note": "…", "email_type_gap": "",
      "thread_state": { },
      "attempts": 1, "error": null,
      "calls": [
        { "attempt": 1, "input_text": "…", "json_schema": { }, "response": { },
          "model": "claude-opus-5-5", "model_resolved": "claude-opus-5-5",
          "session_id": "…", "claude_code_version": "2.1.283",
          "input_tokens": 2, "cache_creation_input_tokens": 6363,
          "cache_read_input_tokens": 0, "output_tokens": 1243, "thinking_tokens": 98,
          "cost_usd": 0.076, "duration_ms": 12600, "duration_api_ms": 12100,
          "is_error": false, "stop_reason": "end_turn" }
      ]
    }
  ]
}
```

- `status` is `done` or `failed`.
- A failed run still carries the events and calls up to and including the failed event. The failed event has `error` set and a null `thread_state`.

### Tests

`organizer/src/tests/ThreadAnalysisRepositoryTest.php` runs against the test database, with fixed data. It covers:

- **Requesting:**
  - `requestRun` creates a run.
  - A second request while one is open returns the same id.
  - After `done`, a new request creates a new run.
- **Claiming:**
  - Takes the oldest run first.
  - Skips runs that are `done` or `failed`.
  - Retakes a run whose lease has expired (set `lease_expires_at` in the past by SQL).
  - `claimThread` takes only the given thread's run.
- **`saveSystemPrompt`** is idempotent: the same text gives the same sha and one row.
- **`saveResult` done:**
  - The events and calls are stored exactly.
  - The run fields are set.
  - `derived_thread_state_type` is computed by prod: sending a wrong one has no effect.
  - The state is applied to `thread_emails`.
  - A `manual` email is left untouched.
- **`saveResult` failed:** the rows are stored, the run is `failed` with its error, and nothing is applied.
- **Validation errors:**
  - a run that isn't claimed;
  - the wrong worker;
  - an email from another thread;
  - a gap in the positions;
  - an invalid `thread_state`;
  - an invalid `email_type`.

Each asserts the exact message, and asserts that nothing was written, because it's one transaction.

## Change 2: the endpoints

These are POST only and accept the admin token only: no admin session, so no
cross-site request can reach them.

| Route | File | Body | Response |
|---|---|---|---|
| `POST /api/admin/analysis/request` | `api/admin/analysis_request.php` | `{"thread_id", "mode"}` | `{"run_id"}`: the new run, or the thread's already open run |
| `POST /api/admin/analysis/claim` | `api/admin/analysis_claim.php` | `{"worker", "thread_id"?}` | 204 with no body if nothing is claimable, else the work item below |
| `POST /api/admin/analysis/result` | `api/admin/analysis_result.php` | `{"run_id", "worker", …the result from change 1}` | `{"run_id", "status"}` |

- **Auth:** a new `adminApiRequireToken()` in `api/admin/admin-api-auth.php`. It accepts the token only and returns 401 JSON otherwise. Like the export endpoints, it runs first, before anything else.
- **Errors:**
  - 405 for anything but POST;
  - 400 for invalid JSON, a missing field, a non-UUID `thread_id` or a bad `mode`;
  - 400 with the message for any `InvalidArgumentException` from the repository;
  - 404 for an unknown thread or run.
- **Logging:** each successful call is logged with `error_log`, like the export endpoints.
- **Routes:** added to `$regularPages` in `webroot/index.php`.
- `requested_by` is `token`.
- **Claim lease:** 3600 seconds.

### Work item (claim response)

```json
{
  "run": { "id": 12, "thread_id": "…", "mode": "incremental", "lease_expires_at": "…" },
  "thread": { "…": "the thread export (export_version 1) with every email's eml_base64 left out" },
  "start_state": { } ,
  "start_after_email_id": "…",
  "email_ids": ["…", "…"]
}
```

Choosing the emails is a pure function,
`ThreadAnalysisWorkItem::selectEmails(array $exportEmails, string $mode): array`,
returning `start_state`, `start_after_email_id` and `email_ids`. It works on
the export's emails, leaves out `ignore = true`, and orders them by
`datetime_received`, then `id`.

- **`full`:** every such email, `start_state` null, `start_after_email_id` null.
- **`incremental`:**
  - find the last email, in that order, whose `thread_state` is not null;
  - `start_state` is that state, and `start_after_email_id` is its id;
  - `email_ids` are the emails after it.
  - With no such email, it behaves like `full`.
  - With nothing after it, `email_ids` is empty. The worker then posts `done` with no events, and saving a `done` result with an empty `events` list must be accepted.

`ThreadExportService::exportThread()` gets an optional `bool $includeEml = true`.
With `false`, `eml_base64` is left out.

### Tests

- **Unit, `ThreadAnalysisWorkItemTest`:**
  - full;
  - incremental from the middle;
  - incremental with no earlier state;
  - incremental with nothing new;
  - ignored emails left out;
  - ordering ties broken by `id`.
- **Unit, `AdminApiAuthTest`:** `adminApiRequireToken`, if it can be tested the way the existing functions are.
- **Unit, `ThreadAnalysisRepositoryTest`:** a `done` result with no events.
- **Unit, `ThreadExportServiceTest`:** `includeEml = false` leaves out `eml_base64` and nothing else.
- **E2E, `AdminAnalysisApiTest`:**
  - 401 without a token, and 401 with an admin session and no token (for all three);
  - 401 with the NP token;
  - 405 on GET;
  - 400 on invalid JSON and on a bad mode;
  - 404 on an unknown thread;
  - the whole flow on a thread from `E2ETestSetup`: request, then claim (check `email_ids` and that there is no `eml_base64`), then post a done result with one valid event and call, then check the run is `done` and `thread_emails.thread_state_source` is `auto`;
  - claim returns 204 when nothing is queued. Make that deterministic: cancel or finish any open runs first, or claim with `thread_id`.

## Change 3: the worker, `tools/analysis-worker.php`

It runs on the owner's machine: a client of the change 2 endpoints, calling
headless Claude Code.

```
php tools/analysis-worker.php --base-url=https://offpost.no --token-file=secrets/admin_api_token
    [--thread=<id> [--mode=incremental|full]] [--once] [--worker=<name>]
    [--model=claude-opus-5-5] [--max-budget-usd=20] [--out=thread-analysis]
    [--claude-bin=claude] [--background] [--help]
```

- **Base URL:** `ThreadExportSync::isSafeBaseUrl` must accept it (https, or http to localhost), since the token is sent.
- **`--worker`:** defaults to `gethostname()`.
- **Loop:**
  1. Resend pending results (see below).
  2. Stop if the summed cost of this worker process has reached `--max-budget-usd`.
  3. Claim. A 204 means stop.
  4. Analyse the run.
  5. Save the result locally, then post it.
  6. Stop after one run with `--once`, else repeat.
- **`--thread`:** first POSTs `/api/admin/analysis/request` with that thread and `--mode` (default `incremental`), then claims with that `thread_id`. It implies `--once`.
- **Analysing a run:**
  - For each id in `email_ids`, in order: build the input with `ThreadEventAnalysis::buildEventInput` from the work item's thread, that email and the previous state (`start_state` for the first). Then call Claude Code, validate, and retry once on an invalid answer, exactly as `tools/analyze-threads.php` does.
  - Every call, retries included, is recorded in the change 1 call format:
    - `input_text`, `json_schema`, `response` (the full Claude Code JSON);
    - `model` and `model_resolved` (from `modelUsage`), `session_id`;
    - `claude_code_version` (from `claude --version`, once per process);
    - the tokens, `cost_usd`, `duration_ms`, `duration_api_ms`, `is_error`, `stop_reason`.
  - If an event fails after two attempts:
    - the run is `failed` with that error;
    - the events up to and including the failed one are sent;
    - the failed event has `error`, a null `thread_state`, and `email_type` null unless the last answer had a valid one.
  - `system_prompt` is the prompt file's text. `schema_version` is 1.
- **Pending results:**
  - Before posting, the full POST body is written to `<out>/worker/pending/<run-id>.json` (atomically).
  - On a 200, it's moved to `<out>/worker/posted/<run-id>.json`.
  - On a network error or a 5xx, it stays pending and is resent at the next loop start or the next worker start. The worker then stops, so it doesn't keep claiming while prod is failing.
  - On a 4xx, it's moved to `<out>/worker/rejected/<run-id>.json`, with the response body saved next to it as `<run-id>.error.txt`. That is logged, and the worker carries on.
  - So no paid analysis is lost.
- **Log:**
  - `<out>/worker/worker.log`, one line per event, in the same format as `analyze-threads.php`, plus claim/post lines.
  - It's also echoed to a tty.
  - `--background` relaunches detached like `analyze-threads.php`, with the process output in `<out>/worker/process.log`.
- **Shared code:**
  - The Claude Code call, the validation and the retry move out of `tools/analyze-threads.php` into `tools/analysis/ClaudeCodeEventRunner.php`.
  - It exposes a method that takes the input text, prompt file, schema, model and claude bin. It returns the parsed output, the derived type, the error, the attempts and the call records.
  - Both tools use it, and `analyze-threads.php` keeps its behaviour and its tests.

### Tests

- **`organizer/src/tests/AnalysisWorkerCliTest.php`** runs the real worker as a subprocess. It uses the existing fake claude (`organizer/src/tests/fixtures/fake-claude.php`) and a fake prod.
  - The fake prod is `organizer/src/tests/fixtures/fake-analysis-api.php`, a `php -S` router. It keeps its queue in a temp JSON file. It implements request, claim and result, checks the token header, and can be told (through the state file) to answer the next result with a 500 or a 400.
  - Tests:
    - draining a queue of two runs posts two done results with the right events and calls;
    - `--thread` sends a request, then claims that thread;
    - incremental: the input for the first event contains the `start_state`;
    - invalid twice gives a failed run posted with the failed event;
    - a 500 on post leaves the file pending and stops, and the next start resends it;
    - a 400 moves it to rejected;
    - the budget stops claiming;
    - an unsafe `--base-url` is refused;
    - `--help`.
- **`organizer/src/tests/ClaudeCodeEventRunnerTest.php`** covers the call records built from a fake response.
- `AnalyzeThreadsCliTest` must still pass unchanged.
- No test calls the real `claude` or the real prod.

## Change 4a: the thread view shows the analysis (everyone)

`view-thread.php` shows the analysis to everyone who can see the thread.
Admins also get links to the debug pages from 4b. The thread list is
unchanged for now.

- **A "Thread state" block**, just before "Emails in Thread". It is shown only when an email of the thread has a `thread_state`, and uses the state of the latest such email (`datetime_received`, then `id`):
  - The thread status as a badge (`span.label`, with a mapping from `ThreadStateType` to `label_ok`, `label_warn`, `label_error` and `label_info`), and whether it is `auto` or `manual`.
  - Who we're waiting for, and what the entity asked of us (`asks_to_us`).
  - A table of items: asked for, status, and the denial basis (refs, text, and issues as warning badges, since they are complaint grounds).
  - Case numbers, dates (`date` and `what`), and complaint rounds (status, items, outcome).
  - `notes`, when not empty.
  - For admins: a link, "Analysis details", to `/thread-analysis/thread?id=<thread id>`.
- **Per email**, in `.email-header` next to today's classification, when the email has a `thread_state`:
  - a small badge with its `thread_state_type` ("after this email");
  - a `<details>` toggle ("Show state") with the blob as pretty-printed JSON in a `<pre>`, escaped.
- **Code:**
  - The block is rendered by a small, testable class, `organizer/src/class/ThreadState/ThreadStateView.php`, with a static method that returns HTML from a state array.
  - It has its own label mapping. All output is escaped with `htmlspecialchars`.
  - CSS goes in `webroot/css/style.css`, with the version in `head.php` bumped.
- **Norwegian labels** for statuses: add a `ThreadStateType::label()` and a `ThreadStateItemStatus::label()` with short Norwegian texts, used in the view. Examples: `WAITING_FOR_ENTITY` → "Venter på offentlig organ", `WAITING_FOR_US` → "Venter på oss", `PARTLY_DENIED_PARTLY_RELEASED` → "Delvis avslått, delvis utlevert", `ANSWERED_IN_TEXT` → "Besvart i e-posten". The enum value is shown next to the label in a `title` attribute.
- **Tests:**
  - `ThreadStateViewTest`: exact HTML for a small state (assertEquals); escaping of `<script>` in `asked_for` and in `notes`; issues shown as badges.
  - E2E, `ThreadViewPageTest`: with a thread whose email has a state (set it directly in the database in the test), the page shows the block and the per-email badge. Without a state, there's no block.

## Change 4b: admin debug pages

These are admin pages, added to `$adminPages` and linked from the Admin tools in `header.php`. They follow the style of `system-pages/openai-request-log-overview.php`: an inline style block, `summary-box` stats, `label` classes and fixed LIMITs.

- **`/thread-analysis`** (`system-pages/thread-analysis.php`):
  - **Stats:** runs per status; the total cost in USD and tokens (from `thread_analysis_claude_code_calls`) for today, the last 7 days and all time; and cost per model and per system prompt version (short sha, first used).
  - **Queue:** runs that are `requested` or `claimed`, with the thread, mode, requested by and at, worker and lease.
  - **Recent runs** (LIMIT 100): thread (linked to the debug thread page), status, mode, model, events, cost, duration (`finished_at − claimed_at`) and error.
  - **Email-type gaps:** events with a non-empty `email_type_gap` (LIMIT 100), with the thread, the email and the gap.
  - **Disagreements** (LIMIT 100): the latest done run's events whose `email_type` differs from `thread_emails.status_type`. Leave out prod's `unknown` and legacy values. Show both values, and whether prod's value is manual or automatic.
- **`/thread-analysis/thread?id=<uuid>`** (`system-pages/thread-analysis-thread.php`):
  - The thread title, with a link to the thread view.
  - Two POST buttons, "Analyse" (incremental) and "Analyse from the start" (full). Each calls `ThreadAnalysisRepository::requestRun($id, $mode, <admin sub>)` and redirects back. Follow the POST handling style of the existing admin pages.
  - Every run, newest first: status, mode, model, system prompt (short sha, linked), worker, times, error and cost.
  - Per run, its events in a table: position, the email (date, direction, subject), email type, note, gap, derived status, attempts and error. A `<details>` holds the state blob as pretty JSON.
  - Per event, its calls: attempt, model or resolved model, Claude Code version, tokens (input, cache creation, cache read, output, thinking), cost and duration. A `<details>` holds the input text, and one holds the response JSON.
  - Everything is escaped. A 400 for a bad uuid, and a 404 for an unknown thread.
- **`/thread-analysis/system-prompt?sha=<sha>`** (`system-pages/thread-analysis-system-prompt.php`): the prompt text in a `<pre>`, when it was first used, and the runs that used it (count).
- **Queries** live in `ThreadAnalysisRepository`, or a new `ThreadAnalysisStats` class, with unit tests on the test database: cost sums, the disagreement query, and the gap query.
- **E2E** (`ThreadAnalysisPagesTest`):
  - each page renders for an admin, and anonymous users are redirected to login;
  - the thread page shows a run, its event and its call, inserted directly into the database;
  - the "Analyse" POST creates a requested run.

## Change 6: thread status in the norske-postlister API

The owner's decision: norske-postlister needs only the thread status, not
the details.

- `GET /api/np/threads` gets one new field per thread: `"thread_state_type"`.
  - It is the `thread_state_type` of the thread's latest email with a non-null `thread_state_type`, ordered by `datetime_received`, then `id`, and leaving out ignored emails as the list already does.
  - It is `null` when no email has one.
  - The change is additive; nothing else in the response changes.
- It is loaded with one batch query for all listed threads (next to the existing batch loading in `NpApiService::listNpThreads`), not one query per thread.
- `docs/np-api.md` documents the field and its values, linking to `docs/thread-state.md`.
- **Tests,** in the existing NP list tests (`organizer/src/tests/NpApiQueryTest.php` or wherever `listNpThreads` is tested):
  - an unanalysed thread gives `null`;
  - the latest email's status is chosen over an earlier one;
  - an ignored email's status is left out.
  - The existing tests keep passing, with their expected arrays extended only by the new key.

## Change 7: "process next" for norske-postlister threads

The owner wants the worker to take the next norske-postlister thread by
itself, instead of being given a thread id.

- **Endpoint:** `POST /api/admin/analysis/request-next`, file `api/admin/analysis_request_next.php`.
  - Token only (`adminApiRequireToken`), POST only, error style as the other analysis endpoints.
  - Body: `{"kind": "np", "mode"?: "incremental"|"full"}`. `kind` is required and only `np` exists for now; anything else gives a 400. `mode` defaults to `incremental`.
  - Picks the next NP thread:
    - it has the label `norske_postlister_no` (in `threads.labels`), archived or not;
    - it has at least one non-ignored email;
    - it has **no** row in `thread_analysis_runs`, in any status.
    - It is ordered by the latest `datetime_received` of its non-ignored emails, newest first, then by thread id.
  - Queues the thread with `ThreadAnalysisRepository::requestRun(<thread>, <mode>, 'token')`.
  - Returns `{"run_id", "thread_id"}`, or a 204 with no body when no thread is left.
- **Repository:** `ThreadAnalysisRepository::requestNextNpThread(string $mode, string $requestedBy): ?array` does the pick and the request in one transaction and returns `['run_id' => …, 'thread_id' => …]` or null. The endpoint is a thin wrapper around it.
- **Worker:** `tools/analysis-worker.php --next-np [--limit=N] [--mode=…]`.
  - Repeats up to `--limit` times (default 1): POST request-next, then claim with that `thread_id`, analyse, and post the result (with the same pending, posted and rejected handling).
  - Stops early on a 204 ("no more NP threads to analyse"), on the budget, or on a post it can't deliver.
  - `--next-np` cannot be combined with `--thread`, which gives an error. `--once` is implied per iteration: after `--limit` threads it stops, and it doesn't drain the general queue.
  - Log lines: `request-next: np -> run <id> thread <id>` and `request-next: np -> none left`.
- **Fake prod** (`organizer/src/tests/fixtures/fake-analysis-api.php`): supports request-next from a list of candidate NP thread ids in its state file.
- **Tests:**
  - **Unit (`ThreadAnalysisRepositoryTest`):**
    - only NP-labelled threads are picked;
    - a thread with any run (even a failed or done one) is skipped;
    - a thread with no non-ignored emails is skipped;
    - the newest latest email wins;
    - ties are broken by id;
    - null when none is left;
    - the run is created with the given mode.
  - **E2E (`AdminAnalysisApiTest`):** 401 without a token, 405 on GET, 400 for a bad kind, 200 with `run_id` and `thread_id`. The test setup must control which NP threads exist and are unanalysed, or assert only on a thread it created and that is newest.
  - **Worker (`AnalysisWorkerCliTest`):** `--next-np --limit=2` analyses two threads in order, then a third call gives 204 and stops; `--next-np` together with `--thread` is refused.
- **Docs:** the endpoint and the worker mode in `docs/thread-analysis.md`.

## Later changes, direction only
4. **`/thread-analysis`:** the queue, progress, cost per run, thread, model and prompt version, results, disagreements with `status_type`, email-type gaps, and request buttons.
5. **The thread view:** the thread status, each email's state as foldable JSON, request buttons, and a link to 2b feedback when it exists.
6. **The norske-postlister thread API:** the thread status and the current state blob.
