<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSummary.php';

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$extractors = array(
    'email_body' => function() { return new ThreadEmailExtractorEmailBody(); },
    'attachment_pdf' => function() { return new ThreadEmailExtractorAttachmentPdf(); },
    'prompt_saksnummer' => function() { return new ThreadEmailExtractorPromptSaksnummer(); },
    'prompt_email_latest_reply' => function() { return new ThreadEmailExtractorPromptEmailLatestReply(); },
    'prompt_copy_asking_for' => function() { return new ThreadEmailExtractorPromptCopyAskingFor(); },
    'prompt_summary' => function() { return new ThreadEmailExtractorPromptSummary(); },
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

try {
    $results = array();
    for($i = 0; $i < 10; $i++) {
        $result = $extractor->processNextEmailExtraction();
        $result['extraction_type'] = $extractionType;
        $results[] = $result;

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
    
} catch (Exception $e) {
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
