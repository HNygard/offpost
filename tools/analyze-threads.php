<?php
// tools/analyze-threads.php
//
// Local, step-2.2 tool (docs/superpowers/plans/2026-09-27-step2-2-thread-analysis.md):
// runs headless Claude Code once per event (email) of each selected thread
// from a pulled export (tools/pull-thread-export.php), building the
// cumulative thread_state and an email_type classification for comparison
// against the export's own status_type. Local evaluation only - this does
// not touch prod.
//
// Usage:
//   php tools/analyze-threads.php [--export=thread-export] [--out=thread-analysis]
//       [--run=<name>] [--limit=N] [--thread=<id>]... [--parallel=2]
//       [--model=claude-opus-5-5] [--max-budget-usd=20] [--claude-bin=claude]
//       [--background] [--help]
//
// No composer autoload: only ThreadEventAnalysis.php (which itself
// require_once's the ThreadState/enum classes) is required.

require_once __DIR__ . '/analysis/ThreadEventAnalysis.php';
require_once __DIR__ . '/analysis/ClaudeCodeEventRunner.php';

function printHelp(): void {
    echo <<<HELP
Usage: php tools/analyze-threads.php [options]

Options:
  --export=DIR         Export directory to read from (default: thread-export)
  --out=DIR            Output directory for runs (default: thread-analysis)
  --run=NAME           Run name; the run directory is <out>/<run>/ (default: a timestamp)
  --limit=N            Analyse at most N threads (ignored together with --thread)
  --thread=ID          Analyse only this thread id; repeatable
  --parallel=N         Threads to run at once (default: 2)
  --model=NAME         Model to pass to claude (default: claude-opus-5-5)
  --max-budget-usd=N   Stop starting new calls once summed cost reaches this (default: 20)
  --claude-bin=PATH    Command to invoke instead of "claude" (for tests)
  --background         Relaunch detached and exit; see <run>/run.log
  --help               Show this help and exit

Output: <out>/<run>/run.json and <out>/<run>/threads/<id>.json, one per
analysed thread. See docs/thread-analysis.md.

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

/**
 * Holds one thread's mutable progress through this run. Orchestration
 * state only - not pure logic, so it lives here rather than in
 * ThreadEventAnalysis.php.
 */
class ThreadRun {
    public string $id;
    public string $path;
    public array $threadInfo;
    /** @var array The thread's ordered events (emails), from ThreadEventAnalysis::selectEvents(). */
    public array $events;
    /** @var array Event records already recorded (kept across a resume). */
    public array $keptEvents;
    public int $nextIndex;
    public $previousState;
    public int $position;
    public int $total;
}

/**
 * One in-flight `claude` call for a ThreadRun's current event.
 */
class Worker {
    public ThreadRun $ctx;
    public $proc;
    public $stdout;
    public $stderr;
    public string $outBuf = '';
    public string $errBuf = '';
    public int $attempt = 1;
    public string $inputText;
    public float $carryCostUsd = 0.0;
    public array $carryUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'thinking_tokens' => 0];
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

function startEventCall(ThreadRun $ctx, array $claudeArgvBase): Worker {
    $email = $ctx->events[$ctx->nextIndex];
    $input = ThreadEventAnalysis::buildEventInput($ctx->threadInfo, $ctx->previousState, emailForInput($email), $email['attachments'] ?? []);

    $worker = new Worker();
    $worker->ctx = $ctx;
    $worker->inputText = $input;
    [$proc, $stdout, $stderr] = ClaudeCodeEventRunner::spawnNonBlocking($claudeArgvBase, $input);
    $worker->proc = $proc;
    $worker->stdout = $stdout;
    $worker->stderr = $stderr;
    return $worker;
}

function startRetryCall(Worker $worker, string $error, array $claudeArgvBase): Worker {
    $retryInput = $worker->inputText . "\n\nYour previous answer was invalid: $error. Return a corrected answer.";
    $worker->attempt = 2;
    $worker->inputText = $retryInput;
    $worker->outBuf = '';
    $worker->errBuf = '';
    [$proc, $stdout, $stderr] = ClaudeCodeEventRunner::spawnNonBlocking($claudeArgvBase, $retryInput);
    $worker->proc = $proc;
    $worker->stdout = $stdout;
    $worker->stderr = $stderr;
    return $worker;
}

function pumpWorkers(array $workers): void {
    if ($workers === []) {
        usleep(20000);
        return;
    }
    $readStreams = [];
    $map = [];
    foreach ($workers as $slot => $w) {
        foreach (['stdout' => $w->stdout, 'stderr' => $w->stderr] as $which => $stream) {
            if (is_resource($stream)) {
                $readStreams[] = $stream;
                $map[(int) $stream] = [$slot, $which];
            }
        }
    }
    if ($readStreams === []) {
        return;
    }
    $write = null;
    $except = null;
    $n = @stream_select($readStreams, $write, $except, 0, 200000);
    if (!$n) {
        return;
    }
    foreach ($readStreams as $stream) {
        $key = (int) $stream;
        if (!isset($map[$key])) {
            continue;
        }
        [$slot, $which] = $map[$key];
        $chunk = @fread($stream, 65536);
        if ($chunk !== false && $chunk !== '') {
            if ($which === 'stdout') {
                $workers[$slot]->outBuf .= $chunk;
            }
            else {
                $workers[$slot]->errBuf .= $chunk;
            }
        }
    }
}

function isWorkerFinished(Worker $w): bool {
    $status = proc_get_status($w->proc);
    return $status['running'] === false;
}

function finalizeWorker(Worker $w): void {
    $w->outBuf .= (string) stream_get_contents($w->stdout);
    $w->errBuf .= (string) stream_get_contents($w->stderr);
    fclose($w->stdout);
    fclose($w->stderr);
    proc_close($w->proc);
}

/**
 * Interprets one finished worker's claude output, updates its ThreadRun and
 * the run totals, and returns what the scheduler should do next:
 * {decision: 'retry'|'continue'|'thread_done'|'thread_failed'|'budget_stop', error: ?string}.
 * `error` is set (and only meaningful) when decision is 'retry', so the
 * caller can build the corrected-answer prompt.
 */
function handleCallResult(Worker $w, array &$runTotals, float $maxBudgetUsd, string $model, string $runLogPath): array {
    $ctx = $w->ctx;
    $email = $ctx->events[$ctx->nextIndex];
    $i = $ctx->nextIndex + 1;
    $k = count($ctx->events);
    $interpreted = ClaudeCodeEventRunner::interpretOutput($w->outBuf, $w->errBuf);
    $decoded = $interpreted['decoded'];
    $structuredOutput = $interpreted['structuredOutput'];
    $validation = ['valid' => $interpreted['valid'], 'error' => $interpreted['error'], 'derivedThreadStateType' => $interpreted['derivedThreadStateType']];
    $error = $interpreted['valid'] ? null : $interpreted['error'];

    $costUsd = $w->carryCostUsd + (float) ($decoded['total_cost_usd'] ?? 0.0);
    $usage = ClaudeCodeEventRunner::sumUsage($w->carryUsage, is_array($decoded) ? ClaudeCodeEventRunner::usageOf($decoded) : []);
    $durationMs = (int) ($decoded['duration_ms'] ?? 0);
    $sessionId = is_array($decoded) ? ($decoded['session_id'] ?? null) : null;
    $modelUsed = is_array($decoded) ? ClaudeCodeEventRunner::modelNameOf($decoded, $model) : $model;

    if ($error !== null) {
        if ($w->attempt === 1) {
            logLine($runLogPath, "[thread {$ctx->position}/{$ctx->total}] {$ctx->id} event $i/$k retry: $error");
            $w->carryCostUsd = $costUsd;
            $w->carryUsage = $usage;
            return ['decision' => 'retry', 'error' => $error];
        }

        $eventRecord = [
            'email_id' => $email['id'] ?? null,
            'email_type_actual' => $email['status_type'] ?? null,
            'direction' => $email['email_type'] ?? null,
            'datetime_received' => $email['datetime_received'] ?? null,
            'input_chars' => mb_strlen($w->inputText),
            'attempts' => 2,
            'output' => $structuredOutput,
            'derived_thread_state_type' => null,
            'error' => $error,
            'usage' => $usage,
            'cost_usd' => $costUsd,
            'duration_ms' => $durationMs,
            'model' => $modelUsed,
            'session_id' => $sessionId,
        ];
        $ctx->keptEvents[] = $eventRecord;
        writeThreadFile($ctx, 'failed');
        addTotalsInto($runTotals, ThreadEventAnalysis::computeTotals([$eventRecord]));
        logLine($runLogPath, "[thread {$ctx->position}/{$ctx->total}] {$ctx->id} event $i/$k FAILED: $error");
        return ['decision' => 'thread_failed', 'error' => null];
    }

    $eventRecord = [
        'email_id' => $email['id'] ?? null,
        'email_type_actual' => $email['status_type'] ?? null,
        'direction' => $email['email_type'] ?? null,
        'datetime_received' => $email['datetime_received'] ?? null,
        'input_chars' => mb_strlen($w->inputText),
        'attempts' => $w->attempt,
        'output' => $structuredOutput,
        'derived_thread_state_type' => $validation['derivedThreadStateType'],
        'error' => null,
        'usage' => $usage,
        'cost_usd' => $costUsd,
        'duration_ms' => $durationMs,
        'model' => $modelUsed,
        'session_id' => $sessionId,
    ];
    $ctx->keptEvents[] = $eventRecord;
    $ctx->previousState = $structuredOutput['thread_state'];
    $ctx->nextIndex++;

    $done = $ctx->nextIndex >= count($ctx->events);
    writeThreadFile($ctx, $done ? 'done' : 'in_progress');

    addTotalsInto($runTotals, ThreadEventAnalysis::computeTotals([$eventRecord]));

    $durationS = round($durationMs / 1000, 1);
    logLine($runLogPath, sprintf(
        '[thread %d/%d] %s event %d/%d %s %s -> %s $%.2f %ss',
        $ctx->position, $ctx->total, $ctx->id, $i, $k,
        $email['email_type'] ?? '', $structuredOutput['email_type'] ?? '',
        $validation['derivedThreadStateType'] ?? '', $costUsd, $durationS
    ));

    if ($done) {
        $runTotals['threads']++;
        return ['decision' => 'thread_done', 'error' => null];
    }

    if ($runTotals['cost_usd'] >= $maxBudgetUsd) {
        return ['decision' => 'budget_stop', 'error' => null];
    }

    return ['decision' => 'continue', 'error' => null];
}

function writeThreadFile(ThreadRun $ctx, string $status): void {
    $data = [
        'thread_id' => $ctx->id,
        'title' => $ctx->threadInfo['title'],
        'entity_id' => $ctx->threadInfo['entity_id'],
        'status' => $status,
        'events' => $ctx->keptEvents,
        'totals' => ThreadEventAnalysis::computeTotals($ctx->keptEvents),
    ];
    writeJsonFileAtomically($ctx->path, $data);
}

function logLine(string $runLogPath, string $line): void {
    file_put_contents($runLogPath, $line . "\n", FILE_APPEND);
    if (stream_isatty(STDOUT)) {
        // Foreground runs otherwise print nothing to the terminal. In
        // background mode stdout goes to process.log and is not a tty, so
        // nothing is duplicated there.
        echo $line . "\n";
    }
}

/**
 * Adds every key of $totals (a ThreadEventAnalysis::computeTotals() result,
 * or one built the same way) into $runTotals, so the run-wide totals share
 * exactly the same key list as a thread's own totals - no separate,
 * hand-maintained list of token keys here.
 */
function addTotalsInto(array &$runTotals, array $totals): void {
    foreach ($totals as $key => $value) {
        $runTotals[$key] = ($runTotals[$key] ?? 0) + $value;
    }
}

// -- argv parsing --

$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    printHelp();
    exit(0);
}

