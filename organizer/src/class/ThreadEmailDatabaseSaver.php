<?php

require_once __DIR__ . '/ThreadEmailHistory.php';
require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/Imap/ImapAttachmentHandler.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ThreadEmail.php';
require_once __DIR__ . '/ThreadEmailAttachment.php';

use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

class ThreadEmailDatabaseSaver {
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
     * Save emails for a thread to the database
     * 
     * @param string $entityId Entity ID
     * @param object $thread Thread object
     * @param string $folder IMAP folder to process
     * @return array Array of saved email IDs
     */
    public function saveThreadEmails(string $folder): array {
        try {
            $savedEmails = [];
            
            // Use a database transaction for concurrency control
            Database::beginTransaction();
            
            $emails = $this->emailProcessor->getEmails($folder);
            
            foreach ($emails as $email) {
                if (!isset($email->mailHeaders) || !is_object($email->mailHeaders)) {
                    throw new Exception('Failed to process email: Invalid email headers');
                }

                # Figure out which thread this email is part of
                $all_emails = $this->emailProcessor->getEmailAddresses($email->mailHeaders);
                $threads = Database::query(
                    "SELECT id, my_email FROM threads WHERE my_email IN (" . implode(',', array_fill(0, count($all_emails), '?')) . ")",
                    $all_emails
                );
                if (count($threads) == 0) {
                    throw new Exception('Failed to process email: No matching thread found');
                }
                if (count($threads) > 1) {
                    throw new Exception('Failed to process email: Multiple matching threads found');
                }
                $thread = Thread::loadFromDatabase($threads[0]['id']);

                try {
                    $direction = $this->emailProcessor->getEmailDirection($email->mailHeaders, $thread->my_email);
                    $filename = $this->emailProcessor->generateEmailFilename($email->mailHeaders, $thread->my_email);
                    
                    // Check if email already exists in database
                    if ($this->emailExistsInDatabase($thread->id, $filename)) {
                        $this->connection->logDebug("Already existing email .. : $filename");
                    }
                    else {
                        $this->connection->logDebug("Saving to database ...... : $filename");

                        // Get raw email content
                        $rawEmail = $this->connection->getRawEmail($email->uid);
                        if (!$rawEmail) {
                            throw new Exception('Connection lost');
                        }
                        
                        // Create new email record in database
                        $emailId = $this->saveEmailToDatabase($thread->id, $email, $direction, $filename, $rawEmail, $email);
                        
                        // Process attachments
                        $attachments = $this->attachmentHandler->processAttachments($email->uid);
                        foreach ($attachments as $i => $attachment) {
                            $j = $i + 1;
                            $attachment->location = $filename . ' - att ' . $j . '-' .
                                                 md5($attachment->name) . '.' . $attachment->filetype;
                            
                            // Save attachment file to disk
                            $attachmentPath = joinPaths(THREADS_DIR, $thread->entity_id, $thread->id, $attachment->location);
                            $attachmentDir = dirname($attachmentPath);
                            
                            if (!file_exists($attachmentDir)) {
                                if (!@mkdir($attachmentDir, 0777, true)) {
                                    throw new Exception('ImapConnection-errorHandler: mkdir(): Permission denied: ' . $attachmentDir);
                                }
                            }
                            
                            if (!file_exists($attachmentPath)) {
                                $this->attachmentHandler->saveAttachment(
                                    $email->uid,
                                    $j + 1,
                                    $attachment, 
                                    $attachmentPath
                                );
                            }
                            
                            // Save attachment metadata to database
                            $this->saveAttachmentToDatabase($emailId, $attachment, $this->attachmentHandler->getAttachmentContent($email->uid, $j + 1));
                        }
                        
                        // Update thread email list
                        if (!isset($thread->emails)) {
                            $thread->emails = [];
                        }
                        
                        $newEmail = new ThreadEmail();
                        $newEmail->timestamp_received = $email->timestamp;
                        $newEmail->datetime_received = date('Y-m-d H:i:s', $newEmail->timestamp_received);
                        $newEmail->id = $emailId;
                        $newEmail->id_old = $filename;
                        $newEmail->email_type = $direction;
                        $newEmail->status_type = 'unknown';
                        $newEmail->status_text = 'Uklassifisert';
                        $newEmail->ignore = false;
                        
                        if (!empty($attachments)) {
                            $newEmail->attachments = array_map(function($att) {
                                $att->status_type = 'unknown';
                                $att->status_text = 'uklassifisert-dok';
                                return $att;
                            }, $attachments);
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
                            $filename, // Use filename as id_old for history compatibility
                            'received',
                            'system'
                        );

                        $savedEmails[] = $filename;
                    }
                } catch (Exception $e) {
                    Database::rollBack();
                    throw new Exception('Exception during processing of email [' . $email->uid . '][' . $email->subject . ']', 0, $e);
                }
            }
            
            // Commit the transaction
            Database::commit();
            
            return $savedEmails;
        } catch (Exception $e) {
            // Ensure transaction is rolled back on error
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw new Exception('Rolling back DB transaction', 0, $e);
        }
    }

