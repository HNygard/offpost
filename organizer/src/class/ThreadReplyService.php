<?php

require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadEmailSending.php';
require_once __DIR__ . '/ThreadHistory.php';
require_once __DIR__ . '/ThreadUtils.php';

class ThreadReplyValidationException extends Exception {}

/**
 * Queues a reply (or draft) on a thread.
 *
 * Shared by the thread-reply page and the MCP send_reply tool. Does NOT check
 * that the user may access the thread; callers do that.
 */
class ThreadReplyService {
    /**
     * @param array $historyDetails Extra details merged into the history row
     * @throws ThreadReplyValidationException On empty fields or a recipient that is not valid for the thread
     */
    public function queueReply(
        Thread $thread,
        string $subject,
        string $body,
        string $recipient,
        string $userId,
        bool $send,
        array $historyDetails = []
    ): ThreadEmailSending {
        if ($subject === '' || $body === '' || trim($recipient) === '') {
            throw new ThreadReplyValidationException('Subject, body and recipient are required');
        }

        // Validate selected recipient against valid thread recipients
        $selectedRecipient = strtolower(trim($recipient));
        $validRecipients = array_map('strtolower', getThreadReplyRecipients($thread));
        if (!in_array($selectedRecipient, $validRecipients)) {
            throw new ThreadReplyValidationException('Invalid recipient selected');
        }

        $status = $send ? ThreadEmailSending::STATUS_READY_FOR_SENDING : ThreadEmailSending::STATUS_STAGING;

        $emailSending = ThreadEmailSending::create(
            $thread->id,
            $body,
            $subject,
            $selectedRecipient,
            $thread->my_email,
            $thread->my_name,
            $status
        );

        if (!$emailSending) {
            throw new Exception('Failed to create email sending record for ' . $selectedRecipient);
        }

        $history = new ThreadHistory();
        $action = $send ? 'thread_reply_created_and_queued_sending' : 'thread_reply_draft';
        $history->logAction($thread->id, $action, $userId, [
            'email_sending_ids' => [$emailSending->id],
            'recipient' => $selectedRecipient,
            'subject' => $subject
        ] + $historyDetails);

        return $emailSending;
    }
}
