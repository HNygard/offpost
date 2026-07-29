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
// Both thread_id and attachment_id are Postgres uuid columns - anything that
// isn't a well-formed UUID must be rejected here with a 400, not passed
// through to the query, where Postgres would throw and 500 with a leaked
// HTML stack trace (invalid input syntax for type uuid).
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
if (!preg_match($uuidRe, $threadId) || !preg_match($uuidRe, $attachmentId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Malformed thread_id or attachment_id']);
    exit;
}

try {
    $attachment = NpApiService::getNpAttachment($threadId, $attachmentId);
    // Attachment names are attacker-controlled (incoming email attachment filenames).
    // Strip ASCII control characters (incl. CR/LF, which would otherwise let a malicious
    // filename inject arbitrary response headers or crash header() with a 500) plus
    // '"' and '\' which would break out of the quoted filename value.
    $safeName = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '', $attachment['name']);
    if ($safeName === '') {
        $safeName = 'attachment';
    }
    header('Content-Type: ' . $attachment['content_type']);
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . strlen($attachment['content']));
    echo $attachment['content'];
} catch (NpApiEntityNotFoundException $e) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
}