    /**
     * Check if email exists in database
     * 
     * @param string $threadId Thread ID
     * @param string $emailIdOld Email ID (old format)
     * @return bool True if email exists
     */
    private function emailExistsInDatabase(string $threadId, string $emailIdOld): bool {
        $count = Database::queryValue(
            "SELECT COUNT(*) FROM thread_emails WHERE thread_id = ? AND id_old = ?",
            [$threadId, $emailIdOld]
        );
        
        return $count > 0;
    }

    /**
     * Save email to database
     * 
     * @param string $threadId Thread ID
     * @param object $email Email object
     * @param string $direction Email direction
     * @param string $filename Email filename (used as id_old)
     * @param string $rawEmail Raw email content
     * @return string UUID of the saved email
     */
    private function saveEmailToDatabase(string $threadId, object $email, string $direction, string $filename, string $rawEmail, stdClass $imap_headers): string {
        $query = "
            INSERT INTO thread_emails (
                thread_id, 
                timestamp_received, 
                datetime_received, 
                ignore, 
                email_type, 
                status_type, 
                status_text, 
                content,
                imap_headers,
                id_old
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ";
        
        $params = [
            $threadId,
            date('Y-m-d H:i:s', $email->timestamp),
            date('Y-m-d H:i:s', $email->timestamp),
            'f', // PostgreSQL boolean false
            $direction,
            'unknown',
            'Uklassifisert',
            $rawEmail,
            json_encode($imap_headers, JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES),
            $filename
        ];
        
        $result = Database::queryValue($query, $params);
        
        if (!$result) {
            throw new Exception('Failed to save email to database');
        }
        
        return $result;
    }

    /**
     * Save attachment metadata to database
     * 
     * @param string $emailId Email ID
     * @param object $attachment Attachment object
     * @return int ID of the saved attachment
     */
    private function saveAttachmentToDatabase(string $emailId, object $attachment, $content): int {
        $query = "
            INSERT INTO thread_email_attachments (
                email_id, 
                name, 
                filename, 
                filetype, 
                location, 
                status_type, 
                status_text,
                content
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?::bytea)
            RETURNING id
        ";
        
        $params = [
            $emailId,
            $attachment->name,
            $attachment->filename,
            $attachment->filetype,
            $attachment->location,
            'unknown',
            'uklassifisert-dok',
            $content
        ];
        
        $result = Database::queryValue($query, $params);
        
        if (!$result) {
            throw new Exception('Failed to save attachment to database');
        }
        
        return $result;
    }

    /**
     * Update thread archiving status
     */
    public function finishThreadProcessing(object $thread): void {
        if ($thread->archived) {
            // Update thread in database to mark as archived
            Database::execute(
                "UPDATE threads SET archived = true WHERE id = ?",
                [$thread->id]
            );
        }
    }
}
