<?php
/**
 * Explore IMAP to validate attachment fix for issue #153
 *
 * This script:
 * 1. Connects to IMAP
 * 2. Lists all folders
 * 3. For target threads, shows what the database expects vs what's in IMAP
 * 4. Searches for the UIDs across different folders
 * 5. Validates attachments by comparing:
 *    - Old method: What would $i + 2 fetch from IMAP?
 *    - New method: What would stored partNumber fetch?
 *    - Database: How many attachments are stored?
 *
 * This proves whether the partNumber fix would solve the missing attachment issue.
 *
 * Usage: docker exec -it offpost-organizer-1 php scripts/explore-imap-for-issue-153.php
 */

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/ThreadFolderManager.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapFolderManager.php';
require_once __DIR__ . '/../class/Imap/ImapAttachmentHandler.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;

// Get IMAP credentials
require __DIR__ . '/../username-password.php';

/**
 * Decode attachment filename using the same logic as ImapAttachmentHandler
 */
function decodeAttachmentFilename(ImapConnection $connection, string $filename): string {
    $handler = new Imap\ImapAttachmentHandler($connection);
    $reflection = new \ReflectionClass($handler);
    $method = $reflection->getMethod('decodeUtf8String2');
    $method->setAccessible(true);
    return $method->invoke($handler, $filename);
}

/**
 * Detect attachments from IMAP structure
 * Uses same logic as ImapAttachmentHandler
 */
function detectImapAttachments($connection, int $uid): array {
    $structure = imap_fetchstructure($connection, $uid, FT_UID);

    if (!isset($structure->parts) || count($structure->parts) == 0) {
        return [];
    }

    $attachments = [];
    for ($i = 0; $i < count($structure->parts); $i++) {
        $part = $structure->parts[$i];
        $partNumber = $i + 1;

        $isAttachment = false;
        $filename = '';

        // Check dparameters for filename
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) == 'filename') {
                    $isAttachment = true;
                    $filename = $param->value;
                    break;
                }
            }
        }

        // Check parameters for name
        if (!$isAttachment && $part->ifparameters) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) == 'name') {
                    $isAttachment = true;
                    $filename = $param->value;
                    break;
                }
            }
        }

        if ($isAttachment) {
            $attachments[] = [
                'partNumber' => $partNumber,
                'filename_raw' => $filename,  // Raw encoded filename
                'filename' => $filename,       // Will be decoded later
                'type' => $part->type,
                'subtype' => $part->subtype ?? ''
            ];
        }
    }

    return $attachments;
}

// Target threads from issue #153
$targetThreads = [
    '1e62b638-5240-4f85-98ff-1d0df88f2895', // Eidskog Kommune
    'e103a76f-49ff-4a02-bd80-087b9288ab0e'  // Voss Kommune
];

echo "=== IMAP Exploration for Issue #153 ===\n\n";

