<?php
// organizer/src/api/admin/analysis_request.php
// Admin analysis-queue API. Token auth only, NOT public, POST only - see
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 2:
// the endpoints".
require_once __DIR__ . '/admin-api-auth.php';
require_once __DIR__ . '/../../class/Database.php';
require_once __DIR__ . '/../../class/ThreadAnalysis/ThreadAnalysisRepository.php';

header('Content-Type: application/json');
// Auth first, before any method check or input parsing/DB access.
$authMethod = adminApiRequireToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)
    || !isset($input['thread_id'], $input['mode'])
    || !is_string($input['thread_id'])
    || !is_string($input['mode'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON fields: thread_id, mode']);
    exit;
}

$threadId = $input['thread_id'];
// threads.id is a Postgres uuid column, and thread_analysis_runs.thread_id
// has a foreign key on it - anything that isn't well-formed must be rejected
// here with a 400, before it reaches a query or that constraint, either of
// which would otherwise 500 with a leaked stack trace.
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
if (!preg_match($uuidRe, $threadId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed thread_id']);
    exit;
}

$mode = $input['mode'];
if ($mode !== 'incremental' && $mode !== 'full') {
    http_response_code(400);
    echo json_encode(['error' => "mode must be 'incremental' or 'full', got " . json_encode($mode)]);
    exit;
}

if (Database::queryOneOrNone("SELECT id FROM threads WHERE id = ?", [$threadId]) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

try {
    $runId = ThreadAnalysisRepository::requestRun($threadId, $mode, 'token');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

error_log(
    'admin analysis access: endpoint=/api/admin/analysis/request thread_id=' . $threadId
    . ' mode=' . $mode . ' run_id=' . $runId . ' auth=' . $authMethod
);

echo json_encode(['run_id' => $runId]);
