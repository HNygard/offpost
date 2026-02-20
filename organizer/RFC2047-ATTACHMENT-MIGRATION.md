# RFC 2047 Attachment Migration

## Overview

This migration fixes missing attachments caused by the RFC 2047 encoding bug (fixed in commit 834ca0be).

**Problem**: Before the fix, attachment filenames with RFC 2047 encoding (e.g., `=?UTF-8?Q?...?=`) were not properly decoded, causing attachments to not be detected and saved.

**Known affected cases**:
- Eidskog Kommune thread (1e62b638-5240-4f85-98ff-1d0df88f2895): 1 email with 1 missing attachment
- Voss Kommune thread (e103a76f-49ff-4a02-bd80-087b9288ab0e): 3 emails with 7 missing attachments

**Potential scope**: Any email with RFC 2047 encoded attachment filenames across all threads.

## How It Works

The migration script:
1. Processes all threads that have IMAP folders
2. For each thread, opens its IMAP folder
3. For each email in IMAP:
   - Matches it to the database using `thread_id + id_old` (same logic as normal email processing)
   - Compares IMAP attachment count with database attachment count
   - If mismatch detected, inserts missing attachments using the FIXED RFC 2047 decoding logic
   - Detects duplicates to avoid inserting attachments that already exist
4. Tracks progress in `attachment_migration_state` table (resumable)
5. Uses database transactions for safety (can rollback on error)

## Usage

### 1. Dry Run (Recommended First Step)

Preview what would be changed without making actual changes:

```bash
docker exec offpost_organizer_1 php scripts/migrate-fix-rfc2047-attachments.php --dry-run
```

Expected output:
- Shows which threads and emails would be processed
- Shows which attachments would be inserted
- Summary statistics
- "⚠️ DRY RUN: No changes were made to the database"

### 2. Run Migration (Active Threads Only)

Process all active (non-archived) threads:

```bash
docker exec offpost_organizer_1 php scripts/migrate-fix-rfc2047-attachments.php
```

### 3. Include Archived Threads

Process both active and archived threads:

```bash
docker exec offpost_organizer_1 php scripts/migrate-fix-rfc2047-attachments.php --include-archived
```

### 4. Resume from Previous Run

Skip emails that were already processed successfully:

```bash
docker exec offpost_organizer_1 php scripts/migrate-fix-rfc2047-attachments.php --resume
```

### 5. Retry Failed Emails

Retry only emails that failed in a previous run:

```bash
docker exec offpost_organizer_1 php scripts/migrate-fix-rfc2047-attachments.php --retry-failed
```

## Options

- `--dry-run`: Preview changes without making them (no database modifications)
- `--include-archived`: Process archived threads (default: only active threads)
- `--resume`: Resume from previous run (skip already processed emails)
- `--retry-failed`: Retry only failed emails from previous run

## Output Example

```
=== RFC 2047 Attachment Migration ===
Mode: LIVE

Found 45 threads with IMAP folders

Thread: Klage på målrettet utestengelse av journalister fra postjournal
Folder: INBOX.964948054-eidskog-kommune - Klage paa maalrettet utestengelse
  Processing 2 emails...
  [1/2] 2026-01-22_214802 - OUT
    IMAP: 0 | DB: 0
  [2/2] 2026-01-23_112011 - IN
    IMAP: 1 | DB: 0
    + Inserted: Klage_på_målrettet_utestengelse.pdf (part 2, uuid)
    ✓ Inserted 1 attachment(s)

============================================================
=== Summary ===
============================================================
Threads processed: 45
Folders processed: 45
Emails checked: 287
Emails with fixes: 4
Emails skipped: 283
Attachments inserted: 8
Errors: 0
Duration: 3m 12s
============================================================
```

## Verification Steps

### 1. Check Migration State

See how many emails were processed:

```bash
docker exec offpost_postgres_1 psql -U offpost -d offpost -c "
SELECT status, COUNT(*)
FROM attachment_migration_state
GROUP BY status;
"
```

