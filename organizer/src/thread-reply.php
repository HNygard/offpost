<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';
require_once __DIR__ . '/class/ThreadHistory.php';
require_once __DIR__ . '/class/ThreadUtils.php';

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
$recipients = isset($_POST['recipients']) && is_array($_POST['recipients']) ? $_POST['recipients'] : [];
$sendReply = isset($_POST['send_reply']);
$saveDraft = isset($_POST['save_draft']);

$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

if (!$threadId || !$entityId || !$replySubject || !$replyBody) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Missing required parameters');
}

if (empty($recipients)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('No recipients selected');
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

    // Validate selected recipients against valid thread recipients
    $validRecipients = getThreadReplyRecipients($thread);
    $selectedRecipients = [];
    
    foreach ($recipients as $selectedEmail) {
        $selectedEmail = strtolower(trim($selectedEmail));
        if (in_array($selectedEmail, array_map('strtolower', $validRecipients))) {
            $selectedRecipients[] = $selectedEmail;
        }
    }
    
    if (empty($selectedRecipients)) {
        throw new Exception('No valid recipients selected');
    }

    // Set status based on action
    $status = $sendReply ? ThreadEmailSending::STATUS_READY_FOR_SENDING : ThreadEmailSending::STATUS_STAGING;

    // Create email sending records for each recipient
    $emailIds = [];
    foreach ($selectedRecipients as $recipientEmail) {
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
            throw new Exception('Failed to create email sending record for ' . $recipientEmail);
        }
        
        $emailIds[] = $emailSending->id;
    }
    
    if (empty($emailIds)) {
        throw new Exception('No email sending records were created');
    }

    // Log the action in thread history
    $history = new ThreadHistory();
    $action = $sendReply ? 'Reply created and marked for sending' : 'Reply draft saved';
    $history->logAction($threadId, $userId, $action, [
        'email_sending_ids' => $emailIds,
        'recipients' => $selectedRecipients,
        'subject' => $replySubject
    ]);

    // Set success message
    $recipientCount = count($selectedRecipients);
    $recipientText = $recipientCount === 1 ? '1 recipient' : $recipientCount . ' recipients';
    $_SESSION['success_message'] = $sendReply ? 
        "Reply has been prepared for {$recipientText} and will be sent shortly." : 
        "Reply draft has been saved for {$recipientText}.";

} catch (Exception $e) {
    error_log('Thread reply error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Error processing reply: ' . $e->getMessage();
}

// Redirect back to thread view
header("Location: /thread-view?threadId=" . urlencode($threadId) . "&entityId=" . urlencode($entityId));
exit;