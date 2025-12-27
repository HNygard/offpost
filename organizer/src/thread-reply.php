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
$replySubject = isset($_POST['reply_subject']) ? trim($_POST['reply_subject']) : null;
$replyBody = isset($_POST['reply_body']) ? trim($_POST['reply_body']) : null;
$recipient = isset($_POST['recipient']) ? trim($_POST['recipient']) : '';
$sendReply = isset($_POST['send_reply']);
$saveDraft = isset($_POST['save_draft']);

$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

if (!$threadId || !$replySubject || !$replyBody) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Missing required parameters');
}

if (empty($recipient)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('No recipient selected');
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

    // Validate selected recipient against valid thread recipients
    $validRecipients = getThreadReplyRecipients($thread);
    $selectedRecipient = strtolower(trim($recipient));
    
    if (!in_array($selectedRecipient, array_map('strtolower', $validRecipients))) {
        throw new Exception('Invalid recipient selected');
    }

    // Set status based on action
    $status = $sendReply ? ThreadEmailSending::STATUS_READY_FOR_SENDING : ThreadEmailSending::STATUS_STAGING;

    // Create email sending record for the recipient
    $emailSending = ThreadEmailSending::create(
        $threadId,
        $replyBody,
        $replySubject,
        $selectedRecipient,
        $thread->my_email,
        $thread->my_name,
        $status
    );
    
    if (!$emailSending) {
        throw new Exception('Failed to create email sending record for ' . $selectedRecipient);
    }
    
    $emailIds = [$emailSending->id];

    // Log the action in thread history
    $history = new ThreadHistory();
    $action = $sendReply ? 'thread_reply_created_and_queued_sending' : 'thread_reply_draft';
    $history->logAction($threadId, $action, $userId, [
        'email_sending_ids' => $emailIds,
        'recipient' => $selectedRecipient,
        'subject' => $replySubject
    ]);

    // Set success message
    $_SESSION['success_message'] = $sendReply ? 
        "Reply has been prepared for {$selectedRecipient} and will be sent shortly." : 
        "Reply draft has been saved for {$selectedRecipient}.";

} catch (Exception $e) {
    error_log('Thread reply error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Error processing reply: ' . $e->getMessage();
}

// Redirect back to thread view
header("Location: /thread-view?threadId=" . urlencode($threadId));
exit;
