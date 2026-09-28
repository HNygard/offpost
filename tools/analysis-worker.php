<?php
// tools/analysis-worker.php
//
// The worker for step 2c of the innsynskrav classification roadmap
// (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 3:
// the worker"): runs on the owner's machine, calls headless Claude Code,
// and drives prod's analysis queue over the change-2 admin endpoints
// (docs/thread-analysis.md). Prod stores and shows everything; this tool
// only makes the model calls and posts the results.
//
// Usage:
//   php tools/analysis-worker.php --base-url=https://offpost.no --token-file=secrets/admin_api_token
//       [--thread=<id> [--mode=incremental|full]] [--once] [--worker=<name>]
//       [--model=claude-opus-5-5] [--max-budget-usd=20] [--out=thread-analysis]
//       [--claude-bin=claude] [--background] [--help]

require_once __DIR__ . '/analysis/ThreadEventAnalysis.php';
require_once __DIR__ . '/analysis/ClaudeCodeEventRunner.php';
require_once __DIR__ . '/../organizer/src/class/ThreadExportSync.php';
require_once __DIR__ . '/../organizer/src/class/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

function printHelp(): void {
    echo <<<HELP
Usage: php tools/analysis-worker.php [options]

Required:
  --base-url=URL       Base URL of the Offpost instance, e.g. https://offpost.no
  --token-file=PATH    File containing the admin API token (X-Admin-Api-Token)

Options:
  --thread=ID          Request (mode --mode) and claim this thread's run, then stop; implies --once
  --mode=MODE          incremental|full, used with --thread and --next-np (default: incremental)
  --once               Process at most one run, then stop
  --next-np            Pick the next norske-postlister.no thread (POST request-next) instead of
                       draining the general queue; repeats up to --limit times, then stops. Cannot
                       be combined with --thread.
  --limit=N            Max threads to process with --next-np (default: 1)
  --worker=NAME        Worker name sent to prod (default: this machine's hostname)
  --model=NAME         Model to pass to claude (default: claude-opus-5-5)
  --max-budget-usd=N   Stop claiming new runs once this process's summed cost reaches this (default: 20)
  --out=DIR            Where worker state (pending/posted/rejected results, worker.log) is kept (default: thread-analysis)
  --claude-bin=PATH    Command to invoke instead of "claude" (for tests)
  --background         Relaunch detached and exit; see <out>/worker/worker.log
  --reviews            Print reviewed runs with issues (GET /api/admin/analysis/reviews) and exit;
                       cannot be combined with --thread or --next-np
  --status=LIST        Comma-separated review statuses for --reviews (default: MINOR_ISSUES,WRONG)
  --help               Show this help and exit

Loop: resend any pending results left from a previous run, stop if the
budget is reached, claim a run (204 means stop), analyse it event by event,
save the result to <out>/worker/pending/<run-id>.json, then post it - moving
that file to posted/ on success, rejected/ on a 4xx, or leaving it pending
(and stopping) on a network error or a 5xx. See docs/thread-analysis.md,
"Worker".

With --next-np, the loop is different: resend pending, then up to --limit
times, POST request-next (kind=np), claim that thread, analyse and post -
stopping early on a 204 ("no more NP threads to analyse"), the budget, or a
post that can't be delivered. It never touches the general queue.

With --reviews, nothing is claimed or analysed: it fetches
GET /api/admin/analysis/reviews?status=... and prints one block per run (the
thread, review status, notes, system prompt sha, and the /thread-analysis/thread
URL) - meant to be pasted into a local AI session for the fix loop in
docs/thread-analysis.md, "Change 9".

HELP;
}

function fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function writeJsonFileAtomically(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create directory: $dir");
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException("Could not encode JSON for: $path");
    }
    $tmpPath = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmpPath, $json) === false) {
        throw new RuntimeException("Could not write temp file: $tmpPath");
    }
    if (!rename($tmpPath, $path)) {
        throw new RuntimeException("Could not rename $tmpPath to $path");
    }
}

