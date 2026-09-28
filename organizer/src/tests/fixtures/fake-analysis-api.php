<?php
// organizer/src/tests/fixtures/fake-analysis-api.php
//
// A fake prod for AnalysisWorkerCliTest: a `php -S` router implementing the
// three analysis-queue endpoints (request/claim/result) closely enough to
// drive tools/analysis-worker.php in a real subprocess, without a database
// or the real api/admin/analysis_*.php. See
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 3:
// the worker".
//
// State (the queue, seeded threads, and test-controlled failure injection)
// lives in one JSON file, read + rewritten on every request under an
// exclusive lock, since `php -S` may serve requests from a fresh include of
// this file each time. Its path comes from the ANALYSIS_FAKE_STATE_FILE
// env var (set by the test before starting the server with putenv(), so
// `php -S`'s child process inherits it).
//
// Claim's email selection reuses the real, pure
// ThreadAnalysisWorkItem::selectEmails() - the same logic the real
// api/admin/analysis_claim.php calls - so this fixture mimics real claim
// behaviour rather than reimplementing it.

require_once __DIR__ . '/../../class/ThreadAnalysis/ThreadAnalysisWorkItem.php';

function fakeApiStatePath(): string {
    $path = getenv('ANALYSIS_FAKE_STATE_FILE');
    if ($path === false || $path === '') {
        http_response_code(500);
        echo json_encode(['error' => 'ANALYSIS_FAKE_STATE_FILE is not set']);
        exit;
    }
    return $path;
}

/**
 * Reads the state file, lets $mutator change it, writes it back - all
 * under one exclusive lock, so concurrent requests never interleave.
 */
