<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$extractors = array(
    'email_body' => function() { return new ThreadEmailExtractorEmailBody(); },
    'attachment_pdf' => function() { return new ThreadEmailExtractorAttachmentPdf(); },
    'prompt_saksnummer' => function() { return new ThreadEmailExtractorPromptSaksnummer(); },
    'prompt_email_latest_reply' => function() { return new ThreadEmailExtractorPromptEmailLatestReply(); },
);

// Get the extraction type from the query string, default to 'email_body'
$extractionType = isset($_GET['type']) ? $_GET['type'] : 'email_body';

// Create the appropriate extractor based on the extraction type
$extractor = $extractors[$extractionType] ?? null;
if ($extractor === null) {
    // If the extraction type is not recognized, return an error
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid extraction type'], JSON_PRETTY_PRINT);
    exit;
}
$extractor = $extractor();

// Process the next extraction
// Note: We only process one at a time to avoid overloading the system
$result = $extractor->processNextEmailExtraction();

// Add the extraction type to the result
$result['extraction_type'] = $extractionType;

// Output the result
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