function readJsonFile(string $path): ?array {
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/** @return array{status:int, body:?array, raw:?string, error:?string} */
function httpPostJson(string $url, string $token, array $body): array {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['X-Admin-Api-Token: ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
    ]);
    $respBody = curl_exec($ch);
    if ($respBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => null, 'raw' => null, 'error' => $error];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = $respBody === '' ? null : json_decode($respBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $respBody, 'error' => null];
}

/** @return array{status:int, body:?array, raw:?string, error:?string} */
function httpGetJson(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Admin-Api-Token: ' . $token],
        CURLOPT_TIMEOUT => 60,
    ]);
    $respBody = curl_exec($ch);
    if ($respBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => null, 'raw' => null, 'error' => $error];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = $respBody === '' ? null : json_decode($respBody, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $respBody, 'error' => null];
}

/**
 * Prints one block per run for --reviews: the thread, review status, notes,
 * system prompt sha, and the /thread-analysis/thread URL - meant to be
 * pasted into a local AI session for the fix loop (docs/thread-analysis.md,
 * "Change 9: review status and notes per run").
 */
function printReviews(array $reviews, string $baseUrl): void {
    foreach ($reviews as $review) {
        echo "Thread: {$review['thread_id']} ({$review['thread_title']})\n";
        echo "Review status: {$review['review_status']}\n";
        echo "Notes: " . ($review['review_notes'] ?? '') . "\n";
        echo "System prompt: " . substr((string) ($review['system_prompt_sha256'] ?? ''), 0, 8) . "\n";
        echo "URL: $baseUrl/thread-analysis/thread?id={$review['thread_id']}\n";
        echo "\n";
    }
}

function logLine(string $logPath, string $line): void {
    file_put_contents($logPath, $line . "\n", FILE_APPEND);
    if (stream_isatty(STDOUT)) {
        // Foreground runs otherwise print nothing; with --background stdout
        // goes to process.log and is not a tty, so nothing is duplicated there.
        echo $line . "\n";
    }
}

function emailForInput(array $email): array {
    return [
        'id' => $email['id'] ?? '',
        'direction' => $email['email_type'] ?? '',
        'datetime_received' => $email['datetime_received'] ?? '',
        'from' => $email['from'] ?? null,
        'to' => $email['to'] ?? [],
        'cc' => $email['cc'] ?? [],
        'subject' => $email['subject'] ?? null,
        'body_plain' => $email['body_plain'] ?? null,
        'body_html' => $email['body_html'] ?? null,
    ];
}

/**
 * The email_type to record for one event: the model's answer when the
 * event succeeded, or - on a failed event - that answer's email_type only
 * if it happens to be a real ThreadEmailStatusType value (the rest of the
 * answer, e.g. thread_state, may still have been invalid). Per the plan:
 * "the failed event has ... email_type null unless the last answer had a
 * valid one."
 */
function emailTypeForEvent(?string $error, ?array $output): ?string {
    if ($output === null || !isset($output['email_type']) || !is_string($output['email_type'])) {
        return null;
    }
    if ($error === null) {
        return $output['email_type'];
    }
    return ThreadEmailStatusType::tryFrom($output['email_type']) !== null ? $output['email_type'] : null;
}

/**
 * Analyses one claimed run's selected emails, in order, building the
 * change-1 result body to post - stopping at the first event whose answer
 * is still invalid after a retry (the run is then 'failed', carrying the
 * events up to and including it). Logs one line per event.
 *
 * @return array{result: array, costUsd: float}
 */
