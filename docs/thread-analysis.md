# Local thread analysis (`tools/analyze-threads.php`)

Sub-step 2 of step 2 of
[the innsynskrav classification roadmap](superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md):
[a concrete plan](superpowers/plans/2026-09-27-step2-2-thread-analysis.md). Runs headless Claude
Code once per event (email) of each thread in a local export
([docs/thread-export-api.md](thread-export-api.md)), asking it to classify the email and build
the cumulative thread state ([docs/thread-state.md](thread-state.md)) after it. Local evaluation
only - it never touches prod. It runs on the owner's Claude subscription, not the Anthropic API,
so it needs no API key.

Code: `tools/analyze-threads.php` (the CLI: argv, process orchestration, output files),
`tools/analysis/ThreadEventAnalysis.php` (pure logic: the per-event input text, the JSON schema,
answer validation, resume planning, totals - unit tested directly),
`tools/analysis/event-prompt.md` (the system prompt).

## Running it

```
php tools/pull-thread-export.php --base-url=... --token-file=...   # first, if not done already
php tools/analyze-threads.php --limit=5                            # try a few threads first
php tools/analyze-threads.php --background                        # then the real run
```

```
php tools/analyze-threads.php [--export=thread-export] [--out=thread-analysis]
    [--run=<name>] [--limit=N] [--thread=<id>]... [--parallel=2]
    [--model=claude-opus-5-5] [--max-budget-usd=20] [--claude-bin=claude]
    [--background] [--help]
```

| Option | Meaning |
|---|---|
| `--export=DIR` | The export to read from (default `thread-export`, from `tools/pull-thread-export.php`). |
| `--out=DIR` | Where runs are written (default `thread-analysis`). |
| `--run=NAME` | Run name; the run directory is `<out>/<run>/`. Default: a timestamp, e.g. `2026-09-27T1130`. |
| `--limit=N` | Analyse at most N threads, taken in thread-id order. Ignored when `--thread` is given. |
| `--thread=ID` | Analyse only this thread id. Repeatable. |
| `--parallel=N` | Threads run at once (default 2); within one thread, its events always run strictly in order. |
| `--model=NAME` | Model passed to `claude` (default `claude-opus-5-5`). |
| `--max-budget-usd=N` | Stop starting new calls once the run's summed cost reaches this (default 20). Calls already running finish. |
| `--claude-bin=PATH` | Command to invoke instead of `claude` - only for tests, with a fake that never calls the real CLI. |
| `--background` | Relaunch detached (`nohup ... &`) and exit immediately; prints the run directory, the pid, and a `tail -f` command. |

## What it does, per event

The thread's events are its emails ordered by `datetime_received` then `id`, with `ignore` ones
dropped; threads with no emails at all are skipped. For each event, in order:

1. Build the input text (thread, previous state or `null`, the email, its attachments' extracted
   text - long fields cut and marked `[CUT, original length: N chars]`). An attachment with no
   extracted text gets one of four `(no text: ...)` sentinels instead (`ThreadEventAnalysis::
   attachmentText()`), so the model can tell a PDF scan apart from a failed or missing
   extraction and an unsupported file type - see `tools/analysis/event-prompt.md`'s "Judge from
   what we have".
2. Call `claude -p --model ... --output-format json --json-schema <schema> --system-prompt-file
   tools/analysis/event-prompt.md ...` with the input on stdin. The schema is built from the
   `ThreadState`/`ThreadEmailStatusType` enums.
3. Validate the answer: `email_type` against the allowed set, `thread_state` through
   `ThreadState::fromArray()`, and derive the thread status with `ThreadStateTypeDeriver`. Invalid
   -> retry once with the error appended to the input. Still invalid -> the event is recorded with
   its error, and the thread's status becomes `failed`.
4. Record the event and write `<out>/<run>/threads/<id>.json` atomically, so a run can resume
   mid-thread.

## Resuming

Rerunning the same `--run` name:
- Skips threads whose file says `status: "done"`.
- Continues an `in_progress` thread after its last recorded event.
- Retries a `failed` thread from its failed event (that event is dropped and redone).

