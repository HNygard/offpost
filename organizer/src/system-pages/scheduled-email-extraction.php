<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';
require_once __DIR__ . '/../class/ScheduledTaskLogger.php';

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

// Start task logging with extraction type
$taskLogger = new ScheduledTaskLogger('scheduled-email-extraction-' . $extractionType);
$taskLogger->start();

// Create the appropriate extractor based on the extraction type
$extractor = $extractors[$extractionType] ?? null;
if ($extractor === null) {
    // If the extraction type is not recognized, return an error
    $taskLogger->fail('Invalid extraction type: ' . $extractionType);
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
        
        // Track items processed for each result
        if ($result['success']) {
            $taskLogger->addItemsProcessed(1);
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
    $output = json_encode($results, JSON_PRETTY_PRINT);
    echo $output;
    
    // Track bytes in output (extraction results may include large email content)
    $taskLogger->addBytesProcessed(strlen($output));
    $taskLogger->complete('Processed ' . count($results) . ' extraction(s) of type: ' . $extractionType);
    
} catch (Exception $e) {
    $taskLogger->fail($e->getMessage());
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
