<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapFolderManager.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/ImapFolderStatus.php';
require_once __DIR__ . '/ThreadFolderManager.php';
require_once __DIR__ . '/ThreadEmailProcessingErrorManager.php';
require_once __DIR__ . '/AdminNotificationService.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;

class ThreadEmailMover {
    private \Imap\ImapConnection $connection;
    private \Imap\ImapFolderManager $folderManager;
    private \Imap\ImapEmailProcessor $emailProcessor;
    
    /**
     * Flag to skip database operations for unit tests
     * @var bool
     */
    public static $skipDatabaseOperations = false;
    
    /**
     * Maximum number of errors allowed before stopping email processing
     * @var int
     */
    private const MAX_ERRORS = 5;

    public function __construct(
        \Imap\ImapConnection $connection,
        \Imap\ImapFolderManager $folderManager,
        \Imap\ImapEmailProcessor $emailProcessor
    ) {
        $this->connection = $connection;
        $this->folderManager = $folderManager;
        $this->emailProcessor = $emailProcessor;
    }

    /**
     * Process emails in a mailbox and move them to appropriate thread folders
     * 
     * @param string $mailbox Source mailbox to process
     * @param array $emailToFolder Mapping of email addresses to target folders
     * @return array Array of unmatched email addresses that need new threads
     */
    public function processMailbox(string $mailbox, array $emailToFolder): array {
        $unmatchedAddresses = [];
        $emails = $this->emailProcessor->getEmails($mailbox);
        
        $maxed_out = false;
        $errorCount = 0;
        $i = 0;
        foreach ($emails as $email) {
            try {
                $rawEmail = $this->connection->getRawEmail($email->uid);
            }
            catch (Exception $e) {
                throw new Exception("Failed to fetch raw email UID {$email->uid} from {$mailbox}: " . $e->getMessage(), $e->getCode(), $e);
            }
            $addresses = $email->getEmailAddresses($rawEmail);
            $targetFolder = 'INBOX';
            
            // First check if the email is manually mapped to a thread (if database operations are enabled)
            if (!self::$skipDatabaseOperations && isset($email->timestamp) && isset($email->subject)) {
                $email_identifier = date('Y-m-d__His', $email->timestamp) . '__' . md5($email->subject);
                $mapped_thread = Database::queryOneOrNone(
                    "SELECT t.entity_id, t.title, t.my_email, t.archived 
                     FROM thread_email_mapping m 
                     JOIN threads t ON m.thread_id = t.id 
                     WHERE m.email_identifier = ?",
                    [$email_identifier]
                );
                
                if ($mapped_thread) {
                    // Use the mapped thread's folder
                    $targetFolder = ThreadFolderManager::getThreadEmailFolder(
                        $mapped_thread['entity_id'], 
                        (object)['title' => $mapped_thread['title'], 'archived' => $mapped_thread['archived']]
                    );
                }
            }
            
            // If no mapping found, fall back to checking email addresses
            if ($targetFolder === 'INBOX') {
                foreach ($addresses as $address) {
                    if (isset($emailToFolder[$address])) {
                        $targetFolder = $emailToFolder[$address];
                        break;
                    }
                }
            }
            
            if ($targetFolder === 'INBOX') {
                // Only add addresses that aren't in emailToFolder and aren't DMARC
                $hasUnmatchedAddress = false;
                foreach ($addresses as $address) {
                    if (!isset($emailToFolder[$address]) && $address !== 'dmarc@offpost.no') {
                        $unmatchedAddresses[] = $address;
                        $hasUnmatchedAddress = true;
                    }
                }
                
                // Save email processing error for unmatched emails in INBOX
                if ($hasUnmatchedAddress) {
                    $this->saveUnmatchedEmailError($email, $addresses, $mailbox);
                }
            }

            // Move the email to the target folder
            try {
                $this->folderManager->moveEmail($email->uid, $targetFolder);
                
                // Request an update for the target folder on successful move
                ImapFolderStatus::createOrUpdate($targetFolder, requestUpdate: true);
            } catch (Exception $e) {
                $errorCount++;
                $errorMessage = "Failed to move email UID {$email->uid} to folder {$targetFolder}: " . $e->getMessage();
                
                // Log the error
                error_log("ThreadEmailMover error: {$errorMessage}");
                
                // Send admin notification
                try {
                    $adminNotificationService = new AdminNotificationService();
                    $adminNotificationService->notifyAdminOfError(
                        'email-move-error',
                        $errorMessage,
                        [
                            'mailbox' => $mailbox,
                            'email_uid' => $email->uid,
                            'target_folder' => $targetFolder,
                            'error' => $e->getMessage(),
                            'error_code' => $e->getCode()
                        ]
                    );
                } catch (Exception $notifyException) {
                    // If notification fails, log it but continue processing
                    error_log("Failed to send admin notification: " . $notifyException->getMessage());
                }
                
                // Check if we've reached the maximum error count
                if ($errorCount >= self::MAX_ERRORS) {
                    error_log("ThreadEmailMover: Maximum error count (" . self::MAX_ERRORS . ") reached, stopping email processing");
                    break;
                }
                
                // Continue to next email
                $i++;
                continue;
            }

            $i++;
            if ($i == 100) {
                $this->connection->logDebug('Processed 100 emails, breaking loop');
                $maxed_out = true;
                break;
            }
        }
        
        return array(
            'unmatched' => array_unique($unmatchedAddresses),
            'maxed_out' => $maxed_out,
        );
    }

    /**
     * Build email-to-folder mapping from threads
     */
    public function buildEmailToFolderMapping(array $threads): array {
        $emailToFolder = [];
        
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                if (!$thread->archived || $thread->my_email == 'dmarc@offpost.no') {
                    $emailToFolder[$thread->my_email] = ThreadFolderManager::getThreadEmailFolder($entityThreads->entity_id, $thread);
                }
            }
        }
        
        return $emailToFolder;
    }

    /**
     * Save unmatched email error to database for GUI resolution
     * 
     * @param object $email Email object
     * @param array $addresses Email addresses
     * @param string $folderName IMAP folder name
     */
    private function saveUnmatchedEmailError(object $email, array $addresses, string $folderName): void {
        // Generate email identifier (same format as in ThreadEmailDatabaseSaver)
        $emailIdentifier = date('Y-m-d__His', $email->timestamp) . '__' . md5($email->subject);
        
        // Filter out DMARC addresses from the list
        $relevantAddresses = array_filter($addresses, function($addr) {
            return $addr !== 'dmarc@offpost.no';
        });
        
        $errorMessage = 'No matching thread found for email(s): ' . implode(', ', $relevantAddresses);
        
        ThreadEmailProcessingErrorManager::saveEmailProcessingError(
            $emailIdentifier,
            $email->subject,
            implode(', ', $relevantAddresses),
            'unmatched_inbox_email',
            $errorMessage,
            null, // No suggested thread ID
            $folderName
        );
    }
}
