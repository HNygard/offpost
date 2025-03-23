<?php

namespace Imap;

require_once __DIR__ . '/ImapEmail.php';

class ImapEmailProcessor {
    private ImapConnection $connection;
    private string $cacheFile;
    private ?object $cache = null;

    public function __construct(ImapConnection $connection, string $cacheFile = '/organizer-data/cache-threads.json') {
        $this->connection = $connection;
        $this->cacheFile = $cacheFile;
        $this->loadCache();
    }

    /**
     * Load the email cache from file
     */
    private function loadCache(): void {
        if (file_exists($this->cacheFile)) {
            $this->cache = json_decode(file_get_contents($this->cacheFile));
        }
        
        if ($this->cache === null) {
            $this->cache = new \stdClass();
            $this->cache->thread_modified = array();
        }
    }

    /**
     * Save the email cache to file
     */
    private function writeCache(): void {
        file_put_contents(
            $this->cacheFile, 
            json_encode($this->cache, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Update cache timestamp for a folder
     */
    public function updateFolderCache(string $folderPath): void {
        $this->cache->{$folderPath} = time();
        $this->writeCache();
    }

    /**
     * Check if folder needs update based on cache
     */
    public function needsUpdate(string $folderPath, ?string $updateOnlyBefore = null): bool {
        if (!isset($this->cache->{$folderPath})) {
            return true;
        }

        if ($updateOnlyBefore === null) {
            return false;
        }

        $lastModified = $this->cache->{$folderPath};
        return $lastModified <= strtotime($updateOnlyBefore);
    }

    /**
     * Process emails in a folder
     */
    public function getEmails(string $folder): array {
        $imapStream = $this->connection->openConnection($folder);
        if (!$imapStream) {
            throw new \Exception('No active IMAP connection');
        }

        $emails = [];
        $mailUIDs = $this->connection->search("ALL", SE_UID);

        if (!$mailUIDs) {
            $this->connection->logDebug('No emails found in folder: ' . $folder);
            return $emails;
        }

        foreach ($mailUIDs as $uid) {
            $this->connection->logDebug("Fetching email UID: $uid");
            $email = $this->getEmail($uid);
            if ($email) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * Process a single email
     * 
     * @param int $uid Email UID
     * @return ImapEmail|null Email object or null if not found
     */
    private function getEmail(int $uid): ?ImapEmail {
        $msgNo = $this->connection->getMsgno($uid);
        $headers = $this->connection->getHeaderInfo($msgNo);

        if (!$headers) {
            return null;
        }

        // Get email body
        $body = $this->connection->getBody($uid, FT_UID);
        
        return ImapEmail::fromImap($this->connection, $uid, $headers, $body);
    }

    /**
     * Get email direction (IN/OUT) based on sender
     */
    public function getEmailDirection(object $headers, string $myEmail): string {
        $from = $headers->from[0]->mailbox . '@' . $headers->from[0]->host;
        return ($from === $myEmail) ? 'OUT' : 'IN';
    }

    /**
     * Generate unique filename for email based on date and direction
     */
    public function generateEmailFilename(object $headers, string $myEmail): string {
        $datetime = date('Y-m-d_His', strtotime($headers->date));
        $direction = $this->getEmailDirection($headers, $myEmail);
        return $datetime . ' - ' . $direction;
    }

    /**
     * Get addresses from email headers
     */
    public function getEmailAddresses(object $headers): array {
        $addresses = [];
        
        if (isset($headers->to)) {
            foreach ($headers->to as $email) {
                $addresses[] = $email->mailbox . '@' . $email->host;
            }
        }
        foreach ($headers->from as $email) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
        foreach ($headers->reply_to as $email) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
        foreach ($headers->sender as $email) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }

        return array_unique($addresses);
    }
}
