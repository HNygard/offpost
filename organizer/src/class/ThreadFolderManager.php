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
                $requiredFolders[] = self::getThreadEmailFolder($entityThreads->entity_id, $thread);
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

        $title = str_replace('INBOX.Archive.', '', self::getThreadEmailFolder($entityThreads->entity_id, $thread));
        $inboxFolder = 'INBOX.' . $title;

        if (in_array($inboxFolder, $this->folderManager->getExistingFolders())) {
            $this->folderManager->archiveFolder($inboxFolder);
        }
    }

    /**
     * Get the IMAP folder path for a thread
     * 
     * @param string $entity_id Entity threads object
     * @param object $thread Thread object
     * @return string IMAP folder path
     */
    public static function getThreadEmailFolder($entity_id, $thread): string {
        $title = $entity_id . ' - ' . $thread->title;
        
        // Decode any MIME-encoded headers (e.g., =?UTF-8?B?...?= or =?iso-8859-1?Q?...?=)
        // Only decode if the string contains MIME encoding markers to avoid corrupting UTF-8 text
        if (strpos($title, '=?') !== false) {
            $title = mb_decode_mimeheader($title);
        }
        
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
