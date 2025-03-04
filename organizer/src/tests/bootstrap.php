<?php

// Set up error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('display_startup_errors', true);

// Set test environment flag
define('PHPUNIT_RUNNING', true);
$environment = 'development';

// Set up test database configuration for development environment
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=25432');
putenv('DB_NAME=offpost');
putenv('DB_USER=offpost');

// Use the actual postgres password file
putenv('DB_PASSWORD_FILE=' . __DIR__ . '/../../../secrets/postgres_password');

// Define test directories
define('DATA_DIR', '/tmp/organizer-test-data');
define('THREADS_DIR', '/tmp/organizer-test-data/threads');
define('THREAD_AUTH_DIR', '/tmp/organizer-test-data/threads/authorizations');

// Create test directories if they don't exist
if (!file_exists(THREADS_DIR)) {
    mkdir(THREADS_DIR, 0777, true);
}
if (!file_exists(THREAD_AUTH_DIR)) {
    mkdir(THREAD_AUTH_DIR, 0777, true);
}

// Include required files
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/ThreadFileOperations.php';

/**
 * Helper function to create a thread for testing
 * @param string $entityId The entity ID to create the thread under
 * @param Thread $thread The thread object to store
 * @return Thread The created thread
 */
function createThread($entityId, $thread) {
    // Store the thread
    $storageManager = ThreadStorageManager::getInstance();
    return $storageManager->createThread($entityId, $thread);
}

/**
 * Helper function to save entity threads
 * @param string $entityId The entity ID
 * @param Threads $entityThreads The threads object to save
 */
function saveEntityThreads($entityId, $entityThreads) {
    $fileOps = new ThreadFileOperations();
    $fileOps->saveEntityThreads($entityId, $entityThreads);
}

/**
 * Helper function to get threads for an entity
 * @param string $entityId The entity ID
 * @return Threads|null The threads object or null if not found
 */
function getThreadsForEntity($entityId) {
    $fileOps = new ThreadFileOperations();
    return $fileOps->getThreadsForEntity($entityId);
}


class MockEmailService implements IEmailService {
    private $shouldSucceed;
    private $lastError = '';
    public $lastEmailData;
    private $sentEmails = [];

    public function __construct($shouldSucceed = true) {
        $this->shouldSucceed = $shouldSucceed;
    }

    public function sendEmail($from, $fromName, $to, $subject, $body, $bcc = null) {
        $this->lastEmailData = [
            'from' => $from,
            'fromName' => $fromName,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'bcc' => $bcc
        ];
        $this->sentEmails[] = $this->lastEmailData;
        if (!$this->shouldSucceed) {
            $this->lastError = 'Mock email failure';
        }
        return $this->shouldSucceed;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getDebugOutput() {
        return 'Mock debug output';
    }

    public function getSentEmails() {
        return $this->sentEmails;
    }
}