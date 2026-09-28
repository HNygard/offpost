<?php
// organizer/src/api/admin/analysis_request_next.php
// Admin analysis-queue API. Token auth only, NOT public, POST only - see
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 7:
// 'process next' for norske-postlister threads".
//
// Lets the worker take the next norske-postlister.no thread by itself,
// instead of being given a thread id: a thin wrapper around
// ThreadAnalysisRepository::requestNextNpThread(), which does the pick and
// the request in one transaction.
require_once __DIR__ . '/admin-api-auth.php';
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
if (!is_array($input) || !isset($input['kind']) || !is_string($input['kind'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON field: kind']);
    exit;
}

$kind = $input['kind'];
if ($kind !== 'np') {
    http_response_code(400);
    echo json_encode(['error' => "kind must be 'np', got " . json_encode($kind)]);
    exit;
}

$mode = $input['mode'] ?? 'incremental';
if ($mode !== 'incremental' && $mode !== 'full') {
    http_response_code(400);
    echo json_encode(['error' => "mode must be 'incremental' or 'full', got " . json_encode($mode)]);
    exit;
}

try {
    $picked = ThreadAnalysisRepository::requestNextNpThread($mode, 'token');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

if ($picked === null) {
    error_log('admin analysis access: endpoint=/api/admin/analysis/request-next kind=' . $kind . ' mode=' . $mode . ' result=none auth=' . $authMethod);
    http_response_code(204);
    exit;
}

error_log(
    'admin analysis access: endpoint=/api/admin/analysis/request-next kind=' . $kind
    . ' mode=' . $mode . ' run_id=' . $picked['run_id'] . ' thread_id=' . $picked['thread_id'] . ' auth=' . $authMethod
);

echo json_encode(['run_id' => $picked['run_id'], 'thread_id' => $picked['thread_id']]);
