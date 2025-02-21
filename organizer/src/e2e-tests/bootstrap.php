<?php

// Set up error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Set up test database configuration for development environment
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=25432');
putenv('DB_NAME=offpost');
putenv('DB_USER=offpost');

// Load Database class first to set test environment
require_once __DIR__ . '/../class/Database.php';
putenv('DB_PASSWORD_FILE=' . __DIR__ . '/../../../secrets/postgres_password');

// Initialize test environment
putenv('APP_ENV=testing');

// Set up test session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user'] = array('sub' => 'test_user');

// Load autoloader and core classes
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/common.php';
require_once __DIR__ . '/../class/ThreadUtils.php';
require_once __DIR__ . '/../class/ThreadEmailService.php';
require_once __DIR__ . '/../class/Threads.php';
require_once __DIR__ . '/../class/ThreadAuthorization.php';
require_once __DIR__ . '/../class/ThreadLabelFilter.php';
require_once __DIR__ . '/../class/ThreadEmailClassifier.php';
require_once __DIR__ . '/../class/ThreadFileOperations.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailHistory.php';
require_once __DIR__ . '/../class/ThreadHistory.php';
