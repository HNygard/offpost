<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailSender.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Create the email sender
    $emailSender = new ThreadScheduledEmailSender();

    // Send the next scheduled email
    // Note: We only send one at the time to not trigger to many alerts for spam.
    $result = $emailSender->sendNextScheduledEmail();

    if (!$result['success']) {
        // Notify admin if there was an error in sending
        $adminNotificationService = new AdminNotificationService();
        $adminNotificationService->notifyAdminOfError(
            'scheduled-email-sending',
            'Error in email sending: ' . $result['message'],
            $result
        );
    }

    // Output the result
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    
}
catch (Exception $e) {
    // Log the error and notify administrators
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        'scheduled-email-sending',
        'Unexpected error: ' . $e->getMessage(),
        [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString()
        ]
    );
    
    throw $e;
}
