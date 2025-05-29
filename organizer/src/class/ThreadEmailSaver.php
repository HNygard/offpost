<?php

require_once __DIR__ . '/ThreadEmailHistory.php';
require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/Imap/ImapAttachmentHandler.php';

use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

class ThreadEmailSaver {
    private \Imap\ImapConnection $connection;
    private \Imap\ImapEmailProcessor $emailProcessor;
    private \Imap\ImapAttachmentHandler $attachmentHandler;
    private ThreadEmailHistory $emailHistory;

    public function __construct(
        \Imap\ImapConnection $connection,
        \Imap\ImapEmailProcessor $emailProcessor,
        \Imap\ImapAttachmentHandler $attachmentHandler
    ) {
        $this->emailHistory = new ThreadEmailHistory();
        $this->connection = $connection;
        $this->emailProcessor = $emailProcessor;
        $this->attachmentHandler = $attachmentHandler;
    }

    /**
     * Save emails for a thread
     * 
     * @param string $folderJson Path to save thread data
     * @param object $thread Thread object
     * @param string $folder IMAP folder to process
     * @return array Array of saved email IDs
     */
    public function saveThreadEmails(string $folderJson, object $thread, string $folder): array {
        $lockFile = $folderJson . '/thread.lock';
        
        try {
            $savedEmails = [];

            // Create directory if it doesn't exist
            if (!file_exists($folderJson)) {
                if (!@mkdir($folderJson, 0777, true)) {
                    throw new Exception('ImapConnection-errorHandler: mkdir(): Permission denied: ' . $folderJson);
                }
            }

            // Check for concurrent access
            if (file_exists($lockFile)) {
                throw new Exception('Thread is locked');
            }

            // Create lock file
            if (!@file_put_contents($lockFile, date('Y-m-d H:i:s'))) {
                throw new Exception('Failed to create lock file');
            }

            $emails = $this->emailProcessor->getEmails($folder);
            
            foreach ($emails as $email) {
                if (!isset($email->mailHeaders) || !is_object($email->mailHeaders)) {
                    throw new Exception('Failed to process email: Invalid email headers');
                }

                try {

                    $direction = $email->getEmailDirection($thread->my_email);
                    $filename = $email->generateEmailFilename($thread->my_email);
                
                    // Save raw email
                    $emailRawFile = $folderJson . '/' . $filename . '.eml';
                    if (!file_exists($emailRawFile)) {
                        $rawEmail = $this->connection->getRawEmail($email->uid);
                        if (!$rawEmail) {
                            throw new Exception('Connection lost');
                        }
                        if (!@file_put_contents($emailRawFile, $rawEmail)) {
                            throw new Exception('Failed to save raw email');
                        }
                    }
                } catch (Exception $e) {
                    throw new Exception('Failed to process email: ' . $e->getMessage(), 0, $e);
                }
            
                // Save email metadata
                $emailJsonFile = $folderJson . '/' . $filename . '.json';
                if (!file_exists($emailJsonFile)) {
                    // Process attachments
                    $attachments = $this->attachmentHandler->processAttachments($email->uid);
                    foreach ($attachments as $i => $attachment) {
                        $j = $i + 1;
                        $attachment->location = $filename . ' - att ' . $j . '-' .
                                             md5($attachment->name) . '.' . $attachment->filetype;
                        
                        $attachmentPath = $folderJson . '/' . $attachment->location;
                        if (!file_exists($attachmentPath)) {
                            $this->attachmentHandler->saveAttachment(
                                $email->uid,
                                $j + 1,
                                $attachment, 
                                $attachmentPath
                            );
                        }
                    }
                    
                    $email->attachments = $attachments;
                    file_put_contents($emailJsonFile, json_encode($email, 
                        JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE));
                }
                
                // Update thread email list
                if (!isset($thread->emails)) {
                    $thread->emails = [];
                }
                
                if (!$this->emailExistsInThread($thread, $filename)) {
                    $newEmail = new \stdClass();
                    $newEmail->timestamp_received = $email->timestamp;
                    $newEmail->datetime_received = date('Y-m-d H:i:s', $newEmail->timestamp_received);
                    $newEmail->datetime_first_seen = date('Y-m-d H:i:s');
                    $newEmail->id = $filename;
                    $newEmail->email_type = $direction;
                    $newEmail->status_type = \App\Enums\ThreadEmailStatusType::UNKNOWN;
                    $newEmail->status_text = 'Uklassifisert';
                    $newEmail->ignore = false;
                    
                    if (!empty($email->attachments)) {
                        $newEmail->attachments = array_map(function($att) {
                            $att->status_type = \App\Enums\ThreadEmailStatusType::UNKNOWN;
                            $att->status_text = 'uklassifisert-dok';
                            return $att;
                        }, $email->attachments);
                    }
                    
                    $thread->emails[] = $newEmail;
                    usort($thread->emails, function($a, $b) {
                        return strcmp($a->datetime_received, $b->datetime_received);
                    });
                    
                    if (!in_array('uklassifisert-epost', $thread->labels)) {
                        $thread->labels[] = 'uklassifisert-epost';
                    }
                    
                    // Log email received in history
                    $this->emailHistory->logAction(
                        $thread->id,
                        $newEmail->id,
                        'received',
                        'system'
                    );

                    $savedEmails[] = $filename;
                }
            }

            return $savedEmails;
        } finally {
            // Always remove lock file if it exists
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    /**
     * Check if email exists in thread
     */
    private function emailExistsInThread(object $thread, string $emailId): bool {
        if (!isset($thread->emails)) {
            return false;
        }
        
        foreach ($thread->emails as $email) {
            if ($email->id === $emailId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Update folder cache and set archiving status
     */
    public function finishThreadProcessing(string $folderJson, object $thread): void {
        try {
            $this->emailProcessor->updateFolderCache($folderJson);

            if ($thread->archived) {
                if (!@file_put_contents(
                    $folderJson . '/archiving_finished.json',
                    '{"date": "' . date('Y-m-d H:i:s') . '"}'
                )) {
                    throw new Exception('Failed to write archiving status');
                }
            }

        } finally {
            // Always remove lock file if it exists
            $lockFile = $folderJson . '/thread.lock';
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }
}
