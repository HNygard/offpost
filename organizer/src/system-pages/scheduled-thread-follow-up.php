<?php

require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Create the follow-up sender
    $followUpSender = new ThreadScheduledFollowUpSender();

    // Send the next follow-up email
    // Note: We only process one at a time to avoid sending too many emails at once
    $result = $followUpSender->sendNextFollowUpEmail();

    // Output the result
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
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
    
    // Return error response
    $errorResult = [
        'success' => false,
        'message' => 'Unexpected error occurred during scheduled follow-up processing',
        'error' => $e->getMessage(),
        'debug' => $e->getTraceAsString()
    ];
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode($errorResult, JSON_PRETTY_PRINT);
}
