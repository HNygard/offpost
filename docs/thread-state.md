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
| `DENIED` | All of it refused | yes | yes |
| `NO_DOCUMENTS` | No such documents exist | yes | |
| `WITHDRAWN` | We withdrew or narrowed it away | yes | |

`denial_basis` is expected on refusals, but is not required: an entity may refuse without giving
any basis. When it is present, it has three fields:

- `refs`: a list of strings, e.g. `offentleglova § 13`.
- `text`: a string.
- `issues`: a list of `NO_REASON_GIVEN`, `NO_LEGAL_REFERENCE` or `INCOMPLETE_REFERENCE`. These are
  complaint grounds, for example § 13 without the confidentiality rule it relies on.

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
- An item is **released** when its status is `RELEASED` or `PARTLY_RELEASED`.
- A lone `PARTLY_RELEASED` item is therefore both refused and released, which gives
  `PARTLY_DENIED_PARTLY_RELEASED`.

"Overdue" is not a status: it is worked out when reading, from the status and `dates`. It is
never stored (no classification from timing alone).

## Export

`ThreadExportService::exportThread()` includes `thread_state` (decoded, or `null`),
`thread_state_type` and `thread_state_source` on every email; see
[docs/thread-export-api.md](thread-export-api.md).
