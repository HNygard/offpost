# Cumulative thread state

Per-email record of what the thread's state is, as of that email: what has been asked for, what
the entity has answered, whether we are waiting for the entity or for ourselves, and any
complaints in progress. It is step 2.1 of
[the innsynskrav classification roadmap](superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md).

Code: `organizer/src/class/ThreadState/ThreadState.php` (the blob, with validation),
`organizer/src/class/ThreadState/ThreadStateTypeDeriver.php` (derives the thread status),
`organizer/src/class/Enums/ThreadStateItemStatus.php`, `organizer/src/class/Enums/ThreadStateType.php`.

Nothing writes these columns yet. Later steps (local analysis, feedback) will.

## What is recorded per email

Three new columns on `thread_emails`, alongside the existing `status_type`/`status_text` of the
email itself:

| Column | Type | Meaning |
|---|---|---|
| `thread_state` | jsonb, nullable | The blob below: the thread's state after this email. |
| `thread_state_type` | varchar, nullable | The thread status derived from the blob by code (`ThreadStateTypeDeriver`). |
| `thread_state_source` | varchar, nullable | `manual` or `auto`. |

The thread's *current* state is the state on its latest email - there is no thread-level column.

## The blob, `schema_version` 1

```json
{
  "schema_version": 1,
  "request": { "summary": "…", "law_basis": "offentleglova", "sent_at": "2023-09-12" },
  "items": [
    { "id": "1", "asked_for": "Valgprotokoll 2023", "status": "PARTLY_RELEASED",
      "denial_basis": { "refs": ["offentleglova § 13"], "text": "…", "issues": ["INCOMPLETE_REFERENCE"] },
      "released_in_email_ids": ["…"], "note": "" }
  ],
  "waiting_for": "NOBODY",
  "asks_to_us": [],
  "case_numbers": ["23/1234"],
  "dates": [ { "date": "2023-10-01", "what": "entity promised answer", "email_id": "…" } ],
  "complaints": [ { "status": "SENT", "item_ids": ["1"], "sent_email_id": "…",
                    "decision_email_id": null, "outcome": "" } ],
  "notes": "",
  "extra": {}
}
```

`ThreadState::fromArray()` validates a blob and throws `InvalidArgumentException` with a precise
message on the first problem found. `toArray()` returns the exact validated array, so a valid
blob round-trips unchanged.

### Core keys

All are required; `extra` holds anything else.

| Key | Type | Notes |
|---|---|---|
| `schema_version` | int | must be 1 |
| `request` | object | `summary` string, `law_basis` string, `sent_at` date string or null |
| `items` | list of item | at least one item |
| `waiting_for` | enum | `ENTITY`, `US`, `NOBODY` |
| `asks_to_us` | list of strings | what the entity asked us to do |
| `case_numbers` | list of strings | |
| `dates` | list of `{date, what, email_id}` | `email_id` may be null |
| `complaints` | list of complaint rounds | ordered, the last one is the latest |
| `notes` | string | only what does not fit elsewhere |
| `extra` | object | free |

### Items

Each item has `id` (string, unique within the blob), `asked_for` (string), `status`
(`ThreadStateItemStatus`), `denial_basis` (object or null), `released_in_email_ids` (list of
strings) and `note` (string).

Item statuses:

| Status | Meaning | Final | Refusal |
|---|---|---|---|
| `NOT_ANSWERED` | Nothing from the entity about this item | | |
| `ACKNOWLEDGED` | Receipt confirmed only | | |
| `BEING_EVALUATED` | The entity is assessing it; may have given a date | | |
| `WILL_RELEASE` | Decided: full release, not yet delivered | | |
| `WILL_RELEASE_PARTLY` | Decided: part refused, the rest not yet delivered | | yes |
| `PARTLY_RELEASED` | Delivered, with part refused | yes | yes |
| `RELEASED` | Delivered in full | yes | |
| `ANSWERED_IN_TEXT` | The information asked for is given in the email body itself, not as a document | yes | |
| `DENIED` | All of it refused | yes | yes |
| `NO_DOCUMENTS` | No such documents exist | yes | |
| `WITHDRAWN` | We withdrew or narrowed it away | yes | |

`denial_basis` is expected on refusals, but is not required: an entity may refuse without giving
any basis. When it is present, it has three fields:

- `refs`: a list of strings, e.g. `offentleglova § 13`.
- `text`: a string.
- `issues`: a list of `NO_REASON_GIVEN`, `NO_LEGAL_REFERENCE`, `INCOMPLETE_REFERENCE` or
  `NOT_MACHINE_READABLE`. These are complaint grounds, for example § 13 without the confidentiality
  rule it relies on. `NOT_MACHINE_READABLE` means we asked for a machine-readable format and the
  entity sent a scan or an image instead: a partial refusal, recorded on the item as
  `PARTLY_RELEASED` (or `WILL_RELEASE_PARTLY` when decided but not yet sent).

