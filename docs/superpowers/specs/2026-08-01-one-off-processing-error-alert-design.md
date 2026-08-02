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
