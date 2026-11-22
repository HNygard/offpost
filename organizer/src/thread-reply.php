<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/class/Thread.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ThreadEmailSending.php';
require_once __DIR__ . '/class/ThreadHistory.php';
require_once __DIR__ . '/class/ThreadUtils.php';
require_once __DIR__ . '/class/ThreadReplyService.php';

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
    $thread = Thread::loadFromDatabase($threadId);
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

    // Validate selected recipient against valid thread recipients
    $validRecipients = getThreadReplyRecipients($thread);
    
    // Check if there are valid recipients available
    if (empty($validRecipients)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        die('No valid recipients found for this thread');
    }
    
    $selectedRecipient = strtolower(trim($recipient));

    $replyService = new ThreadReplyService();
    $replyService->queueReply($thread, $replySubject, $replyBody, $recipient, $userId, $sendReply);

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