Forwarding to another entity is modelled as a refusal plus a new thread.

### Complaint rounds

Complaints go in rounds. When a complaint is sent back to the entity, the items return to
`BEING_EVALUATED`. The entity may refuse again, and a new round follows.

Each round has:
- `status`: `SENT`, `FORWARDED`, `DECIDED`, `OMBUD_SENT` or `OMBUD_DECIDED`.
- `item_ids`: a list of strings.
- `sent_email_id`: a string or null.
- `decision_email_id`: a string or null.
- `outcome`: a string.

A round is open when its status is `SENT`, `FORWARDED` or `OMBUD_SENT`.

## Thread status, derived by `ThreadStateTypeDeriver`

Checked in order; the first match wins.

| # | Rule | Thread status |
|---|---|---|
| 1 | The latest complaint round is open | `COMPLAINT_SENT` / `COMPLAINT_FORWARDED` / `OMBUD_COMPLAINT_SENT` |
| 2 | `waiting_for` = `US` | `WAITING_FOR_US` |
| 3 | Every item final, and all `WITHDRAWN` | `CLOSED` |
| 4 | Every item final, and all non-withdrawn items `NO_DOCUMENTS` | `NO_DOCUMENTS` |
| 5 | Every item final, at least one refused, none released | `DENIED` |
| 6 | Every item final, at least one refused, and at least one released | `PARTLY_DENIED_PARTLY_RELEASED` |
| 7 | Every item final, otherwise | `ANSWERED` |
| 8 | At least one item final or with a non-empty `released_in_email_ids`, and at least one not final | `PARTLY_ANSWERED` |
| 9 | Otherwise | `WAITING_FOR_ENTITY` |

Definitions for rules 5-6:
- An item is **refused** when its status is `DENIED` or `PARTLY_RELEASED`.
- An item is **released** when its status is `RELEASED`, `PARTLY_RELEASED` or `ANSWERED_IN_TEXT`.
  `ANSWERED_IN_TEXT` is never a refusal - it counts as fulfilled the same way a release does, so
  with every item final and none refused it gives `ANSWERED` (rule 7), same as `RELEASED`.
- A lone `PARTLY_RELEASED` item is therefore both refused and released, which gives
  `PARTLY_DENIED_PARTLY_RELEASED`.

"Overdue" is not a status: it is worked out when reading, from the status and `dates`. It is
never stored (no classification from timing alone).

## Export

`ThreadExportService::exportThread()` includes `thread_state` (decoded, or `null`),
`thread_state_type` and `thread_state_source` on every email; see
[docs/thread-export-api.md](thread-export-api.md).

## Shown in the thread view

`organizer/src/class/ThreadState/ThreadStateView.php` renders the state for everyone who can see
the thread, in `view-thread.php`:

- A "Thread state" block, just before "Emails in Thread", shown only when an email of the thread
  has a `thread_state`. It uses the state of the latest such email (`datetime_received`, then
  `id`): the thread status as a badge (`ThreadStateType` mapped to `label_ok`/`label_warn`/
  `label_error`/`label_info`) plus whether it is `auto` or `manual`, who we're waiting for,
  `asks_to_us`, a table of items (asked for, status, denial basis with `issues` as warning
  badges), case numbers, dates, complaint rounds, and `notes` when not empty. Admins also get a
  link to the debug page from step 2c change 4b, `/thread-analysis/thread?id=<thread id>`.
- A complaint deadline - a `dates` entry whose `what` contains "klagefrist" (case-insensitive;
  `dates` has no type field) - is lifted out of the dates list into a callout right under the
  status badge: "Klagefrist: <date>", the days left or how long ago it ran out, and the `what`
  text. Its colour follows urgency: more than 7 days left, 7 days or less (including today),
  overdue, and muted grey with "klage sendt" once `complaints` is not empty. A date that is not
  `YYYY-MM-DD` gets no countdown. Like "overdue" above, this is worked out when rendering and
  never stored.
- Per email, in `.email-header`, when that email has a `thread_state`: a small badge with its
  `thread_state_type`, and a "Show state" link that opens the blob as pretty-printed JSON in the
  shared `ContentDialog` modal (`webroot/js/contentDialog.js`) - a hidden `<template>` per email,
  cloned into the dialog on click, the same look and behaviour as `ExtractionDialog`.

`ThreadStateType::label()` and `ThreadStateItemStatus::label()` give the short Bokmål labels used
there; the enum value itself is always shown alongside, in a `title` attribute. Bokmål labels for
`waiting_for`, denial `issues` and complaint `status` live in `ThreadStateView` itself, since those
are plain string enums rather than PHP enums.

`Thread::mapFromDatabase()` does not copy the `thread_state*` columns onto `ThreadEmail` - the view
loads them separately with `ThreadStateView::loadEmailStates()`.
