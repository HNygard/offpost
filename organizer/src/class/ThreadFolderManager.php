<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapFolderManager.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;

class ThreadFolderManager {
    private \Imap\ImapConnection $connection;
    private \Imap\ImapFolderManager $folderManager;

    public function __construct(\Imap\ImapConnection $connection, ImapFolderManager $folderManager) {
        $this->connection = $connection;
        $this->folderManager = $folderManager;
    }

    /**
     * Initialize folder manager and get existing folders
     */
    public function initialize(): void {
        $this->folderManager->initialize();
    }

    /**
     * Create required folders for threads
     * 
     * @param array $threads Array of thread objects
     * @return array List of created folder paths
     */
    public function createRequiredFolders(array $threads): array {
        require __DIR__ . '/../username-password.php';
        $requiredFolders = ['INBOX.Archive', $imapSentFolder];
        
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                $requiredFolders[] = $this->getThreadEmailFolder($entityThreads->entity_id, $thread);
            }
        }

        $this->folderManager->createThreadFolders($requiredFolders);
        return $requiredFolders;
    }

    /**
     * Archive a thread folder if needed
     */
    public function archiveThreadFolder($entityThreads, $thread): void {
        if (!$thread->archived) {
            return;
        }

        $title = str_replace('INBOX.Archive.', '', $this->getThreadEmailFolder($entityThreads->entity_id, $thread));
        $inboxFolder = 'INBOX.' . $title;

        if (in_array($inboxFolder, $this->folderManager->getExistingFolders())) {
            $this->folderManager->archiveFolder($inboxFolder);
        }
    }

    /**
     * Get the IMAP folder path for a thread
     * 
     * @param object $entity_id Entity threads object
     * @param object $thread Thread object
     * @return string IMAP folder path
     */
    public function getThreadEmailFolder($entity_id, $thread): string {
        $title = $entity_id . ' - ' . $thread->title;
        
        // Replace Nordic characters
        $title = str_replace(
            ['Æ', 'Ø', 'Å', 'æ', 'ø', 'å'],
            ['AE', 'OE', 'AA', 'ae', 'oe', 'aa'],
            $title
        );
        
        // Replace invalid IMAP folder characters
        $title = preg_replace('/[\\\\\/:*?"<>|]/', '-', $title);
        
        // Ensure reasonable length (max 80 chars for folder name)
        if (strlen($title) > 80) {
            $title = substr($title, 0, 77) . 'osv';
        }
        
        return $thread->archived ? 'INBOX.Archive.' . $title : 'INBOX.' . $title;
    }
}