function fakeApiWithState(callable $mutator) {
    $path = fakeApiStatePath();
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        http_response_code(500);
        echo json_encode(['error' => "Could not open state file: $path"]);
        exit;
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $state = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($state)) {
        $state = [];
    }
    $state += [
        'token' => '', 'next_run_id' => 1, 'threads' => [], 'runs' => [],
        'next_result_response' => null, 'posted_results' => [],
        // Change 7 ("process next" for norske-postlister threads): candidate
        // NP thread ids, oldest-picked-first as the test seeds them, consumed
        // (removed) by /api/admin/analysis/request-next as each is queued -
        // mirrors ThreadAnalysisRepository::requestNextNpThread() picking the
        // next thread with no run at all, without reimplementing its SQL.
        'np_candidate_thread_ids' => [],
    ];

    $result = $mutator($state);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function fakeApiRespond(int $status, ?array $body): void {
    http_response_code($status);
    if ($body !== null) {
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function fakeApiReadJsonBody(): array {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

// -- auth (all three routes, POST only, token only - like the real ones) --

header('Content-Type: application/json');

$expectedToken = fakeApiWithState(fn (array $state) => $state['token']);
$providedToken = $_SERVER['HTTP_X_ADMIN_API_TOKEN'] ?? null;
if ($expectedToken === '' || $providedToken === null || !hash_equals($expectedToken, $providedToken)) {
    fakeApiRespond(401, ['error' => 'Invalid or missing X-Admin-Api-Token']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fakeApiRespond(405, ['error' => 'POST only']);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = fakeApiReadJsonBody();

// -- routes --

if ($path === '/api/admin/analysis/request') {
    $threadId = $body['thread_id'] ?? null;
    $mode = $body['mode'] ?? null;
    if (!is_string($threadId) || !is_string($mode)) {
        fakeApiRespond(400, ['error' => 'Required JSON fields: thread_id, mode']);
    }

    $outcome = fakeApiWithState(function (array &$state) use ($threadId, $mode): array {
        if (!isset($state['threads'][$threadId])) {
            return ['status' => 404, 'body' => ['error' => 'Not found']];
        }
        foreach ($state['runs'] as $run) {
            if ($run['thread_id'] === $threadId && in_array($run['status'], ['requested', 'claimed'], true)) {
                return ['status' => 200, 'body' => ['run_id' => $run['id']]];
            }
        }
        $runId = $state['next_run_id']++;
        $state['runs'][(string) $runId] = [
            'id' => $runId, 'thread_id' => $threadId, 'mode' => $mode,
            'status' => 'requested', 'worker' => null, 'lease_expires_at' => null,
        ];
        return ['status' => 200, 'body' => ['run_id' => $runId]];
    });
    fakeApiRespond($outcome['status'], $outcome['body']);
}

if ($path === '/api/admin/analysis/request-next') {
    $kind = $body['kind'] ?? null;
    if ($kind !== 'np') {
        fakeApiRespond(400, ['error' => "kind must be 'np', got " . json_encode($kind)]);
    }
    $mode = $body['mode'] ?? 'incremental';

    $outcome = fakeApiWithState(function (array &$state) use ($mode): array {
        // Mirrors ThreadAnalysisRepository::requestNextNpThread(): pick the
        // next candidate NP thread id (test-seeded order = pick order) that
        // has no run at all yet, and queue it - or 204 when the list is
        // exhausted of unqueued candidates.
        $threadId = null;
        foreach ($state['np_candidate_thread_ids'] as $index => $candidateId) {
            $hasRun = false;
            foreach ($state['runs'] as $run) {
                if ($run['thread_id'] === $candidateId) {
                    $hasRun = true;
                    break;
                }
            }
            if (!$hasRun) {
                $threadId = $candidateId;
                break;
            }
        }
        if ($threadId === null) {
            return ['status' => 204, 'body' => null];
        }

        $runId = $state['next_run_id']++;
        $state['runs'][(string) $runId] = [
            'id' => $runId, 'thread_id' => $threadId, 'mode' => $mode,
            'status' => 'requested', 'worker' => null, 'lease_expires_at' => null,
        ];
        return ['status' => 200, 'body' => ['run_id' => $runId, 'thread_id' => $threadId]];
    });
    fakeApiRespond($outcome['status'], $outcome['body']);
}

if ($path === '/api/admin/analysis/claim') {
    $worker = $body['worker'] ?? null;
    if (!is_string($worker) || $worker === '') {
        fakeApiRespond(400, ['error' => 'Required JSON field: worker']);
    }
    $threadId = $body['thread_id'] ?? null;

    $outcome = fakeApiWithState(function (array &$state) use ($worker, $threadId): array {
        $claimable = null;
        foreach ($state['runs'] as $key => $run) {
            if ($run['status'] !== 'requested') {
                continue;
            }
            if ($threadId !== null && $run['thread_id'] !== $threadId) {
                continue;
            }
            if ($claimable === null || $run['id'] < $claimable['id']) {
                $claimable = $run;
                $claimableKey = $key;
            }
        }
        if ($claimable === null) {
            return ['status' => 204, 'body' => null];
        }

        $leaseExpiresAt = (new DateTime('@' . (time() + 3600)))->format(DateTimeInterface::ATOM);
        $claimable['status'] = 'claimed';
        $claimable['worker'] = $worker;
        $claimable['lease_expires_at'] = $leaseExpiresAt;
        $state['runs'][$claimableKey] = $claimable;

        $threadData = $state['threads'][$claimable['thread_id']];
        $workItem = ThreadAnalysisWorkItem::selectEmails($threadData['emails'], $claimable['mode']);

        return [
            'status' => 200,
            'body' => [
                'run' => [
                    'id' => $claimable['id'], 'thread_id' => $claimable['thread_id'],
                    'mode' => $claimable['mode'], 'lease_expires_at' => $leaseExpiresAt,
                ],
                'thread' => [
                    'export_version' => 1,
                    'thread' => $threadData['thread'],
                    'entity' => $threadData['entity'],
                    'emails' => $threadData['emails'],
                ],
                'start_state' => $workItem['start_state'],
                'start_after_email_id' => $workItem['start_after_email_id'],
                'email_ids' => $workItem['email_ids'],
            ],
        ];
    });
    fakeApiRespond($outcome['status'], $outcome['body']);
}

if ($path === '/api/admin/analysis/result') {
    $runId = $body['run_id'] ?? null;
    $worker = $body['worker'] ?? null;
    if (!is_int($runId) || !is_string($worker)) {
        fakeApiRespond(400, ['error' => 'Required JSON fields: run_id (int), worker (string)']);
    }

    $outcome = fakeApiWithState(function (array &$state) use ($runId, $worker, $body): array {
        // Test-controlled failure injection, consumed on first use - simulates
        // prod being briefly unavailable/rejecting, independent of the payload.
        $override = $state['next_result_response'];
        if ($override !== null) {
            $state['next_result_response'] = null;
            return ['status' => $override['status'], 'body' => $override['body'] ?? null];
        }

        $key = (string) $runId;
        if (!isset($state['runs'][$key])) {
            return ['status' => 404, 'body' => ['error' => 'Not found']];
        }
        $run = $state['runs'][$key];
        if ($run['status'] !== 'claimed') {
            return ['status' => 400, 'body' => ['error' => "run $runId is not claimed (status: '{$run['status']}')"]];
        }
        if ($run['worker'] !== $worker) {
            return ['status' => 400, 'body' => ['error' => "run $runId is claimed by '{$run['worker']}', not '$worker'"]];
        }

        $status = $body['status'] ?? null;
        if (!in_array($status, ['done', 'failed'], true)) {
            return ['status' => 400, 'body' => ['error' => "'status' must be 'done' or 'failed', got " . json_encode($status)]];
        }

        $run['status'] = $status;
        $state['runs'][$key] = $run;
        $state['posted_results'][] = $body;

        return ['status' => 200, 'body' => ['run_id' => $runId, 'status' => $status]];
    });
    fakeApiRespond($outcome['status'], $outcome['body']);
}

fakeApiRespond(404, ['error' => 'Unknown route: ' . $path]);
