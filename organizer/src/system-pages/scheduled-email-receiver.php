<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailReceiver.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up time and memory limits
set_time_limit(0);
ini_set('memory_limit', '768M');

try {
    // Create the email receiver
    $emailReceiver = new ThreadScheduledEmailReceiver();

    // Process the next folder
    // Note: We only process one folder at a time to avoid overloading the system
    $result = $emailReceiver->processNextFolder();

    $results = array();
    for($i = 0; $i < 10; $i++) {
        $result = $emailReceiver->processNextFolder();
        $results[] = $result;

        if (!$result['success']) {
            // Notify admin if there was an error in processing
            $adminNotificationService = new AdminNotificationService();
            $adminNotificationService->notifyAdminOfError(
                'scheduled-email-receiver',
                'Error in email processing: ' . $result['message'],
                $result
            );
            break;
        }
    }

    // Output the result
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Log the error and notify administrators
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        'scheduled-email-receiver',
        'Unexpected error: ' . $e->getMessage(),
        [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString()
        ]
    );

    throw $e;
}