function analyseRun(
    array $claimJson,
    string $worker,
    string $model,
    string $claudeBin,
    float $maxBudgetUsd,
    string $promptFile,
    string $systemPromptText,
    array $schema,
    ?string $claudeCodeVersion,
    string $logPath
): array {
    $run = $claimJson['run'];
    $exportData = $claimJson['thread'];
    $thread = $exportData['thread'] ?? [];
    $threadInfo = [
        'id' => $thread['id'] ?? $run['thread_id'],
        'title' => $thread['title'] ?? '',
        'entity_name' => $exportData['entity']['name'] ?? '',
        'entity_id' => $thread['entity_id'] ?? '',
        'initial_request' => $thread['initial_request'] ?? null,
    ];
    $emailsById = [];
    foreach ($exportData['emails'] ?? [] as $email) {
        $emailsById[$email['id']] = $email;
    }

    $emailIds = $claimJson['email_ids'];
    $total = count($emailIds);
    $previousState = $claimJson['start_state'];
    $events = [];
    $failedError = null;
    $costUsd = 0.0;

    foreach ($emailIds as $index => $emailId) {
        $position = $index + 1;
        $emailRow = $emailsById[$emailId] ?? null;
        if ($emailRow === null) {
            throw new RuntimeException("analysis-worker: claimed email '$emailId' is missing from the work item's thread export");
        }

        $input = ThreadEventAnalysis::buildEventInput($threadInfo, $previousState, emailForInput($emailRow), $emailRow['attachments'] ?? []);
        $runResult = ClaudeCodeEventRunner::runEvent($input, $promptFile, $schema, $model, $claudeBin, $maxBudgetUsd, $claudeCodeVersion);

        $output = $runResult['output'];
        $error = $runResult['error'];
        foreach ($runResult['calls'] as $call) {
            $costUsd += (float) ($call['cost_usd'] ?? 0.0);
        }

        $events[] = [
            'email_id' => $emailId,
            'position' => $position,
            'email_type' => emailTypeForEvent($error, $output),
            'email_note' => $output['email_note'] ?? '',
            'email_type_gap' => $output['email_type_gap'] ?? '',
            'thread_state' => $error === null ? $output['thread_state'] : null,
            'attempts' => $runResult['attempts'],
            'error' => $error,
            'calls' => $runResult['calls'],
        ];

        $eventCostUsd = array_sum(array_map(fn (array $call): float => (float) ($call['cost_usd'] ?? 0.0), $runResult['calls']));
        $eventDurationMs = array_sum(array_map(fn (array $call): int => (int) ($call['duration_ms'] ?? 0), $runResult['calls']));
        if ($error !== null) {
            logLine($logPath, sprintf(
                '[run %d] %s event %d/%d %s FAILED (attempts %d): %s',
                $run['id'], $run['thread_id'], $position, $total, $emailRow['email_type'] ?? '', $runResult['attempts'], $error
            ));
            $failedError = $error;
            break;
        }

        logLine($logPath, sprintf(
            '[run %d] %s event %d/%d %s %s -> %s $%.2f %ss',
            $run['id'], $run['thread_id'], $position, $total,
            $emailRow['email_type'] ?? '', $output['email_type'] ?? '',
            $runResult['derivedThreadStateType'] ?? '', $eventCostUsd, round($eventDurationMs / 1000, 1)
        ));

        $previousState = $output['thread_state'];
    }

    $result = [
        'run_id' => $run['id'],
        'worker' => $worker,
        'status' => $failedError !== null ? 'failed' : 'done',
        'error' => $failedError,
        'model' => $model,
        'system_prompt' => $systemPromptText,
        'schema_version' => 1,
        'events' => $events,
    ];

    return ['result' => $result, 'costUsd' => $costUsd];
}

/**
 * Posts one pending result file (its content is exactly the change-1 POST
 * body). Moves it to posted/ on 200, to rejected/ (plus a .error.txt) on a
 * 4xx, and leaves it in place - returning 'stopped' - on a network error or
 * a 5xx, per the plan ("Pending results").
 *
 * @return 'posted'|'rejected'|'stopped'
 */
