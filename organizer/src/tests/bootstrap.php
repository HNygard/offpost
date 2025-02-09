<?php

// Set test environment flag
define('PHPUNIT_RUNNING', true);

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
 * @param string $titlePrefix The title prefix for the thread
 * @param Thread $thread The thread object to store
 * @return Thread The created thread
 */
function createThread($entityId, $titlePrefix, $thread) {
    // Store the thread
    $storageManager = ThreadStorageManager::getInstance();
    return $storageManager->createThread($entityId, $titlePrefix, $thread);
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
