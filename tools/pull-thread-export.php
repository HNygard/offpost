<?php
// tools/pull-thread-export.php
//
// Runs on your own machine, not on the server: a client for the admin export
// API, needing only plain PHP + ext-curl. No composer autoload is needed; only
// ThreadExportSync.php is require_once'd.
//
// Pulls every thread from the admin export API (docs/thread-export-api.md)
// into a local folder, resuming from where a previous run left off (by
// per-thread fingerprint) unless --full is given.
//
// Usage:
//   php tools/pull-thread-export.php \
//       --base-url=https://offpost.no --token-file=secrets/admin_api_token \
//       [--out=thread-export] [--full] [--limit=N]
//
// Output layout (relative to --out, default "thread-export" under cwd):
//   <out>/threads/<id>.json  - one export per thread
//   <out>/index.json         - id => fingerprint, written after each thread

require_once __DIR__ . '/../organizer/src/class/ThreadExportSync.php';

function printHelp(): void {
    echo <<<HELP
Usage: php pull-thread-export.php --base-url=<url> --token-file=<path> [options]

Required:
  --base-url=URL      Base URL of the Offpost instance, e.g. https://offpost.no
  --token-file=PATH   File containing the admin API token (X-Admin-Api-Token)

Options:
  --out=DIR           Output directory, relative to cwd (default: thread-export)
  --full              Refetch every thread, ignoring local fingerprints
  --limit=N           Fetch at most N threads this run
  --help              Show this help and exit

Writes <out>/threads/<id>.json and <out>/index.json (id => fingerprint).
Prints "fetched N, unchanged M, failed K" and exits 1 if any thread failed
or the list request itself failed, else exits 0. Never deletes local files.

HELP;
}

function fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function httpGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Admin-Api-Token: ' . $token],
        CURLOPT_TIMEOUT => 120,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [0, null, $error];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body, null];
}

function writeJsonFileAtomically(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create directory: $dir");
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException("Could not encode JSON for: $path");
    }
    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json) === false) {
        throw new RuntimeException("Could not write temp file: $tmpPath");
    }
    if (!rename($tmpPath, $path)) {
        throw new RuntimeException("Could not rename $tmpPath to $path");
    }
}

// -- argv parsing --

$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    printHelp();
    exit(0);
}

$baseUrl = null;
$tokenFile = null;
$out = 'thread-export';
$full = false;
$limit = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = substr($arg, strlen('--base-url='));
    }
    elseif (str_starts_with($arg, '--token-file=')) {
        $tokenFile = substr($arg, strlen('--token-file='));
    }
    elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    }
    elseif ($arg === '--full') {
        $full = true;
    }
    elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, strlen('--limit='));
    }
    else {
        fail("Unknown argument: $arg\n\nRun with --help for usage.");
    }
}

if ($baseUrl === null || $baseUrl === '') {
    fail("Missing required --base-url=URL\n\nRun with --help for usage.");
}
if ($tokenFile === null || $tokenFile === '') {
    fail("Missing required --token-file=PATH\n\nRun with --help for usage.");
}
if (!is_file($tokenFile)) {
    fail("Token file not found: $tokenFile");
}
$token = trim(file_get_contents($tokenFile));
if ($token === '') {
    fail("Token file is empty: $tokenFile");
}

$baseUrl = rtrim($baseUrl, '/');
if (!ThreadExportSync::isSafeBaseUrl($baseUrl)) {
    fail("Refusing to send the admin token to $baseUrl: use https (plain http only for localhost)");
}
$threadsDir = $out . '/threads';
$indexPath = $out . '/index.json';

// -- load local index --

$localIndex = [];
if (is_file($indexPath)) {
    $decoded = json_decode(file_get_contents($indexPath), true);
    if (is_array($decoded)) {
        $localIndex = $decoded;
    }
}

// -- fetch the list --

[$status, $body, $curlError] = httpGet($baseUrl . '/api/admin/export/threads', $token);
if ($curlError !== null) {
    fail("Failed to fetch thread list: $curlError");
}
if ($status !== 200) {
    fail("Failed to fetch thread list: HTTP $status\n$body");
}
$list = json_decode($body, true);
if (!is_array($list) || !isset($list['export_version'])) {
    fail("Failed to fetch thread list: response is not valid export JSON");
}
if ($list['export_version'] !== 1) {
    fail("Unsupported export_version: " . json_encode($list['export_version']) . " (expected 1)");
}
$listedThreads = $list['threads'] ?? [];

$plan = ThreadExportSync::planFetch($listedThreads, $localIndex, $full, $limit);

$fetched = 0;
$failed = 0;

foreach ($plan['toFetch'] as $id) {
    $url = $baseUrl . '/api/admin/export/thread?id=' . urlencode($id);
    [$status, $body, $curlError] = httpGet($url, $token);

    if ($curlError !== null) {
        fwrite(STDERR, "Failed to fetch thread $id: $curlError\n");
        $failed++;
        continue;
    }
    if ($status !== 200) {
        fwrite(STDERR, "Failed to fetch thread $id: HTTP $status\n");
        $failed++;
        continue;
    }

    $threadData = json_decode($body, true);
    if (!is_array($threadData) || !isset($threadData['export_version']) || $threadData['export_version'] !== 1) {
        fwrite(STDERR, "Failed to fetch thread $id: response is not valid export_version 1 JSON\n");
        $failed++;
        continue;
    }

    try {
        writeJsonFileAtomically($threadsDir . '/' . $id . '.json', $threadData);
        $localIndex[$id] = $threadData['fingerprint'];
        writeJsonFileAtomically($indexPath, $localIndex);
    }
    catch (Throwable $e) {
        fwrite(STDERR, "Failed to save thread $id: " . $e->getMessage() . "\n");
        $failed++;
        continue;
    }

    $fetched++;
}

echo ThreadExportSync::summaryLine($fetched, $plan['unchanged'], $failed) . "\n";

exit($failed > 0 ? 1 : 0);
