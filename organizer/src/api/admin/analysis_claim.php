<?php
// organizer/src/api/admin/analysis_claim.php
// Admin analysis-queue API. Token auth only, NOT public, POST only - see
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 2:
// the endpoints".
require_once __DIR__ . '/admin-api-auth.php';
require_once __DIR__ . '/../../class/ThreadAnalysis/ThreadAnalysisRepository.php';
require_once __DIR__ . '/../../class/ThreadAnalysis/ThreadAnalysisWorkItem.php';
require_once __DIR__ . '/../../class/ThreadExportService.php';

// A claim's lease, per the plan. An expired lease makes the run claimable
// again (ThreadAnalysisRepository::claimNext()/claimThread()).
const ANALYSIS_CLAIM_LEASE_SECONDS = 3600;

header('Content-Type: application/json');
// Auth first, before any method check or input parsing/DB access.
$authMethod = adminApiRequireToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['worker']) || !is_string($input['worker']) || $input['worker'] === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Required JSON field: worker']);
    exit;
}
$worker = $input['worker'];

$threadId = $input['thread_id'] ?? null;
if ($threadId !== null) {
    if (!is_string($threadId)) {
        http_response_code(400);
        echo json_encode(['error' => 'thread_id must be a string']);
        exit;
    }
    $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    if (!preg_match($uuidRe, $threadId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Malformed thread_id']);
        exit;
    }
}

try {
    $run = $threadId !== null
        ? ThreadAnalysisRepository::claimThread($threadId, $worker, ANALYSIS_CLAIM_LEASE_SECONDS)
        : ThreadAnalysisRepository::claimNext($worker, ANALYSIS_CLAIM_LEASE_SECONDS);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

if ($run === null) {
    error_log('admin analysis access: endpoint=/api/admin/analysis/claim worker=' . $worker . ' result=none auth=' . $authMethod);
    http_response_code(204);
    exit;
}

// includeEml=false: the worker analyses text/metadata, not the raw EML - see
// "Change 2" in the plan.
$export = ThreadExportService::exportThread($run['thread_id'], false);
if ($export === null) {
    // The run's thread no longer exists - surface it rather than crash below.
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$workItem = ThreadAnalysisWorkItem::selectEmails($export['emails'], $run['mode']);

error_log(
    'admin analysis access: endpoint=/api/admin/analysis/claim run_id=' . $run['id']
    . ' thread_id=' . $run['thread_id'] . ' worker=' . $worker . ' auth=' . $authMethod
);

echo json_encode(
    [
        'run' => [
            'id' => (int) $run['id'],
            'thread_id' => $run['thread_id'],
            'mode' => $run['mode'],
            'lease_expires_at' => $run['lease_expires_at'] !== null
                ? (new DateTime($run['lease_expires_at']))->format(DateTimeInterface::ATOM)
                : null,
        ],
        'thread' => $export,
        'start_state' => $workItem['start_state'],
        'start_after_email_id' => $workItem['start_after_email_id'],
        'email_ids' => $workItem['email_ids'],
    ],
    JSON_INVALID_UTF8_SUBSTITUTE
);
