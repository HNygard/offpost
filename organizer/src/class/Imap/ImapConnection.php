<?php

namespace Imap;

use Exception;

use Imap\ImapWrapper;

require_once __DIR__ . '/ImapWrapper.php';

class ImapConnection {
    private string $server;
    private string $email;
    private string $password;
    private $connection = null;
    private bool $debug;
    private ImapWrapper $wrapper;

    /**
     * @param string $server IMAP server address with protocol and port e.g. {imap.one.com:993/imap/ssl}
     * @param string $email Email address for authentication
     * @param string $password Password for authentication
     * @param bool $debug Whether to enable debug logging
     * @param ImapWrapper|null $wrapper IMAP wrapper for testing
     */
    public function __construct(
        string $server, 
        string $email, 
        string $password, 
        bool $debug = false,
        ?ImapWrapper $wrapper = null
    ) {
        $this->server = $server;
        $this->email = $email;
        $this->password = $password;
        $this->debug = $debug;
        $this->wrapper = $wrapper ?? new ImapWrapper();
    }

    /**
     * Open connection to specified IMAP folder
     * 
     * @param string $folder IMAP folder name (defaults to INBOX)
     * @return resource IMAP stream
     * @throws \Exception if connection fails
     */
    public function openConnection(string $folder = 'INBOX') {
        // Add /novalidate-cert for greenmail to accept self-signed certificates
        $server = $this->server;
        if (strpos($server, 'greenmail:') !== false && strpos($server, '/novalidate-cert') === false) {
            $server = str_replace('}', '/novalidate-cert}', $server);
        }

        $fullMailbox = $server . $folder;
        $this->connection = $this->wrapper->open(
            $fullMailbox,
            $this->email, 
            $this->password, 
            0, 
            1,
            ['DISABLE_AUTHENTICATOR' => 'PLAIN']
        );

        if ($this->connection === false) {
            throw new \Exception('Failed to establish IMAP connection');
        }

        return $this->connection;
    }

    /**
     * Log debug message if debug is enabled
     */
    public function logDebug(string $text) {
        if ($this->debug) {
            $time = \time();
            static $lastTime = null;
            
            if ($lastTime === null) {
                $lastTime = $time;
            }
            
            $diff = $time - $lastTime;
            $lastTime = $time;
            
            $heavyIndicator = '';
            if ($diff > 10) {
                $heavyIndicator = ' VERY HEAVY';
            } elseif ($diff > 5) {
                $heavyIndicator = ' HEAVY';
            }
            
            echo \date('Y-m-d H:i:s', $time)
                . ' (+ ' . $diff . ' sec)'
                . $heavyIndicator
                . ' - '
                . $text . PHP_EOL;
        }
    }

    /**
     * List all IMAP folders
     * 
     * @return array Array of folder names
     * @throws \Exception if operation fails
     */
    public function listFolders(): array {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $list = $this->wrapper->list($this->connection, $this->server, "*");
        
        if (!$list) {
            return [];
        }

        \sort($list);
        return \array_map(function($folder) {
            return \str_replace($this->server, '', $folder);
        }, $list);
    }

    /**
     * List subscribed IMAP folders
     * 
     * @return array Array of subscribed folder names
     * @throws \Exception if operation fails
     */
    public function listSubscribedFolders(): array {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $list = $this->wrapper->lsub($this->connection, $this->server, '*');
        
        if (!$list) {
            return [];
        }

        \sort($list);
        return \array_map(function($folder) {
            return \str_replace($this->server, '', $folder);
        }, $list);
    }

    /**
     * Create a new IMAP folder
     * 
     * @param string $folderName Name of folder to create
     * @throws \Exception if operation fails
     */
    public function createFolder(string $folderName) {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $this->wrapper->createMailbox($this->connection, $this->wrapper->utf7Encode($this->server . $folderName));
    }

    /**
     * Subscribe to an IMAP folder
     * 
     * @param string $folderName Name of folder to subscribe to
     * @throws \Exception if operation fails
     */
    public function subscribeFolder(string $folderName) {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $this->wrapper->subscribe($this->connection, $this->wrapper->utf7Encode($this->server . $folderName));
    }

    /**
     * Move an email to a different folder
     * 
     * @param int $uid UID of the email to move
     * @param string $targetFolder Name of the target folder
     * @throws \Exception if operation fails
     */
    public function moveEmail(int $uid, string $targetFolder) {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $this->wrapper->mailMove($this->connection, (string)$uid, $targetFolder, CP_UID);
    }

