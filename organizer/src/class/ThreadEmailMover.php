<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapFolderManager.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/ImapFolderStatus.php';
require_once __DIR__ . '/ThreadFolderManager.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;

class ThreadEmailMover {
    private \Imap\ImapConnection $connection;
    private \Imap\ImapFolderManager $folderManager;
    private \Imap\ImapEmailProcessor $emailProcessor;

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
            
            foreach ($addresses as $address) {
                if (isset($emailToFolder[$address])) {
                    $targetFolder = $emailToFolder[$address];
                    break;
                }
            }
            
            if ($targetFolder === 'INBOX') {
                // Only add addresses that aren't in emailToFolder and aren't DMARC
                foreach ($addresses as $address) {
                    if (!isset($emailToFolder[$address]) && $address !== 'dmarc@offpost.no') {
                        $unmatchedAddresses[] = $address;
                    }
                }
            }

            // Move the email to the target folder
            $moved = $this->folderManager->moveEmail($email->uid, $targetFolder);
            
            // Request an update for the target folder only if email was actually moved
            if ($moved) {
                ImapFolderStatus::createOrUpdate($targetFolder, requestUpdate: true);
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
}
