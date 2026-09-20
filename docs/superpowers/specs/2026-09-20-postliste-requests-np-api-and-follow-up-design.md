# Postliste requests from norske-postlister: np-api extensions and `postliste` follow-up plan

Date: 2026-09-20
Status: Draft (conversation with Hallvard). Counterpart spec in the norske-postlister repo:
`docs/superpowers/specs/2026-09-20-offpost-email-recurring-postjournal-requests-design.md`.

## Background

norske-postlister.no is adding a downloader `offpost-email` that requests postjournals (offentlig journal) by
email for entities that publish nothing scrapeable (Helfo, Nordlandssykehuset, Sørlandet sykehus,
Sykehusapotekene HF first), and for ad hoc gaps in other entities. Offentleglova § 10 gives the right to the
journal on request.

Division of work: norske-postlister creates one thread per entity per period through the np-api and ingests the
attachments. **Offpost owns everything after creation**: sending, reminders, postliste-specific follow-ups and
storing replies. norske-postlister verifies one thing: when offpost reports the thread as answered, did we get
the journal in a form we can read? If not, norske-postlister reclassifies that IN email through the np-api and
sends one reply through the np-api, from the thread's own profile. Every other email is nagging, and offpost
does the nagging.

Entity emails are maintained in offpost by Hallvard as needed, outside this spec. norske-postlister checks
`supported_entities` from the thread listing at run time and refuses to create threads for an entity that is
not there.

Labels: `postliste` on every such thread, plus `postliste:<period>` where period is an ISO week (`2026-W38`), a
month (`2026-09`), a year (`2025`) or a range (`2025-01--2025-06`, `2011--2021`). Police-district threads already
carry `postliste postliste-politidistrikt` and some old threads carry `postliste:2011-2021`. Nothing is renamed.

Authentication: norske-postlister uses the existing admin login (cookie session from
`auth.offpost.no`), not a new token. `npApiRequireTokenOrAdminSession()` already covers the GET endpoints.

Each thread gets a fresh random profile as today. One thread per period keeps threads small and lets the
existing per-thread status logic work unchanged.

## Work packages

Three packages, in order, all code. Each is meant to be run as its own Claude Code prompt in
this repo with the brainstorming skill, so the details below are requirements, not designs. Follow CLAUDE.md,
keep `/docs` and README in sync, add tests next to the existing ones in `organizer/src/tests/`.

### 1. np-api label support for `postliste:<period>` threads

Files: `organizer/src/api/np/`, `organizer/src/class/NpApiService.php`.

1. `NpApiService::createThread()` requires a `document_id:` or `case_num:` label and dedups on it. Accept
   `postliste:<period>` as a third mapping label with the same dedup semantics (entity + label, non-archived,
   oldest wins). Validate the period format: `YYYY-Www`, `YYYY-MM`, `YYYY`, `YYYY-MM--YYYY-MM`, `YYYY--YYYY`.
   Malformed or empty values give 400 as today.
2. `GET /api/np/threads` returns every thread labelled `norske_postlister_no`. Add optional query parameters
   `label` (repeatable, exact match, all must be present) and `entity_id_norske_postlister`. When `label` is
   given, match on those labels instead of the fixed `norske_postlister_no`. No parameters keeps the old
   behaviour, so the norske-postlister frontend cache refresh is unchanged.
3. Add to each thread in the listing: `sending_status`, `request_follow_up_plan`, `created_at`. Add `size`
   to each attachment.
4. Include `archived = true` threads when `label` is given. A finished postliste thread may be archived by
   hand; norske-postlister still needs it to rebuild its period state.

Document the parameters in the API docs. `/api/np/attachment` is unchanged.

### 2. Admin-session thread creation, email reclassification and reply

1. `POST /api/np/thread` is token-only (`npApiRequireToken()`). Also allow an admin session, but only when
   the request carries `X-Requested-With: offpost-email`, so a browser form post can never trigger it. Factor
   this into a helper next to `npApiRequireTokenOrAdminSession()`. The daily cap (100) stays and is shared
   with the frontend one-click requests.
