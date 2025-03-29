<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailReceiver.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$result2 = $emailReceiver->processNextFolder();

$results = array(
    $result,
    $result2
);

if ($result2['success']) {
    $result3 = $emailReceiver->processNextFolder();
    $results[] = $result3;
}

// Output the result
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