$export = 'thread-export';
$out = 'thread-analysis';
$run = null;
$limit = null;
$explicitThreads = [];
$parallel = 2;
$model = 'claude-opus-5-5';
$maxBudgetUsd = 20.0;
$claudeBin = 'claude';
$background = false;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--export=')) {
        $export = substr($arg, strlen('--export='));
    }
    elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    }
    elseif (str_starts_with($arg, '--run=')) {
        $run = substr($arg, strlen('--run='));
    }
    elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
    elseif (str_starts_with($arg, '--thread=')) {
        $explicitThreads[] = substr($arg, strlen('--thread='));
    }
    elseif (str_starts_with($arg, '--parallel=')) {
        $parallel = max(1, (int) substr($arg, strlen('--parallel=')));
    }
    elseif (str_starts_with($arg, '--model=')) {
        $model = substr($arg, strlen('--model='));
    }
    elseif (str_starts_with($arg, '--max-budget-usd=')) {
        $maxBudgetUsd = (float) substr($arg, strlen('--max-budget-usd='));
    }
    elseif (str_starts_with($arg, '--claude-bin=')) {
        $claudeBin = substr($arg, strlen('--claude-bin='));
    }
    elseif ($arg === '--background') {
        $background = true;
    }
    else {
        fail("Unknown argument: $arg\n\nRun with --help for usage.");
    }
}