2. `request_follow_up_plan` becomes an optional field on `POST /api/np/thread`: `speedy`, `slow`, `postliste`
   or empty. Default stays `speedy`.
3. New endpoint `POST /api/np/thread/{thread_id}/email/{email_id}/classify` (token, or admin session with the
   same header). JSON body `{status_type, status_text}`. Sets the classification on that IN email the same way
   the manual classify form does (manual wins over AI, `auto_classification` cleared), logs to
   `thread_history` with user `norske-postlister-api`. The status type used for an unreadable journal reply
   is chosen in this package; it must make the thread count as not answered for package 3, and must be
   excluded from the substantive statuses in the existing NP feed (`listNpThreads`).
4. New endpoint `POST /api/np/thread/{thread_id}/reply` (same auth). JSON body `{subject, body}`. Queues a
   `thread_email_sendings` row at `READY_FOR_SENDING` from the thread's `my_name`/`my_email` to the entity's
   address (same recipient logic as `thread-reply.php`), appends the signature like `createThread()` does, logs
   `thread_history`. Returns `{queued: true, sending_id}`. At most one reply per thread per day from this caller.

### 3. Follow-up plan `postliste`

File: `organizer/src/class/ThreadScheduledFollowUpSender.php`. Today it handles `speedy` (5 days) and `slow`
(14 days), only for threads with exactly one outgoing email, and leaves the reminder in `STAGING` for a human
to release. Add plan `postliste`:

1. Applies to threads with `request_follow_up_plan = 'postliste'` and `request_law_basis = 'offentleglova'`.
2. Time-based reminders, measured from the first `OUT` email's send time, only while the thread status is
   `EMAIL_SENT_NOTHING_RECEIVED` or the last substantive reply predates the last follow-up:
   - day 10: reminder 1, `READY_FOR_SENDING` (no human release).
   - day 20: reminder 2, states that an unanswered request counts as refusal under offentleglova § 32 and that
     a klage will be considered. `READY_FOR_SENDING`.
   - nothing after day 20. Klage is a human decision.
3. A reply that norske-postlister reclassified as unreadable (package 2) does not count as an answer: the
   nagging continues, with the day count measured from norske-postlister's own reply.
4. Works on threads with several `OUT` and `IN` emails (today `createFollowUpEmailContent()` throws unless the
   thread has exactly one email). Quote the original request's subject and date.
5. Stop conditions: `REQUEST_REJECTED` on any `IN` email, thread archived, any sending still in flight.
6. Keep the one-email-per-minute cron ceiling. Log each decision to `thread_history`.
7. `speedy` and `slow` behave exactly as before. Tests cover day-10/day-20 timing, stop conditions, the
   reclassified-reply path, and the unchanged legacy plans.

Templates (Norwegian, placeholders in `{}`), reused from the norske-postlister spec:

- Reminder 1: "Jeg viser til innsynskrav sendt {sent_date} om offentlig journal for perioden {period_from} –
  {period_to}. Kravet skal etter offentleglova § 29 avgjøres uten ugrunnet opphold. Jeg ber om at journalen
  sendes snarest."
- Reminder 2: reminder 1 plus "Dersom kravet ikke blir behandlet, regnes det som avslag etter § 32 tredje
  ledd, og jeg vil vurdere å klage til {klageinstans}." `{klageinstans}` comes from the entity type
  (Statsforvalteren for kommuner, overordnet departement for statlige organ); fall back to "klageinstansen".
The "could not read it" reply text is owned by norske-postlister and arrives through the reply endpoint.

All templates are a first draft. Hallvard iterates on them once the flow runs.

## Non-goals

No OCR, no attachment-level classification, no fixed sender profile per entity, no klage automation, no
entity email collection in this spec, no change to the police-district threads or to `/api/np/attachment`.
