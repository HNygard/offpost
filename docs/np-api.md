# norske-postlister.no API (`/api/np/*`)

Server-to-server API used by [norske-postlister.no](https://norske-postlister.no) to create
innsyn requests through Offpost and read back the threads, their status and their attachments.

Code: `organizer/src/api/np/` (endpoints), `organizer/src/class/NpApiService.php` (logic),
`organizer/src/class/PostlistePeriod.php` (period labels). Routes are registered in
`organizer/src/webroot/index.php`.

## Authentication

| Method | Header | Accepted by |
|---|---|---|
| Shared token | `X-Np-Api-Token: <token>` (file `NP_API_TOKEN_FILE`, default `/run/secrets/np_api_token`) | every endpoint |
| Admin session | cookie session from `auth.offpost.no`, user listed in `$admins` | GET endpoints (`npApiRequireTokenOrAdminSession()`) |

Session auth on the POST endpoints is deliberately not accepted (CSRF).

## Thread kinds and labels

| Kind | Labels | Mapping label (dedup key) |
|---|---|---|
| Document request | `norske_postlister_no`, `document`, `document_id:<id>` | `document_id:<id>` |
| Case request | `norske_postlister_no`, `case_num:<num>` | `case_num:<num>` |
| Postjournal request (one per entity per period) | `postliste`, `postliste:<period>` | `postliste:<period>` |

A thread is visible to this API when it carries `norske_postlister_no` or `postliste`.

`<period>` forms accepted by `PostlistePeriod`:

| Form | Example | Covers |
|---|---|---|
| ISO week | `2026-W38` | Monday to Sunday of that week |
| Month | `2026-09` | whole month |
| Year | `2025` | whole year |
| Month range | `2025-01--2025-06` | first day of first month to last day of last month |
| Year range | `2011--2021` | inclusive |

Anything else (including the legacy hand-made `postliste:2011-2021`) is rejected with 400 on
creation. Old threads with legacy labels are not renamed.

## `GET /api/np/threads`

Lists threads with status, emails and attachment metadata.

Query parameters (all optional):

| Parameter | Meaning |
|---|---|
| `label` (repeatable: `?label=postliste&label=postliste:2026-W38`) | Exact-match labels; a thread must carry all of them. When given, this replaces the default `norske_postlister_no` match. |
| `entity_id_norske_postlister` | Only threads for this entity. An unknown id gives an empty `threads` list. |

Without parameters the response is unchanged from before: every `norske_postlister_no` thread.
Archived threads are always included (archiving is an Offpost GUI concept only), with or without
filters.

Response:

```json
{
  "supported_entities": ["<np entity id>", "..."],
  "threads": [
    {
      "thread_id": "uuid",
      "thread_url": "https://offpost.no/thread-view?threadId=...",
      "entity_id_norske_postlister": "np id or null",
      "labels": ["postliste", "postliste:2026-W38"],
      "sending_status": "STAGING | READY_FOR_SENDING | SENDING | SENT",
      "request_follow_up_plan": "speedy | slow | postliste | null",
      "created_at": 1758362400,
      "status": "EMAIL_SENT_NOTHING_RECEIVED | STATUS_OK | NOT_SENT | ERROR_*",
      "email_count_in": 1,
      "email_count_out": 1,
      "email_last_activity": 1758362400,
      "emails": [
        {
          "email_type": "IN | OUT",
          "timestamp": 1758362400,
          "subject": "decoded subject or null",
          "status_type": "ThreadEmailStatusType value or null",
          "attachments": [
            {"id": "uuid", "name": "journal.pdf", "content_type": "application/pdf", "size": 12345}
          ]
        }
      ]
    }
  ]
}
```

Timestamps are unix seconds. `size` is bytes, or `null` when the attachment content was never
stored. Emails marked *ignore* in Offpost are left out.

## `POST /api/np/thread`

Token only. JSON body:

```json
{
  "entity_id_norske_postlister": "...",
  "title": "...",
  "body": "...",
  "labels": ["postliste", "postliste:2026-W38"]
}
```

`labels` must contain exactly one kind of mapping label (see table above). Creates a thread with
a fresh random profile, law basis `offentleglova`, follow-up plan `speedy`, and queues the
request email at `READY_FOR_SENDING`. If a non-archived or archived thread already carries the
same mapping label for the same entity, no new thread is created and the oldest one is returned
with `existing: true`.

Responses: 200 `{created, existing, thread_id, thread_url, status}`; 400 validation (including a
malformed `postliste:` period); 404 unknown entity; 429 daily cap (100 threads per day across all
callers of this endpoint).

## `GET /api/np/attachment?thread_id=<uuid>&attachment_id=<uuid>`

Attachment bytes with `Content-Type` and `Content-Disposition`. 404 when the thread is not
visible to this API or the attachment is unknown or has no stored content.