## Output

- `<out>/<run>/run.json` - run name, model, `prompt_sha256` (so runs are comparable across prompt
  edits), the `ThreadState` schema version, the resolved options, `started_at`/`finished_at`,
  `stopped_reason` (`null`, or `"budget"` when the budget stopped new calls), and run-wide totals
  (`threads`, plus every `ThreadEventAnalysis::TOKEN_KEYS`/`cost_usd`/`total_input_tokens` field
  below).
- `<out>/<run>/threads/<id>.json` - one file per analysed thread: `status`
  (`done`/`failed`/`in_progress`), its `events` (each with the model's `output`, the derived
  thread-state type, usage/cost/duration, and `email_type_actual` - the export's own
  `status_type`, for comparison), and its own `totals`.
- `<out>/<run>/run.log` - one line per event, e.g.
  `[thread 3/40] <id> event 2/5 IN RESPONSE_TO_REQUEST -> WAITING_FOR_US $0.12 4.1s`, plus retry
  and failure lines. Also echoed to stdout when it is a tty (a foreground run); with
  `--background` stdout instead goes to `process.log` and is not a tty, so nothing is duplicated.
- `<out>/<run>/process.log` - with `--background`, the detached process's own stdout/stderr,
  so a crash leaves a trace. Normally empty.

### Totals

A `totals` block (a thread's own, or a run's run-wide one) is `events`, `cost_usd`, then every key
in `ThreadEventAnalysis::TOKEN_KEYS` - `input_tokens`, `cache_creation_input_tokens`,
`cache_read_input_tokens`, `output_tokens`, `thinking_tokens` - and finally
`total_input_tokens` (`input_tokens + cache_creation_input_tokens + cache_read_input_tokens`).
Headless Claude Code reports almost all input as cache tokens rather than `input_tokens` (a real
run: `input_tokens` around 2, `cache_creation_input_tokens` in the thousands,
`cache_read_input_tokens` in the thousands), so summing only `input_tokens`/`output_tokens` - as
earlier versions of this tool did - understated the actual input by orders of magnitude.
`total_input_tokens` is the figure to look at for "how much input did this cost".

`thread-analysis/` (the default `--out`) is gitignored, next to `thread-export/` - both contain
real email content and must never be committed.

## Budget

`--max-budget-usd` is a run-wide stop, not a per-call limit: the run keeps a running sum of every
call's `total_cost_usd` (both attempts, on a retry) and stops **starting** new calls - for any
thread, including ones already in progress - once that sum reaches the budget. Calls already
running are allowed to finish, and the stop is recorded as `stopped_reason: "budget"` in
`run.json`. A later run with the same `--run` name picks up exactly where it left off.

## Storage in prod

Step 2c (`docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md`) moves analysis from local
files into the database, so prod can store every run and show it. A worker on the owner's machine
still makes the model calls with headless Claude Code; prod only stores and applies results.
`ThreadAnalysisRepository` (`organizer/src/class/ThreadAnalysis/ThreadAnalysisRepository.php`) is
the only class touching these tables.

Four tables, added in migration `034_add_thread_analysis_tables.sql`:

- **`thread_analysis_runs`** - one analysis of one thread, and also the queue: `status` moves
  `requested` -> `claimed` (by a worker, with a lease) -> `done`/`failed`/`cancelled`. `mode` is
  `incremental` (continue after the last analysed email) or `full` (from the start).
- **`thread_analysis_system_prompts`** - the system prompt text, stored once per version, keyed by
  its sha256, so runs sharing a prompt don't duplicate it.
- **`thread_analysis_events`** - the provider-neutral result for one event (email) in a run:
  `email_type`, `email_note`, `email_type_gap`, the validated `thread_state` blob, and
  `derived_thread_state_type` - computed by prod with `ThreadStateTypeDeriver`, never trusted from
  the worker. Unique on (`run_id`, `position`).
