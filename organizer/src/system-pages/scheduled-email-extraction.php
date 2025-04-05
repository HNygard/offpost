<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/ThreadEmailExtractorAttachmentPdf.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get the extraction type from the query string, default to 'email_body'
$extractionType = isset($_GET['type']) ? $_GET['type'] : 'email_body';

// Create the appropriate extractor based on the extraction type
if ($extractionType === 'attachment_pdf') {
    $extractor = new ThreadEmailExtractorAttachmentPdf();
} else {
    // Default to email body extraction
    $extractor = new ThreadEmailExtractorEmailBody();
}

// Process the next extraction
// Note: We only process one at a time to avoid overloading the system
$result = $extractor->processNextEmailExtraction();

// Add the extraction type to the result
$result['extraction_type'] = $extractionType;

// Output the result
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
