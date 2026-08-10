<?php

// Set up error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('display_startup_errors', true);

// Set test environment flag
define('PHPUNIT_RUNNING', true);
$environment = 'development';

// Set up test database configuration for development environment.
//
// The defaults are the host-side view of the dev stack: docker-compose.dev.yaml
// publishes postgres on 127.0.0.1:25432, and the password file sits in the repo.
// Running `./organizer/src/vendor/bin/phpunit organizer/src/tests/` from the repo
// root therefore needs no environment at all, as before.
//
// Each value yields to one already in the environment, so the same suite can run
// inside a container - where postgres is `postgres:5432` and the password is at
// /run/secrets/postgres_password, and where the repo-relative path below does not
// resolve. Without this the hardcoded values won every time and in-container runs
// could not reach the database.
function testEnvDefault(string $name, string $value): void {
    $existing = getenv($name);
    putenv($name . '=' . ($existing !== false && $existing !== '' ? $existing : $value));
}

testEnvDefault('DB_HOST', '127.0.0.1');
testEnvDefault('DB_PORT', '25432');
testEnvDefault('DB_NAME', 'offpost');
testEnvDefault('DB_USER', 'offpost');
testEnvDefault('DB_PASSWORD_FILE', __DIR__ . '/../../../secrets/postgres_password');

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
require_once __DIR__ . '/../class/Enums/ThreadEmailStatusType.php'; // Add this line
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';

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
 * Helper function to get threads for an entity
 * @param string $entityId The entity ID
 * @return Threads|null The threads object or null if not found
 */
function getThreadsForEntity($entityId) {
    $storageManager = ThreadStorageManager::getInstance();
    return $storageManager->getThreadsForEntity($entityId);
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
