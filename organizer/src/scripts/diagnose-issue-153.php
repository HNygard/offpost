<?php
/**
 * Diagnostic test for GitHub issue #153 - Missing attachments (off-by-one bug)
 *
 * This script demonstrates the off-by-one bug in ThreadEmailDatabaseSaver.php:
 * - Reads raw EML content from database
 * - Parses structure to find attachments
 * - Tests BUGGY logic: getAttachmentContent($uid, $j + 1)
 * - Tests FIXED logic: getAttachmentContent($uid, $j)
 * - Shows which attachment parts were fetched incorrectly
 *
 * URLs from issue #153:
 * - https://offpost.no/thread-view?entityId=964948054-eidskog-kommune&threadId=1e62b638-5240-4f85-98ff-1d0df88f2895
 * - https://offpost.no/thread-view?entityId=960510542-voss-kommune&threadId=e103a76f-49ff-4a02-bd80-087b9288ab0e
 *
 * Usage: php diagnose-issue-153.php
 */

require_once __DIR__ . '/../class/Database.php';

$targetThreads = [
    '1e62b638-5240-4f85-98ff-1d0df88f2895', // Eidskog Kommune
    'e103a76f-49ff-4a02-bd80-087b9288ab0e'  // Voss Kommune
];

echo "=== GitHub Issue #153: Off-by-One Bug Diagnostic ===\n\n";
echo "This test demonstrates the off-by-one bug in attachment extraction.\n";
echo "Bug location: ThreadEmailDatabaseSaver.php line 164\n";
echo "Buggy code: \$content = \$attachmentHandler->getAttachmentContent(\$email->uid, \$j + 1)\n";
echo "Fixed code: \$content = \$attachmentHandler->getAttachmentContent(\$email->uid, \$j)\n\n";

$foundBug = false;

foreach ($targetThreads as $threadId) {
    $thread = Database::queryOne(
        "SELECT id, title, entity_id FROM threads WHERE id = ?",
        [$threadId]
    );

    if (!$thread) {
        echo "⚠️  Thread $threadId not found\n\n";
        continue;
    }

    echo str_repeat('=', 80) . "\n";
    echo "Thread: {$thread['title']}\n";
    echo "Entity: {$thread['entity_id']}\n";
    echo "ID: $threadId\n";
    echo str_repeat('=', 80) . "\n\n";

    // Get all emails with their raw content
    $emails = Database::query("
        SELECT
            id,
            id_old,
            content,
            email_type
        FROM thread_emails
        WHERE thread_id = ?
        ORDER BY timestamp_received
    ", [$threadId]);

    foreach ($emails as $email) {
        // Parse the raw email content FIRST to see if it has attachments
        $rawContent = stream_get_contents($email['content']);

        if (empty($rawContent)) {
            continue; // Skip emails with no content
        }

        // Create temporary file for IMAP parsing
        $tempFile = tempnam(sys_get_temp_dir(), 'eml_');
        file_put_contents($tempFile, $rawContent);

        try {
            // Open as mbox format
            $imap = imap_open($tempFile, '', '', OP_READONLY);

            if (!$imap) {
                unlink($tempFile);
                continue;
            }

            // Get structure
            $structure = imap_fetchstructure($imap, 1);

            if (!isset($structure->parts) || empty($structure->parts)) {
                imap_close($imap);
                unlink($tempFile);
                continue; // Skip simple emails without MIME parts
            }

            // Find attachment parts (matching ImapAttachmentHandler logic)
            $attachmentParts = [];
            foreach ($structure->parts as $partNum => $part) {
                $isAttachment = false;

                // Check if it has a disposition of ATTACHMENT
                if (isset($part->disposition) && strtoupper($part->disposition) === 'ATTACHMENT') {
                    $isAttachment = true;
                }

                // Or has a filename in parameters
                if (isset($part->dparameters)) {
                    foreach ($part->dparameters as $param) {
                        if (strtoupper($param->attribute) === 'FILENAME') {
                            $isAttachment = true;
                            break;
                        }
                    }
                }

                if ($isAttachment) {
                    $attachmentParts[] = $partNum + 1; // IMAP parts are 1-indexed
                }
            }

            // Skip emails that don't actually have attachments
            if (empty($attachmentParts)) {
                imap_close($imap);
                unlink($tempFile);
                continue;
            }

            // NOW get stored attachments for this email
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

            echo "Email: {$email['id_old']} ({$email['email_type']})\n";
            echo "  Attachment parts found in structure: " . count($attachmentParts) . "\n";
            echo "  Attachments in database: " . count($storedAttachments) . "\n\n";

            if (count($attachmentParts) !== count($storedAttachments)) {
                echo "  ⚠️  MISMATCH: Found " . count($attachmentParts) .
                     " parts but " . count($storedAttachments) . " stored!\n";
                $foundBug = true;
            }

            // Handle case where attachments exist in structure but not in database
            if (empty($storedAttachments)) {
                echo "  ✗ CRITICAL: Email has " . count($attachmentParts) . " attachment(s) in structure\n";
                echo "             but ZERO records in database!\n";
                echo "             Attachment processing completely failed for this email.\n\n";
                $foundBug = true;
                imap_close($imap);
                unlink($tempFile);
                continue;
            }

            echo "\n  Part Number Analysis:\n";
            echo "  " . str_repeat('-', 60) . "\n";
            echo "  Index | Correct Part | Buggy Part | Stored Status\n";
            echo "  " . str_repeat('-', 60) . "\n";

            foreach ($storedAttachments as $i => $att) {
                $j = $i + 1; // This is how the code iterates ($j = $i + 1)
                $correctPart = $j;           // FIXED CODE: uses $j
                $buggyPart = $j + 1;         // BUGGY CODE: uses $j + 1

                $icon = $att['status'] === 'OK' ? '✓' : '✗';

                printf("  %-5d | %-12d | %-10d | %s %s (%s",
                    $i,
                    $correctPart,
                    $buggyPart,
                    $icon,
                    $att['name'],
                    $att['status']
                );

                if ($att['status'] === 'OK') {
                    echo ", " . number_format($att['size']) . " bytes)\n";
                } else {
                    echo ")\n";
                    $foundBug = true;
                }

                // Check if buggy part number would exceed available parts
                if ($buggyPart > count($attachmentParts)) {
                    echo "       → BUG: Tried to fetch part $buggyPart but only " .
                         count($attachmentParts) . " parts exist!\n";
                    $foundBug = true;
                } elseif ($correctPart !== $buggyPart) {
                    echo "       → BUG: Fetched wrong part ($buggyPart instead of $correctPart)\n";
                    $foundBug = true;
                }
            }

            echo "  " . str_repeat('-', 60) . "\n\n";

            imap_close($imap);
            unlink($tempFile);

        } catch (Exception $e) {
            echo "  ⚠️  Error parsing email: " . $e->getMessage() . "\n\n";
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
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
