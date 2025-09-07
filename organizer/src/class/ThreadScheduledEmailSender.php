<?php

require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadDatabaseOperations.php';
require_once __DIR__ . '/ThreadEmailService.php';
require_once __DIR__ . '/ThreadHistory.php';
require_once __DIR__ . '/ThreadEmailSending.php';

/**
 * Class for handling scheduled email sending
 */
class ThreadScheduledEmailSender {
    protected $dbOps;
    protected $emailService;
    protected $history;
    
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
        // Find the next email that is ready for sending
        $emailSending = ThreadEmailSending::findNextForSending();
        
        if (!$emailSending) {
            return [
                'success' => false,
                'message' => 'No threads ready for sending'
            ];
        }
        
        // Get the thread for this email
        $thread = Thread::loadFromDatabase($emailSending->thread_id);
        if (!$thread) {
            return [
                'success' => false,
                'message' => 'Thread not found for email sending record'
            ];
        }
        
        // Update status to SENDING in both places
        $emailSending->status = ThreadEmailSending::STATUS_SENDING;
        ThreadEmailSending::updateStatus($emailSending->id, ThreadEmailSending::STATUS_SENDING);
        
        $thread->sending_status = Thread::SENDING_STATUS_SENDING;
        $this->dbOps->updateThread($thread, 'system');
        
        // Send the email
        $result = $this->sendEmail($emailSending);
        
        if ($result['success']) {
            // Update status to SENT in both places
            ThreadEmailSending::updateStatus(
                $emailSending->id, 
                ThreadEmailSending::STATUS_SENT,
                $result['smtp_response'] ?? null,
                $result['debug'] ?? null
            );
            
            $thread->sending_status = Thread::SENDING_STATUS_SENT;
            $thread->sent = true; // For backward compatibility
            $this->dbOps->updateThread($thread, 'system');

            // Request an update for the target folder

            $records = ImapFolderStatus::getForThread($thread->id);
            ImapFolderStatus::createOrUpdate($records['folder_name'], requestUpdate: true);
            
            return [
                'success' => true,
                'message' => 'Email sent successfully',
                'thread_id' => $thread->id
            ];
        } else {
            // Revert to READY_FOR_SENDING if failed
            ThreadEmailSending::updateStatus(
                $emailSending->id, 
                ThreadEmailSending::STATUS_READY_FOR_SENDING,
                $result['smtp_response'] ?? null,
                $result['debug'] ?? null,
                $result['error'] ?? null
            );
            
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
     * This method is kept for backward compatibility and testing
     * It's now a wrapper around ThreadEmailSending::findNextForSending()
     * 
     * @return Thread|null The thread or null if none found
     */
    protected function findNextThreadForSending() {
        $emailSending = ThreadEmailSending::findNextForSending();
        
        if (!$emailSending) {
            return null;
        }
        
        return Thread::loadFromDatabase($emailSending->thread_id);
    }
    
    /**
     * Send an email using the ThreadEmailSending record
     * 
     * @param ThreadEmailSending $emailSending The email sending record
     * @return array Result of the send operation
     */
    protected function sendEmail(ThreadEmailSending $emailSending) {
        // Send the email
        $success = $this->emailService->sendEmail(
            $emailSending->email_from,
            $emailSending->email_from_name,
            $emailSending->email_to,
            $emailSending->email_subject,
            $emailSending->email_content,
            $emailSending->email_from // BCC
        );
        
        return [
            'success' => $success,
            'error' => $this->emailService->getLastError(),
            'debug' => $this->emailService->getDebugOutput(),
            'smtp_response' => $success ? 'Email sent successfully' : $this->emailService->getLastError()
        ];
    }
}
