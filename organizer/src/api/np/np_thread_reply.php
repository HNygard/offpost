<?php
// organizer/src/api/np/np_thread_reply.php
// POST /api/np/thread/{thread_id}/reply
// Queues a reply from the thread's own profile to the entity, ready for
// sending. Used by norske-postlister.no to ask for a readable copy of a
// postjournal. At most one reply per thread per day from this API.
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

$threadId = $_GET['thread_id'] ?? '';
if (!is_uuid($threadId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed thread_id']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)
    || !isset($input['subject'], $input['body'])
    || !is_string($input['subject']) || !is_string($input['body'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON fields: subject, body (strings)']);
    exit;
}

try {
    echo json_encode(NpApiService::replyToThread($threadId, $input['subject'], $input['body']));
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
