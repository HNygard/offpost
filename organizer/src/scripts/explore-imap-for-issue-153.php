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
            id_old as imap_uid,
            email_type,
            timestamp_received
        FROM thread_emails
        WHERE thread_id = ?
        AND id_old IS NOT NULL
        ORDER BY timestamp_received
    ", [$threadId]);

    echo "Emails in database: " . count($emails) . "\n";

    if (empty($emails)) {
        echo "⚠️  No emails with UIDs found in database\n\n";
        continue;
    }

    // Show what UIDs we're looking for
    echo "\nDatabase UIDs:\n";
    foreach ($emails as $email) {
        echo "  - UID {$email['imap_uid']}: {$email['email_type']} (" .
             date('Y-m-d H:i', strtotime($email['timestamp_received'])) . ")\n";
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
            $search = imap_search($connection->getStream(), 'ALL', SE_UID);

            if ($search) {
                echo "  UIDs in folder: " . count($search) . " messages\n";
                echo "  UID range: " . min($search) . " - " . max($search) . "\n";

                // Check which of our UIDs exist
                $ourUids = array_column($emails, 'imap_uid');
                $found = array_intersect($ourUids, $search);
                $missing = array_diff($ourUids, $search);

                echo "  Found " . count($found) . " of our " . count($ourUids) . " UIDs\n";

                if (!empty($found)) {
                    echo "  ✓ Found UIDs: " . implode(', ', $found) . "\n";
                }

                if (!empty($missing)) {
                    echo "  ✗ Missing UIDs: " . implode(', ', $missing) . "\n";
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
        $folders = imap_list($connection->getStream(), '{' . $imapServer . '}', '*');

        if ($folders) {
            // Filter to show only folders related to this entity
            $entityId = $thread['entity_id'];
            foreach ($folders as $folder) {
                $folderName = str_replace('{' . $imapServer . '}', '', $folder);

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