Expected output:
```
 status    | count
-----------+-------
 completed |     4
 skipped   |   283
```

### 2. Verify Attachments Were Saved

Check that the known affected emails now have attachments:

```bash
docker exec offpost_postgres_1 psql -U offpost -d offpost -c "
SELECT
    t.title,
    te.id_old,
    COUNT(tea.id) as attachment_count
FROM thread_emails te
JOIN threads t ON t.id = te.thread_id
LEFT JOIN thread_email_attachments tea ON tea.email_id = te.id
WHERE t.id IN (
    '1e62b638-5240-4f85-98ff-1d0df88f2895',
    'e103a76f-49ff-4a02-bd80-087b9288ab0e'
)
GROUP BY t.title, te.id_old
HAVING COUNT(tea.id) > 0
ORDER BY te.timestamp_received;
"
```

Expected: Should show 4 emails with 1, 1, 1, and 5 attachments respectively.

### 3. Run Diagnostic Script Again

Verify that IMAP and database attachment counts now match:

```bash
docker exec offpost_organizer_1 php scripts/explore-imap-for-issue-153.php
```

Expected: Should show "IMAP: X | DB: X" with matching counts (no more mismatches).

### 4. Check in Web UI

Navigate to the affected threads in the web interface and confirm:
- Attachments are visible
- Attachments are downloadable
- Filenames are properly decoded (Norwegian characters display correctly)

## Safety Features

The migration script includes multiple safety measures:

1. **Dry-run mode**: Test without making changes
2. **Transactions**: Each email processed in a transaction, rollback on error
3. **Duplicate detection**: Checks if attachment already exists before inserting
4. **State tracking**: Records what was processed in database table
5. **Resumable**: Can stop and restart without duplicating work
6. **Read-only IMAP**: Only fetches data, never deletes or moves emails
7. **Error isolation**: Email-level error handling, one failure doesn't stop the whole migration
8. **Idempotent**: Can run multiple times safely

## Cleanup (Optional)

After confirming the migration was successful, you can optionally drop the migration state table:

```sql
DROP TABLE attachment_migration_state;
```

**Note**: Keep the table if you want to maintain a record of what was migrated.

## Troubleshooting

### "No such mailbox" errors

This means the IMAP folder doesn't exist. This is normal for:
- Test threads in development environment
- Threads where emails were deleted from IMAP
- Threads where the folder was renamed or moved

The script will skip these threads and continue processing.

### "Could not find partNumber for attachment"

This indicates a mismatch between the structure analysis and attachment processing. This should not happen with the current implementation. If you see this, please investigate the specific email.

### "Error fetching attachment"

This could be due to:
- IMAP connection issues
- Attachment encoding not supported
- Corrupted email structure

The script will log the error and continue with the next attachment.

## Technical Details

### Email Matching Logic

The script matches IMAP emails to database emails using the same logic as normal email processing:

1. Generate filename from email date and direction: `YYYY-MM-DD_HHmmss - IN/OUT`
2. Look up in database by `thread_id + id_old`

This is more reliable than matching by Message-ID or Subject, which may be missing or have encoding issues.

### PartNumber Detection

The script analyzes the IMAP email structure to detect attachments and their partNumbers:

1. Iterate through all message parts
2. For each part, check if it has a filename (in dparameters or parameters)
3. Record the partNumber (1-indexed position in parts array)
4. Match attachments by index order (both arrays iterate parts in the same order)

This ensures the correct IMAP part is fetched for each attachment.

### Duplicate Detection

Before inserting an attachment, the script checks:

```sql
SELECT COUNT(*) FROM thread_email_attachments
WHERE email_id = ? AND filename = ?
```

If count > 0, the attachment is already in the database and is skipped.

## Files

- `organizer/src/scripts/migrate-fix-rfc2047-attachments.php` - Migration script
- `organizer/src/migrations/sql/032_add_attachment_migration_state.sql` - Migration state table
- `organizer/RFC2047-ATTACHMENT-MIGRATION.md` - This documentation
