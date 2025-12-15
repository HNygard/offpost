<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailReceiver.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';
require_once __DIR__ . '/../class/ScheduledTaskLogger.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up time and memory limits
set_time_limit(0);
ini_set('memory_limit', '768M');

// Start task logging
$taskLogger = new ScheduledTaskLogger('scheduled-email-receiver');
$taskLogger->start();

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
        
        // Track items processed for each result
        if ($result['success']) {
            $taskLogger->addItemsProcessed(1);
        }

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
    $output = json_encode($results, JSON_PRETTY_PRINT);
    echo $output;
    
    // Track bytes in output (which represents data downloaded and processed)
    $taskLogger->addBytesProcessed(strlen($output));
    $taskLogger->complete('Processed ' . count($results) . ' folder(s)');
    
} catch (Exception $e) {
    $taskLogger->fail($e->getMessage());
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
