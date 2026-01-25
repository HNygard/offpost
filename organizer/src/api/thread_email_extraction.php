<?php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/Database.php';

// Require authentication
requireAuth();

// Set JSON header
header('Content-Type: application/json');

$userId = $_SESSION['user']['sub']; // OpenID Connect subject identifier

// Check if extraction_id is provided
if (!isset($_GET['extraction_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'extraction_id parameter is required']);
    exit;
}

$extractionId = intval($_GET['extraction_id']);

if ($extractionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid extraction_id']);
    exit;
}

try {
    $service = new ThreadEmailExtractionService();
    $extraction = $service->getExtractionById($extractionId);
    
    if ($extraction === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Extraction not found']);
        exit;
    }
    
    // Get the thread_id from the email associated with this extraction
    $threadId = Database::queryValue(
        "SELECT thread_id FROM thread_emails WHERE id = ?",
        [$extraction->email_id]
    );
    
    if (!$threadId) {
        http_response_code(404);
        echo json_encode(['error' => 'Extraction not found or inaccessible']);
        exit;
    }
    
    // Load the thread and check authorization
    $thread = Thread::loadFromDatabaseOrNone($threadId);
    if (!$thread) {
        http_response_code(404);
        echo json_encode(['error' => 'Thread not found']);
        exit;
    }
    
    // Check if user has access to this thread
    if (!$thread->canUserAccess($userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to access this extraction']);
        exit;
    }
    
    // Return extraction data
    $response = [
        'extraction_id' => $extraction->extraction_id,
        'email_id' => $extraction->email_id,
        'attachment_id' => $extraction->attachment_id,
        'prompt_id' => $extraction->prompt_id,
        'prompt_text' => $extraction->prompt_text,
        'prompt_service' => $extraction->prompt_service,
        'extracted_text' => $extraction->extracted_text,
        'error_message' => $extraction->error_message,
        'created_at' => $extraction->created_at,
        'updated_at' => $extraction->updated_at
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Log the full error details server-side
    error_log('Error fetching extraction: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve extraction data']);
}
