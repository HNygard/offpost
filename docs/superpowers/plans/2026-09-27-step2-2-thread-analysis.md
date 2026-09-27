# Step 2.2: local analysis of every event with Opus 5.5

Concrete plan for sub-step 2 of step 2 in
`docs/superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md`.
Local evaluation only: prod will use code rules and OpenAI models.

## Decisions

- **Headless Claude Code, not the API.** No API key; it runs on the owner's
  subscription. Each call:
  ```
  claude -p --model claude-opus-5-5 --output-format json --tools "" \
    --no-session-persistence --setting-sources "" --strict-mcp-config \
    --system-prompt-file tools/analysis/event-prompt.md --json-schema '<schema>' \
    --max-budget-usd 2
  ```
  - The event input goes on stdin.
  - The answer is in `structured_output`; usage is in `usage` and `modelUsage`,
    cost in `total_cost_usd`, time in `duration_ms`. Check `is_error`.
- **One call per event**, in order. Evaluating event *n* sees only the state
  after *n−1* and email *n*, as prod will.
- **Items are one per document type** named in our request (owner's decision).
- **The prompt is a file** (`tools/analysis/event-prompt.md`, written by the
  lead). Runs record its sha256, so runs can be compared.

## CLI: `tools/analyze-threads.php`

```
php tools/analyze-threads.php [--export=thread-export] [--out=thread-analysis]
    [--run=<name>] [--limit=N] [--thread=<id>]... [--parallel=2]
    [--model=claude-opus-5-5] [--max-budget-usd=20] [--claude-bin=claude]
    [--background] [--help]
```

- **`--run`:** defaults to a timestamp, e.g. `2026-09-27T1130`. The run directory is `<out>/<run>/`.
- **Thread selection:**
  - `--thread` (repeatable) picks specific threads.
  - Otherwise the threads in `<export>/threads/*.json`, sorted by thread id, with the first `--limit`. Without `--limit`, all of them.
  - Threads with no emails are skipped.
- **Events:** the thread's emails ordered by `datetime_received`, then `id`. Emails with `ignore = true` are skipped.
- **Parallelism:** `--parallel` threads run at once; within a thread, events run strictly in order. Use `proc_open` for the calls.
- **Budget:**
  - The run stops starting new calls once the summed `total_cost_usd` reaches `--max-budget-usd`.
  - Calls already running finish.
  - The stop is logged, with `stopped_reason` in `run.json`.
- **`--background`:**
  - Relaunches the same command without `--background`, detached (`nohup … > <run>/run.log 2>&1 &`).
  - Prints the run directory, the pid and `tail -f <run>/run.log`, then exits 0.
  - `--run` is fixed before relaunching, so both processes agree on the directory.
- **`--claude-bin`:** the command to call instead of `claude`. It exists so tests can use a fake.

## Per event

1. **Build the input text** (pure function):
   - the thread: id, title, entity name and id, `initial_request`;
   - the previous state as pretty JSON, or `null`;
   - the email: id, direction, date, from, to, cc, subject;
   - `body_plain`, falling back to `body_html` with tags stripped, cut to 15,000 characters;
   - each attachment: filename, filetype, and the extracted text of its first extraction with non-empty `extracted_text`, cut to 8,000 characters. With no text, say `(no extracted text)`.
   - Every cut is marked `[CUT]` with the original length.
2. **Call claude** with the JSON schema.
   - The schema is built from the PHP enums and matches `ThreadState`'s rules.
   - Top level: `email_type` (the enum below), `email_note`, `email_type_gap`, `thread_state`.
3. **Validate the answer:**
   - `email_type` must be one of `ThreadEmailStatusType` except `info`, `error` and `success` (so the current types plus `unknown`).
   - `thread_state` goes through `ThreadState::fromArray`, and the status is derived with `ThreadStateTypeDeriver`.
   - If invalid, retry once, appending `Your previous answer was invalid: <error>. Return a corrected answer.` to the input.
   - If still invalid, record the error and stop this thread (the status is `failed`).
4. **Record the event** and write the thread file (atomically) after every event, so a run can resume mid-thread.

## Output

- **`<out>/<run>/run.json`:** run, model, `prompt_sha256`, `schema_version`, options, `started_at`, `finished_at`, `stopped_reason`, and totals (threads, events, cost, tokens).
- **`<out>/<run>/threads/<thread-id>.json`:**

```json
{
  "thread_id": "…", "title": "…", "entity_id": "…",
  "status": "done|failed|in_progress",
  "events": [
    { "email_id": "…", "email_type_actual": "…", "direction": "IN",
      "datetime_received": "…", "input_chars": 5123, "attempts": 1,
      "output": { "email_type": "…", "email_note": "…", "email_type_gap": "", "thread_state": { } },
      "derived_thread_state_type": "WAITING_FOR_ENTITY",
      "error": null,
      "usage": { "input_tokens": 0, "output_tokens": 0, "cache_read_input_tokens": 0,
                 "cache_creation_input_tokens": 0, "thinking_tokens": 0 },
      "cost_usd": 0.0, "duration_ms": 0, "model": "claude-opus-5-5", "session_id": "…" }
  ],
  "totals": { "events": 0, "cost_usd": 0.0, "input_tokens": 0, "output_tokens": 0 }
}
```

`email_type_actual` is the email's current `status_type` in the export, for comparison.

- **Resume:** rerunning the same `--run` skips threads whose status is `done`. It continues `in_progress` threads after their last recorded event, and retries `failed` threads from their failed event.
- **`run.log`:** one line per event: `[thread n/N] <thread-id> event i/k <direction> <email_type> -> <derived status> $0.12 4.1s`, plus errors.
- `thread-analysis/` is gitignored.

## Code layout

| Unit | File |
|---|---|
| CLI | `tools/analyze-threads.php` |
| Pure logic: input text, schema, output validation, totals | `tools/analysis/ThreadEventAnalysis.php` |
| Prompt (lead) | `tools/analysis/event-prompt.md` |
| Tests | `organizer/src/tests/ThreadEventAnalysisTest.php` (unit), `organizer/src/tests/AnalyzeThreadsCliTest.php` (runs the CLI with a fake `--claude-bin` against a two-thread fixture export in a temp dir: output files, totals, resume skipping done threads, retry-then-fail, budget stop) |
| Docs | `docs/thread-analysis.md`, and pointers in the roadmap/README/CLAUDE.md |
