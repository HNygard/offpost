<?php
/**
 * Migration script to fix missing RFC 2047 encoded attachments
 *
 * This script processes all threads with IMAP folders and inserts missing attachments
 * that were not saved due to the RFC 2047 encoding bug (fixed in commit 834ca0be).
 *
 * The script:
 * 1. Processes all threads with IMAP folders
 * 2. For each thread, compares IMAP attachment counts with database counts
 * 3. Inserts missing attachments using the fixed decoding logic
 * 4. Tracks progress in attachment_migration_state table
 *
 * Usage:
 *   php scripts/migrate-fix-rfc2047-attachments.php [--dry-run] [--include-archived] [--resume] [--retry-failed]
 *
 * Options:
 *   --dry-run            Preview changes without making them
 *   --include-archived   Process archived threads (default: only active threads)
 *   --resume             Resume from previous run (skip already processed emails)
 *   --retry-failed       Retry only failed emails from previous run
 */

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadFolderManager.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapEmail.php';
require_once __DIR__ . '/../class/Imap/ImapAttachmentHandler.php';
require_once __DIR__ . '/../class/ThreadEmailDatabaseSaver.php';

use Imap\ImapConnection;
use Imap\ImapEmail;
use Imap\ImapAttachmentHandler;

class AttachmentMigration {
    private ImapConnection $connection;
    private ImapAttachmentHandler $attachmentHandler;
    private ThreadEmailDatabaseSaver $saver;
    private bool $dryRun = false;
    private bool $includeArchived = false;
    private bool $resume = false;
    private bool $retryFailed = false;

    // Statistics
    private int $threadsProcessed = 0;
    private int $foldersProcessed = 0;
    private int $emailsChecked = 0;
    private int $emailsWithFixes = 0;
    private int $emailsSkipped = 0;
    private int $attachmentsInserted = 0;
    private int $errors = 0;
    private int $startTime = 0;

    public function __construct(
        ImapConnection $connection,
        bool $dryRun = false,
        bool $includeArchived = false,
        bool $resume = false,
        bool $retryFailed = false
    ) {
        $this->connection = $connection;
        $this->attachmentHandler = new ImapAttachmentHandler($connection);

        // Create a minimal ThreadEmailDatabaseSaver instance
        // We only need the saveAttachmentToDatabase method
        $emailProcessor = new Imap\ImapEmailProcessor($connection);
        $this->saver = new ThreadEmailDatabaseSaver($connection, $emailProcessor, $this->attachmentHandler);

        $this->dryRun = $dryRun;
        $this->includeArchived = $includeArchived;
        $this->resume = $resume;
        $this->retryFailed = $retryFailed;
        $this->startTime = time();
    }

    public function run(): void {
        echo "=== RFC 2047 Attachment Migration ===\n";
        echo "Mode: " . ($this->dryRun ? "DRY RUN" : "LIVE") . "\n";
        if ($this->includeArchived) {
            echo "Including archived threads\n";
        }
        if ($this->resume) {
            echo "Resuming from previous run\n";
        }
        if ($this->retryFailed) {
            echo "Retrying failed emails only\n";
        }
        echo "\n";

        $this->createMigrationTable();

        // Get all threads with IMAP folders
        $threads = $this->getAllThreadsWithFolders();
        echo "Found " . count($threads) . " threads with IMAP folders\n\n";

        foreach ($threads as $thread) {
            $this->processThread($thread);
            $this->threadsProcessed++;
        }

        // Close IMAP connection if open
        try {
            $this->connection->closeConnection();
        } catch (Exception $e) {
            // Ignore close errors
        }

        $this->printSummary();
    }

    private function createMigrationTable(): void {
        try {
            // Check if table exists
            $exists = Database::queryValue(
                "SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_name = 'attachment_migration_state'
                )"
            );

            if (!$exists) {
                echo "Creating migration state table...\n";
                $migration = file_get_contents(__DIR__ . '/../migrations/sql/032_add_attachment_migration_state.sql');
                Database::getInstance()->exec($migration);
                echo "✓ Migration table created\n\n";
            }
        } catch (Exception $e) {
            echo "Error creating migration table: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    private function getAllThreadsWithFolders(): array {
        $query = "
            SELECT
                t.id,
                t.title,
                t.entity_id,
                t.my_email,
                t.archived
            FROM threads t
            WHERE EXISTS (
                SELECT 1 FROM thread_emails te
                WHERE te.thread_id = t.id
            )
        ";

        if (!$this->includeArchived) {
            $query .= " AND t.archived = false";
        }

        $query .= " ORDER BY t.created_at DESC";

        return Database::query($query);
    }

