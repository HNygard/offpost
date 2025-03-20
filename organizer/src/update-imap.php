<?php

require_once __DIR__ . '/auth.php';

set_time_limit(0);
ini_set('memory_limit', '-1');

// Require authentication
requireAuth();

require_once __DIR__ . '/class/ThreadFolderManager.php';
require_once __DIR__ . '/class/ThreadEmailMover.php';
require_once __DIR__ . '/class/ThreadEmailSaver.php';
require_once __DIR__ . '/class/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

// Load IMAP credentials
require __DIR__ . '/username-password.php';

// Task functions
function createFolders($connection, $folderManager, $threads) {
    $connection->logDebug('---- CREATING FOLDERS ----');
    $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
    $threadFolderManager->initialize();
    return $threadFolderManager->createRequiredFolders($threads);
}

function archiveFolders($connection, $folderManager, $threads) {
    $connection->logDebug('---- ARCHIVING FOLDERS ----');
    $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
    $threadFolderManager->initialize();
    
    foreach ($threads as $entityThreads) {
        foreach ($entityThreads->threads as $thread) {
            $threadFolderManager->archiveThreadFolder($entityThreads, $thread);
        }
    }
}

function processInbox($connection, $folderManager, $emailProcessor, $threads) {
    $connection->logDebug('---- PROCESSING INBOX ----');
    $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
    $emailToFolder = $threadEmailMover->buildEmailToFolderMapping($threads);
    $unmatchedAddresses = $threadEmailMover->processMailbox('INBOX', $emailToFolder);
    
    return $unmatchedAddresses;
}

function processSentFolder($connection, $folderManager, $emailProcessor, $threads, $imapSentFolder) {
    $connection->logDebug('---- PROCESSING SENT FOLDER ----');
    $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
    $emailToFolder = $threadEmailMover->buildEmailToFolderMapping($threads);
    return $threadEmailMover->processMailbox($imapSentFolder, $emailToFolder);
}

function processThreadFolder($connection, $folderManager, $emailProcessor, $attachmentHandler, $entityThreads, $thread) {
    $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
    $folder = $threadFolderManager->getThreadEmailFolder($entityThreads, $thread);
    $connection->logDebug("-- $folder");
    
    try {
        $threadEmailDbSaver = new ThreadEmailDatabaseSaver($connection, $emailProcessor, $attachmentHandler);
        $savedEmails = $threadEmailDbSaver->saveThreadEmails($entityThreads->entity_id, $thread, $folder);
        
        if (!empty($savedEmails)) {
            $connection->logDebug("   Saved " . count($savedEmails) . " emails");
            ThreadStorageManager::getInstance()->updateThread($thread);
        }
        
        $threadEmailDbSaver->finishThreadProcessing($thread);
        return $savedEmails;
        
    } catch(Exception $e) {
        $connection->logDebug('ERROR during thread email processing.');
        $connection->logDebug($e->getMessage());
        $connection->logDebug($e->getTraceAsString());
        throw $e;
    }
}

function displayTaskOptions($threads) {
    require __DIR__ . '/head.php';
    ?>
    <h1>IMAP Tasks</h1>
    
    <h2>Available Tasks</h2>
    <ul>
        <li><a href="?task=create-folders">Create Required Folders</a></li>
        <li><a href="?task=archive-folders">Archive Folders</a></li>
        <li><a href="?task=process-inbox">Process Inbox</a></li>
        <li><a href="?task=process-sent">Process Sent Folder</a></li>
        <li><a href="?task=process-all">Process All Thread Folders</a></li>
    </ul>

    <h2>Process Specific Thread Folder</h2>
    <ul>
    <?php foreach ($threads as $entityThreads): ?>
        <?php foreach ($entityThreads->threads as $thread): ?>
            <li>
                <a href="?task=process-thread&entity_id=<?= urlencode($entityThreads->entity_id) ?>&thread_id=<?= urlencode($thread->id) ?>">
                    <?= htmlspecialchars($entityThreads->entity_id . ' - ' . $thread->title) ?>
                </a>
            </li>
        <?php endforeach; ?>
    <?php endforeach; ?>
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
    $emailProcessor = new ImapEmailProcessor($connection);
    $attachmentHandler = new ImapAttachmentHandler($connection);
    
    // Get threads
    $threads = ThreadStorageManager::getInstance()->getThreads();
    
    // Display task selection if no task specified
    $task = $_GET['task'] ?? null;
    
    if (!$task) {
        displayTaskOptions($threads);
    } else {
        echo '<pre>';
        
        echo ":: IMAP setting\n";
        echo "Server ..... : $imapServer\n";
        echo "Username ... : $imap_username\n";
        echo "\n\n";
        
        switch ($task) {
            case 'create-folders':
                $folders = createFolders($connection, $folderManager, $threads);
                echo "Created folders:\n";
                foreach ($folders as $folder) {
                    echo "- $folder\n";
                }
                break;
                
            case 'archive-folders':
                archiveFolders($connection, $folderManager, $threads);
                echo "Archived folders for archived threads\n";
                break;
                
            case 'process-inbox':
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
                
            case 'process-thread':
                $entityId = $_GET['entity_id'] ?? null;
                $threadId = $_GET['thread_id'] ?? null;
                
                if (!$entityId || !$threadId) {
                    echo "Error: Missing entity_id or thread_id parameter\n";
                    break;
                }
                
                $found = false;
                foreach ($threads as $entityThreads) {
                    if ($entityThreads->entity_id === $entityId) {
                        foreach ($entityThreads->threads as $thread) {
                            if ($thread->id === $threadId) {
                                $savedEmails = processThreadFolder(
                                    $connection, 
                                    $folderManager, 
                                    $emailProcessor, 
                                    $attachmentHandler,
                                    $entityThreads,
                                    $thread
                                );
                                
                                if (!empty($savedEmails)) {
                                    echo "Saved emails:\n";
                                    foreach ($savedEmails as $email) {
                                        echo "- $email\n";
                                    }
                                } else {
                                    echo "No new emails to save\n";
                                }
                                
                                $found = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if (!$found) {
                    echo "Error: Thread not found\n";
                }
                break;
                
            case 'process-all':
                foreach ($threads as $entityThreads) {
                    foreach ($entityThreads->threads as $thread) {
                        $savedEmails = processThreadFolder(
                            $connection, 
                            $folderManager, 
                            $emailProcessor, 
                            $attachmentHandler,
                            $entityThreads,
                            $thread
                        );
                        
                        if (!empty($savedEmails)) {
                            echo "Saved emails for {$entityThreads->entity_id} - {$thread->title}:\n";
                            foreach ($savedEmails as $email) {
                                echo "- $email\n";
                            }
                            echo "\n";
                        }
                    }
                }
                break;
                
            default:
                echo "Error: Unknown task '$task'\n";
        }
        
        echo "\n";
        echo '<a href="update-imap.php">Back to task selection</a>';
        echo '</pre>';
    }
    
} catch(Exception $e) {
    echo "\n\n";
    echo "Error updating imap:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