    /**
     * Rename/move a folder
     * 
     * @param string $oldName Current name of the folder
     * @param string $newName New name for the folder
     * @throws \Exception if operation fails
     */
    public function renameFolder(string $oldName, string $newName) {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $this->wrapper->renameMailbox(
            $this->connection,
            $this->wrapper->utf7Encode($this->server . $oldName),
            $this->wrapper->utf7Encode($this->server . $newName)
        );
    }

    /**
     * Search for emails using IMAP search criteria
     * 
     * @param string $criteria The search criteria
     * @param int $options Search options (e.g. SE_UID)
     * @return array Array of message numbers or UIDs matching the criteria
     * @throws \Exception if operation fails
     */
    public function search(string $criteria, int $options = 0): array {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $result = $this->wrapper->search($this->connection, $criteria, $options);
        
        return $result ?: [];
    }

    /**
     * Get message number for a UID
     * 
     * @param int $uid The UID to get message number for
     * @return int The message number
     */
    public function getMsgno(int $uid): int {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $msgno = $this->wrapper->msgno($this->connection, $uid);
        if ($msgno <= 0) {
            throw new \Exception("Invalid message number for UID: $uid");
        }
        return $msgno;
    }

    /**
     * Get header info for a message
     * 
     * @param int $msgno The message number
     * @return object Header information
     */
    public function getHeaderInfo(int $msgno): object {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $result = $this->wrapper->headerinfo($this->connection, $msgno);
        
        return $result;
    }

    /**
     * Get message body
     * 
     * @param int $uid The message UID
     * @param int $options Options for fetching body (e.g. FT_UID)
     * @return string The message body
     */
    public function getBody(int $uid, int $options = 0): string {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $result = $this->wrapper->body($this->connection, $uid, $options);
        
        return $result;
    }

    /**
     * Get message structure
     * 
     * @param int $uid Message UID
     * @param int $options Options for fetching structure (e.g. FT_UID)
     * @return object Message structure
     * @throws \Exception if operation fails
     */
    public function getFetchstructure(int $uid, int $options = 0): object {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $result = $this->wrapper->fetchstructure($this->connection, $uid, $options);
        
        return $result;
    }

    /**
     * Get message body part
     * 
     * @param int $uid Message UID
     * @param string $section Message section
     * @param int $options Options for fetching body (e.g. FT_UID)
     * @return string Message body part
     * @throws \Exception if operation fails
     */
    public function getFetchbody(int $uid, string $section, int $options = 0): string {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $result = $this->wrapper->fetchbody($this->connection, $uid, $section, $options);
        
        return $result;
    }

    /**
     * Convert text to UTF-8
     * 
     * @param string $text Text to convert
     * @return string UTF-8 encoded text
     */
    public function utf8(string $text): string {
        return $this->wrapper->utf8($text);
    }

    /**
     * Close the IMAP connection
     * 
     * @param int $flag Optional flag for imap_close (e.g. CL_EXPUNGE)
     */
    public function closeConnection(int $flag = 0) {
        if ($this->connection) {
            $this->wrapper->close($this->connection, $flag);
            $this->connection = null;
        }
    }

    /**
     * Get decoded raw email content with proper encoding handling
     * 
     * @param int $uid Message UID
     * @return string Decoded raw email content in UTF-8
     * @throws \Exception if operation fails
     */
    public function getRawEmail(int $uid): string {
        if (!$this->connection) {
            throw new \Exception('No active IMAP connection');
        }

        $content = $this->wrapper->fetchbody($this->connection, $uid, "", FT_UID);
        $structure = $this->getFetchstructure($uid, FT_UID);
        
        // Check encoding of the main message
        if (isset($structure->encoding)) {
            if ($structure->encoding == 3) { // BASE64
                $content = base64_decode($content);
            } elseif ($structure->encoding == 4) { // QUOTED-PRINTABLE
                $content = quoted_printable_decode($content);
            }
        }
        
        // Ensure content is valid UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Try to convert from ISO-8859-1 to UTF-8
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        
        return $content;
    }

    /**
     * Get the current IMAP connection resource
     * 
     * @return IMAP\Connection The IMAP stream
     */
    public function getConnection() {
        if ($this->connection == null) {
            throw new Exception('No active IMAP connection');
        }
        return $this->connection;
    }

    /**
     * Destructor ensures connection is closed
     */
    public function __destruct() {
        $this->closeConnection();
    }
}
