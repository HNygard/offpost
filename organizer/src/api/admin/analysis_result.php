<?php
// organizer/src/api/admin/analysis_result.php
// Admin analysis-queue API. Token auth only, NOT public, POST only - see
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 2:
// the endpoints".
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
if (!is_array($input)
    || !isset($input['run_id'], $input['worker'])
    || !is_int($input['run_id'])
    || !is_string($input['worker'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON fields: run_id (int), worker (string)']);
    exit;
}

$runId = $input['run_id'];
$worker = $input['worker'];

if (ThreadAnalysisRepository::getRun($runId) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Everything but run_id/worker is the result format from change 1 (the plan's
// "Result format"): pass it straight through to saveResult() for validation.
$result = $input;
unset($result['run_id'], $result['worker']);

try {
    ThreadAnalysisRepository::saveResult($runId, $worker, $result);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$run = ThreadAnalysisRepository::getRun($runId);

error_log(
    'admin analysis access: endpoint=/api/admin/analysis/result run_id=' . $runId
    . ' worker=' . $worker . ' status=' . $run['status'] . ' auth=' . $authMethod
);

echo json_encode(['run_id' => $runId, 'status' => $run['status']]);
