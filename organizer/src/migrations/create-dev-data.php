<?php

require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadDatabaseOperations.php';
require_once __DIR__ . '/../class/common.php';

require_once __DIR__ . '/../class/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/../class/Imap/ImapAttachmentHandler.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

// Only run in development environment
if (getenv('ENVIRONMENT') !== 'development') {
    exit(0);
}

// Flag to track if any errors occurred
$hasErrors = false;

function generateUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
echo "Creating development data...\n";

// Source and destination paths
$sourceEntityId = "964950768-nord-odal-kommune";
$sourceThreadsFile = "/organizer-data/threads/threads-{$sourceEntityId}.json";
$sourceThreadsDir = "/organizer-data/threads/{$sourceEntityId}";

// Create destination directories if they don't exist
if (!file_exists(THREADS_DIR)) {
    mkdir(THREADS_DIR, 0777, true);
}

// Delete 'test-entity-development' threads
if (file_exists('/organizer-data/threads/test-entity-development')) {
    echo "- Removing test directory in threads.\n";
    exec('rm -rf /organizer-data/threads/test-entity-development');
}

// Load thread data
$threadsData = json_decode(file_get_contents($sourceThreadsFile), true);
$threadOps = new ThreadDatabaseOperations();

// Process each thread
foreach ($threadsData['threads'] as $threadData) {
    try {
        $uuid = createThreadTestData($threadsData, $threadData, $sourceEntityId);
    }
    catch (Exception $e) {
        echo "Error creating thread: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        echo "Error creating thread: " . $e->getMessage() . "\n";
        $hasErrors = true;
    }
}
function createThreadTestData($threadsData, $threadData, $sourceEntityId) {
    global $threadOps, $sourceThreadsDir;
    $connection = new ImapConnection('', '', '', true);
    $emailProcessor = new ImapEmailProcessor($connection);
    $attachmentHandler = new ImapAttachmentHandler($connection);
    $saver = new ThreadEmailDatabaseSaver($connection, $emailProcessor, $attachmentHandler);

    // Create thread in database
    $thread = new Thread();
    $thread->id_old = $threadData['id']; // Store original ID as id_old
    $thread->title = $threadData['title'];
    $thread->my_name = $threadData['my_name'];
    $thread->my_email = $threadData['my_email'];
    $thread->labels = $threadData['labels'];
    $thread->sent = $threadData['sent'];
    $thread->archived = $threadData['archived'];
    $thread->public = $threadData['public'];
    $thread->sentComment = $threadData['sentComment'];

    $thread = $threadOps->createThread($sourceEntityId, $thread, 'dev-user-id');

    // Process emails
    foreach ($threadData['emails'] as $emailData) {
        $email = new ThreadEmail();
        $email->timestamp_received = date('Y-m-d H:i:s', $emailData['timestamp_received']);
        $email->datetime_received = $emailData['datetime_received'];
        $email->ignore = $emailData['ignore'];
        $email->email_type = $emailData['email_type'];
        $email->status_type = $emailData['status_type'];
        $email->status_text = $emailData['status_text'];
        $email->id = $emailData['id'];

        /*$email_content = file_get_contents($sourceThreadsDir . "/{$thread->id_old}/{$email->id}.eml");
        if (!$email_content) {
            throw new Exception("Email content not found for thread {$thread->id_old} email {$email->id}");
        }
        $email_content = mb_convert_encoding($email_content, 'UTF-8', 'UTF-8');
        */

        // Insert email into database with original ID stored in id_old
        $sql = "INSERT INTO thread_emails (
                thread_id, id_old, timestamp_received, 
                datetime_received, ignore, email_type, 
                status_type, status_text, content) 
            VALUES (
                :thread_id, :id_old, :timestamp_received,
                :datetime_received, :ignore, :email_type,
                :status_type, :status_text, :content
            ) RETURNING id";
        
        try {
            $emailId = Database::queryValueWithBinaryParam($sql, [
                ':thread_id' => $thread->id,
                ':id_old' => $email->id,
                ':timestamp_received' => $email->timestamp_received,
                ':datetime_received' => $email->datetime_received,
                ':ignore' => $email->ignore ? 't' : 'f',
                ':email_type' => $email->email_type,
                ':status_type' => $email->status_type,
                ':status_text' => $email->status_text,
            ],
            [
                ':content' => file_get_contents($sourceThreadsDir . "/{$thread->id_old}/{$email->id}.eml") ?: ''
            ]
        );
        }
        catch (Exception $e) {
            //throw new Exception("Error inserting email: " . "/{$thread->id_old}/{$email->id}.eml", 0, $e);
            throw $e;
        }

        // Process attachments if any
        if (isset($emailData['attachments'])) {
            foreach ($emailData['attachments'] as $attachmentData) {
                $att_content = file_get_contents($sourceThreadsDir . "/{$thread->id_old}/{$attachmentData['location']}");
                if (!$att_content) {
                    throw new Exception("Attachment content not found for thread {$thread->id_old} email {$email->id} attachment {$attachmentData['location']}");
                }
                $att = new stdClass();
                $att->name = $attachmentData['name'];
                $att->filename = $attachmentData['filename'];
                $att->filetype = $attachmentData['filetype'];
                $att->location = $attachmentData['location'];

                $saver->saveAttachmentToDatabase($emailId, $att, $att_content);
            }
        }
    }

    // Grant access to dev-user-id
    $thread->addUser('dev-user-id', true);

    return $thread->id; // Return UUID for mapping
}

if ($hasErrors) {
    echo "Errors occurred while creating development data. Check the logs above.\n";
    exit(1);
} else {
    echo "Development data created successfully.\n";
    exit(0);
}
