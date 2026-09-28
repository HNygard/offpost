# Step 2.1: the cumulative thread state model

Concrete plan for sub-step 1 of step 2 in
`docs/superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md`.
Statuses and rules below were settled by interviewing the owner.

## What is recorded per email

- **The email itself:** `status_type` and `status_text`, as today.
- **The cumulative thread state after this email:**
  - `thread_state` (jsonb): the blob below.
  - `thread_state_type`: the thread status derived from the blob by code.
  - `thread_state_source`: `manual` or `auto`.

The thread's current state is the state on its latest email. There is no
thread-level column. Nothing writes these columns in this step; the local
analysis (step 2.2) and feedback (step 2b) will.

## The blob, schema_version 1

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

Each item has `id` (string, unique within the blob), `asked_for` (string), `status`, `denial_basis` (object or null), `released_in_email_ids` (list of strings) and `note` (string).

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

`denial_basis` is expected on refusals, but is not required: an entity may refuse without giving any basis. When it is present, it has three fields:

- `refs`: a list of strings, e.g. `offentleglova § 13`.
- `text`: a string.
- `issues`: a list of `NO_REASON_GIVEN`, `NO_LEGAL_REFERENCE`, `INCOMPLETE_REFERENCE` or `NOT_MACHINE_READABLE`. These are complaint grounds, for example § 13 without the confidentiality rule it relies on. `NOT_MACHINE_READABLE` covers asking for a machine-readable format and getting a scan or an image instead.

Forwarding to another entity is modelled as a refusal plus a new thread.

### Complaint rounds

Complaints go in rounds. When a complaint is sent back to the entity, the items return to `BEING_EVALUATED`. The entity may refuse again, and a new round follows.

Each round has:
- `status`: `SENT`, `FORWARDED`, `DECIDED`, `OMBUD_SENT` or `OMBUD_DECIDED`.
- `item_ids`: a list of strings.
- `sent_email_id`: a string or null.
- `decision_email_id`: a string or null.
- `outcome`: a string.

A round is open when its status is `SENT`, `FORWARDED` or `OMBUD_SENT`.

## Thread status, derived by code

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

Definitions for rules 5–6:
- An item is **refused** when its status is `DENIED` or `PARTLY_RELEASED`.
- An item is **released** when its status is `RELEASED`, `PARTLY_RELEASED` or `ANSWERED_IN_TEXT`.
  `ANSWERED_IN_TEXT` is never a refusal - it counts as fulfilled the same way a release does, so
  with every item final and none refused it gives `ANSWERED` (rule 7), same as `RELEASED`.
- A lone `PARTLY_RELEASED` item is therefore both refused and released, which gives `PARTLY_DENIED_PARTLY_RELEASED`.

"Overdue" is not a status: it is worked out when reading, from the status and `dates`. It is never stored (no classification from timing alone).

## Components

| Unit | File |
|---|---|
| Migration | `organizer/src/migrations/sql/033_add_thread_state_to_thread_emails.sql` (+ regenerated `99999-database-schema-after-migrations.sql`) |
| Thread status enum | `organizer/src/class/Enums/ThreadStateType.php` |
| Item status enum | `organizer/src/class/Enums/ThreadStateItemStatus.php` |
| Blob value class with validation | `organizer/src/class/ThreadState/ThreadState.php` (`fromArray()` throws `InvalidArgumentException` with a precise message, `toArray()` round-trips) |
| Derivation | `organizer/src/class/ThreadState/ThreadStateTypeDeriver.php` (pure) |
| Export | `ThreadExportService`: each email gets `thread_state` (decoded JSON or null), `thread_state_type`, `thread_state_source` |
| Docs | `docs/thread-state.md` (the model), pointer in `docs/thread-export-api.md`, CLAUDE.md/README key classes |

## Tests

- `ThreadStateTest`:
  - a valid blob round-trips unchanged;
  - each missing core key is rejected;
  - bad enum values are rejected (item status, `waiting_for`, issue, complaint status);
  - duplicate item ids are rejected;
  - an empty items list is rejected;
  - `denial_basis` null is accepted on `DENIED`.
- `ThreadStateTypeDeriverTest`:
  - one test per rule 1–9;
  - rule order: an open complaint beats `waiting_for` = `US`, and `US` beats all-final;
  - a lone `PARTLY_RELEASED` item gives `PARTLY_DENIED_PARTLY_RELEASED`;
  - `DENIED` + `NO_DOCUMENTS` gives `DENIED`;
  - a `DECIDED` round with items back in `BEING_EVALUATED` gives `WAITING_FOR_ENTITY`.
- `ThreadExportServiceTest`: the new fields are exported, both null and set.
