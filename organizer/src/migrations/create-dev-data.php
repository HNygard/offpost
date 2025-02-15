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
$sourceThreadsFile = THREADS_DIR . "/threads-{$sourceEntityId}.json";
$sourceThreadsDir = THREADS_DIR . "/threads/{$sourceEntityId}";

// Create destination directories if they don't exist
if (!file_exists(THREADS_DIR)) {
    mkdir(THREADS_DIR, 0777, true);
}

// Load thread data
$threadsData = json_decode(file_get_contents($sourceThreadsFile), true);
$threadOps = new ThreadDatabaseOperations();

// Process each thread
foreach ($threadsData['threads'] as $threadData) {
    try {
        createThreadTestData($threadsData, $threadData, $sourceEntityId);
    }
    catch (Exception $e) {
        echo "Error creating thread: " . $e->getMessage() . "\n";
    }
}
function createThreadTestData($threadsData, $threadData, $sourceEntityId) {
    global $threadOps;

    // Create thread in database
    $thread = new Thread();
    $thread->id = $threadData['id'];
    $thread->title = $threadData['title'];
    $thread->my_name = $threadData['my_name'];
    $thread->my_email = $threadData['my_email'];
    $thread->labels = $threadData['labels'];
    $thread->sent = $threadData['sent'];
    $thread->archived = $threadData['archived'];
    $thread->public = $threadData['public'];
    $thread->sentComment = $threadData['sentComment'];

    $thread = $threadOps->createThread($sourceEntityId, $threadsData['title_prefix'], $thread);

    // Process emails
    foreach ($threadData['emails'] as $emailData) {
        $email = new ThreadEmail();
        $email->timestamp_received = $emailData['timestamp_received'];
        $email->datetime_received = new DateTime($emailData['datetime_received']);
        $email->ignore = $emailData['ignore'];
        $email->email_type = $emailData['email_type'];
        $email->status_type = $emailData['status_type'];
        $email->status_text = $emailData['status_text'];
        $email->id = $emailData['id'];

        // Insert email into database
        $emailId = generateUuid();
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, timestamp_received, datetime_received, ignore, email_type, status_type, status_text) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $emailId,
                $thread->id,
                $email->timestamp_received,
                $email->datetime_received->format('Y-m-d H:i:s'),
                $email->ignore ? 't' : 'f',
                $email->email_type,
                $email->status_type,
                $email->status_text
            ]
        );

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
}

// Copy thread files
$destEntityDir = joinPaths(THREADS_DIR, $sourceEntityId);
if (!file_exists($destEntityDir)) {
    mkdir($destEntityDir, 0777, true);
}

// Copy threads JSON file
copy($sourceThreadsFile, joinPaths(THREADS_DIR, "threads-{$sourceEntityId}.json"));

// Copy all thread files
foreach ($threadsData['threads'] as $threadData) {
    $threadId = $threadData['id'];
    $sourceThreadDir = joinPaths($sourceThreadsDir, $threadId);
    $destThreadDir = joinPaths($destEntityDir, $threadId);
    
    if (!file_exists($destThreadDir)) {
        mkdir($destThreadDir, 0777, true);
    }

    // Copy all files in thread directory
    foreach (glob("{$sourceThreadDir}/*") as $file) {
        copy($file, joinPaths($destThreadDir, basename($file)));
    }
}

echo "Development data created successfully.\n";
