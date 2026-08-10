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
<span class="classification label label_information_release label_ok">From entity: Information Release</span> <span class="status-text">Svar med vedlegg</span>
```

Rules:

- The chip carries `classification` plus the classes from the existing `getLabelType()`,
  unchanged. The extra `classification` class is the styling hook (see section 2b) and does
  not disturb any existing selector.
- The chip's text is `ThreadEmailStatusType::tryFrom($status_type)->label()`. Null or empty
  `status_type` is normalised to `UNKNOWN` first, so it renders as `Unknown` rather than
  reaching `getLabelType()`'s `default:` branch. A value that `getLabelType()` accepts but
  the enum does not (`disabled`, `danger`, `UNKNOWN` in caps) renders as the raw string
  rather than being swallowed. Anything else still throws from `getLabelType()`, exactly as
  it does today.
- `label()` includes the group prefix added in a7ccd70 — `From us: `, `From entity: `,
  `Legacy: ` — so the chip reads "From entity: Information Release". This is the label the
  classify page's dropdown already shows, and it tells the reader who sent the email on
  attachment rows, where the `IN`/`OUT` marker is not repeated.
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

### 2b. Chip styling

`webroot/css/style.css` styles labels through `span.label a` — the background, border,
padding and radius all live on the anchor. A label with no link inside it, which is exactly
what these classification labels are, renders as unstyled plain text. On top of that,
`label_our_request`, `label_request_receipt`, `label_asking_for_*`, `label_clarification_sent`,
`label_copy_sent`, `label_response_to_request`, `label_request_rejected` and
`label_information_release` have no CSS rules anywhere. The classification is therefore not
merely missing its type — even the coloured chip the markup implies has never rendered.

Add to `style.css`:

- `span.label.classification` — the chip box (background, 1px border, 4px radius, 4px/10px
  padding, `cursor: default`), mirroring `span.label a` so linked and unlinked labels look
  alike. It also neutralises the `span.label:hover` lift, which is a link affordance.
- One background/border pair per status group, keyed off the `label_*` classes that
  `getLabelType()` already emits: "From us" blue, "From entity" amber for the asking/waiting
  states, green for `label_information_release`, red for `label_request_rejected`, neutral
  grey for unknown and legacy.
- `.status-text` — muted (`#6c757d`), `0.9em`, no chip box.

Existing `span.label a` rules are untouched, so the label chips in the thread header, the
label filters and the status labels keep their current appearance.

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

There are **two** placeholders, one per level, and both need the same treatment:

- `ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT` = `'uklassifisert-dok'`, stamped at
  `ThreadEmailDatabaseSaver.php:196` and `:341`.
- `ThreadEmail::UNCLASSIFIED_STATUS_TEXT` = `'Uklassifisert'`, stamped at
  `ThreadEmailDatabaseSaver.php:190` and `:292`.

An earlier draft of this document claimed emails never receive a placeholder. That was
wrong — the final whole-branch review caught it, with 121 such rows sitting in the dev
database. The email row is the more prominent of the two, so leaving it would have left
half the reported bug in place.

Each class carries the rule alongside its own constant, as a static:

```php
public static function normalizeStatusText($statusType, $statusText)
```

It returns `''` when `$statusText` is exactly that class's `UNCLASSIFIED_STATUS_TEXT` and
`$statusType` is anything other than `UNKNOWN`, and returns `$statusText` unchanged
otherwise (`null` becomes `''`). It accepts either a `ThreadEmailStatusType` case or a raw
string for `$statusType`, matching how the rest of the codebase passes status types around.

`classify-email.php` runs both the submitted email text and the submitted attachment text
through the matching rule before persisting. Keeping the rule in a class rather than inline
in the page script is what makes it unit-testable — the page script itself cannot be
exercised from PHPUnit.

Choosing `UNKNOWN` keeps the placeholder — the email or attachment is genuinely still
unclassified and should keep saying so.

Note the deliberate asymmetry between the two mechanisms: display suppression (section 1)
is unconditional, while save-clearing is conditional on a non-`UNKNOWN` type. One
unconditional rule in the helper is simpler than branching on level, and hiding a
placeholder from the reader costs nothing, whereas erasing it from the database would lose
the marker that says an item still needs classifying.

## Testing

New unit tests:

- `ThreadUtilsRenderClassificationTest` — one case per enum value asserting the exact
  rendered HTML; null and empty `status_type` render `Unknown`; the legacy `disabled`,
  `danger` and caps-`UNKNOWN` values render verbatim; `status_text` suppressed when empty,
  when equal to the label, and when it is the placeholder; `status_text` shown otherwise;
  HTML in `status_text` is escaped; a `ThreadEmailStatusType` case and its raw string value
  produce identical output.
- `ThreadEmailAttachmentNormalizeStatusTextTest` — placeholder + real type → `''`;
  placeholder + `UNKNOWN` (both as enum case and as the string `'unknown'`) → placeholder
  retained; non-placeholder text → untouched; empty and null text → untouched.
- Extend the existing thread-loading tests to assert `auto_classification` survives a load
  through `getThreads()`, `getThreadsForEntity()` and `Thread::loadFromDatabase()`.

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
