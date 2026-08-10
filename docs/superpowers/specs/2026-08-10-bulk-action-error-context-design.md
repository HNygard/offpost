# Bulk thread action error context

## Problem

`thread-bulk-actions.php` reports every failure as a bare count:

```
Failed to process 1 thread(s)
```

An admin who fails to archive a thread has no way to tell what went wrong. Five
distinct situations collapse into that one sentence, and a sixth is not reported
at all.

| Situation | Current handling |
|---|---|
| `thread_ids` entry is not `entityId:threadId` | counted, no reason |
| Thread not in the user's thread list | counted, no reason |
| Thread found but `canUserAccess()` false | counted, no reason |
| `ready_for_sending` on a non-staging thread | counted, no reason |
| Unknown action | counted, no reason |
| `updateThread()` throws | **not counted** — PHP fatal, no message at all |

`ThreadDatabaseOperations::getThreads($userId)` already filters with
`WHERE t.public = true OR ta.thread_id IS NOT NULL`, so the `!$thread ||
!$thread->canUserAccess($userId)` branch conflates "does not exist", "belongs to
a different entity" and "you are not authorized". The `canUserAccess()` half of
that condition is effectively dead — anything present in the list already
satisfies it.

The first row is not hypothetical. The thread view page submits `:threadId`
with an empty entity, so its Archive/Unarchive button never matches a thread.
See section 2b.

## Goals

Tell the admin, per failed thread, what specifically stopped it.

Non-goals: changing who may perform bulk actions, changing the actions
themselves, or reworking the bulk action UI.

## Design

### 1. Collect reasons instead of a count

Replace the `$errorCount` integer with an `$errors` array. Each entry records
the thread reference the admin submitted, the thread title when it is known, and
a specific reason:

```php
$errors[] = [
    'ref'    => "$entityId:$threadId",
    'title'  => $thread->title ?? null,
    'reason' => 'Not in staging status (current: sent)',
];
```

The session message is assembled as a heading plus one bullet per failure:

```
Failed to process 2 thread(s):
• 957-999-999:a1b2c3d4 (Innsyn i reiseregninger) — Not in staging status (current: sent)
• 957-999-999:d4e5f6a7 — No thread exists with this ID
```

The existing heading text `Failed to process N thread(s)` is preserved as a
prefix so the current e2e assertion keeps matching.

### 2. Diagnose the not-found case

When a thread ID is absent from the user's thread list, run one targeted lookup
against `threads` by ID to distinguish two causes:

| Lookup result | Reason |
|---|---|
| No row | `No thread exists with this ID` |
| Row exists | `You do not have access to this thread (not public, no authorization)` |

Distinguishing "does not exist" from "no access" does confirm the existence of a
thread ID to a user not authorized for it. Accepted: the ID is a UUID the caller
must already possess, and this is an authenticated internal tool. The diagnostic
value outweighs the disclosure.

### 2b. Identify threads by ID alone

Bulk actions took references shaped `entityId:threadId`, a leftover from the
era when threads lived in `threads-<entity>.json` files. Thread IDs are UUIDs
and the primary key, so the entity prefix carries no information.

`fd3c025` moved the thread view to threadId-only but left the archive button
submitting a hardcoded empty prefix (`:threadId`), which no longer matched any
thread. The Archive/Unarchive button on the thread view page has been silently
broken since - producing exactly the bare "Failed to process 1 thread(s)" that
prompted this work.

The handler now resolves threads by ID alone and both pages submit a plain
thread ID. A leading `entityId:` prefix is still accepted so a page loaded
before the change keeps working. The entity-mismatch reason disappears with it,
since entity is no longer part of resolution.

### 3. Report exceptions instead of dying

Wrap each thread's action in `try`/`catch`. A throwing `updateThread()` becomes
a reported reason (`Database update failed: <message>`) rather than a fatal that
aborts the request and loses every other thread's result.

### 4. Render newlines

`index.php` and `view-thread.php` emit `htmlescape($_SESSION['error_message'])`
into a single element, so embedded newlines collapse. Wrap both in `nl2br()`.
Escaping still happens first, so `nl2br()` only ever sees escaped text.

### 5. Reason strings

| Trigger | Reason |
|---|---|
| Thread row missing, or reference is not a thread ID | `No thread exists with this ID` |
| Not authorized | `You do not have access to this thread (not public, no authorization)` |
| Wrong sending status | `Cannot mark as ready for sending: status is X, expected STAGING` |
| Unknown action | `Unknown bulk action "<action>"` |
| Exception | `Update failed: <message>` |

## Testing

Extend `organizer/src/e2e-tests/pages/BulkThreadActionsPageTest.php`:

- the archive button as the thread view page actually renders it archives the
  thread — scrape the form value from `/thread-view` and submit that, so a page
  emitting an unusable reference fails the test instead of silently doing nothing
- a legacy `entityId:threadId` reference still resolves
- an unrecognised reference produces the no-such-thread reason
- a non-uuid thread ID reports cleanly rather than raising a Postgres parse error
- `ready_for_sending` against a sent thread names the current status
- the unknown-action case still reports the count, now with a reason per thread
- the error alert names the failed thread's title, asserted against the alert
  box itself rather than the whole page
- each failure renders on its own line
- a mixed batch reports both the success count and the per-thread failures

Reason strings are deterministic, so assert them with `assertStringContainsString`
against fixed thread data.
