<?php

// Set test environment flag
define('PHPUNIT_RUNNING', true);

// Define test data directory
define('DATA_DIR', '/tmp/organizer-test-data');

// Create test directories if they don't exist
if (!file_exists('/tmp/organizer-test-data/threads')) {
    mkdir('/tmp/organizer-test-data/threads', 0777, true);
}