    private function buildFolderName(array $thread): string {
        // Use existing logic from ThreadFolderManager
        $threadObj = (object)[
            'title' => $thread['title'],
            'archived' => $thread['archived']
        ];
        return ThreadFolderManager::getThreadEmailFolder($thread['entity_id'], $threadObj);
    }

    private function processThread(array $thread): void {
        $folder = $this->buildFolderName($thread);

        echo "Thread: {$thread['title']}\n";
        echo "Folder: {$folder}\n";

        try {
            // Open IMAP connection to folder
            $this->connection->openConnection($folder);

            // Get all email UIDs from IMAP
            $uids = $this->connection->search('ALL', SE_UID);
            if (empty($uids)) {
                echo "  (no emails in IMAP folder)\n\n";
                return;
            }

            echo "  Processing " . count($uids) . " emails...\n";

            foreach ($uids as $i => $uid) {
                $this->processImapEmail($uid, $thread, $i + 1, count($uids));
            }

            $this->foldersProcessed++;

            // Close connection after processing folder
            $this->connection->closeConnection();

        } catch (Exception $e) {
            echo "  ✗ Error accessing folder: {$e->getMessage()}\n";
            $this->errors++;
        }

        echo "\n";
    }

    private function processImapEmail(
        int $uid,
        array $thread,
        int $current,
        int $total
    ): void {
        try {
            // Get IMAP email headers
            $msgno = imap_msgno($this->connection->getConnection(), $uid);
            $headers = imap_headerinfo($this->connection->getConnection(), $msgno);

            if (!$headers) {
                return; // Skip emails without headers
            }

            // Create ImapEmail object to generate filename
            $imapEmail = ImapEmail::fromImap($this->connection, $uid, $headers, '');

            // Generate filename using same logic as normal processing
            $filename = $imapEmail->generateEmailFilename($thread['my_email']);

            // Look up in database by thread_id + id_old
            $dbEmail = Database::queryOneOrNone(
                "SELECT id, id_old FROM thread_emails
                 WHERE thread_id = ? AND id_old = ?",
                [$thread['id'], $filename]
            );

            if (!$dbEmail) {
                // Email not in database (maybe deleted or not yet processed)
                // Skip silently
                return;
            }

            $this->emailsChecked++;

            // Check if already processed (resume mode)
            if ($this->resume || $this->retryFailed) {
                $state = Database::queryOneOrNone(
                    "SELECT status FROM attachment_migration_state WHERE email_id = ?",
                    [$dbEmail['id']]
                );

                if ($state) {
                    if ($this->retryFailed && $state['status'] !== 'failed') {
                        return; // Skip non-failed emails in retry mode
                    }
                    if ($this->resume && in_array($state['status'], ['completed', 'skipped'])) {
                        return; // Skip already processed emails in resume mode
                    }
                }
            }

            echo "  [{$current}/{$total}] {$filename}\n";

            if (!$this->dryRun) {
                Database::beginTransaction();
            }

            // Mark as processing
            $this->updateState($dbEmail['id'], 'processing', $filename);

            // Compare attachment counts
            $imapAttachments = $this->attachmentHandler->processAttachments($uid);
            $dbCount = $this->getDbAttachmentCount($dbEmail['id']);

            echo "    IMAP: " . count($imapAttachments) . " | DB: {$dbCount}\n";

            if (count($imapAttachments) > $dbCount) {
                // Insert missing attachments
                $inserted = $this->insertMissingAttachments(
                    $dbEmail['id'],
                    $uid,
                    $imapAttachments,
                    $filename
                );
                echo "    ✓ Inserted {$inserted} attachment(s)\n";

                $this->updateState($dbEmail['id'], 'completed', $filename, [
                    'attachments_before' => $dbCount,
                    'attachments_after' => $dbCount + $inserted
                ]);

                $this->emailsWithFixes++;
                $this->attachmentsInserted += $inserted;
            } else {
                // No missing attachments
                $this->updateState($dbEmail['id'], 'skipped', $filename, [
                    'attachments_before' => $dbCount,
                    'attachments_after' => $dbCount
                ]);
                $this->emailsSkipped++;
            }

            if (!$this->dryRun) {
                Database::commit();
            }

        } catch (Exception $e) {
            if (!$this->dryRun && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            echo "    ✗ Error: {$e->getMessage()}\n";
            if (isset($dbEmail)) {
                $this->updateState($dbEmail['id'], 'failed', $filename ?? 'unknown', [
                    'error_message' => $e->getMessage()
                ]);
            }
            $this->errors++;
        }
    }

    private function getDbAttachmentCount(string $emailId): int {
        return (int)Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_attachments WHERE email_id = ?",
            [$emailId]
        );
    }

