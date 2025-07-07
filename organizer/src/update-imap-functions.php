<?php

use Imap\ImapFolderManager;

require_once __DIR__ . '/class/ThreadEmailMover.php';
require_once __DIR__ . '/class/ImapFolderLog.php';
require_once __DIR__ . '/class/ThreadFolderManager.php';
require_once __DIR__ . '/class/ImapFolderStatus.php';
require_once __DIR__ . '/class/ThreadEmailDatabaseSaver.php';


// Task functions
function createFolders($connection, $folderManager, $threads) {
    $connection->logDebug('---- CREATING FOLDERS ----');
    
    // Start output buffering to capture debug output
    ob_start();
    
    // Create a single log entry at the start
    $logId = ImapFolderLog::createLog('SYSTEM', 'started', "Starting to create required folders");
    
    try {
        $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
        $threadFolderManager->initialize();
        $folders = $threadFolderManager->createRequiredFolders($threads);
        
        // Create ImapFolderStatus records for newly created folders
        $folderStatusCount = 0;
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                $folder = $threadFolderManager->getThreadEmailFolder($entityThreads->entity_id, $thread);
                
                // Only create status records for folders that were just created
                if (in_array($folder, $folders)) {
                    // Connect folder to threadId in ImapFolderStatus
                    if (ImapFolderStatus::createOrUpdate($folder, $thread->id, false, null)) {
                        $folderStatusCount++;
                        $connection->logDebug("Created folder status record for $folder (Thread ID: {$thread->id})");
                    }
                }
            }
        }
        
        // Get the debug output
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the result and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'success', 
            "Successfully created " . count($folders) . " folders and $folderStatusCount folder status records.\n\nDebug log:\n$debugOutput"
        );
        
        return $folders;
    } catch (Exception $e) {
        // Get the debug output even if there was an error
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the error and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'error', 
            "Error creating folders: " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
        );
        
        // Re-throw the exception to be handled by the caller
        throw $e;
    }
}

function archiveFolders($connection, $folderManager, $threads) {
    $connection->logDebug('---- ARCHIVING FOLDERS ----');
    
    // Start output buffering to capture debug output
    ob_start();
    
    // Create a single log entry at the start
    $logId = ImapFolderLog::createLog('SYSTEM', 'started', "Starting to archive folders for archived threads");
    
    try {
        $threadFolderManager = new ThreadFolderManager($connection, $folderManager);
        $threadFolderManager->initialize();
        
        $archivedCount = 0;
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                if ($threadFolderManager->archiveThreadFolder($entityThreads, $thread)) {
                    $archivedCount++;
                }
            }
        }
        
        // Get the debug output
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the result and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'success', 
            "Successfully archived folders for $archivedCount threads.\n\nDebug log:\n$debugOutput"
        );
    } catch (Exception $e) {
        // Get the debug output even if there was an error
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the error and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'error', 
            "Error archiving folders: " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
        );
        
        // Re-throw the exception to be handled by the caller
        throw $e;
    }
}

function processInbox($connection, $folderManager, $emailProcessor, $threads) {
    $connection->logDebug('---- PROCESSING INBOX ----');
    
    // Start output buffering to capture debug output
    ob_start();
    
    // Create a single log entry at the start
    $logId = ImapFolderLog::createLog('INBOX', 'started', "Starting to process INBOX");
    
    try {
        $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
        $emailToFolder = $threadEmailMover->buildEmailToFolderMapping($threads);
        $proccess = $threadEmailMover->processMailbox('INBOX', $emailToFolder);
        $unmatchedAddresses = $proccess['unmatched'];
        
        // Get the debug output
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the result and debug output
        $message = "Successfully processed INBOX. ";
        if (!empty($unmatchedAddresses)) {
            $message .= "Found " . count($unmatchedAddresses) . " unmatched email addresses.";
        } else {
            $message .= "No unmatched email addresses found.";
        }

        if ($proccess['maxed_out']) {
            $message .= " Processing of emails maxed out. Not marking INBOX as updated.";
        }
        else {
            $message .= " Marking INBOX as updated.";
            ImapFolderStatus::createOrUpdate('INBOX', updateLastChecked: true);
        }
        
        ImapFolderLog::updateLog(
            $logId, 
            'success', 
            $message . "\n\nDebug log:\n$debugOutput"
        );
        
        return $unmatchedAddresses;
    } catch (Exception $e) {
        // Get the debug output even if there was an error
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the error and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'error', 
            "Error processing INBOX: " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
        );
        
        // Re-throw the exception to be handled by the caller
        throw $e;
    }
}