foreach ($targetThreads as $threadId) {
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
    echo "Archived: " . ($thread['archived'] ? 'YES' : 'NO') . "\n";
    echo str_repeat('=', 80) . "\n\n";

    // Get expected IMAP folder
    $threadObj = (object)$thread;
    $expectedFolder = ThreadFolderManager::getThreadEmailFolder($thread['entity_id'], $threadObj);
    echo "Expected IMAP Folder: $expectedFolder\n";

    // Get emails from database
    $emails = Database::query("
        SELECT
            id,
            id_old as filename,
            email_type,
            timestamp_received,
            imap_headers->>'message-id' as message_id,
            imap_headers->>'subject' as subject,
            imap_headers->>'date' as email_date
        FROM thread_emails
        WHERE thread_id = ?
        AND imap_headers IS NOT NULL
        ORDER BY timestamp_received
    ", [$threadId]);

    // Get attachment info for all emails in this thread
    $emailIds = array_column($emails, 'id');
    $attachmentInfo = [];
    if (!empty($emailIds)) {
        $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
        $attachmentInfo = Database::query("
            SELECT
                email_id,
                name,
                filename,
                filetype
            FROM thread_email_attachments
            WHERE email_id IN ($placeholders)
            ORDER BY email_id, created_at
        ", $emailIds);
    }

    echo "Emails in database: " . count($emails) . "\n";

    if (empty($emails)) {
        echo "⚠️  No emails with headers found in database\n\n";
        continue;
    }

    // Show what emails we're looking for
    echo "\nDatabase emails:\n";
    foreach ($emails as $email) {
        echo "  - {$email['filename']}: {$email['email_type']}\n";
        echo "    Subject: {$email['subject']}\n";
        echo "    Message-ID: " . ($email['message_id'] ?: 'NONE') . "\n";
    }
    echo "\n";

    // Now connect to IMAP and explore
    try {
        $connection = new ImapConnection($imapServer, $imap_username, $imap_password, false);

        // First, try the expected folder
        echo "Checking expected folder: $expectedFolder\n";
        try {
            $connection->openConnection($expectedFolder);

            // Get all UIDs in this folder
            $search = $connection->search('ALL', SE_UID);

            if ($search) {
                echo "  UIDs in folder: " . count($search) . " messages\n";
                echo "  UID range: " . min($search) . " - " . max($search) . "\n";

                // Match emails by Message-ID
                $matched = [];
                $unmatched = [];

                foreach ($search as $uid) {
                    // Get headers for this IMAP message
                    $msgno = imap_msgno($connection->getConnection(), $uid);
                    $headers = imap_headerinfo($connection->getConnection(), $msgno);

                    // Try to find matching database email
                    $found = false;
                    foreach ($emails as $dbEmail) {
                        // Primary: Match by Message-ID
                        if ($dbEmail['message_id'] &&
                            isset($headers->message_id) &&
                            trim($dbEmail['message_id']) === trim($headers->message_id)) {
                            $matched[] = [
                                'uid' => $uid,
                                'filename' => $dbEmail['filename'],
                                'email_id' => $dbEmail['id'],
                                'method' => 'Message-ID',
                                'subject' => $dbEmail['subject']
                            ];
                            $found = true;
                            break;
                        }

                        // Secondary: Match by Subject + Date
                        if ($dbEmail['subject'] &&
                            isset($headers->subject) &&
                            trim($dbEmail['subject']) === trim($headers->subject) &&
                            isset($headers->date) &&
                            strtotime($dbEmail['email_date']) === strtotime($headers->date)) {
                            $matched[] = [
                                'uid' => $uid,
                                'filename' => $dbEmail['filename'],
                                'email_id' => $dbEmail['id'],
                                'method' => 'Subject+Date',
                                'subject' => $dbEmail['subject']
                            ];
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $unmatched[] = [
                            'uid' => $uid,
                            'subject' => $headers->subject ?? 'UNKNOWN'
                        ];
                    }
                }

                // Detect IMAP attachments for matched emails
                foreach ($matched as &$match) {
                    $match['imap_attachments'] = detectImapAttachments($connection->getConnection(), $match['uid']);

                    // Decode filenames using the new logic
                    foreach ($match['imap_attachments'] as &$att) {
                        $att['filename'] = decodeAttachmentFilename($connection, $att['filename_raw']);
                    }
                    unset($att);

                    $match['structure'] = imap_fetchstructure($connection->getConnection(), $match['uid'], FT_UID);
                }
                unset($match);

                // Display results
                echo "  Matched " . count($matched) . " of " . count($emails) . " database emails\n\n";

                if (!empty($matched)) {
                    echo "  ✓ Matched emails:\n";
                    foreach ($matched as $m) {
                        echo "    - UID {$m['uid']} → {$m['filename']} - {$m['method']}\n";
                        echo "      Subject: {$m['subject']}\n";

                        // Attachment Analysis
                        echo "\n      Attachment Analysis:\n";

                        // Get database attachment count
                        $dbAttachments = array_filter($attachmentInfo, fn($a) => $a['email_id'] === $m['email_id']);
                        $dbAttachmentCount = count($dbAttachments);

                        echo "        Database: {$dbAttachmentCount} attachment(s)\n";
                        echo "        IMAP: " . count($m['imap_attachments']) . " attachment(s)\n";

                        if (!empty($dbAttachments)) {
                            echo "\n        Database Attachments:\n";
                            foreach ($dbAttachments as $dbAtt) {
                                echo "          - {$dbAtt['filename']} ({$dbAtt['filetype']})\n";
                            }
                        }

                        if (count($m['imap_attachments']) > 0) {
                            echo "\n        IMAP Attachment Parts:\n";
                            foreach ($m['imap_attachments'] as $att) {
                                echo "          - Part {$att['partNumber']}:\n";
                                echo "            Raw:     {$att['filename_raw']}\n";
                                echo "            Decoded: {$att['filename']}\n";
                            }

                            // Simulate old method ($i + 2)
                            echo "\n        Old Method (\$i + 2) would fetch:\n";
                            for ($i = 0; $i < $dbAttachmentCount; $i++) {
                                $oldPartNumber = $i + 2;
                                echo "          - Attachment index $i → Part $oldPartNumber";

                                // Find what's actually at that part
                                $actualPart = null;
                                foreach ($m['imap_attachments'] as $att) {
                                    if ($att['partNumber'] == $oldPartNumber) {
                                        $actualPart = $att;
                                        break;
                                    }
                                }

                                if ($actualPart) {
                                    echo " ✓ (correct: {$actualPart['filename']})\n";
                                } else {
                                    // Check if it's a non-attachment part
                                    if (isset($m['structure']->parts[$oldPartNumber - 1])) {
                                        $part = $m['structure']->parts[$oldPartNumber - 1];
                                        $typeName = $part->type == 0 ? 'text' : ($part->type == 5 ? 'image' : 'other');
                                        $subtype = $part->subtype ?? 'unknown';
                                        echo " ✗ (WRONG: fetches $typeName/$subtype part, not attachment!)\n";
                                    } else {
                                        echo " ✗ (WRONG: part doesn't exist!)\n";
                                    }
                                }
                            }

                            // Simulate new method (stored partNumber)
                            echo "\n        New Method (partNumber) would fetch:\n";
                            foreach ($m['imap_attachments'] as $i => $att) {
                                echo "          - Attachment index $i → Part {$att['partNumber']} ✓ ({$att['filename']})\n";
                            }

                            // Highlight if mismatch
                            if (count($m['imap_attachments']) != $dbAttachmentCount) {
                                echo "\n        ⚠️  MISMATCH: IMAP has " . count($m['imap_attachments']) .
                                     " but database has {$dbAttachmentCount}\n";
                            }
                        } elseif ($dbAttachmentCount > 0) {
                            echo "\n        ⚠️  WARNING: Database has {$dbAttachmentCount} attachment(s) but IMAP has none!\n";
                        }

                        echo "\n";
                    }
                    echo "\n";
                }

                if (!empty($unmatched)) {
                    echo "  ✗ Unmatched IMAP messages:\n";
                    foreach ($unmatched as $u) {
                        echo "    - UID {$u['uid']}: {$u['subject']}\n";
                    }
                    echo "\n";
                }

                // Check for database emails not found in IMAP
                $matchedFilenames = array_column($matched, 'filename');
                $dbFilenames = array_column($emails, 'filename');
                $missingFromImap = array_diff($dbFilenames, $matchedFilenames);

                if (!empty($missingFromImap)) {
                    echo "  ⚠️  Database emails not found in IMAP:\n";
                    foreach ($missingFromImap as $filename) {
                        $dbEmail = array_filter($emails, fn($e) => $e['filename'] === $filename);
                        $dbEmail = reset($dbEmail);
                        echo "    - {$filename}\n";
                        echo "      Subject: {$dbEmail['subject']}\n";
                    }
                }
            } else {
                echo "  ⚠️  No messages found in folder\n";
            }

            $connection->closeConnection();
        } catch (Exception $e) {
            echo "  ✗ Error accessing folder: " . $e->getMessage() . "\n";
        }

        echo "\n";

        // List all folders to see what else exists
        echo "All IMAP folders:\n";
        $connection->openConnection('INBOX');
        $folders = $connection->listFolders();

        if ($folders) {
            // Filter to show only folders related to this entity
            $entityId = $thread['entity_id'];
            foreach ($folders as $folderName) {
                // Show all folders that might be related
                if (stripos($folderName, $entityId) !== false ||
                    stripos($folderName, 'eidskog') !== false ||
                    stripos($folderName, 'voss') !== false) {
                    echo "  📁 $folderName\n";
                }
            }
        }

        $connection->closeConnection();

    } catch (Exception $e) {
        echo "⚠️  Error connecting to IMAP: " . $e->getMessage() . "\n";
    }

    echo "\n\n";
}

// Generate summary report
echo str_repeat('=', 80) . "\n";
echo "=== Attachment Summary ===\n";
echo str_repeat('=', 80) . "\n\n";

// We need to track statistics across all threads
// For simplicity, we'll note that detailed per-thread analysis is shown above
echo "Detailed attachment analysis shown above for each thread.\n\n";
echo "Key findings to look for:\n";
echo "  - Emails where old method (\$i + 2) would fail (marked with ✗)\n";
echo "  - Emails where new method (partNumber) works correctly (marked with ✓)\n";
echo "  - Mismatches between database and IMAP attachment counts\n\n";

echo "If old method shows ✗ for any emails:\n";
echo "  ✅ The new partNumber fix WOULD solve the issue!\n\n";

echo "If old method shows ✓ for all emails:\n";
echo "  ⚠️  Old method works for these emails (issue might be elsewhere)\n\n";

echo "=== Exploration Complete ===\n";
