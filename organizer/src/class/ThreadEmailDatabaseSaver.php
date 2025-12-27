<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
use App\Enums\ThreadEmailStatusType;
require_once __DIR__ . '/ThreadEmailHistory.php';
require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/Imap/ImapAttachmentHandler.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadEmail.php';
require_once __DIR__ . '/ThreadEmailAttachment.php';
require_once __DIR__ . '/ImapFolderStatus.php';
require_once __DIR__ . '/ThreadFolderManager.php';
require_once __DIR__ . '/ThreadStorageManager.php';
require_once __DIR__ . '/ThreadEmailProcessingErrorManager.php';

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
     * @param string $folder IMAP folder to process
     * @return array Array of saved email IDs
     */
    public function saveThreadEmails(string $folder): array {
        try {
            $savedEmails = [];
            $last_thread_id = '?';
            
            // Use a database transaction for concurrency control
            Database::beginTransaction();
            
            $emails = $this->emailProcessor->getEmails($folder);
            
            foreach ($emails as $email) {
                if (!isset($email->mailHeaders) || !is_object($email->mailHeaders)) {
                    throw new Exception('Failed to process email: Invalid email headers');
                }

                // Get raw email content
                $rawEmail = $this->connection->getRawEmail($email->uid);
                if (!$rawEmail) {
                    throw new Exception('Connection lost');
                }

                # Figure out which thread this email is part of
                $all_emails = $email->getEmailAddresses($rawEmail);

                $email_identifier = date('Y-m-d__His', $email->timestamp) . '__' . md5($email->subject);
                
                // First check if the email is manually mapped to a thread
                $mapped_threads = Database::query(
                    "SELECT t.id, t.my_email 
                     FROM thread_email_mapping m 
                     JOIN threads t ON m.thread_id = t.id 
                     WHERE m.email_identifier = ?",
                    [$email_identifier]
                );
                
                // If we found mapped threads, use those
                if (count($mapped_threads) > 0) {
                    $threads = $mapped_threads;
                } 
                else {
                    // Otherwise fall back to the default behavior of matching against my_email
                    $threads = Database::query(
                        "SELECT id, my_email FROM threads WHERE my_email IN (" . implode(',', array_fill(0, count($all_emails), '?')) . ")",
                        $all_emails
                    );
                }

                if (count($threads) == 0 || count($threads) > 1) {
                    // Try to figure out the thread id based on folder
                    $threads = ThreadStorageManager::getInstance()->getThreads();
                    $thread_id = $last_thread_id;
                    foreach ($threads as $entityThreads) {
                        foreach ($entityThreads->threads as $thread) {
                            if (ThreadFolderManager::getThreadEmailFolder($thread->entity_id, $thread) == $folder) {
                                $thread_id = $thread->id;
                                break;
                            }
                        }
                    }

                    $error_type = (count($threads) == 0 ? 'no_matching_thread' : 'multiple_matching_threads');
                    $message = (count($threads) == 0 ? 
                        'No matching thread found for email(s): ' . implode(', ', $all_emails) :
                        'Multiple matching threads found for email(s): ' . implode(', ', $all_emails)
                    );

                    // Commit current transaction to save error record separately
                    // This ensures the error is persisted even if processing fails
                    if (Database::getInstance()->inTransaction()) {
                        Database::commit();
                    }
                    
                    // Save error to database for GUI resolution in a separate transaction
                    Database::beginTransaction();
                    $this->saveEmailProcessingError(
                        $email_identifier,
                        $email->subject,
                        implode(', ', $all_emails),
                        $error_type,
                        $message,
                        $thread_id !== '?' ? $thread_id : null,
                        $folder
                    );
                    Database::commit();

                    throw new Exception("Failed to process email:\n"
                        . $message . "\n"
                        . "Email subject: " . $email->subject . "\n"
                        . "Email identifier: " . $email_identifier . "\n"
                        . "Query to insert mapping: \n"
                        . "INSERT INTO thread_email_mapping (email_identifier, thread_id, description) VALUES ('$email_identifier', '$thread_id', '');"
                    );
                }
                $thread = Thread::loadFromDatabase($threads[0]['id']);
                $last_thread_id = $thread->id;

                try {
                    $direction = $email->getEmailDirection($thread->my_email);
                    $filename = $email->generateEmailFilename($thread->my_email);
                    
                    // Check if email already exists in database
                    if ($this->emailExistsInDatabase($thread->id, $filename)) {
                        $this->connection->logDebug("Already existing email .. thread_id[$thread->id][$thread->my_email]: $filename");
                    }
                    else {
                        $this->connection->logDebug("Saving to database ...... thread_id[$thread->id][$thread->my_email]: $filename");
                        
                        // Create new email record in database
                        $emailId = $this->saveEmailToDatabase($thread->id, $email, $direction, $filename, $rawEmail, $email->mailHeaders);
                        
                        // Process attachments
                        $attachments = $this->attachmentHandler->processAttachments($email->uid);
                        foreach ($attachments as $i => $attachment) {
                            $j = $i + 1;
                            $attachment->location = $filename . ' - att ' . $j . '-' .
                                                 md5($attachment->name) . '.' . $attachment->filetype;
                            
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
                        $newEmail->status_type = ThreadEmailStatusType::UNKNOWN;
                        $newEmail->status_text = 'Uklassifisert';
                        $newEmail->ignore = false;
                        
                        if (!empty($attachments)) {
                            $newEmail->attachments = array_map(function($att) {
                                $att->status_type = ThreadEmailStatusType::UNKNOWN;
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

                        $savedEmails[] = $emailId;
                    }
                } catch (Exception $e) {
                    Database::rollBack();
                    throw new Exception('Exception during processing of email [' . $email->uid . '][' . $email->subject . ']', 0, $e);
                }
            }
            
            // Always update the last checked timestamp, even if no new emails were found
            ImapFolderStatus::createOrUpdate($folder, null, true);
            
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
            ) VALUES (:thread_id, :timestamp_received, :datetime_received, :ignore, :email_type, :status_type, :status_text, :content, :imap_headers, :id_old)
            RETURNING id
        ";
        
        $params = [
            ':thread_id' => $threadId,
            ':timestamp_received' => date('Y-m-d H:i:s', $email->timestamp),
            ':datetime_received' => date('Y-m-d H:i:s', $email->timestamp),
            ':ignore' => 'f', // PostgreSQL boolean false
            ':email_type' => $direction,
            ':status_type' => ThreadEmailStatusType::UNKNOWN->value,
            ':status_text' => 'Uklassifisert',
            ':imap_headers' => json_encode($imap_headers, JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES),
            ':id_old' => $filename
        ];
        
        // Handle binary content separately
        $binaryParams = [
            ':content' => $rawEmail
        ];
        
        $result = Database::queryValueWithBinaryParam($query, $params, $binaryParams);
        
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
     * @param mixed $content Binary content of the attachment
     * @return string UUID of the saved attachment
     */
    public function saveAttachmentToDatabase(string $emailId, object $attachment, $content): string {
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
            ) VALUES (:email_id, :name, :filename, :filetype, :location, :status_type, :status_text, :content)
            RETURNING id
        ";
        
        $params = [
            ':email_id' => $emailId,
            ':name' => $attachment->name,
            ':filename' => $attachment->filename,
            ':filetype' => $attachment->filetype,
            ':location' => $attachment->location,
            ':status_type' => ThreadEmailStatusType::UNKNOWN->value,
            ':status_text' => 'uklassifisert-dok'
        ];
        
        // Handle binary content separately
        $binaryParams = [
            ':content' => $content
        ];
        
        $result = Database::queryValueWithBinaryParam($query, $params, $binaryParams);
        
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

    /**
     * Save email processing error to database for GUI resolution
     * 
     * @param string $emailIdentifier Email identifier
     * @param string $emailSubject Email subject
     * @param string $emailAddresses Comma-separated email addresses
     * @param string $errorType Type of error (no_matching_thread or multiple_matching_threads)
     * @param string $errorMessage Error message
     * @param string|null $suggestedThreadId Suggested thread ID for resolution
     * @param string $folderName IMAP folder name
     */
    private function saveEmailProcessingError(
        string $emailIdentifier,
        string $emailSubject,
        string $emailAddresses,
        string $errorType,
        string $errorMessage,
        ?string $suggestedThreadId,
        string $folderName
    ): void {
        ThreadEmailProcessingErrorManager::saveEmailProcessingError(
            $emailIdentifier,
            $emailSubject,
            $emailAddresses,
            $errorType,
            $errorMessage,
            $suggestedThreadId,
            $folderName
        );
    }
}
