<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

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

$startTime = microtime(true);
$taskName = 'scheduled-email-extraction-' . $extractionType;
error_log("[$taskName] Starting task");

// Create the appropriate extractor based on the extraction type
$extractor = $extractors[$extractionType] ?? null;
if ($extractor === null) {
    // If the extraction type is not recognized, return an error
    error_log("[$taskName] Invalid extraction type: $extractionType");
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid extraction type'], JSON_PRETTY_PRINT);
    exit;
}
$extractor = $extractor();

try {
    $results = array();
    $extractionsProcessed = 0;
    for($i = 0; $i < 10; $i++) {
        $result = $extractor->processNextEmailExtraction();
        $result['extraction_type'] = $extractionType;
        $results[] = $result;
        if ($result['success']) {
            $extractionsProcessed++;
        }

        if (!$result['success'] && $result['message'] !== 'No emails found that need extraction') {
            // Log the error and notify administrators
            $adminNotificationService = new AdminNotificationService();
            $adminNotificationService->notifyAdminOfError(
                'scheduled-email-extraction',
                'Unsuccessful email extraction',
                $result
            );

            break;
        }
    }

    // Output the result
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT);
    
    $duration = round(microtime(true) - $startTime, 3);
    error_log("[$taskName] Task completed in {$duration}s - Processed $extractionsProcessed extractions");
    
} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 3);
    error_log("[$taskName] Task failed in {$duration}s - Exception: " . $e->getMessage());
    // Log the error and notify administrators
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        'scheduled-email-extraction',
        'Unexpected error: ' . $e->getMessage(),
        [
            'extraction_type' => $extractionType,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString()
        ]
    );

    throw $e;
}
