<?php

require_once __DIR__ . '/../class/Extraction/ThreadEmailStatusUpdater.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Rule-based classification of new incoming emails (auto-replies by subject,
// emails carrying documents), without waiting for the AI summary.
// See ThreadEmailResponseClassifier.

error_reporting(E_ALL);
ini_set('display_errors', 1);

$startTime = microtime(true);
$taskName = 'scheduled-email-classification';
error_log(date('Y-m-d H:i:s') . " [$taskName] Starting task");

try {
    $updater = new ThreadEmailStatusUpdater();
    $result = $updater->classifyPendingByRules(50);

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);

    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task completed in {$duration}s - found {$result['found']}, classified " . json_encode($result['classified']));

} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task failed in {$duration}s - Exception: " . $e->getMessage());
    $exceptionChain = [];
    for ($chainError = $e; $chainError !== null; $chainError = $chainError->getPrevious()) {
        $exceptionChain[] = $chainError->getMessage();
    }
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        $taskName,
        'Unexpected error: ' . $e->getMessage(),
        [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
            'exception_chain' => $exceptionChain,
        ]
    );

    throw $e;
}
