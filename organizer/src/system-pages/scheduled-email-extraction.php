<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$extractors = array(
    'email_body' => function() { return new ThreadEmailExtractorEmailBody(); },
    'attachment_pdf' => function() { return new ThreadEmailExtractorAttachmentPdf(); },
    'prompt_saksnummer' => function() { return new ThreadEmailExtractorPromptSaksnummer(); },
    'prompt_email_latest_reply' => function() { return new ThreadEmailExtractorPromptEmailLatestReply(); },
    'prompt_copy_asking_for' => function() { return new ThreadEmailExtractorPromptCopyAskingFor(); },
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
$result = $extractor->processNextEmailExtraction();

// Add the extraction type to the result
$result['extraction_type'] = $extractionType;

if (!$result['success']) {
    // Output the result
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$results = array($result);

$result = $extractor->processNextEmailExtraction();
$result['extraction_type'] = $extractionType;
$results[] = $result;

if ($result['success']) {
    for($i = 0; $i < 10; $i++) {
        $result = $extractor->processNextEmailExtraction();
        $result['extraction_type'] = $extractionType;
        $results[] = $result;

        if (!$result['success']) {
            break;
        }
    }
}

// Output the result
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
