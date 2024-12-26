<?php

namespace Imap;

class ImapFolderManager {
    private ImapConnection $connection;
    private array $existingFolders = [];
    private array $subscribedFolders = [];

    public function __construct(ImapConnection $connection) {
        $this->connection = $connection;
    }

    /**
     * Initialize folder lists
     */
    public function initialize(): void {
        $this->existingFolders = $this->connection->listFolders();
        $this->subscribedFolders = $this->connection->listSubscribedFolders();
    }

    /**
     * Create folder if it doesn't exist
     */
    public function ensureFolderExists(string $folderName): void {
        if (!in_array($folderName, $this->existingFolders)) {
            $this->connection->logDebug("Creating folder: $folderName");
            $this->connection->createFolder($folderName);
            $this->existingFolders[] = $folderName;
        }
    }

    /**
     * Subscribe to folder if not already subscribed
     */
    public function ensureFolderSubscribed(string $folderName): void {
        if (!in_array($folderName, $this->subscribedFolders)) {
            $this->connection->logDebug("Subscribing to folder: $folderName");
            $this->connection->subscribeFolder($folderName);
            $this->subscribedFolders[] = $folderName;
        }
    }

    /**
     * Move email to specified folder
     */
    public function moveEmail(int $uid, string $targetFolder): void {
        if (!$this->connection->getConnection()) {
            throw new \Exception('No active IMAP connection');
        }

        // Ensure target folder exists and is subscribed
        $this->ensureFolderExists($targetFolder);
        $this->ensureFolderSubscribed($targetFolder);
        
        // Perform the move using ImapConnection's methods
        $this->connection->moveEmail($uid, $targetFolder);
    }

    /**
     * Rename/move folder
     */
    public function renameFolder(string $oldName, string $newName): void {
        if (!$this->connection->getConnection()) {
            throw new \Exception('No active IMAP connection');
        }

        // Perform the rename using ImapConnection's methods
        $this->connection->renameFolder($oldName, $newName);

        // Update local folder lists
        $key = array_search($oldName, $this->existingFolders);
        if ($key !== false) {
            $this->existingFolders[$key] = $newName;
        }
        
        $key = array_search($oldName, $this->subscribedFolders);
        if ($key !== false) {
            $this->subscribedFolders[$key] = $newName;
        }
    }

    /**
     * Create all required folders for threads
     */
    public function createThreadFolders(array $requiredFolders): void {
        foreach ($requiredFolders as $folder) {
            $this->ensureFolderExists($folder);
            $this->ensureFolderSubscribed($folder);
        }
    }

    /**
     * Archive a folder by moving it to the Archive location
     */
    public function archiveFolder(string $folderName): void {
        if (!str_starts_with($folderName, 'INBOX.Archive.')) {
            $archivedName = str_replace('INBOX.', 'INBOX.Archive.', $folderName);
            $this->renameFolder($folderName, $archivedName);
        }
    }

    /**
     * Get list of existing folders
     */
    public function getExistingFolders(): array {
        return $this->existingFolders;
    }

    /**
     * Get list of subscribed folders
     */
    public function getSubscribedFolders(): array {
        return $this->subscribedFolders;
    }
}
