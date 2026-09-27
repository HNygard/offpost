# Thread export API (`/api/admin/export/*`)

Admin-only API that dumps every Offpost thread, with everything we know about it, to JSON.
It is step 1 of
[the innsynskrav classification roadmap](superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md):
get all thread data down to a local folder so a later step can label it with an expensive
model.

Code: `organizer/src/api/admin/` (endpoints), `organizer/src/class/ThreadExportService.php`
(export queries and JSON building), `organizer/src/class/ThreadExportSync.php` (pure logic
for which threads the CLI needs to (re)fetch). Routes are registered in
`organizer/src/webroot/index.php`.

## Authentication

| Method | Header | Accepted by |
|---|---|---|
| Shared token | `X-Admin-Api-Token: <token>` (file `ADMIN_API_TOKEN_FILE`, default `/run/secrets/admin_api_token`) | both endpoints |
| Admin session | cookie session from `auth.offpost.no`, user listed in `$admins` | both endpoints (GET only) |

This is a separate token from the norske-postlister.no API token (`X-Np-Api-Token`). That
token is held by norske-postlister.no and only covers `norske_postlister_no`/`postliste`
threads; this export covers every thread, so the NP token is **not** accepted here.

### Production setup

Create the token file once:

```
openssl rand -hex 32 > /opt/offpost/secrets/admin_api_token
```

`docker-compose.prod.yaml` mounts this file into the container as
`/run/secrets/admin_api_token`, which is the default `ADMIN_API_TOKEN_FILE`.

## `GET /api/admin/export/threads`

Every thread, archived ones included, ordered by `created_at`. One query, not one per thread.

```json
{
  "export_version": 1,
  "threads": [
    {"id": "uuid", "title": "...", "entity_id": "...", "labels": [],
     "email_count": 3, "last_changed_at": "2026-09-27T10:00:00+02:00",
     "fingerprint": "md5hex"}
  ]
}
```

## `GET /api/admin/export/thread?id=<uuid>`

Everything about one thread: the thread row, its entity, every email (with headers, parsed
body, the raw EML as base64, extractions, attachments and per-email history), sendings and
thread-level history. 400 on a non-UUID id, 404 on an unknown thread.

```json
{
  "export_version": 1,
  "exported_at": "ISO-8601",
  "fingerprint": "same value as in the list",
  "thread": { "id", "entity_id", "title", "my_name", "my_email", "labels",
              "sent", "archived", "public", "sent_comment", "sending_status",
              "initial_request", "request_law_basis", "request_follow_up_plan",
              "created_at", "updated_at" },
  "entity": { "entity_id", "name", "email", "type", "org_num",
              "entity_id_norske_postlister" } | null,
  "emails": [
    { "id", "email_type": "IN|OUT", "datetime_received", "timestamp_received",
      "created_at", "ignore",
      "status_type", "status_text", "auto_classification",
      "classification_source": "manual|algo|prompt|none",
      "description", "answer",
      "subject", "from", "to", "cc", "imap_headers",
      "body_plain", "body_html", "body_parse_error": null | "message",
      "eml_base64",
      "extractions": [ { "extraction_id", "prompt_id", "prompt_service",
                         "prompt_text", "extracted_text", "error_message",
                         "created_at", "updated_at" } ],
      "attachments": [ { "id", "name", "filename", "filetype", "size",
                         "location", "status_type", "status_text",
                         "created_at", "extractions": [ ...same shape... ] } ],
      "history": [ thread_email_history rows for this email ] }
  ],
  "sendings": [ thread_email_sendings rows ],
  "history": [ thread_history rows ]
}
```

Emails are ordered by `datetime_received`, then `id`. The other lists are ordered by
`created_at`, then id. `classification_source` is `none` when `status_type` is `unknown` or
null, otherwise `auto_classification` if set, otherwise `manual`. A body-parsing failure is
recorded in `body_parse_error` and does not fail the whole thread. JSON is encoded with
`JSON_INVALID_UTF8_SUBSTITUTE`.

### Fingerprint

An md5 over: the latest timestamp of the thread, its emails, extractions, sendings and both
history tables, plus `id|status_type|auto_classification|ignore` for every email. It changes
whenever any of that changes - including a classification change with no timestamp move - so
the CLI's change detection (below) catches it.

## Admin page: `/thread-export`

Shows the thread count and the CLI command to run. Lists threads (title, entity, emails,
last change), each with a link to download its own JSON straight from the thread endpoint
(using the admin session, no token needed in the browser). It does no bulk download - that
is the CLI's job.

## CLI: `tools/pull-thread-export.php`

Runs on **your own machine**, not on the server: it is a client for the two
endpoints above, needing plain PHP + `ext-curl`. It sends the token only over
https (plain http is allowed for localhost only).

```
php tools/pull-thread-export.php \
    --base-url=https://offpost.no --token-file=secrets/admin_api_token \
    [--out=thread-export] [--full] [--limit=N]
```

| Option | Meaning |
|---|---|
| `--base-url=URL` | Required. Base URL of the Offpost instance. |
| `--token-file=PATH` | Required. File holding the admin API token; read and trimmed. |
| `--out=DIR` | Output directory, relative to cwd. Default `thread-export`. |
| `--full` | Refetch every thread, ignoring local fingerprints. |
| `--limit=N` | Fetch at most N threads this run. |
| `--help` | Print usage and exit. |

It calls the list endpoint, then the thread endpoint for each id that is new or whose
fingerprint changed (all of them with `--full`). Output layout:

```
<out>/threads/<id>.json   one export per thread, pretty-printed
<out>/index.json          id => fingerprint, for change detection next run
```

Both files are written via a temp file plus rename, and `index.json` is rewritten after
*each* successful thread, so an interrupted run resumes cleanly - nothing already downloaded
is refetched or deleted. A thread that fails to fetch or save is reported on STDERR and
counted, and the run continues with the rest. The final line is
`fetched N, unchanged M, failed K`; the process exits 1 if any thread failed or the list
request itself failed, otherwise 0. Threads that disappear from the list stay on disk.

**The output contains full email content (including raw EML) and must never be committed.**
`thread-export/` (the default `--out`) is gitignored; if you use a different `--out`, keep it
out of git yourself.
