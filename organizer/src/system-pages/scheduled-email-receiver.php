<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailReceiver.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up time and memory limits
set_time_limit(0);
ini_set('memory_limit', '768M');

// Create the email receiver
$emailReceiver = new ThreadScheduledEmailReceiver();

// Process the next folder
// Note: We only process one folder at a time to avoid overloading the system
$result = $emailReceiver->processNextFolder();

if (!$result['success']) {
    // Output the result
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$results = array($result);

$result = $emailReceiver->processNextFolder();

$results[] = $result;

if ($result['success']) {
    for($i = 0; $i < 10; $i++) {
        $result = $emailReceiver->processNextFolder();
        $results[] = $result;

        if (!$result['success']) {
            break;
        }
    }
}

// Output the result
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
