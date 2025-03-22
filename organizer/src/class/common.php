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

function is_uuid($string) {
    if (!is_string($string)) {
        return false;
    }

    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $string) === 1;
}

/**
 * 
 * jTraceEx() - provide a Java style exception trace
 * 
 * From https://www.php.net/manual/en/exception.gettraceasstring.php#114980
 * 
 * @param $exception
 * @param $seen      - array passed to recursive calls to accumulate trace lines already seen
 *                     leave as NULL when calling this function
 * @return array of strings, one entry per trace line
 */
function jTraceEx($e, $seen=null) {
    $starter = $seen ? 'Caused by: ' : '';
    $result = array();
    if (!$seen) $seen = array();
    $trace  = $e->getTrace();
    $prev   = $e->getPrevious();
    $result[] = sprintf('%s%s: %s', $starter, get_class($e), $e->getMessage());
    $file = $e->getFile();
    $line = $e->getLine();
    while (true) {
        $current = "$file:$line";
        if (is_array($seen) && in_array($current, $seen)) {
            $result[] = sprintf(' ... %d more', count($trace)+1);
            break;
        }
        $result[] = sprintf(' at %s%s%s(%s%s%s)',
                                    count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '',
                                    count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '',
                                    count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)',
                                    $line === null ? $file : basename($file),
                                    $line === null ? '' : ':',
                                    $line === null ? '' : $line);
        if (is_array($seen))
            $seen[] = "$file:$line";
        if (!count($trace))
            break;
        $file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'Unknown Source';
        $line = array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line'] ? $trace[0]['line'] : null;
        array_shift($trace);
    }
    $result = join("\n", $result);
    if ($prev)
        $result  .= "\n" . jTraceEx($prev, $seen);

    return $result;
}