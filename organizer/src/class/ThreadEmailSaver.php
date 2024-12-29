<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/Imap/ImapAttachmentHandler.php';

use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

class ThreadEmailSaver {
    private ImapConnection $connection;
    private ImapEmailProcessor $emailProcessor;
    private ImapAttachmentHandler $attachmentHandler;

    public function __construct(
        ImapConnection $connection,
        ImapEmailProcessor $emailProcessor,
        ImapAttachmentHandler $attachmentHandler
    ) {
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
        if (!file_exists($folderJson)) {
            mkdir($folderJson, 0777, true);
        }

        $savedEmails = [];
        $emails = $this->emailProcessor->processEmails($folder);
        
        foreach ($emails as $email) {
            $direction = $this->emailProcessor->getEmailDirection($email->mailHeaders, $thread->my_email);
            $filename = $this->emailProcessor->generateEmailFilename($email->mailHeaders, $thread->my_email);
            
            // Save raw email
            $emailRawFile = $folderJson . '/' . $filename . '.eml';
            if (!file_exists($emailRawFile)) {
                file_put_contents(
                    $emailRawFile, 
                    imap_fetchbody($this->connection->getConnection(), $email->uid, "", FT_UID)
                );
            }
            
            // Save email metadata
            $emailJsonFile = $folderJson . '/' . $filename . '.json';
            if (!file_exists($emailJsonFile)) {
                // Process attachments
                $attachments = $this->attachmentHandler->processAttachments($email->uid);
                foreach ($attachments as $i => $attachment) {
                    $attachment->location = $filename . ' - att ' . $i . '-' . 
                                         md5($attachment->name) . '.' . $attachment->filetype;
                    
                    $attachmentPath = $folderJson . '/' . $attachment->location;
                    if (!file_exists($attachmentPath)) {
                        $this->attachmentHandler->saveAttachment(
                            $email->uid, 
                            $i + 1, 
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
                $newEmail->status_type = 'unknown';
                $newEmail->status_text = 'Uklassifisert';
                $newEmail->ignore = false;
                
                if (!empty($email->attachments)) {
                    $newEmail->attachments = array_map(function($att) {
                        $att->status_type = 'unknown';
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
                
                $savedEmails[] = $filename;
            }
        }
        
        return $savedEmails;
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
        if ($thread->archived) {
            file_put_contents(
                $folderJson . '/archiving_finished.json',
                '{"date": "' . date('Y-m-d H:i:s') . '"}'
            );
        }

        $this->emailProcessor->updateFolderCache($folderJson);
    }
}
