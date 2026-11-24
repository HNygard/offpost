<?php

require_once __DIR__ . '/../mcp-config.php';
require_once __DIR__ . '/../class/OAuth/OAuthTokenService.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

// Prunes stale OAuth data (idle access tokens, unused clients, old codes and
// registration attempts). See OAuthTokenService::cleanup(). Runs daily.

error_reporting(E_ALL);
ini_set('display_errors', 1);

$startTime = microtime(true);
$taskName = 'scheduled-oauth-cleanup';
error_log(date('Y-m-d H:i:s') . " [$taskName] Starting task");

try {
    $counts = (new OAuthTokenService($mcp_resource))->cleanup();

    header('Content-Type: text/plain');
    foreach ($counts as $name => $count) {
        echo "$name: $count\n";
    }

    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task completed in {$duration}s - " . json_encode($counts));

} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 3);
    error_log(date('Y-m-d H:i:s') . " [$taskName] Task failed in {$duration}s - Exception: " . $e->getMessage());
    $adminNotificationService = new AdminNotificationService();
    $adminNotificationService->notifyAdminOfError(
        $taskName,
        'Unexpected error: ' . $e->getMessage(),
        [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
        ]
    );

    throw $e;
}
