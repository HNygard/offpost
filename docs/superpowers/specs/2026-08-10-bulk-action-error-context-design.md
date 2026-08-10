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

When a thread reference is absent from the user's thread list, run one targeted
lookup against `threads` by ID to distinguish three causes:

| Lookup result | Reason |
|---|---|
| No row | `No thread exists with this ID` |
| Row with a different `entity_id` | `Thread belongs to entity <actual>, not <submitted>` |
| Row with the same `entity_id` | `You do not have access to this thread (not public, no authorization)` |

The wrong-entity case is the most likely explanation for a bulk action that
fails on a thread the admin can plainly see: a stale listing page posts an
`entityId` that no longer matches the thread's row, the inner match loop never
fires, and the old message says only "Failed to process 1 thread(s)".

Distinguishing "does not exist" from "no access" does confirm the existence of a
thread ID to a user not authorized for it. Accepted: the ID is a UUID the caller
must already possess, and this is an authenticated internal tool. The diagnostic
value outweighs the disclosure.

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
| Malformed reference | `Invalid thread reference (expected entityId:threadId)` |
| Thread row missing | `No thread exists with this ID` |
| Entity mismatch | `Thread belongs to entity X, not Y` |
| Not authorized | `You do not have access to this thread (not public, no authorization)` |
| Wrong sending status | `Cannot mark as ready for sending: status is X, expected staging` |
| Unknown action | `Unknown bulk action` |
| Exception | `Database update failed: <message>` |

## Testing

Extend `organizer/src/e2e-tests/pages/BulkThreadActionsPageTest.php`:

- malformed reference produces the invalid-reference reason
- non-existent thread ID produces the no-such-thread reason
- reference with a mismatched entity ID produces the wrong-entity reason
- `ready_for_sending` against a sent thread names the current status
- the unknown-action case still reports the count, now with a reason per thread
- a mixed batch reports both the success count and the per-thread failures

Reason strings are deterministic, so assert them with `assertStringContainsString`
against fixed thread data.
