<?php
/**
 * Explore IMAP to find where the emails for issue #153 actually are
 *
 * This script:
 * 1. Connects to IMAP
 * 2. Lists all folders
 * 3. For target threads, shows what the database expects vs what's in IMAP
 * 4. Searches for the UIDs across different folders
 *
 * Usage: php explore-imap-for-issue-153.php
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

                // Display results
                echo "  Matched " . count($matched) . " of " . count($emails) . " database emails\n\n";

                if (!empty($matched)) {
                    echo "  ✓ Matched emails:\n";
                    foreach ($matched as $m) {
                        echo "    - UID {$m['uid']} → {$m['filename']} via {$m['method']}\n";
                        echo "      Subject: {$m['subject']}\n";
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

echo "=== Exploration Complete ===\n";