    /**
     * Get attachments with their partNumbers by analyzing IMAP structure
     * This mirrors processAttachments but also tracks partNumbers
     */
    private function getAttachmentsWithPartNumbers(int $uid): array {
        $structure = $this->connection->getFetchstructure($uid, FT_UID);

        if (!isset($structure->parts) || count($structure->parts) == 0) {
            return [];
        }

        $attachmentsWithParts = [];
        for ($i = 0; $i < count($structure->parts); $i++) {
            $part = $structure->parts[$i];
            $partNumber = $i + 1;

            // Check if this part is an attachment (same logic as ImapAttachmentHandler)
            $isAttachment = false;
            $filename = '';

            // Check dparameters for filename
            if ($part->ifdparameters) {
                foreach ($part->dparameters as $param) {
                    if (strtolower($param->attribute) == 'filename' || strtolower($param->attribute) == 'filename*') {
                        $isAttachment = true;
                        $filename = $param->value;
                        break;
                    }
                }
            }

            // Check parameters for name
            if (!$isAttachment && $part->ifparameters) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) == 'name' || strtolower($param->attribute) == 'name*') {
                        $isAttachment = true;
                        $filename = $param->value;
                        break;
                    }
                }
            }

            if ($isAttachment && $filename) {
                $attachmentsWithParts[] = [
                    'partNumber' => $partNumber,
                    'filename_raw' => $filename
                ];
            }
        }

        return $attachmentsWithParts;
    }

    private function insertMissingAttachments(
        string $emailId,
        int $uid,
        array $imapAttachments,
        string $emailFilename
    ): int {
        $inserted = 0;

        // Get attachments with their partNumbers
        $attachmentsWithParts = $this->getAttachmentsWithPartNumbers($uid);

        // The imapAttachments array from processAttachments should be in the same order
        // as the attachmentsWithParts array (both iterate through parts in order)
        if (count($imapAttachments) != count($attachmentsWithParts)) {
            echo "      ! Warning: Attachment count mismatch (processAttachments: " .
                 count($imapAttachments) . ", structure analysis: " .
                 count($attachmentsWithParts) . ")\n";
        }

        foreach ($imapAttachments as $i => $attachment) {
            // Check if attachment already exists (by filename)
            if ($this->attachmentExists($emailId, $attachment->filename)) {
                echo "      - Skip duplicate: {$attachment->filename}\n";
                continue;
            }

            if ($this->dryRun) {
                echo "      + Would insert: {$attachment->filename}\n";
                $inserted++;
                continue;
            }

            // Get partNumber from the corresponding entry
            // Since both arrays are built by iterating through parts in order,
            // they should align by index
            if (!isset($attachmentsWithParts[$i])) {
                echo "      ! Could not find partNumber for attachment {$i}: {$attachment->filename}\n";
                continue;
            }

            $partNumber = $attachmentsWithParts[$i]['partNumber'];

            try {
                $content = $this->attachmentHandler->getAttachmentContent($uid, $partNumber);
            } catch (Exception $e) {
                echo "      ! Error fetching attachment {$attachment->filename} (part {$partNumber}): {$e->getMessage()}\n";
                continue;
            }

            // Generate location using same pattern as normal processing
            $j = $i + 1;
            $attachment->location = $emailFilename . ' - att ' . $j . '-' .
                                    md5($attachment->name) . '.' . $attachment->filetype;

            // Save to database using existing method
            $attachmentId = $this->saver->saveAttachmentToDatabase(
                $emailId,
                $attachment,
                $content
            );

            echo "      + Inserted: {$attachment->filename} (part {$partNumber}, {$attachmentId})\n";
            $inserted++;
        }

        return $inserted;
    }

    private function attachmentExists(string $emailId, string $filename): bool {
        $count = Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_attachments
             WHERE email_id = ? AND filename = ?",
            [$emailId, $filename]
        );
        return $count > 0;
    }

    private function updateState(
        string $emailId,
        string $status,
        string $filename,
        array $metadata = []
    ): void {
        if ($this->dryRun) {
            return; // Don't update state in dry-run mode
        }

        $messageId = null; // We don't have easy access to message-id here
        $folder = ''; // Could be extracted from context if needed

        // Check if record exists
        $exists = Database::queryValue(
            "SELECT COUNT(*) FROM attachment_migration_state WHERE email_id = ?",
            [$emailId]
        );

        if ($exists) {
            // Update existing record
            $query = "
                UPDATE attachment_migration_state
                SET status = ?,
                    processed_at = CURRENT_TIMESTAMP
            ";
            $params = [$status];

            if (isset($metadata['attachments_before'])) {
                $query .= ", attachments_before = ?";
                $params[] = $metadata['attachments_before'];
            }
            if (isset($metadata['attachments_after'])) {
                $query .= ", attachments_after = ?";
                $params[] = $metadata['attachments_after'];
            }
            if (isset($metadata['error_message'])) {
                $query .= ", error_message = ?";
                $params[] = $metadata['error_message'];
            }

            $query .= " WHERE email_id = ?";
            $params[] = $emailId;

            Database::execute($query, $params);
        } else {
            // Insert new record
            Database::execute(
                "INSERT INTO attachment_migration_state
                 (email_id, folder_name, status, attachments_before, attachments_after, error_message, processed_at)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
                [
                    $emailId,
                    $filename, // Use filename as folder_name for tracking
                    $status,
                    $metadata['attachments_before'] ?? 0,
                    $metadata['attachments_after'] ?? 0,
                    $metadata['error_message'] ?? null
                ]
            );
        }
    }

    private function printSummary(): void {
        $duration = time() - $this->startTime;
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        echo str_repeat('=', 60) . "\n";
        echo "=== Summary ===\n";
        echo str_repeat('=', 60) . "\n";
        echo "Threads processed: {$this->threadsProcessed}\n";
        echo "Folders processed: {$this->foldersProcessed}\n";
        echo "Emails checked: {$this->emailsChecked}\n";
        echo "Emails with fixes: {$this->emailsWithFixes}\n";
        echo "Emails skipped: {$this->emailsSkipped}\n";
        echo "Attachments inserted: {$this->attachmentsInserted}\n";
        echo "Errors: {$this->errors}\n";
        echo "Duration: {$minutes}m {$seconds}s\n";
        echo str_repeat('=', 60) . "\n";

        if ($this->dryRun) {
            echo "\n⚠️  DRY RUN: No changes were made to the database\n";
            echo "Run without --dry-run to apply changes\n";
        }
    }
}

// Parse command-line arguments
$dryRun = in_array('--dry-run', $argv);
$includeArchived = in_array('--include-archived', $argv);
$resume = in_array('--resume', $argv);
$retryFailed = in_array('--retry-failed', $argv);

// Suppress IMAP errors during shutdown
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignore IMAP shutdown errors
    if (strpos($errstr, 'PHP Request Shutdown') !== false ||
        strpos($errstr, 'No such mailbox') !== false) {
        return true;
    }
    return false;
});

// Get IMAP credentials
require __DIR__ . '/../username-password.php';

// Create IMAP connection
$connection = new ImapConnection($imapServer, $imap_username, $imap_password, false);

// Run migration
$migration = new AttachmentMigration($connection, $dryRun, $includeArchived, $resume, $retryFailed);
$migration->run();

// Restore error handler
restore_error_handler();