function postPendingFile(
    string $runId,
    array $body,
    string $pendingPath,
    string $postedDir,
    string $rejectedDir,
    string $baseUrl,
    string $token,
    string $logPath
): string {
    $resp = httpPostJson($baseUrl . '/api/admin/analysis/result', $token, $body);

    if ($resp['status'] === 200) {
        rename($pendingPath, $postedDir . '/' . $runId . '.json');
        logLine($logPath, "post: run $runId status={$body['status']} -> posted (200)");
        return 'posted';
    }

    if ($resp['status'] >= 400 && $resp['status'] < 500) {
        rename($pendingPath, $rejectedDir . '/' . $runId . '.json');
        file_put_contents($rejectedDir . '/' . $runId . '.error.txt', (string) $resp['raw']);
        logLine($logPath, "post: run $runId REJECTED (HTTP {$resp['status']}): " . trim((string) $resp['raw']));
        return 'rejected';
    }

    $why = $resp['error'] !== null ? $resp['error'] : ('HTTP ' . $resp['status']);
    logLine($logPath, "post: run $runId could not be posted ($why); left pending for the next start");
    return 'stopped';
}

/**
 * Resends every file under pending/, oldest run id first. Stops at (and
 * returns true for) the first one that still can't be posted, so the
 * worker doesn't keep claiming while prod is failing.
 */
function resendPending(string $pendingDir, string $postedDir, string $rejectedDir, string $baseUrl, string $token, string $logPath): bool {
    $files = glob($pendingDir . '/*.json') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $runId = basename($file, '.json');
        $body = readJsonFile($file);
        if ($body === null) {
            logLine($logPath, "resend: run $runId's pending file is unreadable, leaving it as is");
            continue;
        }
        logLine($logPath, "resend: run $runId");
        if (postPendingFile($runId, $body, $file, $postedDir, $rejectedDir, $baseUrl, $token, $logPath) === 'stopped') {
            return true;
        }
    }
    return false;
}

// -- argv parsing --

$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    printHelp();
    exit(0);
}

$baseUrl = null;
$tokenFile = null;
$explicitThread = null;
$mode = 'incremental';
$once = false;
$nextNp = false;
$limit = 1;
$workerName = null;
$model = 'claude-opus-5-5';
$maxBudgetUsd = 20.0;
$out = 'thread-analysis';
$claudeBin = 'claude';
$background = false;
$reviewsMode = false;
$reviewsStatus = 'MINOR_ISSUES,WRONG';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = substr($arg, strlen('--base-url='));
    }
    elseif (str_starts_with($arg, '--token-file=')) {
        $tokenFile = substr($arg, strlen('--token-file='));
    }
    elseif (str_starts_with($arg, '--thread=')) {
        $explicitThread = substr($arg, strlen('--thread='));
    }
    elseif (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, strlen('--mode='));
    }
    elseif ($arg === '--once') {
        $once = true;
    }
    elseif ($arg === '--next-np') {
        $nextNp = true;
    }
    elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
    elseif (str_starts_with($arg, '--worker=')) {
        $workerName = substr($arg, strlen('--worker='));
    }
    elseif (str_starts_with($arg, '--model=')) {
        $model = substr($arg, strlen('--model='));
    }
    elseif (str_starts_with($arg, '--max-budget-usd=')) {
        $maxBudgetUsd = (float) substr($arg, strlen('--max-budget-usd='));
    }
    elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    }
    elseif (str_starts_with($arg, '--claude-bin=')) {
        $claudeBin = substr($arg, strlen('--claude-bin='));
    }
    elseif ($arg === '--background') {
        $background = true;
    }
    elseif ($arg === '--reviews') {
        $reviewsMode = true;
    }
    elseif (str_starts_with($arg, '--status=')) {
        $reviewsStatus = substr($arg, strlen('--status='));
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
if ($mode !== 'incremental' && $mode !== 'full') {
    fail("--mode must be 'incremental' or 'full', got '$mode'");
}
if ($nextNp && $explicitThread !== null) {
    fail("--next-np cannot be combined with --thread");
}
if ($reviewsMode && ($nextNp || $explicitThread !== null)) {
    fail("--reviews cannot be combined with --thread or --next-np");
}
if ($explicitThread !== null) {
    $once = true;
}

$baseUrl = rtrim($baseUrl, '/');
if (!ThreadExportSync::isSafeBaseUrl($baseUrl)) {
    fail("Refusing to send the admin token to $baseUrl: use https (plain http only for localhost)");
}
if (!is_file($tokenFile)) {
    fail("Token file not found: $tokenFile");
}
$token = trim((string) file_get_contents($tokenFile));
if ($token === '') {
    fail("Token file is empty: $tokenFile");
}
if ($workerName === null || $workerName === '') {
    $workerName = (string) gethostname();
}

// -- --reviews: just fetch and print, no claiming/analysing, no worker dirs --

if ($reviewsMode) {
    $query = $reviewsStatus !== '' ? ('?status=' . rawurlencode($reviewsStatus)) : '';
    $resp = httpGetJson($baseUrl . '/api/admin/analysis/reviews' . $query, $token);
    if ($resp['status'] !== 200) {
        fail("Failed to fetch reviews: HTTP {$resp['status']} {$resp['raw']}");
    }
    printReviews($resp['body']['reviews'] ?? [], $baseUrl);
    exit(0);
}

$workerDir = $out . '/worker';
$pendingDir = $workerDir . '/pending';
$postedDir = $workerDir . '/posted';
$rejectedDir = $workerDir . '/rejected';
$logPath = $workerDir . '/worker.log';

// -- background relaunch --

if ($background) {
    foreach ([$workerDir, $pendingDir, $postedDir, $rejectedDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            fail("Could not create directory: $dir");
        }
    }
    $selfArgs = [];
    foreach ($args as $arg) {
        if ($arg === '--background') {
            continue;
        }
        $selfArgs[] = $arg;
    }
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, __FILE__], $selfArgs)));
    // The child writes worker.log itself; its own stdout/stderr go to
    // process.log so a crash (e.g. a PHP fatal error) still leaves a trace.
    $processLogPath = $workerDir . '/process.log';
    $pid = trim((string) shell_exec("nohup $cmd > " . escapeshellarg($processLogPath) . " 2>&1 & echo \$!"));
    echo "Worker directory: $workerDir\n";
    echo "PID: $pid\n";
    echo "tail -f $logPath\n";
    echo "Crashes, if any: $processLogPath\n";
    exit(0);
}

