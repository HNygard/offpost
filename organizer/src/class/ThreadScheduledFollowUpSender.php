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

        foreach ($threadIds as $thread_status) {
            if ($thread_status->request_law_basis != Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA) {
                // Skip threads that are not under the law basis for follow-up
                continue;
            }
            if (empty($thread_status->request_follow_up_plan)) {
                // Skip threads that are not under the follow-up plan
                continue;
            }
            if (date('Y', $thread_status->email_last_activity) < 2024) {
                // Skip threads that are not sent in 2025 or later
                continue;
            }

            if ($thread_status->request_follow_up_plan == Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY) {
                $days = 5;
            }
            elseif($thread_status->request_follow_up_plan == Thread::REQUEST_FOLLOW_UP_PLAN_SLOW) {
                $days = 14;
            }
            else {
                throw new Exception("Unknown follow-up plan: " . $thread_status->request_follow_up_plan);
            }

            if ($thread_status->email_last_activity + ($days * 86400) > time()) {
                // Skip threads that are not due for follow-up
                continue;
            }

            $thread = Thread::loadFromDatabase($thread_status->thread_id);

            // Just an extra check that the thread is not archived.
            if ($thread->archived) {
                throw new Exception("Thread is archived: " . $thread->id . '. Archived threads should already have been excluded in query.');
            }

            return $thread;
        }

        return null;
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
