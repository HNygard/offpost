<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapFolderManager.php';
require_once __DIR__ . '/Imap/ImapEmailProcessor.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;

class ThreadEmailMover {
    private ImapConnection $connection;
    private ImapFolderManager $folderManager;
    private ImapEmailProcessor $emailProcessor;

    public function __construct(
        ImapConnection $connection,
        ImapFolderManager $folderManager,
        ImapEmailProcessor $emailProcessor
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
        $emails = $this->emailProcessor->processEmails($mailbox);
        
        foreach ($emails as $email) {
            $addresses = $this->emailProcessor->getEmailAddresses($email->mailHeaders);
            $targetFolder = 'INBOX';
            
            foreach ($addresses as $address) {
                if (isset($emailToFolder[$address])) {
                    $targetFolder = $emailToFolder[$address];
                    break;
                }
            }
            
            if ($targetFolder === 'INBOX') {
                $unmatchedAddresses = array_merge($unmatchedAddresses, $addresses);
            }
            
            $this->folderManager->moveEmail($email->uid, $targetFolder);
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
                    $emailToFolder[$thread->my_email] = $this->getThreadEmailFolder($entityThreads, $thread);
                }
            }
        }
        
        return $emailToFolder;
    }

    /**
     * Get the IMAP folder path for a thread
     */
    private function getThreadEmailFolder($entityThreads, $thread): string {
        $title = $entityThreads->title_prefix . ' - ' . str_replace('/', '-', $thread->title);
        $title = str_replace(
            ['Æ', 'Ø', 'Å', 'æ', 'ø', 'å'],
            ['AE', 'OE', 'AA', 'ae', 'oe', 'aa'],
            $title
        );
        
        return $thread->archived ? 'INBOX.Archive.' . $title : 'INBOX.' . $title;
    }
}
