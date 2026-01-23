<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorAttachmentPdf.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSaksnummer.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';

// Require authentication
requireAuth();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get the request data - support both JSON and form-encoded data
$data = null;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    // JSON request from AJAX
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Check for JSON decoding errors explicitly
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body']);
        exit;
    }
} else {
    // Form-encoded request (e.g., from tests or form submission)
    $data = $_POST;
}

if (!isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request: ids array required']);
    exit;
}

$ids = array_map('intval', $data['ids']);
// Filter out invalid IDs (0 or negative values)
$ids = array_filter($ids, function($id) { return $id > 0; });
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided']);
    exit;
}

// Get the extraction entries
$extractionService = new ThreadEmailExtractionService();
$results = [];
$successCount = 0;
$errorCount = 0;

foreach ($ids as $id) {
    try {
        // Get the extraction by ID
        $extraction = $extractionService->getExtractionById($id);
        
        if (!$extraction) {
            $results[] = [
                'id' => $id,
                'success' => false,
                'message' => 'Extraction not found'
            ];
            $errorCount++;
            continue;
        }
        
        // Delete the existing extraction to allow re-processing
        $deleted = $extractionService->deleteExtraction($id);
        
        if (!$deleted) {
            $results[] = [
                'id' => $id,
                'success' => false,
                'message' => 'Failed to delete extraction'
            ];
            $errorCount++;
            continue;
        }
        
        $results[] = [
            'id' => $id,
            'success' => true,
            'message' => 'Extraction deleted successfully - will be re-processed automatically',
            'email_id' => $extraction->email_id,
            'prompt_text' => $extraction->prompt_text
        ];
        $successCount++;
        
    } catch (Exception $e) {
        $results[] = [
            'id' => $id,
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
        $errorCount++;
    }
}

// Return the results
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => "Deleted $successCount extraction(s) successfully for re-processing" . ($errorCount > 0 ? ", $errorCount failed" : ""),
    'results' => $results,
    'successCount' => $successCount,
    'errorCount' => $errorCount
]);
