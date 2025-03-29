<?php

require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/Imap/ImapAttachmentHandler.php';
require_once __DIR__ . '/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/ImapFolderStatus.php';
require_once __DIR__ . '/ImapFolderLog.php';
require_once __DIR__ . '/Database.php';

use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

/**
 * Class for handling scheduled email receiving from IMAP folders
 */
class ThreadScheduledEmailReceiver {
    private $connection;
    private $emailProcessor;
    private $attachmentHandler;
    private $emailDbSaver;
    private $imapFolderLog;
    private $imapFolderStatus;
    
    /**
     * Constructor
     * 
     * @param ImapConnection $connection IMAP connection instance
     * @param ImapEmailProcessor $emailProcessor Email processor instance
     * @param ImapAttachmentHandler $attachmentHandler Attachment handler instance
     */
    public function __construct(
        ImapConnection $connection = null,
        ImapEmailProcessor $emailProcessor = null,
        ImapAttachmentHandler $attachmentHandler = null
    ) {
        if ($connection === null || $emailProcessor === null || $attachmentHandler === null) {
            // Initialize IMAP connection and components if not provided
            require __DIR__ . '/../username-password.php';
            $this->connection = new ImapConnection($imapServer, $imap_username, $imap_password, true);
            $this->connection->openConnection();
            
            $this->emailProcessor = new ImapEmailProcessor($this->connection);
            $this->attachmentHandler = new ImapAttachmentHandler($this->connection);
        } else {
            $this->connection = $connection;
            $this->emailProcessor = $emailProcessor;
            $this->attachmentHandler = $attachmentHandler;
        }
        
        $this->emailDbSaver = new ThreadEmailDatabaseSaver(
            $this->connection,
            $this->emailProcessor,
            $this->attachmentHandler
        );
        
    }
    
    /**
     * Start output buffering to capture debug output
     * This method can be overridden in tests to avoid actual output buffering
     */
    protected function startOutputBuffer() {
        ob_start();
    }
    
    /**
     * Get the output buffer contents and end output buffering
     * This method can be overridden in tests to avoid actual output buffering
     * 
     * @return string The contents of the output buffer
     */
    protected function getOutputBuffer() {
        return ob_get_flush();
    }
    
    /**
     * Find and process the next folder based on ImapFolderStatus
     * 
     * @return array Result of the operation
     */
    public function processNextFolder() {
        // Find the next folder to process based on ImapFolderStatus
        $nextFolder = $this->findNextFolderForProcessing();
        
        if (!$nextFolder) {
            return [
                'success' => false,
                'message' => 'No folders ready for processing'
            ];
        }
        
        // Start output buffering to capture debug output
        $this->startOutputBuffer();
        
        // Create a log entry for this processing
        $logId = ImapFolderLog::createLog(
            $nextFolder['folder_name'], 
            'started', 
            "Starting scheduled processing of folder: {$nextFolder['folder_name']}"
        );
        
        try {
            // Process the folder
            $savedEmails = $this->emailDbSaver->saveThreadEmails($nextFolder['folder_name']);
            
            // Get the debug output
            $debugOutput = $this->getOutputBuffer();
            
            // Update the folder's last_checked_at timestamp
            ImapFolderStatus::createOrUpdate($nextFolder['folder_name'], null, true);
            
            // Update the log entry with success
            if (!empty($savedEmails)) {
                ImapFolderLog::updateLog(
                    $logId, 
                    'success', 
                    "Successfully processed folder: {$nextFolder['folder_name']}. Saved " . 
                    count($savedEmails) . " emails.\n\nDebug log:\n$debugOutput"
                );
                
                return [
                    'success' => true,
                    'message' => "Successfully processed folder: {$nextFolder['folder_name']}",
                    'folder_name' => $nextFolder['folder_name'],
                    'thread_id' => $nextFolder['thread_id'],
                    'saved_emails' => count($savedEmails),
                    'email_ids' => $savedEmails
                ];
            } else {
                ImapFolderLog::updateLog(
                    $logId, 
                    'info', 
                    "No new emails to save in folder: {$nextFolder['folder_name']}\n\nDebug log:\n$debugOutput"
                );
                
                return [
                    'success' => true,
                    'message' => "No new emails in folder: {$nextFolder['folder_name']}",
                    'folder_name' => $nextFolder['folder_name'],
                    'thread_id' => $nextFolder['thread_id'],
                    'saved_emails' => 0
                ];
            }
        } catch (Exception $e) {
            // Get the debug output even if there was an error
            $debugOutput = $this->getOutputBuffer();
            
            // Update the log entry with the error
            ImapFolderLog::updateLog(
                $logId, 
                'error', 
                "Error processing folder: {$nextFolder['folder_name']}. " . 
                $e->getMessage() . "\n\nDebug log:\n$debugOutput"
            );
            
            return [
                'success' => false,
                'message' => "Error processing folder: {$nextFolder['folder_name']}. " . $e->getMessage(),
                'folder_name' => $nextFolder['folder_name'],
                'thread_id' => $nextFolder['thread_id'],
                'error' => $e->getMessage(),
                'debug' => $debugOutput
            ];
        }
    }
    
    /**
     * Find the next folder to process based on ImapFolderStatus
     * 
     * @return array|null Folder record or null if none found
     */
    protected function findNextFolderForProcessing() {
        // Get all folder status records
        $folders = Database::query(
            "SELECT fs.*
             FROM imap_folder_status fs
             WHERE fs.folder_name LIKE 'INBOX.%' 
               AND fs.folder_name NOT LIKE 'INBOX.Archive.%'
             ORDER BY fs.last_checked_at ASC NULLS FIRST
             LIMIT 1"
        );
        
        return !empty($folders) ? $folders[0] : null;
    }
    
    /**
     * Close the IMAP connection
     */
    public function close() {
        if ($this->connection) {
            $this->connection->closeConnection();
        }
    }
    
    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct() {
        $this->close();
    }
}
