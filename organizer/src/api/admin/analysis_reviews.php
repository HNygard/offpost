<?php
// organizer/src/api/admin/analysis_reviews.php
// Admin analysis-queue API. Token or admin-session auth, GET only - feeds
// the local fix loop (tools/analysis-worker.php --reviews). See
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 9:
// review status and notes per run (step 2b, first part)".
require_once __DIR__ . '/admin-api-auth.php';
require_once __DIR__ . '/../../class/ThreadAnalysis/ThreadAnalysisRepository.php';

header('Content-Type: application/json');
// Auth first, before any method check or input parsing/DB access.
$authMethod = adminApiRequireTokenOrAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$statuses = [];
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $statuses = explode(',', $_GET['status']);
    foreach ($statuses as $status) {
        if (!in_array($status, ThreadAnalysisRepository::REVIEW_STATUSES, true)) {
            http_response_code(400);
            echo json_encode(['error' => "Unknown review status: " . json_encode($status)]);
            exit;
        }
    }
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
if ($limit <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'limit must be a positive integer']);
    exit;
}

$reviews = ThreadAnalysisRepository::getReviews($statuses, $limit);

error_log(
    'admin analysis access: endpoint=/api/admin/analysis/reviews status=' . implode(',', $statuses)
    . ' limit=' . $limit . ' auth=' . $authMethod
);

echo json_encode(['reviews' => $reviews], JSON_INVALID_UTF8_SUBSTITUTE);
