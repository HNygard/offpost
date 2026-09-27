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

## Later changes, direction only

2. **Endpoints** (token only; POST without a session):
   - request a run;
   - claim the next run or a given thread's run. The response includes the thread's export data without the raw EML, the events to analyse, and the state to continue from.
   - post the result.
3. **`tools/analysis-worker.php`:** reuses `ThreadEventAnalysis`, with `--thread`, `--once`, `--background` and a budget cap.
4. **`/thread-analysis`:** the queue, progress, cost per run, thread, model and prompt version, results, disagreements with `status_type`, email-type gaps, and request buttons.
5. **The thread view:** the thread status, each email's state as foldable JSON, request buttons, and a link to 2b feedback when it exists.
6. **The norske-postlister thread API:** the thread status and the current state blob.
