<?php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractionService.php';

// Require authentication
requireAuth();

// Set JSON header
header('Content-Type: application/json');

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
