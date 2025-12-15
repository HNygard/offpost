<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailSender.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';
require_once __DIR__ . '/../class/ScheduledTaskLogger.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start task logging
$taskLogger = new ScheduledTaskLogger('scheduled-email-sending');
$taskLogger->start();

try {
    // Create the email sender
    $emailSender = new ThreadScheduledEmailSender();

    // Send the next scheduled email
    // Note: We only send one at the time to not trigger to many alerts for spam.
    $result = $emailSender->sendNextScheduledEmail();
    
    // Track items processed
    if ($result['success']) {
        $taskLogger->addItemsProcessed(1);
    }

    if (!$result['success'] && $result['message'] !== 'No threads ready for sending') {
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
    $output = json_encode($result, JSON_PRETTY_PRINT);
    echo $output;
    
    // Track bytes in output
    $taskLogger->addBytesProcessed(strlen($output));
    $taskLogger->complete($result['message'] ?? 'Task completed');
    
}
catch (Exception $e) {
    $taskLogger->fail($e->getMessage());
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
