# Archived threads in the norske-postlister.no API

Date: 2026-08-10
Status: Approved (conversation with Hallvard)

## Background

`archived` is a housekeeping flag set from the offpost GUI — `view-thread.php`
("Archive thread") and `thread-bulk-actions.php`. Its purpose is to get a
finished thread out of the way in offpost's own thread list, which filters it
out in `index.php` unless `?archived` is given.

The norske-postlister.no API leaks that flag into a place it does not belong.
Two of the three NP endpoints filter on it:

- `NpApiService::listNpThreads()` — `WHERE archived = false AND ? = ANY(labels)`
- `NpApiService::findExistingThread()` — `WHERE entity_id = ? AND archived = false AND ? = ANY(labels)`
- `NpApiService::getNpAttachment()` — deliberately *no* archived filter, so that
  attachment links norske-postlister.no has already published keep working.

So archiving a thread in the offpost GUI silently changes what the public site
shows, in four ways:

1. **The request disappears from `/innsyn`.** `updateOffpostCache_ifOld()`
   replaces `threads.json` wholesale on each 15-minute poll, so the entry, its
   status, and its answer documents are all erased from the page.
2. **The journal entry reverts to "not requested".** With no entry in
   `offpostBuildMaps()`, the doc/case page offers the "søk innsyn" button again
   as if the request had never been made.
3. **A re-click sends a duplicate request to the myndighet.** `foi-request.php`
   dedups against the (now empty) cache map, then calls `createThread()`, whose
   `findExistingThread()` also ignores archived — producing a second thread and
   a second email to the entity.
4. **Answer documents become orphaned.** `/foi-document/{thread_id}/{attachment_id}`
   still serves them, but nothing on the site links there any more.

The third point is the damaging one: an offpost-internal housekeeping action
causes real outbound email to a public body.

## Decision

Archive is an offpost-GUI concept. The NP API behaves as if it does not exist.

This is a change to offpost only. norske-postlister.no is not touched, and the
API payload gains no `archived` field — the NP side has never known about the
flag and will not start now.

## Changes

All in `organizer/src/class/NpApiService.php`.

### 1. `listNpThreads()` — select archived threads too

Drop `archived = false` from the thread query, leaving the NP-label predicate:

```sql
SELECT id, entity_id, labels FROM threads
WHERE ? = ANY(labels)
```

### 2. `listNpThreads()` — status lookup must cover archived threads

`ThreadStatusRepository::getAllThreadStatusesEfficient()` treats its `$archived`
parameter as an either/or filter, not "include both", and it defaults to
`false`. Change 1 alone therefore leaves every archived thread without a status
row, and the existing `$status !== null ? … : ThreadStatusRepository::ERROR_THREAD_NOT_FOUND`
fallback reports it as `ERROR_THREAD_NOT_FOUND` with `email_count_in = 0` and
`email_count_out = 0`.

On the NP side that is worse than hiding the thread: `offpostDisplayStatus()`
maps an `ERROR_*` status with zero counts to "Sendes snart", so a years-old
answered request would be shown to visitors as one that is about to be sent, and
`offpostBuildMaps()` would list none of its documents.

Union both calls, the same way `index.php` and `recent-activity.php` already do:

```php
$statuses = count($threadIds) > 0
    ? ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: false)
    + ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: true)
    : [];
```

Both halves key by `thread_id` and the two sets are disjoint, so `+` on the
arrays is safe here.

### 3. `findExistingThread()` — dedup against archived threads

Drop `archived = false`:

```sql
SELECT id FROM threads
WHERE entity_id = ? AND ? = ANY(labels)
ORDER BY created_at ASC LIMIT 1
```

This is the half that removes the duplicate-email failure above. The tolerant
`ORDER BY created_at ASC LIMIT 1` read is kept unchanged — widening the query
can only increase the number of rows it must tolerate.

Note the consequence: archiving is no longer a way to let a document be
requested a second time. That is intended. Archiving is a display action, and
nothing in the current GUI presents it as a re-request mechanism.

### 4. Comment corrections

Two comments describe a distinction that no longer exists and become actively
misleading:

- `getNpAttachment()`: "No `archived` filter here (unlike `listNpThreads()`)…"
  — the contrast is gone; all three queries now ignore the flag. Reword to state
  plainly that the NP API does not filter on `archived`.
- `findExistingThread()`'s docblock: it reasons about "2+ non-archived threads"
  and sketches a unique index `… WHERE archived = false`. With the filter
  removed, that partial-index predicate would no longer match the query it is
  meant to back. Update the prose and the sketched index to drop the predicate.

## Tests

`organizer/src/tests/NpApiServiceListTest.php`:

- `testArchivedNpThreadExcluded()` asserts today's behaviour and is inverted into
  `testArchivedNpThreadIncludedWithRealStatusAndCounts()`.
- The replacement asserts not just that the thread is listed, but that its
  `email_count_in` / `email_count_out` match its actual emails and that its
  `status` is not `ERROR_THREAD_NOT_FOUND`. This is the regression test for
  change 2 — change 1 without change 2 passes a naive "is it in the list"
  assertion while serving wrong data.

`organizer/src/tests/NpApiServiceCreateTest.php`:

- `testArchivedThreadStillDeduplicates()`: `createThread()` against a label whose
  only thread is archived returns `created: false`, `existing: true`, and the
  archived thread's `thread_id` — no new thread, no new outbound email.

## Out of scope

Archiving a thread renames its IMAP folder to `INBOX.Archive.*`
(`ThreadFolderManager::archiveThreadFolder()`), and
`ThreadScheduledEmailReceiver::findNextFolderForProcessing()` excludes
`INBOX.Archive.%` from processing, so the thread's `last_checked_at` stops
advancing. `ThreadStatusRepository` turns a `last_checked_at` older than 6 hours
into `ERROR_OLD_SYNC`.

Consequence after this change: an archived thread that received an answer will,
6 hours after it was last synced, report `ERROR_OLD_SYNC` to the NP API, which
`offpostDisplayStatus()` renders as "Ukjent status" rather than "Svar mottatt".
The thread and its documents remain visible; only the status label degrades.

This is a pre-existing behaviour of the status computation, not something this
change introduces, and fixing it means touching sync behaviour or the status
rules. Deliberately left for a separate task.
