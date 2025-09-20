<?php

use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapFolderManager;

require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapFolderManager.php';
require_once __DIR__ . '/../class/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up time and memory limits
set_time_limit(0);
ini_set('memory_limit', '768M');

try {
    require_once __DIR__ . '/../username-password.php';
    require_once __DIR__ . '/../update-imap-functions.php';

    // Start output buffering to capture debug output
    ob_start();

    // Initialize IMAP connection and components
    $connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
    $connection->openConnection();

    // Get all threads
    $threads = ThreadStorageManager::getInstance()->getThreads();

    $folderManager = new ImapFolderManager($connection);
    $folderManager->initialize();

    $emailProcessor = new ImapEmailProcessor($connection);

    // Same as the task https://offpost.no/update-imap?task=create-folders:
    createFolders($connection, $folderManager, $threads);

    // Same as the task https://offpost.no/update-imap?task=process-sent:
    processSentFolder($connection, $folderManager, $emailProcessor, $threads, $imapSentFolder);

    // Same as the task https://offpost.no/update-imap?task=process-inbox:
    processInbox($connection, $folderManager, $emailProcessor, $threads);

    // Finally, expunge to remove any deleted emails
    $connection->closeConnection(CL_EXPUNGE);
    
    // Get the debug output
    $debugOutput = ob_get_clean();
    
    // Return success response with debug output
    $result = [
        'success' => true,
        'message' => 'IMAP handling completed successfully',
        'debug' => $debugOutput
    ];
    
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Clean the output buffer if it exists
    if (ob_get_level()) {
        $debugOutput = ob_get_clean();
    } else {
        $debugOutput = '';
    }
    
    // Log the error and notify administrators
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        'scheduled-imap-handling',
        'Unexpected error: ' . $e->getMessage(),
        [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
            'debug_output' => $debugOutput
        ]
    );
    
    // Return error response
    $errorResult = [
        'success' => false,
        'message' => 'Unexpected error occurred during scheduled IMAP handling',
        'error' => $e->getMessage(),
        'debug' => $debugOutput,
        'stack_trace' => $e->getTraceAsString()
    ];
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode($errorResult, JSON_PRETTY_PRINT);
}
