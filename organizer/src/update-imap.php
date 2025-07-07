<?php

require_once __DIR__ . '/auth.php';
require_once(__DIR__ . '/class/common.php');

set_time_limit(0);
ini_set('memory_limit', '-1');

// Require authentication
requireAuth();

require_once __DIR__ . '/class/ThreadFolderManager.php';
require_once __DIR__ . '/class/ThreadEmailSaver.php';
require_once __DIR__ . '/class/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';
require_once __DIR__ . '/class/ImapFolderStatus.php';
require_once __DIR__ . '/class/ImapFolderLog.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

// Load IMAP credentials
require __DIR__ . '/username-password.php';

require_once __DIR__ . '/update-imap-functions.php';

function displayTaskOptions() {
    require __DIR__ . '/head.php';
    ?>
    <h1>IMAP Tasks</h1>
    
    <h2>Available Tasks</h2>
    <ul>
        <li><a href="?task=create-folders">Create Required Folders</a></li>
        <li><a href="?task=archive-folders">Archive Folders</a></li>
        <li><a href="?task=process-inbox">Process Inbox</a></li>
        <li><a href="?task=process-sent">Process Sent Folder</a> (Roundcube sending only)</li>
        <li><a href="?task=process-all">Process All Thread Folders</a> (now also available via <a href="/system-pages/scheduled-email-receiver.php">scheduled-email-receiver.php</a>)</li>
        <li><a href="?task=list-folders">List All Folders</a></li>
        <li><a href="?task=create-folder-status">Create Folder Status Records</a></li>
        <li><a href="?task=view-folder-status">View Folder Status</a></li>
        <li><a href="?task=view-folder-logs">View Folder Logs</a></li>
    </ul>
    <?php
}

