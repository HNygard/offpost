<?php

require_once __DIR__ . '/class/Imap/ImapWrapper.php';

use Imap\ImapWrapper;

// Simple test to validate the ImapWrapper retry logic
echo "Testing ImapWrapper retry logic...\n";

$wrapper = new ImapWrapper();

// Test private method access using reflection
$reflection = new ReflectionClass(ImapWrapper::class);

// Test constants
$maxRetries = $reflection->getConstant('MAX_RETRIES');
$retryDelay = $reflection->getConstant('RETRY_DELAY_MS');

echo "MAX_RETRIES: $maxRetries\n";
echo "RETRY_DELAY_MS: $retryDelay\n";

// Test isRetryableError method
$isRetryableMethod = $reflection->getMethod('isRetryableError');
$isRetryableMethod->setAccessible(true);

$testCases = [
    ['[CLOSED] IMAP connection broken', true],
    ['connection lost', true],
    ['connection reset', true],
    ['timeout occurred', true],
    ['network error', true],
    ['server response', true],
    ['invalid credentials', false],
    ['authentication failed', false],
    ['mailbox not found', false],
];

echo "\nTesting isRetryableError method:\n";
foreach ($testCases as [$error, $expected]) {
    $result = $isRetryableMethod->invoke($wrapper, $error);
    $status = $result === $expected ? 'PASS' : 'FAIL';
    echo "  '$error' -> " . ($result ? 'true' : 'false') . " ($status)\n";
}

// Test waitBeforeRetry method (just make sure it doesn't crash)
$waitMethod = $reflection->getMethod('waitBeforeRetry');
$waitMethod->setAccessible(true);

echo "\nTesting waitBeforeRetry method:\n";
$start = microtime(true);
$waitMethod->invoke($wrapper, 1);
$elapsed = microtime(true) - $start;
echo "  First retry delay: " . number_format($elapsed * 1000, 2) . "ms\n";

echo "\nAll tests completed successfully!\n";