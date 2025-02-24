<?php

require_once __DIR__ . '/auth.php';

set_time_limit(0);
ini_set('memory_limit', '-1');

// Require authentication
requireAuth();

require_once __DIR__ . '/class/ThreadFolderManager.php';
require_once __DIR__ . '/class/ThreadEmailMover.php';
require_once __DIR__ . '/class/ThreadEmailSaver.php';
require_once __DIR__ . '/class/Threads.php';
require_once __DIR__ . '/class/ThreadStorageManager.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

// Load IMAP credentials
require_once __DIR__ . '/username-password.php';

echo '<pre>';

try {
    // Initialize IMAP connection and components
    $connection = new ImapConnection($imapServer, $yourEmail, $yourEmailPassword, true);
    $connection->openConnection();
    
    $folderManager = new ImapFolderManager($connection);
    $folderManager->initialize();  // Initialize folder manager to populate existing folders
    $emailProcessor = new ImapEmailProcessor($connection);
    $attachmentHandler = new ImapAttachmentHandler($connection);
    
    // Initialize managers
    $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
    $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
    $threadEmailSaver = new ThreadEmailSaver($connection, $emailProcessor, $attachmentHandler);
    
    // Get threads
    $threads = ThreadStorageManager::getInstance()->getThreads();
    
    // Initialize and create folders
    $connection->logDebug('---- CREATING FOLDERS ----');
    $threadFolderManager->initialize();
    $threadFolderManager->createRequiredFolders($threads);
    
    // Archive folders if needed
    $connection->logDebug('---- ARCHIVING FOLDERS ----');
    foreach ($threads as $entityThreads) {
        foreach ($entityThreads->threads as $thread) {
            $threadFolderManager->archiveThreadFolder($entityThreads, $thread);
        }
    }
    
    // Build email to folder mapping
    $emailToFolder = $threadEmailMover->buildEmailToFolderMapping($threads);
    
    // Process INBOX
    $connection->logDebug('---- PROCESSING INBOX ----');
    $unmatchedAddresses = $threadEmailMover->processMailbox('INBOX', $emailToFolder);
    foreach ($unmatchedAddresses as $address) {
        echo '- <a href="start-thread.php?my_email=' . urlencode($address) . 
             '">Start thread with ' . htmlspecialchars($address) . '</a>' . PHP_EOL;
    }
    $connection->closeConnection(CL_EXPUNGE);
    
    // Process Sent folder
    $connection->logDebug('---- PROCESSING SENT FOLDER ----');
    $connection = new ImapConnection($imapServer, $yourEmail, $yourEmailPassword, true);
    $connection->openConnection($imapSentFolder);
    
    $folderManager = new ImapFolderManager($connection);
    $folderManager->initialize();  // Initialize folder manager to populate existing folders
    $emailProcessor = new ImapEmailProcessor($connection);
    $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
    
    $threadEmailMover->processMailbox($imapSentFolder, $emailToFolder);
    $connection->closeConnection(CL_EXPUNGE);
    
    // Save thread emails
    $connection->logDebug('---- SAVING THREAD EMAILS ----');
    foreach ($threads as $threadFile => $entityThreads) {
        foreach ($entityThreads->threads as $thread) {
            $folder = $threadFolderManager->getThreadEmailFolder($entityThreads, $thread);
            $connection->logDebug("-- $folder");
            
            $folderJson = '/organizer-data/threads/' . $entityThreads->entity_id . '/' . $thread->id;
            $connection->logDebug("   Folder ... : $folderJson");
            
            // Skip if already archived
            if (file_exists($folderJson . '/archiving_finished.json')) {
                if (!$thread->archived) {
                    unlink($folderJson . '/archiving_finished.json');
                } else {
                    continue;
                }
            }
            
            // Check if folder needs update
            if (isset($_GET['update-only-before']) && 
                !$emailProcessor->needsUpdate($folderJson, $_GET['update-only-before'])) {
                continue;
            }
            
            try {
                $connection = new ImapConnection($imapServer, $yourEmail, $yourEmailPassword, true);
                $connection->openConnection($folder);
                
                $folderManager = new ImapFolderManager($connection);
                $folderManager->initialize();  // Initialize folder manager to populate existing folders
                $emailProcessor = new ImapEmailProcessor($connection);
                $attachmentHandler = new ImapAttachmentHandler($connection);
                $threadEmailSaver = new ThreadEmailSaver($connection, $emailProcessor, $attachmentHandler);
                
                $threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);
                $threadEmailSaver->finishThreadProcessing($folderJson, $thread);
                
            } catch(Exception $e) {
                $connection->logDebug('ERROR during thread email processing.');
                $connection->logDebug($e->getMessage());
                $connection->logDebug($e->getTraceAsString());
                throw $e;
            }
            
            $connection->logDebug('');
        }
        saveEntityThreads($entityThreads->entity_id, $entityThreads);
    }
    
} catch(Exception $e) {
    echo "\n\n";
    echo "Error updating imap:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
