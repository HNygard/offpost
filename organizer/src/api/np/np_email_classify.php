<?php
// organizer/src/api/np/np_email_classify.php
// POST /api/np/thread/{thread_id}/email/{email_id}/classify
// Sets the classification of one incoming email, as the manual classify form
// does. Used by norske-postlister.no to mark a postjournal reply it could not
// read (RESPONSE_UNREADABLE) so the postliste follow-up plan keeps nagging.
// Token auth, or an admin session carrying X-Requested-With: offpost-email.
require_once __DIR__ . '/np-api-auth.php';
require_once __DIR__ . '/../../class/NpApiService.php';
require_once __DIR__ . '/../../class/common.php';

header('Content-Type: application/json');
npApiRequireTokenOrOffpostEmailAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// Both ids are Postgres uuid columns; a malformed value must be a 400 here,
// not a Postgres error further down (see np_attachment_get.php).
$threadId = $_GET['thread_id'] ?? '';
$emailId = $_GET['email_id'] ?? '';
if (!is_uuid($threadId) || !is_uuid($emailId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed thread_id or email_id']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['status_type']) || !is_string($input['status_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON fields: status_type (string), status_text (string, may be empty)']);
    exit;
}
$statusText = $input['status_text'] ?? '';
if (!is_string($statusText)) {
    http_response_code(400);
    echo json_encode(['error' => 'status_text must be a string']);
    exit;
}

try {
    echo json_encode(NpApiService::classifyEmail($threadId, $emailId, $input['status_type'], $statusText));
} catch (NpApiEntityNotFoundException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
} catch (NpApiValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
