<?php

require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadDatabaseOperations.php';
require_once __DIR__ . '/../class/common.php';

// Only run in development environment
if (getenv('ENVIRONMENT') !== 'development') {
    exit(0);
}
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
$sourceEntityId = "0418-nord-odal-kommune";
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

    }
}
function createThreadTestData($threadsData, $threadData, $sourceEntityId) {
    global $threadOps;

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

        // Insert email into database with original ID stored in id_old
        $sql = "INSERT INTO thread_emails (thread_id, id_old, timestamp_received, datetime_received, ignore, email_type, status_type, status_text, content) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id";
        
        $emailId = Database::queryValue($sql, [
            $thread->id,
            $email->id,
            $email->timestamp_received,
            $email->datetime_received,
            $email->ignore ? 't' : 'f',
            $email->email_type,
            $email->status_type,
            $email->status_text,
            'content not imported'
        ]);

        // Process attachments if any
        if (isset($emailData['attachments'])) {
            foreach ($emailData['attachments'] as $attachmentData) {
                Database::execute(
                    "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, status_type, status_text)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $emailId,
                        $attachmentData['name'],
                        $attachmentData['filename'],
                        $attachmentData['filetype'],
                        $attachmentData['location'],
                        $attachmentData['status_type'],
                        $attachmentData['status_text']
                    ]
                );
            }
        }
    }

    // Grant access to dev-user-id
    $thread->addUser('dev-user-id', true);

    return $thread->id; // Return UUID for mapping
}

echo "Development data created successfully.\n";
