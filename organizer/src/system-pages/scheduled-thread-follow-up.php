<?php

require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';
require_once __DIR__ . '/../class/ScheduledTaskLogger.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start task logging
$taskLogger = new ScheduledTaskLogger('scheduled-thread-follow-up');
$taskLogger->start();

try {
    // Create the follow-up sender
    $followUpSender = new ThreadScheduledFollowUpSender();

    // Send the next follow-up email
    // Note: We only process one at a time to avoid sending too many emails at once
    $result = $followUpSender->sendNextFollowUpEmail();
    
    // Track items processed
    if ($result['success']) {
        $taskLogger->addItemsProcessed(1);
    }

    if (!$result['success'] && $result['message'] !== 'No threads ready for follow-up') {
        // Notify admin if there was an error in sending
        $adminNotificationService = new AdminNotificationService();
        $adminNotificationService->notifyAdminOfError(
            'scheduled-thread-follow-up',
            'Error in follow-up email sending: ' . $result['message'],
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
    
} catch (Exception $e) {
    $taskLogger->fail($e->getMessage());
    // Log the error and notify administrators
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        'scheduled-thread-follow-up',
        'Unexpected error: ' . $e->getMessage(),
        [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString()
        ]
    );

    throw $e;
}
