# Thread-view classification display

Date: 2026-08-10

## Problem

The thread-view page (`organizer/src/view-thread.php`) does not show the classification of
an email or an attachment. Three separate defects combine to produce this:

1. **The status type is never rendered.** Both the email row (line 434) and the attachment
   row (line 500) print only the free-text `status_text`. The classification itself —
   `App\Enums\ThreadEmailStatusType`, which carries the meaningful values
   (`RESPONSE_TO_REQUEST`, `INFORMATION_RELEASE`, `REQUEST_REJECTED`, …) and a
   human-readable `label()` — is used only to pick a CSS class via `getLabelType()`. The
   reader sees a coloured chip with arbitrary text in it and no indication of what the
   email was actually classified as.

2. **A placeholder text masks real classifications.** `ThreadEmailDatabaseSaver` stamps
   every ingested attachment with `status_text = 'uklassifisert-dok'`
   (`ThreadEmailDatabaseSaver.php:196` and `:341`). The classify page pre-fills that string
   into the Status Text input, so classifying an attachment as Information Release leaves
   the text untouched. Because thread-view renders only the text, the attachment still
   reads as unclassified after it has been classified.

3. **"Classified by" always claims Human.** `ThreadEmailClassifier::getClassificationLabel()`
   distinguishes human, code (`algo`) and AI (`prompt`) classification via the
   `auto_classification` column. No thread-loading path maps that column onto the
   `ThreadEmail` object, even though `ThreadEmail` declares the property and
   `Thread::mapFromDatabase()` already selects it with `SELECT *`. `isset()` is therefore
   always false and every classified email reports "Classified by Human", on thread-view,
   on the front page, and on the classify page.

## Goals

- Show the classification type, by its human-readable label, on every email and attachment
  row in thread-view.
- Stop the `uklassifisert-dok` placeholder from surviving a real classification.
- Report the true classification source.
- Keep the front page (`index.php`) consistent with thread-view, since it renders the same
  information from a duplicated block.

## Non-goals

- No migration or backfill of existing rows. Rows that still carry the placeholder are
  handled by the display rule; they are corrected in the database only when someone
  re-saves them on the classify page.
- No change to the ingest-time placeholder itself. `'uklassifisert-dok'` and the
  `uklassifisert-epost` thread label still mark newly received attachments as needing
  classification.
- No redesign of the classify page.

## Design

### 1. A shared rendering helper

Add `renderClassification($status_type, $status_text)` to
`organizer/src/class/ThreadUtils.php`, alongside the existing `getLabelType()`. It returns
the HTML for one classification, and is the only place that decides how a classification
looks.

Output shape:

```html
<span class="label label_information_release label_ok">Information Release</span>
<span class="status-text">Svar med vedlegg</span>
```

Rules:

- The chip's CSS classes come from the existing `getLabelType()`, unchanged.
- The chip's text is `ThreadEmailStatusType::tryFrom($status_type)->label()`. A null or
  empty `status_type` renders as `Unknown`. A value not present in the enum renders as the
  raw string rather than being swallowed, so unexpected database values stay visible.
- `status_text` is appended in a muted `status-text` span, and is **omitted** when it is
  empty, when it equals the chip's label, or when it equals the placeholder
  `uklassifisert-dok`.
- Both the label and the text are escaped with `htmlescape()`.

The placeholder is defined once as the constant
`ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT` and referenced from
`ThreadEmailDatabaseSaver`, `classify-email.php` and the helper, so the literal
`'uklassifisert-dok'` is not repeated across the codebase. It lives on
`ThreadEmailAttachment` because attachments are the only rows that ever receive it.

`getLabelType()` takes an unused `$type` first argument (callers pass `'email'` or the
misspelled `'attachement'`). The new helper does not take it — one classification renders
the same way wherever it appears. `getLabelType()` itself is left alone.

### 2. Call sites

Four blocks collapse to calls of the helper:

| File | Line | Level |
|---|---|---|
| `view-thread.php` | 434 | email |
| `view-thread.php` | 500 | attachment |
| `index.php` | 369 | email |
| `index.php` | 386 | attachment |

`index.php` currently echoes `$email->status_text` and `$att->status_text` without
escaping. Routing both through the helper closes that hole.

### 3. Load `auto_classification`

Map the column in the three loaders that build `ThreadEmail` objects:

- `ThreadDatabaseOperations::getThreads()` — add `e.auto_classification` to the SELECT
  (~line 51) and the assignment (~line 150).
- `ThreadDatabaseOperations::getThreadsForEntity()` — same (~lines 205 and 281).
- `Thread::mapFromDatabase()` — the query is already `SELECT *`; add the assignment
  (~line 203).

`ThreadEmail::$auto_classification` is declared but never initialised, so it is `null` for
unclassified rows and `isset()` stays false — `getClassificationLabel()` keeps returning
`'Human'` for genuinely human-classified emails and now returns `AI` / `Code` for the rest.

### 4. Clear the placeholder on save

In `classify-email.php`, when a submitted attachment `status_text` is exactly the
placeholder and the chosen `status_type` is anything other than `UNKNOWN`, persist an empty
string instead.

Choosing `UNKNOWN` keeps the placeholder — the attachment is genuinely still unclassified
and should keep saying so.

Attachments only. Emails never receive the placeholder, so no clearing rule is added on the
email path. The display suppression in section 1 stays uniform across both levels because
one unconditional rule in the helper is simpler than branching on level.

## Testing

New unit tests:

- `ThreadUtilsRenderClassificationTest` — one case per enum value asserting the exact
  rendered HTML; null and empty `status_type` render `Unknown`; an unrecognised database
  value renders verbatim; `status_text` suppressed when empty, when equal to the label, and
  when it is the placeholder; `status_text` shown otherwise; HTML in `status_text` and in an
  unrecognised `status_type` is escaped.
- Extend the existing thread-loading tests to assert `auto_classification` survives a load
  through `getThreads()`, `getThreadsForEntity()` and `Thread::loadFromDatabase()`.
- A test for the attachment placeholder-clearing rule: placeholder + real type → empty;
  placeholder + `UNKNOWN` → placeholder retained; non-placeholder text → untouched.

Existing suites that must still pass unchanged: `ThreadEmailClassifierTest`,
`ThreadEmailClassificationPersistenceTest`, `SummaryClassificationWiringTest`,
`e2e-tests/pages/ThreadClassifyPageTest`, `e2e-tests/pages/ThreadClassifyPersistsTest`.

Because outputs are deterministic, assertions use `assertEquals` against the full expected
HTML string rather than substring checks.

Run:

```
./organizer/src/vendor/bin/phpunit organizer/src/tests/ organizer/src/e2e-tests/
```

### Baseline in this worktree

Unit tests: 483 tests, 1668 assertions, 0 failures, 2 skipped.

E2E tests: 112 tests, 9 errors and 7 failures **before any change**. All of them are NP API
and extraction-overview cases failing because `docker-compose.dev.yaml` mounts
`./secrets/np_api_token` and `./secrets/openai_api_key`, which do not exist — Docker creates
directories in their place and the endpoints return 500 instead of 401/404/400. Unrelated to
this work; the same set must still be the only failures afterwards.

The e2e suite drives `http://localhost:25081`, which the dev stack serves from the **main
checkout**, not from a worktree. E2E runs therefore do not exercise worktree changes unless a
container is started against the worktree's sources on that port. Verification of this change
rests on the unit tests.

## Documentation

- `docs/` — update the thread-view / classification page documentation to describe that the
  classification type is displayed and what the source annotation means.
- `README.md` — no change; this alters presentation of an existing capability rather than
  adding one.