$runName = $run ?? date('Y-m-d\THi');
$runDir = $out . '/' . $runName;

// -- background relaunch --

if ($background) {
    if (!is_dir($runDir) && !mkdir($runDir, 0777, true) && !is_dir($runDir)) {
        fail("Could not create run directory: $runDir");
    }
    $logPath = $runDir . '/run.log';
    $selfArgs = [];
    foreach ($args as $arg) {
        if ($arg === '--background' || str_starts_with($arg, '--run=')) {
            continue;
        }
        $selfArgs[] = $arg;
    }
    $selfArgs[] = '--run=' . $runName;
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, __FILE__], $selfArgs)));
    // The child writes run.log itself; its own stdout/stderr go to
    // process.log so a crash (e.g. a PHP fatal error) still leaves a trace.
    $processLogPath = $runDir . '/process.log';
    $pid = trim((string) shell_exec("nohup $cmd > " . escapeshellarg($processLogPath) . " 2>&1 & echo \$!"));
    echo "Run directory: $runDir\n";
    echo "PID: $pid\n";
    echo "tail -f $logPath\n";
    echo "Crashes, if any: $processLogPath\n";
    exit(0);
}

// -- setup --

if (!is_dir($export . '/threads')) {
    fail("Export directory not found: $export/threads (run tools/pull-thread-export.php first, or pass --export)");
}
if (!is_dir($runDir) && !mkdir($runDir, 0777, true) && !is_dir($runDir)) {
    fail("Could not create run directory: $runDir");
}
$threadsOutDir = $runDir . '/threads';
if (!is_dir($threadsOutDir) && !mkdir($threadsOutDir, 0777, true) && !is_dir($threadsOutDir)) {
    fail("Could not create directory: $threadsOutDir");
}
$runLogPath = $runDir . '/run.log';

