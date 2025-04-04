<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/ThreadEmailExtractorEmailBody.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create the email extractor
$emailExtractor = new ThreadEmailExtractorEmailBody();

// Process the next email extraction
// Note: We only process one at a time to avoid overloading the system
$result = $emailExtractor->processNextEmailExtraction();

// Output the result
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
