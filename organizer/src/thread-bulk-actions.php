<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadHistory.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';

// Require authentication
requireAuth();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Validate required parameters
if (!isset($_POST['action']) || !isset($_POST['thread_ids']) || !is_array($_POST['thread_ids']) || empty($_POST['thread_ids'])) {
    $_SESSION['error_message'] = 'Invalid request: Missing required parameters';
    header('Location: /');
    exit;
}

$action = $_POST['action'];
$threadIds = $_POST['thread_ids'];
$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier
$storageManager = ThreadStorageManager::getInstance();
$allThreads = $storageManager->getThreads($userId);
$processedCount = 0;
$errorCount = 0;

// Process each thread
foreach ($threadIds as $threadInfo) {
    // Parse thread info (format: entityId:threadId)
    $parts = explode(':', $threadInfo);
    if (count($parts) !== 2) {
        $errorCount++;
        continue;
    }
    
    $entityId = $parts[0];
    $threadId = $parts[1];
    
    // Find the thread
    $thread = null;
    foreach ($allThreads as $file => $threads) {
        if ($threads->entity_id === $entityId) {
            foreach ($threads->threads as $t) {
                if ($t->id === $threadId) {
                    $thread = $t;
                    break 2;
                }
            }
        }
    }
    
    // Skip if thread not found or user not authorized
    if (!$thread || !$thread->canUserAccess($userId)) {
        $errorCount++;
        continue;
    }
    
    // Apply the selected action
    switch ($action) {
        case 'archive':
            $thread->archived = true;
            $storageManager->updateThread($thread, $userId);
            $processedCount++;
            break;
            
        case 'ready_for_sending':
            if ($thread->sending_status === Thread::SENDING_STATUS_STAGING) {
                $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
                $storageManager->updateThread($thread, $userId);
                
                // Also update the corresponding ThreadEmailSending records
                $emailSendings = ThreadEmailSending::getByThreadId($thread->id);
                foreach ($emailSendings as $emailSending) {
                    if ($emailSending->status === ThreadEmailSending::STATUS_STAGING) {
                        ThreadEmailSending::updateStatus(
                            $emailSending->id,
                            ThreadEmailSending::STATUS_READY_FOR_SENDING
                        );
                    }
                }
                $processedCount++;
            } else {
                $errorCount++;
            }
            break;
            
        case 'make_private':
            if ($thread->public) {
                $thread->public = false;
                $storageManager->updateThread($thread, $userId);
                $processedCount++;
            } else {
                // Already private, count as processed
                $processedCount++;
            }
            break;
            
        case 'make_public':
            if (!$thread->public) {
                $thread->public = true;
                $storageManager->updateThread($thread, $userId);
                $processedCount++;
            } else {
                // Already public, count as processed
                $processedCount++;
            }
            break;
            
        default:
            $errorCount++;
            break;
    }
}

// Set success/error messages
if ($processedCount > 0) {
    $_SESSION['success_message'] = "Successfully processed $processedCount thread(s)";
}

if ($errorCount > 0) {
    $_SESSION['error_message'] = "Failed to process $errorCount thread(s)";
}

// Redirect back to the thread listing
header('Location: /');
exit;