// Main execution
try {
    // Initialize IMAP connection and components
    $connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
    $connection->openConnection();
    
    $folderManager = new ImapFolderManager($connection);
    $folderManager->initialize();
    $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
    $emailProcessor = new ImapEmailProcessor($connection);
    $attachmentHandler = new ImapAttachmentHandler($connection);
    
    // Display task selection if no task specified
    $task = $_GET['task'] ?? null;
    
    if (!$task) {
        displayTaskOptions();
    } else {
        echo '<pre>';
        
        echo ":: IMAP setting\n";
        echo "Server ..... : $imapServer\n";
        echo "Username ... : $imap_username\n";
        echo "\n\n";
        
        switch ($task) {
            case 'create-folders':
                $threads = ThreadStorageManager::getInstance()->getThreads();

                if (isset($_GET['not-before'])) {
                    // Filter threads based on 'not-before' date
                    $notBefore = strtotime($_GET['not-before']);
                    if ($notBefore === false) {
                        throw new Exception("Invalid 'not-before' date format");
                    }
                    foreach ($threads as $key => $entityThreads) {
                        $threads[$key]->threads = array_filter($entityThreads->threads, function($thread) use ($notBefore) {
                            if (!isset($thread->created_at)) {
                                throw new Exception("Thread created_at is not set");
                            }
                            return strtotime($thread->created_at) >= $notBefore;
                        });
                    }
                }

                $folders = createFolders($connection, $folderManager, $threads);
                echo "Created folders:\n";
                foreach ($folders as $folder) {
                    echo "- $folder\n";
                }
                break;
                
            case 'archive-folders':
                $threads = ThreadStorageManager::getInstance()->getThreads();
                archiveFolders($connection, $folderManager, $threads);
                echo "Archived folders for archived threads\n";
                break;
                
            case 'process-inbox':
                $threads = ThreadStorageManager::getInstance()->getThreads();
                $unmatchedAddresses = processInbox($connection, $folderManager, $emailProcessor, $threads);
                $connection->closeConnection(CL_EXPUNGE);
                
                if (!empty($unmatchedAddresses)) {
                    echo "Unmatched email addresses:\n";
                    foreach ($unmatchedAddresses as $address) {
                        echo '- <a href="start-thread.php?my_email=' . urlencode($address) . 
                             '">Start thread with ' . htmlspecialchars($address) . "</a>\n";
                    }
                } else {
                    echo "No unmatched email addresses found\n";
                }
                break;
                
            case 'process-sent':
                $threads = ThreadStorageManager::getInstance()->getThreads();

                $connection->closeConnection();
                $connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
                $connection->openConnection($imapSentFolder);
                
                $folderManager = new ImapFolderManager($connection);
                $folderManager->initialize();
                $emailProcessor = new ImapEmailProcessor($connection);
                
                processSentFolder($connection, $folderManager, $emailProcessor, $threads, $imapSentFolder);
                $connection->closeConnection(CL_EXPUNGE);
                echo "Processed sent folder\n";
                break;
            
            case 'list-folders':
                $folders = $folderManager->getExistingFolders();
                echo "All folders:\n";
                echo "</pre><ul>\n";
                foreach ($folders as $folder) {
                    ?>
                    <li>
                        <a href="?task=process-folder&folder=<?= urlencode($folder) ?>">
                            <?= htmlspecialchars($folder) ?>
                        </a>
                    </li>
                    <?php
                }
                echo "</ul><pre>\n";
                
                break;

                
            case 'process-folder':
                $folder = $_GET['folder'] ?? null;
                if (!$folder) {
                    echo "Error: Missing folder parameter\n";
                    break;
                }
                
                echo "Note: This functionality is now handled by scheduled-email-receiver.php\n";
                echo "Using legacy method for backward compatibility...\n\n";
                
                $savedEmails = processThreadFolder(
                    $connection, 
                    $folderManager, 
                    $emailProcessor, 
                    $attachmentHandler,
                    $folder
                );
                
                if (!empty($savedEmails)) {
                    echo "Saved emails:\n";
                    foreach ($savedEmails as $email) {
                        echo "- $email\n";
                    }
                } else {
                    echo "No new emails to save\n";
                }
                
                break;
                
            case 'process-all':
                $threadFolders = getThreadFoldersFromImap($folderManager);
                echo "Note: This functionality is now handled by scheduled-email-receiver.php\n";
                echo "Using legacy method for backward compatibility...\n\n";
                
                echo "Processing folders from IMAP server:\n";
                foreach ($threadFolders as $folder) {
                    echo "Processing folder: $folder\n";
                    try {
                        // Find matching thread for this folder
                        $savedEmails = processThreadFolder(
                            $connection,
                            $folderManager,
                            $emailProcessor,
                            $attachmentHandler,
                            $folder
                        );
                        
                        if (!empty($savedEmails)) {
                            echo "  Saved emails:\n";
                            foreach ($savedEmails as $email) {
                                echo "  - $email\n";
                            }
                            echo "\n";
                        } else {
                            echo "  No new emails to save\n\n";
                        }
                    } catch (Exception $e) {
                        // Error is already logged in processThreadFolder
                        echo "  Error processing folder $folder: " . $e->getMessage() . "\n\n";
                        continue;
                    }
                }
                break;
                
            case 'create-folder-status':
                $threads = ThreadStorageManager::getInstance()->getThreads();
                $count = createImapFolderStatusRecords($folderManager, $threadFolderManager, $threads);
                echo "Created/updated $count folder status records\n";
                break;
                
            case 'view-folder-status':
                $records = ImapFolderStatus::getAll();
                echo "IMAP Folder Status Records:<br><br>";
                
                if (empty($records)) {
                    echo "No records found. Run 'Create Folder Status Records' first.";
                } else {
                    echo '<table border="1" cellpadding="5" cellspacing="0">';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th>Folder Name</th>';
                    echo '<th>Thread</th>';
                    echo '<th>Last Checked</th>';
                    echo '<th>Requested update</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';
                    
                    foreach ($records as $record) {
                        $lastChecked = $record['last_checked_at'] ? date('Y-m-d H:i:s', strtotime($record['last_checked_at'])) : 'Never';
                        $requested_update_time = $record['requested_update_time'] ? date('Y-m-d H:i:s', strtotime($record['requested_update_time'])) : 'Not requested';
                        $threadInfo = htmlspecialchars($record['entity_id'] . ' - ' . $record['thread_title']);
                        $folderName = htmlspecialchars($record['folder_name']);
                        
                        echo '<tr>';
                        echo '<td><a href="?task=process-folder&folder=' . urlencode($record['folder_name']) . '">' . $folderName . '</a></td>';
                        echo '<td>' . $threadInfo . '</td>';
                        echo '<td>' . $lastChecked . '</td>';
                        echo '<td>' . $requested_update_time . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                }
                break;
                
            case 'view-folder-logs':
                $folder = $_GET['folder'] ?? null;
                $limit = $_GET['limit'] ?? 100;
                
                if ($folder) {
                    $logs = ImapFolderLog::getForFolder($folder, $limit);
                    echo "Logs for folder: $folder<br><br>";
                } else {
                    $logs = ImapFolderLog::getAll($limit);
                    echo "All folder logs<br><br>";
                }
                
                if (empty($logs)) {
                    echo "No logs found.";
                } else {
                    echo '<table border="1" cellpadding="5" cellspacing="0">';
                    echo '<thead>';
                    echo '<tr><th>Created At</th><th>Folder Name</th><th>Status</th><th>Message</th></tr>';
                    echo '</thead>';
                    echo '<tbody>';
                    
                    foreach ($logs as $log) {
                        $createdAt = date('Y-m-d H:i:s', strtotime($log['created_at']));
                        $folderName = htmlspecialchars($log['folder_name']);
                        $status = htmlspecialchars($log['status']);
                        $message = htmlspecialchars($log['message']);
                        
                        echo '<tr>';
                        echo '<td>' . $createdAt . '</td>';
                        echo '<td><a href="?task=process-folder&folder=' . urlencode($log['folder_name']) . '">' . $folderName . '</a></td>';
                        echo '<td>' . $status . '</td>';
                        echo '<td><pre>' . $message . '</pre></td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                }
                break;
                
            default:
                echo "Error: Unknown task '$task'\n";
        }
        
        echo "\n";
        echo '<a href="/update-imap">Back to task selection</a>';
        echo '</pre>';
    }
    
} catch(Exception $e) {
    // Start output buffering to capture debug output if not already started
    if (!ob_get_level()) {
        ob_start();
    }
    
    // Get any debug output
    $debugOutput = ob_get_flush();
    
    // Log the error if we can determine which folder was being processed
    if (isset($folder)) {
        // Check if there's already a log entry for this folder
        $log = ImapFolderLog::getMostRecentForFolder($folder);
        
        if ($log && $log['status'] === 'started') {
            // Update the existing log entry
            ImapFolderLog::updateLog(
                $log['id'], 
                'error', 
                "Error processing folder: $folder. " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
            );
        } else {
            // Create a new log entry
            ImapFolderLog::createLog(
                $folder, 
                'error', 
                "Error processing folder: $folder. " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
            );
        }
    }
    
    echo "\n\n";
    echo "Error updating imap:\n";
    echo jTraceEx($e);
}
