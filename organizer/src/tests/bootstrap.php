<?php

// Set test environment flag
define('PHPUNIT_RUNNING', true);

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
