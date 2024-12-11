<?php

namespace Imap;

class ImapConnection {
    private string $server;
    private string $email;
    private string $password;
    private $connection = null;
    private bool $debug;

    /**
     * @param string $server IMAP server address with protocol and port e.g. {imap.one.com:993/imap/ssl}
     * @param string $email Email address for authentication
     * @param string $password Password for authentication
     * @param bool $debug Whether to enable debug logging
     */
    public function __construct(string $server, string $email, string $password, bool $debug = false) {
        $this->server = $server;
        $this->email = $email;
        $this->password = $password;
        $this->debug = $debug;

        // Set custom error handler for IMAP operations
        set_error_handler([$this, 'errorHandler']);
    }

    /**
     * Custom error handler that converts PHP errors to exceptions
     */
    public function errorHandler($errno, $errstr, $errfile, $errline) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Open connection to specified IMAP folder
     * 
     * @param string $folder IMAP folder name (defaults to INBOX)
     * @return resource IMAP stream
     * @throws \Exception if connection fails
     */
    public function openConnection(string $folder = 'INBOX') {
        try {
            $this->connection = imap_open(
                $this->server . $folder, 
                $this->email, 
                $this->password, 
                0, 
                1,
                ['DISABLE_AUTHENTICATOR' => 'PLAIN']
            );
            $this->checkForImapError();
            return $this->connection;
        } catch (\Exception $e) {
            $this->checkForImapError();
            throw $e;
        }
    }

    /**
     * Check for IMAP errors and throw exception if found
     * 
     * @throws \Exception if IMAP error exists
     */
    public function checkForImapError() {
        $error = imap_last_error();
        if (!empty($error)) {
            throw new \Exception('IMAP error: ' . $error);
        }
    }

    /**
     * Log debug message if debug is enabled
     */
    public function logDebug(string $text) {
        if ($this->debug) {
            $time = time();
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
            
            echo date('Y-m-d H:i:s', $time)
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

        $list = imap_list($this->connection, $this->server, "*");
        $this->checkForImapError();
        
        if (!$list) {
            return [];
        }

        sort($list);
        return array_map(function($folder) {
            return str_replace($this->server, '', $folder);
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

        $list = imap_lsub($this->connection, $this->server, '*');
        $this->checkForImapError();
        
        if (!$list) {
            return [];
        }

        sort($list);
        return array_map(function($folder) {
            return str_replace($this->server, '', $folder);
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

        imap_createmailbox($this->connection, imap_utf7_encode($this->server . $folderName));
        $this->checkForImapError();
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

        imap_subscribe($this->connection, imap_utf7_encode($this->server . $folderName));
        $this->checkForImapError();
    }

    /**
     * Close the IMAP connection
     * 
     * @param int $flag Optional flag for imap_close (e.g. CL_EXPUNGE)
     */
    public function closeConnection(int $flag = 0) {
        if ($this->connection) {
            imap_close($this->connection, $flag);
            $this->connection = null;
        }
    }

    /**
     * Get the current IMAP connection resource
     * 
     * @return resource|null The IMAP stream or null if not connected
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Destructor ensures connection is closed
     */
    public function __destruct() {
        $this->closeConnection();
    }
}
