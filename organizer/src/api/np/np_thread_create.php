<?php
// organizer/src/api/np/np_thread_create.php
// Server-to-server API for norske-postlister.no. Token auth, NOT session auth.
require_once __DIR__ . '/np-api-auth.php';
require_once __DIR__ . '/../../class/NpApiService.php';

header('Content-Type: application/json');
npApiRequireToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)
    || !isset($input['entity_id_norske_postlister'], $input['title'], $input['body'], $input['labels'])
    || !is_array($input['labels'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON fields: entity_id_norske_postlister, title, body, labels[]']);
    exit;
}

// Reject mapping labels whose value after the prefix is empty (e.g. exactly
// "document_id:" or "case_num:" after trimming). NpApiService::createThread
// would otherwise accept these and dedup every such document onto one thread.
foreach ($input['labels'] as $label) {
    if (!is_string($label)) {
        http_response_code(400);
        echo json_encode(['error' => 'labels must be an array of strings']);
        exit;
    }
    $trimmed = trim($label);
    if (($trimmed === 'document_id:') || ($trimmed === 'case_num:')) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty document_id/case_num label value']);
        exit;
    }
}

try {
    $result = NpApiService::createThread(
        $input['entity_id_norske_postlister'],
        $input['title'],
        $input['body'],
        $input['labels']
    );
    echo json_encode($result);
} catch (NpApiEntityNotFoundException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
} catch (NpApiValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (NpApiCapExceededException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()]);
}