// -- setup --

foreach ([$workerDir, $pendingDir, $postedDir, $rejectedDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("Could not create directory: $dir");
    }
}

$promptFile = __DIR__ . '/analysis/event-prompt.md';
if (!is_file($promptFile)) {
    fail("Prompt file not found: $promptFile");
}
$systemPromptText = (string) file_get_contents($promptFile);
$schema = ThreadEventAnalysis::buildJsonSchema();
$claudeCodeVersion = ClaudeCodeEventRunner::claudeCodeVersion($claudeBin);

logLine($logPath, "worker $workerName started: base_url=$baseUrl model=$model max_budget_usd=$maxBudgetUsd claude_code_version=" . ($claudeCodeVersion ?? 'unknown'));

$requestedExplicitThread = false;
$totalCostUsd = 0.0;

// -- --next-np: pick norske-postlister.no threads by itself, up to --limit,
// never touching the general queue. See docs/thread-analysis.md, "Worker".
if ($nextNp) {
    if (resendPending($pendingDir, $postedDir, $rejectedDir, $baseUrl, $token, $logPath)) {
        logLine($logPath, 'worker stopping: a pending result could not be posted');
        exit(0);
    }

    for ($i = 0; $i < $limit; $i++) {
        if ($totalCostUsd >= $maxBudgetUsd) {
            logLine($logPath, "worker stopping: max-budget-usd $maxBudgetUsd reached (spent \$" . round($totalCostUsd, 2) . ' this process)');
            break;
        }

        $nextResp = httpPostJson($baseUrl . '/api/admin/analysis/request-next', $token, ['kind' => 'np', 'mode' => $mode]);
        if ($nextResp['status'] === 204) {
            logLine($logPath, 'request-next: np -> none left');
            break;
        }
        if ($nextResp['status'] !== 200) {
            fail("Failed to request-next: HTTP {$nextResp['status']} {$nextResp['raw']}");
        }
        $nextRunId = $nextResp['body']['run_id'];
        $nextThreadId = $nextResp['body']['thread_id'];
        logLine($logPath, "request-next: np -> run $nextRunId thread $nextThreadId");

        $claimResp = httpPostJson($baseUrl . '/api/admin/analysis/claim', $token, ['worker' => $workerName, 'thread_id' => $nextThreadId]);
        if ($claimResp['status'] === 204) {
            logLine($logPath, 'claim: nothing to claim, stopping');
            break;
        }
        if ($claimResp['status'] !== 200) {
            fail("Failed to claim: HTTP {$claimResp['status']} {$claimResp['raw']}");
        }

        $claimJson = $claimResp['body'];
        $run = $claimJson['run'];
        logLine($logPath, "claim: run {$run['id']} thread {$run['thread_id']} mode={$run['mode']}, " . count($claimJson['email_ids']) . ' emails');

        ['result' => $resultBody, 'costUsd' => $runCostUsd] = analyseRun(
            $claimJson, $workerName, $model, $claudeBin, $maxBudgetUsd,
            $promptFile, $systemPromptText, $schema, $claudeCodeVersion, $logPath
        );
        $totalCostUsd += $runCostUsd;

        $pendingPath = $pendingDir . '/' . $run['id'] . '.json';
        writeJsonFileAtomically($pendingPath, $resultBody);

        if (postPendingFile((string) $run['id'], $resultBody, $pendingPath, $postedDir, $rejectedDir, $baseUrl, $token, $logPath) === 'stopped') {
            break;
        }
    }

    exit(0);
}

