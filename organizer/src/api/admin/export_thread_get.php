<?php
// organizer/src/api/admin/export_thread_get.php
// Admin thread-export API. Token or admin-session auth, NOT public.
require_once __DIR__ . '/admin-api-auth.php';
require_once __DIR__ . '/../../class/ThreadExportService.php';

header('Content-Type: application/json');
// Auth first, before any method check or input parsing/DB access.
$authMethod = adminApiRequireTokenOrAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$threadId = $_GET['id'] ?? '';
// threads.id is a Postgres uuid column - anything that isn't well-formed must
// be rejected here with a 400 before it reaches a query, where Postgres would
// throw and 500 with a leaked stack trace (invalid input syntax for type uuid).
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
if (!preg_match($uuidRe, $threadId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed id']);
    exit;
}

$thread = ThreadExportService::exportThread($threadId);
if ($thread === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

error_log('admin export access: endpoint=/api/admin/export/thread thread_id=' . $threadId . ' auth=' . $authMethod);

echo json_encode($thread, JSON_INVALID_UTF8_SUBSTITUTE);
