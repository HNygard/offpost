<?php

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Base data directory configuration
if (!defined('DATA_DIR')) {
    // Check if we're in test environment
    if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING === true) {
        define('DATA_DIR', '/tmp/organizer-test-data');
    } else {
        // Production path
        define('DATA_DIR', '/organizer-data');
    }
}

// Threads directory configuration
if (!defined('THREADS_DIR')) {
    define('THREADS_DIR', joinPaths(DATA_DIR, 'threads'));
}

/**
 * Safely join path segments using the correct directory separator
 * @param string ...$segments Path segments to join
 * @return string The joined path
 */
function joinPaths(...$segments) {
    // Ensure first segment starts with / if it's an absolute path
    $firstSegment = array_shift($segments);
    if (strpos($firstSegment, '/') === 0) {
        $result = '/' . trim($firstSegment, '/');
    } else {
        $result = trim($firstSegment, '/');
    }
    
    // Join remaining segments
    foreach ($segments as $segment) {
        $result .= '/' . trim($segment, '/');
    }
    
    return $result;
}

function getDirContentsRecursive($dir) {
    $command = 'find "' . $dir . '"';
    exec($command, $find);
    $data_store_files = array();
    foreach ($find as $line) {
        if (is_dir($line)) {
            // -> Find already got all recursively
            continue;
        }
        $data_store_files[] = $line;
    }
    return $data_store_files;
}

function getFilesInDirectoryNoRecursive($dir) {
    $files = scandir($dir);
    $results = array();
    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            $results[] = $path;
        }
    }

    return $results;
}

function htmlescape($html) {
    return $html === null ? '' : htmlentities($html, ENT_QUOTES);
}

if (!function_exists('logDebug')) {
    function logDebug($message) {
        // Skip debug output during tests
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING === true) {
            return;
        }
        echo date('Y-m-d H:i:s')
        . $message . chr(10);
    }
}
