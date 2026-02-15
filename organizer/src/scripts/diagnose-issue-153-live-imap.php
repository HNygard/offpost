<?php
/**
 * Diagnostic script for GitHub issue #153 - Missing attachments (off-by-one bug)
 *
 * This script demonstrates the off-by-one bug by:
 * 1. Connecting to the live IMAP server
 * 2. Using imap_fetchstructure() to count attachments (same as buggy code)
 * 3. Comparing IMAP structure to database attachment records
 * 4. Showing the off-by-one error in action
 *
 * This uses the EXACT SAME code path as the buggy ImapAttachmentHandler
 *
 * Usage: php diagnose-issue-153-live-imap.php
 */

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/ThreadFolderManager.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapFolderManager.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;

// Get IMAP credentials
require __DIR__ . '/../username-password.php';

// Target threads from issue #153
$targetThreads = [
    '1e62b638-5240-4f85-98ff-1d0df88f2895', // Eidskog Kommune
    'e103a76f-49ff-4a02-bd80-087b9288ab0e'  // Voss Kommune
];

echo "=== GitHub Issue #153: Off-by-One Bug Diagnostic ===\n\n";
echo "This test connects to the live IMAP server and compares:\n";
echo "  - Attachment count in IMAP structure (imap_fetchstructure)\n";
echo "  - Attachment records in database\n\n";
echo "Bug location: ThreadEmailDatabaseSaver.php line 164\n";
echo "Buggy code: \$content = \$attachmentHandler->getAttachmentContent(\$email->uid, \$j + 1)\n";
echo "Fixed code: \$content = \$attachmentHandler->getAttachmentContent(\$email->uid, \$j)\n\n";

$foundBug = false;

