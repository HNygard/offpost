<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapFolderManager.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/ImapFolderStatus.php';

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
        
        foreach ($emails as $email) {
            $addresses = $email->getEmailAddresses();
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
            $this->folderManager->moveEmail($email->uid, $targetFolder);
            
            // Request an update for the target folder
            ImapFolderStatus::createOrUpdate($targetFolder, null, false, true);
        }
        
        return array_unique($unmatchedAddresses);
    }

    /**
     * Build email-to-folder mapping from threads
     */
    public function buildEmailToFolderMapping(array $threads): array {
        $emailToFolder = [];
        
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                if (!$thread->archived && $thread->my_email != 'dmarc@offpost.no') {
                    $emailToFolder[$thread->my_email] = $this->getThreadEmailFolder($entityThreads->entity_id, $thread);
                }
            }
        }
        
        return $emailToFolder;
    }

    /**
     * Get the IMAP folder path for a thread
     */
    private function getThreadEmailFolder($entity_id, $thread): string {
        $title = $entity_id . ' - ' . str_replace('/', '-', $thread->title);
        $title = str_replace(
            ['Æ', 'Ø', 'Å', 'æ', 'ø', 'å'],
            ['AE', 'OE', 'AA', 'ae', 'oe', 'aa'],
            $title
        );
        
        return $thread->archived ? 'INBOX.Archive.' . $title : 'INBOX.' . $title;
    }
}