- **`thread_analysis_claude_code_calls`** - everything Anthropic-specific, one row per call
  (retries included): the full input/response, model/session/version, token usage, cost, duration,
  and `stop_reason`. `openai_request_log` is unchanged and still used for OpenAI.

### The repository's API

- `requestRun(threadId, mode, requestedBy)` - creates a `requested` run, or returns the id of one
  already open (`requested`/`claimed`) for that thread.
- `claimNext(worker, leaseSeconds)` / `claimThread(threadId, worker, leaseSeconds)` - claim the
  oldest claimable run (`requested`, or `claimed` with an expired lease) for `worker`, using
  `FOR UPDATE SKIP LOCKED` so two workers never claim the same run.
- `saveSystemPrompt(text)` - upserts the prompt by its sha256 (idempotent) and returns the sha.
- `saveResult(runId, worker, result)` - validates and stores a worker's result; see "Applying a
  run" below.
- `getRun(runId)`, `getRunsForThread(threadId)`, `getEventsForRun(runId)` - read-only lookups for
  the endpoints and UI that later changes add.
- `saveReview(runId, status, notes, reviewedBy)` / `getReviews(statuses, limit)` - see "Review
  status and notes per run" below.

### Review status and notes per run

Migration `035_add_review_to_thread_analysis_runs.sql` adds four columns to `thread_analysis_runs`
for the owner's manual review of a finished run (step 2c "Change 9", the first part of step 2b -
issues found this way are fixed by changing the prompt or script and rerunning, preferred, or by a
manual edit done locally with AI, later):

- `review_status` - `NOT_REVIEWED` (the default, so every existing run gets one with no backfill),
  `CORRECT`, `MINOR_ISSUES` or `WRONG`.
- `review_notes` - free text describing any issues found.
- `reviewed_by`, `reviewed_at` - who reviewed it and when.

