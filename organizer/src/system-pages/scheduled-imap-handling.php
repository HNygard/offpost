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

$startTime = microtime(true);
$taskName = 'scheduled-imap-handling';
error_log(date('Y-m-d H:i:s') . " [$taskName] Starting task");

try {
    require_once __DIR__ . '/../username-password.php';
    require_once __DIR__ . '/../update-imap-functions.php';

    // Start output buffering to capture debug output
    ob_start();

    // Initialize IMAP connection and components
    $connection = new ImapConnection($imapServer, $imap_username, $imap_password, false);
    $connection->openConnection();

    // Get all threads
    $threads = ThreadStorageManager::getInstance()->getThreads();
    $threadCount = count($threads);

    $folderManager = new ImapFolderManager($connection);
    $folderManager->initialize();

    $emailProcessor = new ImapEmailProcessor($connection);

    // Same as the task https://offpost.no/update-imap?task=create-folders:
    $createFoldersStart = microtime(true);
    createFolders($connection, $folderManager, $threads);
    $createFoldersDuration = round(microtime(true) - $createFoldersStart, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] createFolders completed in {$createFoldersDuration}s");

    // Same as the task https://offpost.no/update-imap?task=process-sent:
    $processSentStart = microtime(true);
    processSentFolder($connection, $folderManager, $emailProcessor, $threads, $imapSentFolder);
    $processSentDuration = round(microtime(true) - $processSentStart, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] processSentFolder completed in {$processSentDuration}s");

    // Same as the task https://offpost.no/update-imap?task=process-inbox:
    $processInboxStart = microtime(true);
    processInbox($connection, $folderManager, $emailProcessor, $threads);
    $processInboxDuration = round(microtime(true) - $processInboxStart, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] processInbox completed in {$processInboxDuration}s");

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
    
    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task completed in {$duration}s - Processed $threadCount threads");
    
} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task failed in {$duration}s - Exception: " . $e->getMessage());
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

    throw $e;
}
