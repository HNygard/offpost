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

// Output the result
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
