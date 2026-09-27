<?php
// organizer/src/api/admin/export_threads_list.php
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

$threads = ThreadExportService::listThreads();

error_log('admin export access: endpoint=/api/admin/export/threads auth=' . $authMethod);

// JSON_INVALID_UTF8_SUBSTITUTE: thread titles/labels ultimately come from
// attacker-controlled email content; without this flag invalid UTF-8 anywhere
// in the payload makes json_encode() return false, silently emptying the
// response (see np_threads_list.php for the same reasoning).
echo json_encode(
    ['export_version' => ThreadExportService::EXPORT_VERSION, 'threads' => $threads],
    JSON_INVALID_UTF8_SUBSTITUTE
);
