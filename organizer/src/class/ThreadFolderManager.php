<?php

require_once __DIR__ . '/Imap/ImapWrapper.php';
require_once __DIR__ . '/Imap/ImapConnection.php';
require_once __DIR__ . '/Imap/ImapFolderManager.php';

use Imap\ImapConnection;
use Imap\ImapFolderManager;

class ThreadFolderManager {
    private ImapConnection $connection;
    private ImapFolderManager $folderManager;

    public function __construct(ImapConnection $connection) {
        $this->connection = $connection;
        $this->folderManager = new ImapFolderManager($connection);
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
        $requiredFolders = ['INBOX.Archive'];
        
        foreach ($threads as $entityThreads) {
            foreach ($entityThreads->threads as $thread) {
                $requiredFolders[] = $this->getThreadEmailFolder($entityThreads, $thread);
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

        $title = str_replace('INBOX.Archive.', '', $this->getThreadEmailFolder($entityThreads, $thread));
        $inboxFolder = 'INBOX.' . $title;

        if (in_array($inboxFolder, $this->folderManager->getExistingFolders())) {
            $this->folderManager->archiveFolder($inboxFolder);
        }
    }

    /**
     * Get the IMAP folder path for a thread
     * 
     * @param object $entityThreads Entity threads object
     * @param object $thread Thread object
     * @return string IMAP folder path
     */
    public function getThreadEmailFolder($entityThreads, $thread): string {
        $title = $entityThreads->title_prefix . ' - ' . str_replace('/', '-', $thread->title);
        $title = str_replace(
            ['Æ', 'Ø', 'Å', 'æ', 'ø', 'å'],
            ['AE', 'OE', 'AA', 'ae', 'oe', 'aa'],
            $title
        );
        
        return $thread->archived ? 'INBOX.Archive.' . $title : 'INBOX.' . $title;
    }
}
