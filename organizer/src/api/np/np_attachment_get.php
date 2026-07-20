<?php
// organizer/src/api/np/np_attachment_get.php
// Serves attachment bytes to norske-postlister.no for re-serving. Token auth only.
require_once __DIR__ . '/np-api-auth.php';
require_once __DIR__ . '/../../class/NpApiService.php';

npApiRequireToken();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'GET only']);
    exit;
}

$threadId = $_GET['thread_id'] ?? '';
$attachmentId = $_GET['attachment_id'] ?? '';
if (!preg_match('/^[0-9a-f-]{36}$/', $threadId) || !preg_match('/^[0-9a-zA-Z_-]+$/', $attachmentId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Malformed thread_id or attachment_id']);
    exit;
}

try {
    $attachment = NpApiService::getNpAttachment($threadId, $attachmentId);
    header('Content-Type: ' . $attachment['content_type']);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $attachment['name']) . '"');
    header('Content-Length: ' . strlen($attachment['content']));
    echo $attachment['content'];
} catch (NpApiEntityNotFoundException $e) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
}