while (true) {
    if (resendPending($pendingDir, $postedDir, $rejectedDir, $baseUrl, $token, $logPath)) {
        logLine($logPath, 'worker stopping: a pending result could not be posted');
        break;
    }

    if ($totalCostUsd >= $maxBudgetUsd) {
        logLine($logPath, "worker stopping: max-budget-usd $maxBudgetUsd reached (spent \$" . round($totalCostUsd, 2) . ' this process)');
        break;
    }

    if ($explicitThread !== null && !$requestedExplicitThread) {
        $requestedExplicitThread = true;
        $reqResp = httpPostJson($baseUrl . '/api/admin/analysis/request', $token, ['thread_id' => $explicitThread, 'mode' => $mode]);
        if ($reqResp['status'] !== 200) {
            fail("Failed to request analysis for thread $explicitThread: HTTP {$reqResp['status']} {$reqResp['raw']}");
        }
        logLine($logPath, "request: thread $explicitThread mode=$mode -> run {$reqResp['body']['run_id']}");
    }

    $claimBody = ['worker' => $workerName];
    if ($explicitThread !== null) {
        $claimBody['thread_id'] = $explicitThread;
    }
    $claimResp = httpPostJson($baseUrl . '/api/admin/analysis/claim', $token, $claimBody);

    if ($claimResp['status'] === 204) {
        logLine($logPath, 'claim: nothing to claim, stopping');
        break;
    }
    if ($claimResp['status'] !== 200) {
        fail("Failed to claim: HTTP {$claimResp['status']} {$claimResp['raw']}");
    }

    $claimJson = $claimResp['body'];
    $run = $claimJson['run'];
    logLine($logPath, "claim: run {$run['id']} thread {$run['thread_id']} mode={$run['mode']}, " . count($claimJson['email_ids']) . ' emails');

    ['result' => $resultBody, 'costUsd' => $runCostUsd] = analyseRun(
        $claimJson, $workerName, $model, $claudeBin, $maxBudgetUsd,
        $promptFile, $systemPromptText, $schema, $claudeCodeVersion, $logPath
    );
    $totalCostUsd += $runCostUsd;

    $pendingPath = $pendingDir . '/' . $run['id'] . '.json';
    writeJsonFileAtomically($pendingPath, $resultBody);

    if (postPendingFile((string) $run['id'], $resultBody, $pendingPath, $postedDir, $rejectedDir, $baseUrl, $token, $logPath) === 'stopped') {
        break;
    }

    if ($once) {
        break;
    }
}

exit(0);
