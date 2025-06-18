<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';
require_once __DIR__ . '/class/ThreadHistory.php';

// Require authentication
requireAuth();

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    die('Method not allowed');
}

// Get required parameters
$threadId = isset($_POST['thread_id']) ? $_POST['thread_id'] : null;
$entityId = isset($_POST['entity_id']) ? $_POST['entity_id'] : null;
$replySubject = isset($_POST['reply_subject']) ? trim($_POST['reply_subject']) : null;
$replyBody = isset($_POST['reply_body']) ? trim($_POST['reply_body']) : null;
$sendReply = isset($_POST['send_reply']);
$saveDraft = isset($_POST['save_draft']);

$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

if (!$threadId || !$entityId || !$replySubject || !$replyBody) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Missing required parameters');
}

if (!$sendReply && !$saveDraft) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid action');
}

try {
    $storageManager = ThreadStorageManager::getInstance();
    $allThreads = $storageManager->getThreads();

    $thread = null;
    $threadEntity = null;

    // Find the specific thread
    foreach ($allThreads as $file => $threads) {
        if ($threads->entity_id === $entityId) {
            foreach ($threads->threads as $t) {
                if ($t->id === $threadId) {
                    $thread = $t;
                    $threadEntity = $threads;
                    break 2;
                }
            }
        }
    }

    if (!$thread) {
        http_response_code(404);
        header('Content-Type: text/plain');
        die('Thread not found');
    }

    // Check authorization
    if (!$thread->canUserAccess($userId)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('You do not have permission to reply to this thread');
    }

    // Verify thread has incoming emails (should have at least one to justify a reply)
    $hasIncomingEmails = false;
    if (isset($thread->emails)) {
        foreach ($thread->emails as $email) {
            if ($email->email_type === 'IN') {
                $hasIncomingEmails = true;
                break;
            }
        }
    }

    if (!$hasIncomingEmails) {
        http_response_code(400);
        header('Content-Type: text/plain');
        die('No incoming emails found in thread - reply not allowed');
    }

    // Get the recipient email from the thread's entity
    $entity = $thread->getEntity();
    if ($entity && isset($entity->email)) {
        $recipientEmail = $entity->email;
    } else {
        // Fallback: try to get recipient from the most recent incoming email
        $lastIncomingEmail = null;
        if (isset($thread->emails)) {
            foreach (array_reverse($thread->emails) as $email) {
                if ($email->email_type === 'IN') {
                    $lastIncomingEmail = $email;
                    break;
                }
            }
        }
        
        if (!$lastIncomingEmail) {
            throw new Exception('Could not determine recipient email address');
        }
        
        // This would need to be implemented based on how email addresses are stored in emails
        // For now, use entity email as fallback
        $recipientEmail = $entity ? $entity->email : 'unknown@example.com';
    }

    // Set status based on action
    $status = $sendReply ? ThreadEmailSending::STATUS_READY_FOR_SENDING : ThreadEmailSending::STATUS_STAGING;

    // Create the email sending record
    $emailSending = ThreadEmailSending::create(
        $threadId,
        $replyBody,
        $replySubject,
        $recipientEmail,
        $thread->my_email,
        $thread->my_name,
        $status
    );
    
    if (!$emailSending) {
        throw new Exception('Failed to create email sending record');
    }
    
    $emailId = $emailSending->id;

    // Log the action in thread history
    $history = new ThreadHistory();
    $action = $sendReply ? 'Reply created and marked for sending' : 'Reply draft saved';
    $history->logAction($threadId, $userId, $action, [
        'email_sending_id' => $emailId,
        'subject' => $replySubject
    ]);

    // Set success message
    $_SESSION['success_message'] = $sendReply ? 
        'Reply has been prepared and will be sent shortly.' : 
        'Reply draft has been saved.';

} catch (Exception $e) {
    error_log('Thread reply error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Error processing reply: ' . $e->getMessage();
}

// Redirect back to thread view
header("Location: /thread-view?threadId=" . urlencode($threadId) . "&entityId=" . urlencode($entityId));
exit;