function processSentFolder($connection, $folderManager, $emailProcessor, $threads, $imapSentFolder) {
    $connection->logDebug('---- PROCESSING SENT FOLDER ----');
    
    // Start output buffering to capture debug output
    ob_start();
    
    // Create a single log entry at the start
    $logId = ImapFolderLog::createLog($imapSentFolder, 'started', "Starting to process sent folder: $imapSentFolder");
    
    try {
        $threadEmailMover = new ThreadEmailMover($connection, $folderManager, $emailProcessor);
        $emailToFolder = $threadEmailMover->buildEmailToFolderMapping($threads);
        $proccess = $threadEmailMover->processMailbox($imapSentFolder, $emailToFolder);
        $unmatchedAddresses = $proccess['unmatched'];
        
        // Get the debug output
        $debugOutput = ob_get_flush();
        
        if ($proccess['maxed_out']) {
            $debugOutput .= " Processing of emails maxed out. Not marking INBOX.Sent as updated.";
        }
        else {
            $debugOutput .= " Marking INBOX.Sent as updated.";
            ImapFolderStatus::createOrUpdate('INBOX.Sent', updateLastChecked: true);
        }

        // Update the log entry with the result and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'success', 
            "Successfully processed sent folder: $imapSentFolder\n\nDebug log:\n$debugOutput"
        );
        
        return $unmatchedAddresses;
    } catch (Exception $e) {
        // Get the debug output even if there was an error
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the error and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'error', 
            "Error processing sent folder: $imapSentFolder. " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
        );
        
        // Re-throw the exception to be handled by the caller
        throw $e;
    }
}

/**
 * Get thread folders from IMAP server
 * Returns array of folders that are thread folders (starting with INBOX. but not INBOX.Archive.)
 */
function getThreadFoldersFromImap($folderManager) {
    $folders = $folderManager->getExistingFolders();
    return array_filter($folders, function($folder) {
        return strpos($folder, 'INBOX.') === 0 && 
               strpos($folder, 'INBOX.Archive.') === false;
    });
}

/**
 * Process a thread folder - now handled by ThreadScheduledEmailReceiver
 * This function is kept for backward compatibility but now delegates to the new class
 * 
 * @param ImapConnection $connection IMAP connection
 * @param ImapFolderManager $folderManager IMAP folder manager
 * @param ImapEmailProcessor $emailProcessor Email processor
 * @param ImapAttachmentHandler $attachmentHandler Attachment handler
 * @param string $folder Folder name
 * @return array Array of saved email IDs
 * @deprecated Use ThreadScheduledEmailReceiver instead
 */
function processThreadFolder($connection, $folderManager, $emailProcessor, $attachmentHandler, $folder = null) {
    $connection->logDebug("-- $folder");
    
    // Start output buffering to capture debug output
    ob_start();
    
    // Create a single log entry at the start
    $logId = ImapFolderLog::createLog($folder, 'started', "Starting to process folder: $folder");
    
    try {
        $threadEmailDbSaver = new ThreadEmailDatabaseSaver($connection, $emailProcessor, $attachmentHandler);
        $savedEmails = $threadEmailDbSaver->saveThreadEmails($folder);
        
        // Get the debug output
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the result and debug output
        if (!empty($savedEmails)) {
            ImapFolderLog::updateLog(
                $logId, 
                'success', 
                "Successfully processed folder: $folder. Saved " . count($savedEmails) . " emails.\n\nDebug log:\n$debugOutput"
            );
        } else {
            ImapFolderLog::updateLog(
                $logId, 
                'info', 
                "No new emails to save in folder: $folder\n\nDebug log:\n$debugOutput"
            );
        }
        
        return $savedEmails;
    } catch (Exception $e) {
        // Get the debug output even if there was an error
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the error and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'error', 
            "Error processing folder: $folder. " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
        );
        
        // Re-throw the exception to be handled by the caller
        throw $e;
    }
}

/**
 * Create IMAP folder status records for all IMAP folders
 * 
 * @param ImapFolderManager $folderManager IMAP folder manager
 * @param ThreadFolderManager $threadFolderManager
 * @param array $threads Array of thread objects
 * @return int Number of records created
 */
function createImapFolderStatusRecords(ImapFolderManager $folderManager, ThreadFolderManager $threadFolderManager, $threads) {
    // Start output buffering to capture debug output
    ob_start();
    
    // Create a single log entry at the start
    $logId = ImapFolderLog::createLog('SYSTEM', 'started', "Starting to create folder status records");
    
    try {
        $count = 0;

        // folder => $thread_id
        $threadFolders = array();
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                
                $folder = $threadFolderManager->getThreadEmailFolder($entityThreads->entity_id, $thread);
                $threadFolders[$folder] = $thread->id;
            }
        }
        
        // Get all folders from IMAP server
        $folders = $folderManager->getExistingFolders();
        
        // Process each folder
        foreach ($folders as $folderName) {
            // Try to find thread ID for this folder
            $threadId = isset($threadFolders[$folderName]) ? $threadFolders[$folderName] : null;
            
            // Create folder status record
            if (ImapFolderStatus::createOrUpdate($folderName, $threadId)) {
                $count++;
            }
        }
        
        // Get the debug output
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the result and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'success', 
            "Successfully created/updated $count folder status records.\n\nDebug log:\n$debugOutput"
        );
        
        return $count;
    } catch (Exception $e) {
        // Get the debug output even if there was an error
        $debugOutput = ob_get_flush();
        
        // Update the log entry with the error and debug output
        ImapFolderLog::updateLog(
            $logId, 
            'error', 
            "Error creating folder status records: " . $e->getMessage() . "\n\nDebug log:\n$debugOutput"
        );
        
        // Re-throw the exception to be handled by the caller
        throw $e;
    }
}