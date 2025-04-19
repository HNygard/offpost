<?php

require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create the follow-up sender
$followUpSender = new ThreadScheduledFollowUpSender();

// Send the next follow-up email
// Note: We only process one at a time to avoid sending too many emails at once
$result = $followUpSender->sendNextFollowUpEmail();

// Output the result
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
