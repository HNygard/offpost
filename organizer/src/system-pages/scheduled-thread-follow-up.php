<?php

require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$startTime = microtime(true);
$taskName = 'scheduled-thread-follow-up';
error_log("[$taskName] Starting task");

try {
    // Create the follow-up sender
    $followUpSender = new ThreadScheduledFollowUpSender();

    // Send the next follow-up email
    // Note: We only process one at a time to avoid sending too many emails at once
    $result = $followUpSender->sendNextFollowUpEmail();

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
    echo json_encode($result, JSON_PRETTY_PRINT);
    
    $duration = round(microtime(true) - $startTime, 3);
    $status = $result['success'] ? 'completed' : 'failed';
    error_log("[$taskName] Task $status in {$duration}s - " . ($result['message'] ?? 'no message'));
    
} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 3);
    error_log("[$taskName] Task failed in {$duration}s - Exception: " . $e->getMessage());
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
