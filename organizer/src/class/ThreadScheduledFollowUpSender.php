<?php

require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadEmailSending.php';
require_once __DIR__ . '/ThreadStatusRepository.php';

/**
 * Class for handling scheduled thread follow-ups
 */
class ThreadScheduledFollowUpSender {
    /**
     * Find and send the next follow-up email
     * 
     * @return array Result of the operation
     */
    public function sendNextFollowUpEmail() {
        // Find the next thread that needs follow-up
        $thread = $this->findNextThreadForProcessing();
        
        if (!$thread) {
            return [
                'success' => false,
                'message' => 'No threads ready for follow-up'
            ];
        }
        
        // Get the entity for this thread
        $entity = $thread->getEntity();
        if (!$entity) {
            return [
                'success' => false,
                'message' => 'Entity not found for thread'
            ];
        }
        
        // Create a follow-up email
        $subject = "Purring - " . $thread->title;
        $content = $this->createFollowUpEmailContent($thread);
        
        // Create email sending record
        $emailSending = ThreadEmailSending::create(
            $thread->id,
            $content,
            $subject,
            $entity->email,
            $thread->my_email,
            $thread->my_name,
            ThreadEmailSending::STATUS_STAGING
        );
        
        if (!$emailSending) {
            return [
                'success' => false,
                'message' => 'Failed to create email sending record'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Follow-up email scheduled for sending',
            'thread_id' => $thread->id
        ];
    }
    
    /**
     * Find the next thread that needs processing
     * 
     * @return Thread|null The thread or null if none found
     */
    protected function findNextThreadForProcessing() {
        // Get threads with status EMAIL_SENT_NOTHING_RECEIVED
        $threadIds = ThreadStatusRepository::getThreadsByStatus(
            ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED
        );
        
        if (empty($threadIds)) {
            return null;
        }
        
        // Get the first thread
        return Thread::loadFromDatabase($threadIds[0]);
    }
    
    /**
     * Create follow-up email content
     * 
     * @param Thread $thread The thread
     * @return string The email content
     */
    protected function createFollowUpEmailContent(Thread $thread) {
        // Get email for thread
        if (count($thread->emails) != 1) {
            throw new Exception("Thread should have exactly one email for this 'follow up implementation' to work");
        }
        $email_sent = $thread->emails[0];
        if ($email_sent->email_type != 'OUT') {
            throw new Exception("Thread should have exactly one email of type OUT for this 'follow up implementation' to work");
        }

        // Hardcoded template for follow-up email
        return "Hei,\n\n"
            . "Jeg sendte en henvendelse til dere angående \"" . $thread->title . "\" og har ikke mottatt svar."
            . " Vår hendvendelse ble sendt " . date('H:i:s d.m.Y', strtotime($email_sent->timestamp_received)) . ".\n\n"
            . "Vennligst gi meg en oppdatering på status for min henvendelse.\n\n"
            . "Med vennlig hilsen,\n" . $thread->my_name;
    }
}
