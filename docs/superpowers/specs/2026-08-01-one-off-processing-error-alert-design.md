# One-off admin alert for email processing errors

Date: 2026-08-01
Status: Approved

## Problem

When `ThreadEmailDatabaseSaver::saveThreadEmails()` cannot attribute an email to
exactly one thread (`no_matching_thread` / `multiple_matching_threads`), it saves
a row in `thread_email_processing_errors` for GUI resolution and throws. The
scheduled receiver (`system-pages/scheduled-email-receiver.php`) sends an admin
alert email on every failed run, so the same unresolved email produces an alert
on every cron run (warning fatigue). The throw also rolls back the folder batch,
so other emails in the same folder stay stuck behind the ambiguous one.

## Decision

Alert only when the processing error is *new*:

- Before upserting the error record, check whether an unresolved error already
  exists for the `email_identifier`
  (`ThreadEmailProcessingErrorManager::hasUnresolvedError()`, new helper).
- **New error**: unchanged behavior — save record, throw. The receiver reports
  the failure and the admin gets one alert with full details.
- **Known error**: update the record (upsert as before), then skip the email and
  continue processing the rest of the folder. No throw, no alert. The folder
  completes normally, remaining emails are saved, and the folder stops
  re-erroring on every run.

The GUI resolution flow is untouched: resolving inserts a `thread_email_mapping`
row and deletes the error row; the mapping takes priority on the next pass over
the folder. Because resolving deletes the error row, a recurrence after a bad
resolution produces one fresh alert rather than silence.

## Changes

- `ThreadEmailProcessingErrorManager::hasUnresolvedError(string $emailIdentifier): bool` (new)
- `ThreadEmailDatabaseSaver::saveThreadEmails()`: in the no-match/multi-match
  branch, capture `hasUnresolvedError()` before the upsert; after committing the
  error record, `continue` (with a fresh transaction) instead of throwing when
  the error was already known.

## Testing

- Unit test for `hasUnresolvedError()` (resolved rows do not count).
- `saveThreadEmails()` with mocked IMAP components against the dev database:
  - First encounter: throws, error row registered.
  - Known error: no throw, ambiguous email skipped, remaining emails in the
    folder are saved.

## Missing IMAP UID diagnostics

Separate from the attribution-error deduplication above, `ThreadEmailMover`
reports vanished UIDs as `email-fetch-missing-uid`. Each report includes the
same structured diagnostic log line written to the application log:

- Mailbox status before `SEARCH ALL` (message counts, UIDVALIDITY, UIDNEXT),
  search time/options, total UID count, and the first 20 UIDs.
- The last 100 IMAP operation events, including timestamps, UID/message-number
  mappings, fetch attempts/retries, move targets/results, and close flags.
  History is retained even when debug output is disabled; bodies, decoded text,
  authentication parameters, and arbitrary search expressions are not retained.
- At failure: queued IMAP errors/alerts, connection liveness, the server-reported
  selected mailbox, current source-mailbox status, a fresh search for the failed
  UID, its message number, and flags including `deleted`. Each probe's errors
  are included separately so a failed probe is not mistaken for proof of deletion.

Compare UIDVALIDITY first: a change invalidates the old UID namespace. A different
selected mailbox indicates a selection mismatch. With the same UIDVALIDITY and
mailbox, an empty successful UID search indicates the UID is now absent; a
present UID with `deleted` set is marked for deletion but not yet expunged.
Earlier move events can show whether this process moved that UID, including
moves back to the same folder. A UID still present after the failed fetch points
to a transient fetch/client-state problem rather than confirmed disappearance.

Probes do not reopen, move, delete, or explicitly expunge messages, and failures
are recorded without hiding the original fetch error. These observations are
not an atomic snapshot or a server audit trail: concurrent changes can happen
between probes. The history is bounded, and identifying another client or
scheduled worker that deleted/expunged a UID requires IMAP server logs correlated
with the mailbox, UIDVALIDITY, UID, and event timestamps.
