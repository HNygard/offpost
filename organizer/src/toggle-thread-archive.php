<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadHistory.php';

// Require authentication
requireAuth();

$entityId = $_GET['entityId'];
$threadId = $_GET['threadId'];
$archive = isset($_GET['archive']) ? $_GET['archive'] === '1' : false;

$storageManager = ThreadStorageManager::getInstance();
$threads = $storageManager->getThreadsForEntity($entityId);

$thread = null;
foreach ($threads->threads as $thread1) {
    if ($thread1->id == $threadId) {
        $thread = $thread1;
    }
}

// Set archived status
$thread->archived = $archive;

// Handle attachment status if archiving
if ($archive && isset($_GET['attachment'])) {
    foreach($thread->emails as $email) {
        if (!isset($email->attachments)) {
            continue;
        }
        foreach($email->attachments as $att) {
            if ($att->location == $_GET['attachment']) {
                $att->status_type = 'success';
                $att->status_text = 'Document OK';
            }
        }
    }
}

$storageManager->updateThread($thread, $_SESSION['user']['sub']);

// Redirect back to thread view
header("Location: /thread-view?threadId=" . urlencode($threadId) . "&entityId=" . urlencode($entityId));
exit;