`ThreadAnalysisRepository::saveReview(runId, status, notes, reviewedBy)` validates `status` and
sets `reviewed_at` to now; only a `done` or `failed` run can be reviewed (a run still queued or
claimed throws `InvalidArgumentException`). `getReviews(statuses, limit)` returns runs with their
review fields plus thread id/title, model, `system_prompt_sha256`, `finished_at` and cost
(summed over the run's calls), filtered to `statuses` when given (unfiltered otherwise), ordered by
`reviewed_at` desc with never-reviewed runs last.

On `/thread-analysis/thread`, every `done`/`failed` run gets a small form (a status `<select>`, a
notes `<textarea>`, and Save) handled the same way as the "Analyse" buttons - an admin-session POST
with `action=review`, `reviewed_by` set to the admin's sub, redirecting back. The saved review is
shown next to the run: a status badge, the notes, who and when. On `/thread-analysis`, a summary box
shows runs per review status, and a "Runs with issues" table (`MINOR_ISSUES`/`WRONG`, up to 100)
lists thread, status, notes, prompt sha and reviewer. On the thread view (`view-thread.php`),
admins see the latest run's review status next to the "Analysis details" link
(`ThreadStateView::renderBlock()`'s `$latestRunReviewStatus` parameter).

For the local fix loop, `GET /api/admin/analysis/reviews?status=MINOR_ISSUES,WRONG&limit=100`
(token or admin session, like the export endpoints) returns `{"reviews": [...]}` - see "The
endpoints" below - and `tools/analysis-worker.php --reviews [--status=...]` prints them, one block
per run, to paste into a local AI session - see "Worker" below.

### Applying a run

`saveResult` runs in one transaction. It checks that the run exists, is `claimed`, and is claimed
by `worker`; that every event's `email_id` belongs to the run's thread; that positions start at 1
with no gaps; that each event without an `error` has a `thread_state` that passes
`ThreadState::fromArray`; that `email_type` is a real `ThreadEmailStatusType` value; and that
counts/`cost_usd`/`attempt` are the right shape. Any failure throws `InvalidArgumentException`
with a precise message and writes nothing.

Once validated, it stores the events and calls, sets the run to `done` or `failed`
(`finished_at`, `model`, `system_prompt_sha256`, `schema_version`, `error`), and - only when the
run is `done` - applies each event's state onto its email: `thread_emails.thread_state`,
`thread_state_type` (the derived one, not the worker's), and `thread_state_source = 'auto'`. An
email whose `thread_state_source` is `manual` is left untouched. A `failed` run applies nothing,
even though its events and calls up to the failure are still stored.

### The endpoints

Three POST-only admin endpoints let a worker on the owner's machine drive the queue. They accept
the admin token only (`adminApiRequireToken()` in `api/admin/admin-api-auth.php`) - unlike the GET
export endpoints, there is no admin-session fallback, since a POST has no CSRF protection from a
session cookie alone.

| Route | File | Body | Response |
|---|---|---|---|
| `POST /api/admin/analysis/request` | `api/admin/analysis_request.php` | `{"thread_id", "mode"}` | `{"run_id"}`: the new run, or the thread's already open run |
| `POST /api/admin/analysis/claim` | `api/admin/analysis_claim.php` | `{"worker", "thread_id"?}` | 204 with no body if nothing is claimable, else the work item below |
| `POST /api/admin/analysis/result` | `api/admin/analysis_result.php` | `{"run_id", "worker", …the result format above}` | `{"run_id", "status"}` |
| `POST /api/admin/analysis/request-next` | `api/admin/analysis_request_next.php` | `{"kind": "np", "mode"?}` | `{"run_id", "thread_id"}`, or 204 with no body when nothing is left |
| `GET /api/admin/analysis/reviews?status=...&limit=...` | `api/admin/analysis_reviews.php` | - | `{"reviews": [...]}` - see "Review status and notes per run" above |

`requested_by` is always `token`. The claim lease is 3600 seconds. Errors: 405 for anything but
POST (or, for `reviews`, anything but GET), 400 for invalid JSON/a missing field/a non-UUID
`thread_id`/a bad `mode`/an unknown review status or any `InvalidArgumentException` from the
repository, and 404 for an unknown thread (`request`) or run (`result`). Each successful call is
logged with `error_log`, like the export endpoints. Unlike the three POST-only endpoints, `reviews`
accepts the admin token *or* an admin session (`adminApiRequireTokenOrAdminSession()`), the same as
the GET export endpoints - it's a GET with no side effects, so a session cookie is not a CSRF risk
here the way it would be for the POST endpoints.

### Picking the next norske-postlister.no thread

`/api/admin/analysis/request-next` lets a worker take the next work item by itself instead of
being given a thread id. `kind` is required and only `"np"` exists so far (anything else is a 400);
`mode` defaults to `incremental`. It is a thin wrapper around
`ThreadAnalysisRepository::requestNextNpThread(mode, requestedBy)`, which does the pick and the
`requestRun()` call in one transaction:

- the thread carries the label `NpApiService::NP_LABEL` (`norske_postlister_no`) in `threads.labels`
  - archived or not;
- it has at least one email with `ignore` not true;
- it has **no** row at all in `thread_analysis_runs`, in any status - once a thread has been queued
  once, even if that run failed, it is never picked again here (a fresh look at it goes through the
  normal `request`/admin debug page instead);
- ordered by the latest `datetime_received` of its non-ignored emails, newest first, then by
  thread id.

Null (204) once every norske-postlister.no thread has been queued at least once.

The claim response's `thread` field is the full thread export
(`ThreadExportService::exportThread($threadId, false)`) with every email's `eml_base64` left out -
the worker analyses text and metadata, not the raw EML. Which emails the worker should analyse is
chosen by the pure function `ThreadAnalysisWorkItem::selectEmails(array $exportEmails, string $mode)`
(`organizer/src/class/ThreadAnalysis/ThreadAnalysisWorkItem.php`): it drops emails with
`ignore = true`, orders the rest by `datetime_received` then `id`, and then:

- **`full`:** every remaining email; `start_state` and `start_after_email_id` are both null.
- **`incremental`:** finds the last such email whose `thread_state` is not null. `start_state` is
  that state and `start_after_email_id` is its id; `email_ids` are the emails after it. With no
  such email it behaves like `full`; with nothing after it, `email_ids` is empty - the worker then
  posts a `done` result with no events, which `saveResult` accepts.

```json
{
  "run": { "id": 12, "thread_id": "…", "mode": "incremental", "lease_expires_at": "…" },
  "thread": { "…": "the thread export (export_version 1), every email's eml_base64 left out" },
  "start_state": { },
  "start_after_email_id": "…",
  "email_ids": ["…", "…"]
}
```

## Worker

`tools/analysis-worker.php` runs on the owner's machine: a client of the three endpoints above, calling
headless Claude Code exactly as `tools/analyze-threads.php` does (they share the call/validate/retry
logic - see "Shared code" below), but posting results to prod instead of writing local files.

```
php tools/analysis-worker.php --base-url=https://offpost.no --token-file=secrets/admin_api_token
    [--thread=<id> [--mode=incremental|full]] [--once] [--worker=<name>]
    [--next-np [--limit=N]] [--model=claude-opus-5-5] [--max-budget-usd=20]
    [--out=thread-analysis] [--claude-bin=claude] [--background] [--help]
```

| Option | Meaning |
|---|---|
| `--base-url=URL` | The Offpost instance. Must be `https`, or plain `http` to `localhost`/`127.0.0.1` (`ThreadExportSync::isSafeBaseUrl`) - the admin token is sent to it. |
| `--token-file=PATH` | File containing the admin API token, sent as `X-Admin-Api-Token`. |
| `--thread=ID` | Request (with `--mode`) then claim that thread's run, then stop. Implies `--once`. |
| `--mode=MODE` | `incremental` (default) or `full`, used with `--thread` and `--next-np`. |
| `--once` | Stop after at most one claimed run. |
| `--next-np` | Pick the next norske-postlister.no thread itself, up to `--limit` times, instead of draining the general queue (see "`--next-np`: picking norske-postlister.no threads" below). Cannot be combined with `--thread`. |
| `--limit=N` | Max threads to process with `--next-np` (default 1). |
| `--worker=NAME` | Sent to prod as the claiming worker (default: `gethostname()`). |
| `--model=NAME` | Model passed to `claude` (default `claude-opus-5-5`). |
| `--max-budget-usd=N` | Stop claiming a new run once this process's summed cost reaches this (default 20). |
| `--out=DIR` | Where the worker's own state lives - `<out>/worker/...` (default `thread-analysis`, same default as `tools/analyze-threads.php`, but a different subdirectory so the two never collide). |
| `--claude-bin=PATH` | Command to invoke instead of `claude` - only for tests, with a fake that never calls the real CLI. |
| `--background` | Relaunch detached (`nohup ... &`) and exit immediately, like `tools/analyze-threads.php --background`; the process's own stdout/stderr go to `<out>/worker/process.log`. |
| `--reviews` | Print reviewed runs with issues and exit - see "`--reviews`" below. Cannot be combined with `--thread` or `--next-np`. |
| `--status=LIST` | Comma-separated review statuses for `--reviews` (default `MINOR_ISSUES,WRONG`). |

### The loop

1. **Resend pending results** left over from a previous run of the worker (see "Pending results"
   below).
2. **Stop if the budget is spent:** once this process's summed call cost reaches `--max-budget-usd`.
3. **Claim:** `POST /api/admin/analysis/claim` with `{worker}` (plus `thread_id` when `--thread` was
   given - after first `POST`ing `/api/admin/analysis/request` with that thread and `--mode`). A 204
   response means nothing is claimable, and the worker stops.
4. **Analyse the run:** for each id in the claim response's `email_ids`, in order, build the input
   with `ThreadEventAnalysis::buildEventInput()` from the work item's thread export, that email, and
   the previous state (`start_state` for the first email) - then call Claude Code, validate, and
   retry once on an invalid answer, exactly as `tools/analyze-threads.php` does. If an event is still
   invalid after the retry, the run becomes `failed` with that event's error, and no further emails in
   that run are analysed.
5. **Save then post:** write the full result body to `<out>/worker/pending/<run-id>.json`, then
   `POST /api/admin/analysis/result`.
6. **Repeat**, unless `--once` (or `--thread`, which implies it) - then stop.

### `--next-np`: picking norske-postlister.no threads

With `--next-np`, the worker runs a separate loop that never touches the general queue:

1. Resend pending results, same as step 1 above.
2. Up to `--limit` times (default 1):
   1. Stop if the budget is spent (same check as the general loop).
   2. `POST /api/admin/analysis/request-next` with `{"kind": "np", "mode"}`. A 204 means no
      norske-postlister.no thread is left to queue (logged as `request-next: np -> none left`) -
      the worker stops.
   3. `POST /api/admin/analysis/claim` with `{worker, thread_id}` for the thread it just got.
   4. Analyse and post exactly as steps 4-5 above (same pending/posted/rejected handling); a post
      that can't be delivered stops the worker here too.
3. Stop after `--limit` threads (or earlier, per the above) - it does not drain the general queue
   afterwards.

Log line per pick: `request-next: np -> run <id> thread <id>`.

### Pending results

No paid analysis call is ever lost because prod happened to reject or miss the post that carries it:

- Before posting, the full result body is written to `<out>/worker/pending/<run-id>.json` (atomically).
- **200:** moved to `<out>/worker/posted/<run-id>.json`.
- **4xx:** moved to `<out>/worker/rejected/<run-id>.json`, with the response body saved alongside as
  `<run-id>.error.txt`. Logged, and the worker carries on to the next run.
- **Network error or 5xx:** the file stays in `pending/`, to be resent at the next loop start (step 1)
  or the next time the worker is started. The worker then stops for this run entirely - it does not
  keep claiming new work while prod is failing.

### Log and background

`<out>/worker/worker.log` gets one line per event (the same shape as `tools/analyze-threads.php`'s
`run.log`: `[run <id>] <thread_id> event <i>/<k> <direction> <email_type> -> <derived_type> $<cost>
<duration>s`, or a `FAILED` line), plus a line for each request/claim/post/resend. It is also echoed
to a tty (a foreground run); with `--background`, stdout instead goes to `<out>/worker/process.log`
and is not a tty, so nothing duplicates.

### `--reviews`: printing runs with issues

`--reviews [--status=MINOR_ISSUES,WRONG]` skips the queue entirely: it makes one
`GET /api/admin/analysis/reviews?status=...` call and prints one block per run to stdout - the
thread id and title, review status, notes, the system prompt's short sha, and the
`/thread-analysis/thread` URL - then exits. Meant to be pasted straight into a local AI session
that fixes the prompt or script per "Review status and notes per run" above. It touches none of
`<out>/worker/...` and makes no Claude Code calls.

### Shared code

The Claude Code call, answer validation, and the retry-once-on-invalid logic live in
`tools/analysis/ClaudeCodeEventRunner.php`, extracted from `tools/analyze-threads.php` so both tools
share the exact same behaviour instead of two copies drifting apart:

- **`buildClaudeArgv`, `spawnNonBlocking`, `usageOf`, `sumUsage`, `modelNameOf`, `interpretOutput`,
  `buildCallRecord`** - the low-level pieces. `tools/analyze-threads.php` still drives its own
  async, many-threads-at-once loop (multiple `claude` subprocesses pumped with `stream_select`,
  so `--parallel` still runs real OS-level parallelism across threads); it now calls these instead
  of keeping its own copies.
- **`claudeCodeVersion(claudeBin)`** - reads `claude --version` once per process (cached; stdin is
  always explicitly closed so a test's fake `claude` binary, which reads all of stdin regardless of
  argv, can never hang it).
- **`runEvent(inputText, promptFile, schema, model, claudeBin, maxBudgetUsd, claudeCodeVersion)`** -
  the single blocking call+validate+retry-once method `tools/analysis-worker.php` uses directly
  (one event at a time, no concurrency needed there): it returns the parsed output, the derived
  thread-state type, the error (if still invalid after the retry), the attempt count, and every
  call made, in the "Result format" `calls` shape from "Change 1" above.

## Debug pages

Three admin-only pages (`$adminPages` in `webroot/index.php`, linked from "Admin tools" in
`header.php`) let an admin see what the queue and past runs actually did, without querying the
database by hand. They follow the style of `system-pages/openai-request-log-overview.php`: an
inline `<style>` block, `summary-box` stats, `label` classes, and fixed `LIMIT`s on anything that
lists rows. The read-only queries behind them live in
`organizer/src/class/ThreadAnalysis/ThreadAnalysisStats.php`, plus `getCallsForRun()` added to
`ThreadAnalysisRepository`.

- **`/thread-analysis`** (`system-pages/thread-analysis.php`) - the overview:
  - Runs by status, runs by review status, cost/tokens for today/last 7 days/all time, cost per
    model, and cost per system-prompt version (short sha, linked, with when that version was
    first used).
  - **Queue** - runs that are `requested` or `claimed`: thread, mode, requested by/at, worker,
    lease.
  - **Recent runs** (up to 100, newest first) - thread (linked to the debug thread page below),
    status, mode, model, event count, cost, duration (`finished_at - claimed_at`) and error.
  - **Email-type gaps** (up to 100) - events with a non-empty `email_type_gap`, with the thread and
    email.
  - **Disagreements** (up to 100) - for each thread's *latest* `done` run, the events whose
    `email_type` differs from that email's `thread_emails.status_type`. Prod values that don't mean
    a real classification (`unknown`, the legacy `info`/`error`/`success` values, and never
    classified at all) are left out, since a mismatch there isn't a real disagreement. Shows both
    values and whether prod's value is `manual`, or `algo`/`prompt` (from `auto_classification`,
    the same distinction `ThreadExportService::classificationSource()` makes for the export).
  - **Runs with issues** (up to 100) - runs reviewed `MINOR_ISSUES` or `WRONG`
    (`ThreadAnalysisRepository::getReviews()`), with thread, review status, notes, system-prompt
    sha and reviewer - see "Review status and notes per run" above.
- **`/thread-analysis/thread?id=<uuid>`** (`system-pages/thread-analysis-thread.php`) - one
  thread's full history:
  - The thread title, linked to `/thread-view`, and two POST buttons - "Analyse" (incremental) and
    "Analyse from the start" (full) - that call `ThreadAnalysisRepository::requestRun()` with the
    admin's sub as `requested_by`, then redirect back (avoids a resubmission on refresh).
  - Every run, newest first, with its fields, a review form for every `done`/`failed` run (status
    `<select>`, notes `<textarea>`, Save - `action=review`, same POST-and-redirect style, saving
    with `ThreadAnalysisRepository::saveReview()`) and the saved review (badge, notes, who and
    when), and a table of its events (position, the email's date/direction/subject, email type,
    note, gap, derived status, attempts, error, and a "Show state" link opening the state blob as
    pretty JSON in the shared `ContentDialog` modal - see "Shown in the thread view" in
    [docs/thread-state.md](thread-state.md)), each with its calls (attempt, model, resolved model,
    Claude Code version, token counts, cost, duration, and "Show input"/"Show response" links
    opening the input text and the response JSON the same way).
  - A malformed `id` is a 400; an unknown thread is a 404 (same style as `view-thread.php`/`file.php`:
    `is_uuid()` plus a direct `http_response_code()` + `die()`, not a thrown exception - this page
    must not go through `error.php`'s generic 500 for what are really client errors).
- **`/thread-analysis/system-prompt?sha=<sha>`** (`system-pages/thread-analysis-system-prompt.php`) -
  one system-prompt version: its full text, when it was first used (the prompt row's own
  `created_at`), and how many runs used it. A malformed `sha` (not 64 hex characters) is a 400; an
  unknown one is a 404.

All three pages escape every value with `htmlspecialchars`.
