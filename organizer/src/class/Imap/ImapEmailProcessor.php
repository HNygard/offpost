<?php

namespace Imap;

class ImapEmailProcessor {
    private ImapConnection $connection;
    private string $cacheFile;
    private ?object $cache;

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
    public function processEmails(string $folder): array {
        $imapStream = $this->connection->getConnection();
        if (!$imapStream) {
            throw new \Exception('No active IMAP connection');
        }

        $emails = [];
        $mailUIDs = imap_search($imapStream, "ALL", SE_UID);
        $this->connection->checkForImapError();

        if (!$mailUIDs) {
            $this->connection->logDebug('No emails found in folder: ' . $folder);
            return $emails;
        }

        foreach ($mailUIDs as $uid) {
            $this->connection->logDebug("Processing email UID: $uid");
            $email = $this->processEmail($uid);
            if ($email) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * Process a single email
     */
    private function processEmail(int $uid): ?object {
        $imapStream = $this->connection->getConnection();
        
        $msgNo = imap_msgno($imapStream, $uid);
        $headers = imap_headerinfo($imapStream, $msgNo);
        $this->connection->checkForImapError();

        if (!$headers) {
            return null;
        }

        $email = new \stdClass();
        
        // Basic email information
        $email->subject = $headers->subject;
        $email->timestamp = strtotime($headers->date);
        $email->date = $headers->date;
        
        // Clean up and convert character encodings
        $email->toaddress = isset($headers->toaddress) ? imap_utf8($headers->toaddress) : null;
        $email->fromaddress = imap_utf8($headers->fromaddress);
        $email->senderaddress = imap_utf8($headers->senderaddress);
        $email->reply_toaddress = imap_utf8($headers->reply_toaddress);

        // Convert personal names to UTF-8
        if (isset($headers->to[0]->personal)) {
            $headers->to[0]->personal = imap_utf8($headers->to[0]->personal);
        }
        if (isset($headers->from[0]->personal)) {
            $headers->from[0]->personal = imap_utf8($headers->from[0]->personal);
        }
        if (isset($headers->sender[0]->personal)) {
            $headers->sender[0]->personal = imap_utf8($headers->sender[0]->personal);
        }
        if (isset($headers->reply_to[0]->personal)) {
            $headers->reply_to[0]->personal = imap_utf8($headers->reply_to[0]->personal);
        }

        // Get email body
        $email->body = imap_body($imapStream, $uid, FT_UID);
        $this->connection->checkForImapError();

        // Ensure body is UTF-8
        if (json_encode($email->body, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_UNICODE ^ JSON_UNESCAPED_SLASHES) === false) {
            $email->body = mb_convert_encoding($email->body, 'UTF-8', 'ISO-8859-1');
        }

        $email->mailHeaders = $headers;
        
        return $email;
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