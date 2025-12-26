<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailReceiver.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up time and memory limits
set_time_limit(0);
ini_set('memory_limit', '768M');

$startTime = microtime(true);
$taskName = 'scheduled-email-receiver';
error_log(date('Y-m-d H:i:s') . " [$taskName] Starting task");

try {
    // Create the email receiver
    $emailReceiver = new ThreadScheduledEmailReceiver();

    // Process the next folder
    // Note: We only process one folder at a time to avoid overloading the system
    $result = $emailReceiver->processNextFolder();

    $results = array();
    $foldersProcessed = 0;
    for($i = 0; $i < 10; $i++) {
        $result = $emailReceiver->processNextFolder();
        $results[] = $result;
        if ($result['success']) {
            $foldersProcessed++;
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
    echo json_encode($results, JSON_PRETTY_PRINT);
    
    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task completed in {$duration}s - Processed $foldersProcessed folders");
    
} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task failed in {$duration}s - Exception: " . $e->getMessage());
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
