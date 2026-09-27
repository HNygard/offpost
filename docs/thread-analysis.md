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
   text - long fields cut and marked `[CUT, original length: N chars]`).
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