$promptFile = __DIR__ . '/analysis/event-prompt.md';
if (!is_file($promptFile)) {
    fail("Prompt file not found: $promptFile");
}
$promptSha256 = hash('sha256', (string) file_get_contents($promptFile));
$schemaJson = json_encode(ThreadEventAnalysis::buildJsonSchema());
$claudeArgvBase = ClaudeCodeEventRunner::buildClaudeArgv($claudeBin, $model, $promptFile, $schemaJson, $maxBudgetUsd);

if ($explicitThreads !== []) {
    $threadIds = $explicitThreads;
}
else {
    $files = glob($export . '/threads/*.json');
    $threadIds = array_map(fn($f) => basename($f, '.json'), $files === false ? [] : $files);
    sort($threadIds, SORT_STRING);
    if ($limit !== null && $limit > 0) {
        $threadIds = array_slice($threadIds, 0, $limit);
    }
}

$startedAt = date('c');
logLine($runLogPath, "run $runName started: " . count($threadIds) . ' threads, model=' . $model . ', max_budget_usd=' . $maxBudgetUsd);

$runTotals = ['threads' => 0] + ThreadEventAnalysis::computeTotals([]);
$stoppedReason = null;
$totalThreads = count($threadIds);
$queue = [];
foreach ($threadIds as $index => $id) {
    $queue[] = ['id' => $id, 'position' => $index + 1];
}

/** @var array<int, Worker> $activeWorkers */
$activeWorkers = [];
$nextSlot = 0;
$budgetStopped = false;

/**
 * Pops the next queued thread, builds its ThreadRun (applying resume),
 * handles the "no emails" and "already done" cases fully by itself, and
 * returns a ThreadRun ready for its next call - or null if there was
 * nothing left to run for it.
 */
