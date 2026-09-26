<?php

require_once __DIR__ . '/../class/NpThreadAutoArchiver.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Archive norske-postlister.no threads that finished successfully.
// See NpThreadAutoArchiver. ?dry_run=1 lists what would be archived.

error_reporting(E_ALL);
ini_set('display_errors', 1);

$startTime = microtime(true);
$taskName = 'scheduled-np-thread-archiving';
error_log(date('Y-m-d H:i:s') . " [$taskName] Starting task");

try {
    $dryRun = !empty($_GET['dry_run']);
    $archiver = new NpThreadAutoArchiver();
    $result = $archiver->archiveFinishedThreads($dryRun);
    $result['dry_run'] = $dryRun;

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);

    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task completed in {$duration}s - "
        . ($dryRun ? 'would archive ' : 'archived ') . count($result['archived']) . ' threads');

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
