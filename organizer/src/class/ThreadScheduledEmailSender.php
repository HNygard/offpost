<?php

require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadDatabaseOperations.php';
require_once __DIR__ . '/ThreadEmailService.php';
require_once __DIR__ . '/ThreadHistory.php';

/**
 * Class for handling scheduled email sending
 */
class ThreadScheduledEmailSender {
    private $dbOps;
    private $emailService;
    private $history;
    
    /**
     * Constructor
     * 
     * @param ThreadDatabaseOperations $dbOps Database operations instance
     * @param IEmailService $emailService Email service instance
     * @param ThreadHistory $history Thread history instance
     */
    public function __construct(
        ThreadDatabaseOperations $dbOps = null,
        IEmailService $emailService = null,
        ThreadHistory $history = null
    ) {
        $this->dbOps = $dbOps ?: new ThreadDatabaseOperations();
        $this->history = $history ?: new ThreadHistory();
        
        if ($emailService === null) {
            require __DIR__ . '/../username-password.php';
            $this->emailService = new PHPMailerService(
                $smtpServer,
                $smtpUsername,
                $smtpPassword,
                $smtpPort,
                $smtpSecure
            );
        } else {
            $this->emailService = $emailService;
        }
    }
    
    /**
     * Find and send the next scheduled email
     * 
     * @return array Result of the operation
     */
    public function sendNextScheduledEmail() {
        // Find the next thread that is ready for sending
        $thread = $this->findNextThreadForSending();
        
        if (!$thread) {
            return [
                'success' => false,
                'message' => 'No threads ready for sending'
            ];
        }
        
        // Get entity details for the thread
        $entity = $this->getEntityById($thread->entity_id);
        
        // Update status to SENDING
        $thread->sending_status = Thread::SENDING_STATUS_SENDING;
        $this->dbOps->updateThread($thread, 'system');
        
        // Send the email
        $result = $this->sendEmail($thread, $entity);
        
        if ($result['success']) {
            // Update status to SENT
            $thread->sending_status = Thread::SENDING_STATUS_SENT;
            $thread->sent = true; // For backward compatibility
            $this->dbOps->updateThread($thread, 'system');
            
            return [
                'success' => true,
                'message' => 'Email sent successfully',
                'thread_id' => $thread->id
            ];
        } else {
            // Revert to READY_FOR_SENDING if failed
            $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
            $this->dbOps->updateThread($thread, 'system');
            
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $result['error'],
                'thread_id' => $thread->id,
                'debug' => $result['debug']
            ];
        }
    }
    
    /**
     * Get entity by ID
     * 
     * @param string $entityId The entity ID
     * @return object The entity
     */
    protected function getEntityById($entityId) {
        return Entity::getById($entityId);
    }
    
    /**
     * Find the next thread that is ready for sending
     * 
     * @return Thread|null The thread or null if none found
     */
    protected function findNextThreadForSending() {
        $query = "
            SELECT id
            FROM threads
            WHERE sending_status = ?
            AND initial_request IS NOT NULL
            AND initial_request != ''
            ORDER BY created_at ASC 
            LIMIT 1
        ";
        
        $threadId = Database::queryValue($query, [Thread::SENDING_STATUS_READY_FOR_SENDING]);
        
        if (!$threadId) {
            return null;
        }
        
        return Thread::loadFromDatabase($threadId);
    }
    
    /**
     * Send an email for a thread
     * 
     * @param Thread $thread The thread to send email for
     * @param object $entity The entity to send email to
     * @return array Result of the send operation
     */
    protected function sendEmail(Thread $thread, $entity) {
        // Get email details from thread
        $emailTo = $entity->email;
        $emailSubject = $thread->title;
        $emailBody = $thread->initial_request;
        
        // Send the email
        $success = $this->emailService->sendEmail(
            $thread->my_email,
            $thread->my_name,
            $emailTo,
            $emailSubject,
            $emailBody,
            $thread->my_email // BCC
        );
        
        return [
            'success' => $success,
            'error' => $this->emailService->getLastError(),
            'debug' => $this->emailService->getDebugOutput()
        ];
    }
}
