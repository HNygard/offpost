<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailSender.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create the email sender
$emailSender = new ThreadScheduledEmailSender();

// Send the next scheduled email
// Note: We only send one at the time to not trigger to many alerts for spam.
$result = $emailSender->sendNextScheduledEmail();

// Output the result
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
