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

echo '<pre>';

echo ":: IMAP setting\n";
echo "Server ..... : $imapServer\n";
echo "Username ... : $imap_username\n";
echo "\n\n";

try {
    // Initialize IMAP connection and components
    $connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
    $connection->openConnection();
    
    $folderManager = new ImapFolderManager($connection);
    $folderManager->initialize();  // Initialize folder manager to populate existing folders
    $emailProcessor = new ImapEmailProcessor($connection);
    $attachmentHandler = new ImapAttachmentHandler($connection);
    
    // Initialize managers
    $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
    $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
    $threadEmailSaver = new ThreadEmailSaver($connection, $emailProcessor, $attachmentHandler);
    $threadEmailDbSaver = new ThreadEmailDatabaseSaver($connection, $emailProcessor, $attachmentHandler);
    
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
    $connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
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
            
            try {
                $connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
                $connection->openConnection($folder);
                
                $folderManager = new ImapFolderManager($connection);
                $folderManager->initialize();  // Initialize folder manager to populate existing folders
                $emailProcessor = new ImapEmailProcessor($connection);
                $attachmentHandler = new ImapAttachmentHandler($connection);
                $threadEmailDbSaver = new ThreadEmailDatabaseSaver($connection, $emailProcessor, $attachmentHandler);
                
                $savedEmails = $threadEmailDbSaver->saveThreadEmails($entityThreads->entity_id, $thread, $folder);
                if (!empty($savedEmails)) {
                    $connection->logDebug("   Saved " . count($savedEmails) . " emails");
                    
                    // Update thread in database
                    ThreadStorageManager::getInstance()->updateThread($thread);
                }
                
                $threadEmailDbSaver->finishThreadProcessing($thread);
                
            } catch(Exception $e) {
                $connection->logDebug('ERROR during thread email processing.');
                $connection->logDebug($e->getMessage());
                $connection->logDebug($e->getTraceAsString());
                throw $e;
            }
            
            $connection->logDebug('');
        }
    }
    
} catch(Exception $e) {
    echo "\n\n";
    echo "Error updating imap:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