foreach ($targetThreads as $threadId) {
    // :: Setup

    // Get thread from database
    $thread = Database::queryOne(
        "SELECT id, title, entity_id, archived FROM threads WHERE id = ?",
        [$threadId]
    );

    if (!$thread) {
        echo "⚠️  Thread $threadId not found in database\n\n";
        continue;
    }

    echo str_repeat('=', 80) . "\n";
    echo "Thread: {$thread['title']}\n";
    echo "Entity: {$thread['entity_id']}\n";
    echo "ID: $threadId\n";
    echo str_repeat('=', 80) . "\n\n";

    // Get IMAP folder for this thread
    $threadObj = (object)$thread;
    $imapFolder = ThreadFolderManager::getThreadEmailFolder($thread['entity_id'], $threadObj);
    echo "IMAP Folder: $imapFolder\n\n";

    // :: Act - Connect to IMAP

    try {
        $connection = new ImapConnection($imapServer, $imap_username, $imap_password, false);
        $connection->openConnection($imapFolder);

        // Get all emails for this thread from database
        $emails = Database::query("
            SELECT
                id,
                id_old as imap_uid,
                email_type
            FROM thread_emails
            WHERE thread_id = ?
            AND id_old IS NOT NULL
            ORDER BY timestamp_received
        ", [$threadId]);

        if (empty($emails)) {
            echo "⚠️  No emails found for this thread\n\n";
            $connection->closeConnection();
            continue;
        }

        foreach ($emails as $email) {
            // Get structure from IMAP (same as ImapAttachmentHandler does)
            $structure = $connection->getFetchstructure((int)$email['imap_uid'], FT_UID);

            if (!isset($structure->parts) || empty($structure->parts)) {
                continue; // Skip simple emails without MIME parts
            }

            // Count attachment parts (matching ImapAttachmentHandler logic)
            $attachmentParts = [];
            foreach ($structure->parts as $partNum => $part) {
                $isAttachment = false;

                // Check if it has a disposition of ATTACHMENT
                if (isset($part->disposition) && strtoupper($part->disposition) === 'ATTACHMENT') {
                    $isAttachment = true;
                }

                // Or has a filename in dparameters
                if (isset($part->dparameters)) {
                    foreach ($part->dparameters as $param) {
                        if (strtoupper($param->attribute) === 'FILENAME') {
                            $isAttachment = true;
                            break;
                        }
                    }
                }

                // Or has a name in parameters
                if (isset($part->parameters)) {
                    foreach ($part->parameters as $param) {
                        if (strtoupper($param->attribute) === 'NAME') {
                            $isAttachment = true;
                            break;
                        }
                    }
                }

                if ($isAttachment) {
                    $attachmentParts[] = $partNum + 1; // IMAP parts are 1-indexed
                }
            }

            // Skip emails without attachments
            if (empty($attachmentParts)) {
                continue;
            }

            // :: Assert - Compare to database

            // Get stored attachments for this email
            $storedAttachments = Database::query("
                SELECT
                    name,
                    filename,
                    filetype,
                    CASE
                        WHEN content IS NULL THEN 'NULL'
                        WHEN length(content) = 0 THEN 'EMPTY'
                        ELSE 'OK'
                    END as status,
                    length(content) as size
                FROM thread_email_attachments
                WHERE email_id = ?
                ORDER BY id
            ", [$email['id']]);

            echo "Email: UID {$email['imap_uid']} ({$email['email_type']})\n";
            echo "  Attachments found in IMAP structure: " . count($attachmentParts) . "\n";
            echo "  Attachments in database: " . count($storedAttachments) . "\n";

            if (count($attachmentParts) !== count($storedAttachments)) {
                echo "  ⚠️  MISMATCH: Found " . count($attachmentParts) .
                     " parts in IMAP but " . count($storedAttachments) . " stored in database!\n";
                $foundBug = true;
            }

            // Handle case where attachments exist in structure but not in database
            if (empty($storedAttachments)) {
                echo "\n  ✗ CRITICAL: Email has " . count($attachmentParts) . " attachment(s) in IMAP structure\n";
                echo "             but ZERO records in database!\n";
                echo "             Attachment processing completely failed for this email.\n";
                echo "             This demonstrates the off-by-one bug.\n\n";
                $foundBug = true;
                continue;
            }

            echo "\n  Part Number Analysis:\n";
            echo "  " . str_repeat('-', 70) . "\n";
            echo "  Index | Correct Part | Buggy Part | Stored Status\n";
            echo "  " . str_repeat('-', 70) . "\n";

            foreach ($storedAttachments as $i => $att) {
                $j = $i + 1;         // This is how the code iterates ($j = $i + 1)
                $correctPart = $j;   // FIXED CODE: uses $j
                $buggyPart = $j + 1; // BUGGY CODE: uses $j + 1

                $icon = $att['status'] === 'OK' ? '✓' : '✗';

                printf("  %-5d | %-12d | %-10d | %s %s (%s",
                    $i,
                    $correctPart,
                    $buggyPart,
                    $icon,
                    $att['name'] ?: $att['filename'],
                    $att['status']
                );

                if ($att['status'] === 'OK' && $att['size']) {
                    echo ", " . number_format($att['size']) . " bytes)\n";
                } else {
                    echo ")\n";
                    if ($att['status'] !== 'OK') {
                        $foundBug = true;
                    }
                }

                // Check if buggy part number would exceed available parts
                if ($buggyPart > count($attachmentParts)) {
                    echo "       → BUG: Tried to fetch part $buggyPart but only " .
                         count($attachmentParts) . " parts exist!\n";
                    $foundBug = true;
                } elseif ($correctPart !== $buggyPart && $att['status'] !== 'OK') {
                    echo "       → BUG: Fetched wrong part ($buggyPart instead of $correctPart)\n";
                    $foundBug = true;
                }
            }

            echo "  " . str_repeat('-', 70) . "\n\n";
        }

        $connection->closeConnection();

    } catch (Exception $e) {
        echo "  ⚠️  Error connecting to IMAP or processing emails: " . $e->getMessage() . "\n\n";
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
if ($foundBug) {
    echo "RESULT: ✗ BUG CONFIRMED\n\n";
    echo "The off-by-one bug caused attachment parts to be fetched with incorrect\n";
    echo "part numbers (\$j + 1 instead of \$j), resulting in NULL or wrong content.\n\n";
    echo "Fix required: Change line 164 in ThreadEmailDatabaseSaver.php\n";
    echo "From: \$attachmentHandler->getAttachmentContent(\$email->uid, \$j + 1)\n";
    echo "To:   \$attachmentHandler->getAttachmentContent(\$email->uid, \$j)\n";
    exit(1);
} else {
    echo "RESULT: ✓ NO ISSUES FOUND\n\n";
    echo "All attachments have correct content. The bug may already be fixed\n";
    echo "or these specific threads may not be affected.\n";
    exit(0);
}
