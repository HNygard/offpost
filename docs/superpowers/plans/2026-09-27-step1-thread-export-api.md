# Step 1: admin API to get thread data down locally

Concrete plan for step 1 of
`docs/superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md`.

## Goal

Get every Offpost thread, with everything we know about it, from prod to a
local folder outside git. Step 2 labels it with an expensive model. Success:
one command on the developer's machine fills a folder with one JSON file per
thread, and running it again only fetches threads that changed.

## Decisions

- **Auth: a new admin API token**, separate from the NP token. The NP token
  is held by norske-postlister.no. This export covers every thread, so it
  must not be readable with that token.
  - Header `X-Admin-Api-Token`, file `ADMIN_API_TOKEN_FILE`, default
    `/run/secrets/admin_api_token`.
  - Endpoints are GET only and accept the token OR an admin session. The
    session lets the admin page link straight to the download.
- **Raw EML is included** (base64), next to the parsed body and headers. It
  holds the attachments too, so there is no separate attachment-bytes
  endpoint.
- **Change detection by fingerprint.** The list endpoint returns a
  `fingerprint` per thread, and the CLI refetches a thread when it differs.
  - The fingerprint is an md5 over: the latest timestamp of the thread,
    emails, extractions, sendings and both history tables, plus
    `id|status_type|auto_classification|ignore` for every email.
  - Classification changes are therefore picked up even if no timestamp
    moves. `--full` refetches everything regardless.
- **The CLI runs on your own machine**, not on the server. It is a client for
  the export API, with plain PHP and curl, and lives in `tools/`, outside the
  server source.
  - Default output is `./thread-export/` in the repo root, which is
    gitignored because it contains email content.

## API

### `GET /api/admin/export/threads`

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

- Every thread, archived ones included, ordered by `created_at`.
- It runs as one query, not one query per thread.

### `GET /api/admin/export/thread?id=<uuid>`

- Returns 400 on a non-UUID id and 404 on an unknown thread.

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

- Emails are ordered by `datetime_received`, then `id`. The other lists are
  ordered by `created_at`, then id.
- `classification_source`:
  - `none` when `status_type` is `unknown` or null;
  - otherwise `auto_classification` if it is set;
  - otherwise `manual`.
- Body parsing uses `ThreadEmailExtractorEmailBody::extractContentFromEmail`.
  A parse failure is recorded in `body_parse_error` and does not fail the
  thread.
- JSON is encoded with `JSON_INVALID_UTF8_SUBSTITUTE`.

## Components

| Unit | File |
|---|---|
| Admin token auth (same shape as the NP auth) | `organizer/src/api/admin/admin-api-auth.php` |
| Export queries and JSON building | `organizer/src/class/ThreadExportService.php` |
| List endpoint | `organizer/src/api/admin/export_threads_list.php` |
| Thread endpoint | `organizer/src/api/admin/export_thread_get.php` |
| Routes | `organizer/src/webroot/index.php` (`$regularPages`, like `/api/np/*`) |
| Admin page | `organizer/src/system-pages/thread-export.php`, route `/thread-export` in `$adminPages`, link in `header.php` admin tools |
| Pure sync logic (which ids to fetch) | `organizer/src/class/ThreadExportSync.php` |
| CLI | `tools/pull-thread-export.php` |
| Secret wiring | `docker-compose.dev.yaml`, `docker-compose.prod.yaml`, `.gitignore`, `.github/workflows/php.yml` placeholder |
| Docs | `docs/thread-export-api.md`, README/CLAUDE.md pointers |

### Admin page

- Shows the thread count and the CLI command.
- Has a table of threads (title, entity, emails, last change), each with a
  link to download its JSON through the thread endpoint.
- It does no bulk download; bulk is the CLI's job.

### CLI

```
php tools/pull-thread-export.php \
    --base-url=https://offpost.no --token-file=secrets/admin_api_token \
    [--out=thread-export] [--full] [--limit=N]
```

- Writes `<out>/threads/<id>.json` and `<out>/index.json` (`id` → `fingerprint`).
- `index.json` is written after each thread, so an interrupted run resumes.
- Prints `fetched N, unchanged M, failed K`.
- Exits non-zero if any thread failed.
- Threads that disappear from the list are kept locally and are not deleted.

## Testing

- **Unit (`tests/`, uses the test DB):**
  - `ThreadExportService`: builds a thread with emails, an attachment,
    extractions, a sending and history, then asserts the exact JSON shape;
    `classification_source` for all four cases; a fingerprint change after a
    classification change; the list includes the thread with the same
    fingerprint.
  - `ThreadExportSync`: pure decisions (new, changed, unchanged, `--full`,
    `--limit`).
  - Admin token check: missing, wrong and right token, missing file.
- **E2E (`e2e-tests/pages/`):**
  - Endpoints: 401 without auth, 401 with the NP token, 200 with the admin
    token and with an admin session, 400/404 on the thread endpoint.
  - Admin page: renders for an admin; a 404 page for a non-admin.

## Not in this step

Labelling, the dataset, any change to classifiers.