function prepareThreadRun(array $queued, string $export, string $threadsOutDir, int $totalThreads, array &$runTotals, string $runLogPath): ?ThreadRun {
    $id = $queued['id'];
    $position = $queued['position'];
    $path = $threadsOutDir . '/' . $id . '.json';

    $exportData = readJsonFile($export . '/threads/' . $id . '.json');
    if ($exportData === null) {
        logLine($runLogPath, "[thread $position/$totalThreads] $id skipped: export file not found or invalid");
        return null;
    }

    $emails = $exportData['emails'] ?? [];
    if ($emails === []) {
        logLine($runLogPath, "[thread $position/$totalThreads] $id skipped: no emails");
        return null;
    }

    $thread = $exportData['thread'] ?? [];
    $threadInfo = [
        'id' => $thread['id'] ?? $id,
        'title' => $thread['title'] ?? '',
        'entity_name' => $exportData['entity']['name'] ?? '',
        'entity_id' => $thread['entity_id'] ?? '',
        'initial_request' => $thread['initial_request'] ?? null,
    ];

    $events = ThreadEventAnalysis::selectEvents($emails);
    $existing = readJsonFile($path);
    $plan = ThreadEventAnalysis::planResume($existing);

    if ($plan['skip']) {
        $totals = ThreadEventAnalysis::computeTotals($plan['keptEvents']);
        $runTotals['threads']++;
        addTotalsInto($runTotals, $totals);
        logLine($runLogPath, "[thread $position/$totalThreads] $id already done, skipping");
        return null;
    }

    $ctx = new ThreadRun();
    $ctx->id = $id;
    $ctx->path = $path;
    $ctx->threadInfo = $threadInfo;
    $ctx->events = $events;
    $ctx->keptEvents = $plan['keptEvents'];
    $ctx->nextIndex = $plan['startIndex'];
    $ctx->previousState = $ctx->keptEvents === [] ? null : end($ctx->keptEvents)['output']['thread_state'];
    $ctx->position = $position;
    $ctx->total = $totalThreads;

    if ($ctx->nextIndex >= count($ctx->events)) {
        // Every event is already recorded (e.g. a resumed run whose thread
        // was left 'in_progress' right after its last event) - nothing left
        // to call, so finish it as done without spending anything.
        writeThreadFile($ctx, 'done');
        $totals = ThreadEventAnalysis::computeTotals($ctx->keptEvents);
        $runTotals['threads']++;
        addTotalsInto($runTotals, $totals);
        logLine($runLogPath, "[thread $position/$totalThreads] $id already complete, skipping");
        return null;
    }

    return $ctx;
}

while (true) {
    while (count($activeWorkers) < $parallel && $queue !== []) {
        if ($runTotals['cost_usd'] >= $maxBudgetUsd) {
            if (!$budgetStopped) {
                $budgetStopped = true;
                $stoppedReason = 'budget';
                logLine($runLogPath, "run $runName stopping: max-budget-usd $maxBudgetUsd reached");
            }
            break;
        }
        $queued = array_shift($queue);
        $ctx = prepareThreadRun($queued, $export, $threadsOutDir, $totalThreads, $runTotals, $runLogPath);
        if ($ctx === null) {
            continue;
        }
        $activeWorkers[$nextSlot++] = startEventCall($ctx, $claudeArgvBase);
    }

    if ($activeWorkers === []) {
        break;
    }

    pumpWorkers($activeWorkers);

    foreach ($activeWorkers as $slot => $w) {
        if (!isWorkerFinished($w)) {
            continue;
        }
        finalizeWorker($w);
        $result = handleCallResult($w, $runTotals, $maxBudgetUsd, $model, $runLogPath);
        $decision = $result['decision'];

        if ($decision === 'retry') {
            $activeWorkers[$slot] = startRetryCall($w, (string) $result['error'], $claudeArgvBase);
        }
        elseif ($decision === 'continue' && !$budgetStopped) {
            $activeWorkers[$slot] = startEventCall($w->ctx, $claudeArgvBase);
        }
        else {
            // Either the thread is done/failed, budget_stop was just
            // decided by this worker, or another worker already decided
            // budget_stop in this same batch - either way, no further call
            // starts for this thread (the file already reflects its
            // in_progress/done/failed state as of the last recorded event).
            if ($decision === 'budget_stop') {
                $budgetStopped = true;
                $stoppedReason = 'budget';
                logLine($runLogPath, "run $runName stopping: max-budget-usd $maxBudgetUsd reached");
            }
            unset($activeWorkers[$slot]);
        }
    }
}

$finishedAt = date('c');
if ($stoppedReason === null && $queue !== []) {
    // Should not happen (the loop only exits once both queue and active
    // workers are empty), kept as a safety net.
    $stoppedReason = 'incomplete';
}

$runInfo = [
    'run' => $runName,
    'model' => $model,
    'prompt_sha256' => $promptSha256,
    'schema_version' => ThreadState::SCHEMA_VERSION,
    'options' => [
        'export' => $export,
        'out' => $out,
        'limit' => $limit,
        'thread' => $explicitThreads,
        'parallel' => $parallel,
        'model' => $model,
        'max_budget_usd' => $maxBudgetUsd,
        'claude_bin' => $claudeBin,
    ],
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
    'stopped_reason' => $stoppedReason,
    'totals' => $runTotals,
];
writeJsonFileAtomically($runDir . '/run.json', $runInfo);

logLine($runLogPath, "run $runName finished: " . json_encode($runTotals));

exit(0);
