<?php

require_once __DIR__ . '/ThreadEmailService.php';

/**
 * Service class for sending error notifications to administrators
 */
class AdminNotificationService {
    private $emailService;
    private $adminEmails;
    
    /**
     * Constructor
     * 
     * @param IEmailService $emailService Email service instance (optional)
     */
    public function __construct(IEmailService $emailService = null) {
        // Load admin configuration
        require __DIR__ . '/../username-password.php';

        if ($emailService === null) {
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

        if (!isset($adminEmails)) {
            throw new Exception("Admin emails not configured in username-password.php");
        }

        // Set admin emails
        $this->adminEmails = $adminEmails;
    }
    
    /**
     * Send error notification to administrators
     * 
     * @param string $source Source of the error (e.g., 'scheduled-email-sending')
     * @param string $message Error message
     * @param array $errorDetails Additional error details (optional)
     * @return bool Success status
     */
    public function notifyAdminOfError(string $source, string $message, array $errorDetails = []): bool {
        if (empty($this->adminEmails)) {
            // No admin emails configured, skip notification
            return true;
        }
        
        $subject = "Offpost Error: {$source}";
        $body = $this->formatErrorNotification($source, $message, $errorDetails);
        
        $success = true;
        foreach ($this->adminEmails as $adminEmail) {
            $result = $this->emailService->sendEmail(
                'system@offpost.no',
                'Offpost System',
                $adminEmail,
                $subject,
                $body
            );
            
            if (!$result) {
                $success = false;
                // Continue trying to send to other admins even if one fails
            }
        }
        
        return $success;
    }
    
    /**
     * Format error notification message
     * 
     * @param string $source Source of the error
     * @param string $message Error message
     * @param array $errorDetails Additional error details
     * @return string Formatted message body
     */
    private function formatErrorNotification(string $source, string $message, array $errorDetails): string {
        $body = "An error occurred in the Offpost system:\n\n";
        $body .= "Source: {$source}\n";
        $body .= "Time: " . date('Y-m-d H:i:s T') . "\n";
        $body .= "Message: {$message}\n\n";
        
        if (!empty($errorDetails)) {
            $body .= "Error Details:\n";
            foreach ($errorDetails as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_PRETTY_PRINT);
                }
                $body .= "{$key}: {$value}\n";
            }
            $body .= "\n";
        }
        
        $body .= "Please check the system logs for more information.\n";
        $body .= "\n--\n";
        $body .= "Offpost System Administrator";
        
        return $body;
    }
    
    /**
     * Get the last error from the email service
     * 
     * @return string|null Last error message
     */
    public function getLastError(): ?string {
        return $this->emailService->getLastError();
    }
}