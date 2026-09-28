<?php
// organizer/src/tests/AnalysisWorkerCliTest.php
//
// Runs tools/analysis-worker.php as a real subprocess against a fake prod
// (fixtures/fake-analysis-api.php, a `php -S` router) and the existing fake
// claude binary (fixtures/fake-claude.php) - never the real claude CLI or
// the real prod. See docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md,
// "Change 3: the worker".
use PHPUnit\Framework\TestCase;

class AnalysisWorkerCliTest extends TestCase {
    // Tried in order until one binds; deterministic, no random port.
    private const CANDIDATE_PORTS = [8391, 8392, 8393, 8394, 8395];

    private string $cliPath;
    private string $claudeBin;
    private string $routerPath;
    private string $baseDir;
    private string $outDir;
    private string $tokenFile;
    private string $stateFile;
    private string $token = 'test-admin-token';
    private $serverProc = null;
    private string $baseUrl;

    protected function setUp(): void {
        parent::setUp();
        $this->cliPath = __DIR__ . '/../../../tools/analysis-worker.php';
        $this->claudeBin = __DIR__ . '/fixtures/fake-claude.php';
        $this->routerPath = __DIR__ . '/fixtures/fake-analysis-api.php';
        $this->baseDir = sys_get_temp_dir() . '/analysis_worker_test_' . uniqid();
        $this->outDir = $this->baseDir . '/thread-analysis';
        mkdir($this->baseDir, 0777, true);

        $this->tokenFile = $this->baseDir . '/admin_api_token';
        file_put_contents($this->tokenFile, $this->token . "\n");

        $this->stateFile = $this->baseDir . '/fake-state.json';
        $this->writeState(['token' => $this->token, 'next_run_id' => 1, 'threads' => [], 'runs' => [], 'next_result_response' => null, 'posted_results' => []]);

        $this->startFakeServer();
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->stopFakeServer();
        $this->cleanDirectory($this->baseDir);
        if (is_dir($this->baseDir)) {
            rmdir($this->baseDir);
        }
    }

    private function cleanDirectory(string $dir): void {
        if (!file_exists($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanDirectory($path);
                rmdir($path);
            }
            else {
                unlink($path);
            }
        }
    }

    private function startFakeServer(): void {
        foreach (self::CANDIDATE_PORTS as $port) {
            putenv('ANALYSIS_FAKE_STATE_FILE=' . $this->stateFile);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open(
                ['php', '-S', "127.0.0.1:$port", $this->routerPath],
                $descriptors,
                $pipes,
                null,
                null
            );
            if (!is_resource($proc)) {
                continue;
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $connected = false;
            for ($i = 0; $i < 100; $i++) {
                $status = proc_get_status($proc);
                if ($status['running'] === false) {
                    break; // failed to start (e.g. port already in use elsewhere)
                }
                // A refused connection (normal while the server is still
                // starting) makes fsockopen() emit a PHP warning; a plain @
                // does not reliably suppress it under PHPUnit's error
                // handler (called regardless of the runtime error_reporting
                // level @ sets, and can turn the warning into a test error
                // when this suite runs alongside many others), so install
                // and restore a handler that swallows everything instead.
                set_error_handler(fn () => true);
                try {
                    $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
                } finally {
                    restore_error_handler();
                }
                if ($fp !== false) {
                    fclose($fp);
                    $connected = true;
                    break;
                }
                usleep(20000);
            }

            if ($connected) {
                $this->serverProc = $proc;
                $this->baseUrl = "http://127.0.0.1:$port";
                return;
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
        }
        $this->fail('Could not start the fake analysis API server on any candidate port: ' . json_encode(self::CANDIDATE_PORTS));
    }

    private function stopFakeServer(): void {
        if ($this->serverProc === null) {
            return;
        }
        proc_terminate($this->serverProc);
        for ($i = 0; $i < 50; $i++) {
            $status = proc_get_status($this->serverProc);
            if ($status['running'] === false) {
                break;
            }
            usleep(20000);
        }
        proc_close($this->serverProc);
        $this->serverProc = null;
    }

    private function readState(): array {
        return json_decode((string) file_get_contents($this->stateFile), true);
    }

    private function writeState(array $state): void {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * Seeds one thread with $emails (each: id, direction OUT|IN,
     * datetime_received, subject, body_plain, threadState (or null)). Marker
     * strings like FAKE_INVALID_ALWAYS/FAKE_COST=N (see fixtures/fake-claude.php)
     * belong in body_plain, which reaches the model's input.
     */
    private function seedThread(string $threadId, string $title, array $emails): void {
        $exportEmails = [];
        foreach ($emails as $email) {
            $exportEmails[] = [
                'id' => $email['id'],
                'email_type' => $email['direction'],
                'datetime_received' => $email['datetime_received'],
                'ignore' => false,
                'thread_state' => $email['threadState'] ?? null,
                'from' => $email['direction'] === 'OUT' ? 'oss@example.org' : 'post@example.org',
                'to' => $email['direction'] === 'OUT' ? ['post@example.org'] : ['oss@example.org'],
                'cc' => [],
                'subject' => $email['subject'],
                'body_plain' => $email['body_plain'],
                'attachments' => [],
            ];
        }
        $state = $this->readState();
        $state['threads'][$threadId] = [
            'thread' => ['id' => $threadId, 'entity_id' => 'entity-' . $threadId, 'title' => $title, 'initial_request' => $emails[0]['body_plain'] ?? ''],
            'entity' => ['entity_id' => 'entity-' . $threadId, 'name' => 'Testkommune'],
            'emails' => $exportEmails,
        ];
        $this->writeState($state);
    }

    /** Creates a 'requested' run directly, bypassing the /request endpoint. */
    private function seedRun(int $runId, string $threadId, string $mode = 'incremental'): void {
        $state = $this->readState();
        $state['next_run_id'] = max($state['next_run_id'], $runId + 1);
        $state['runs'][(string) $runId] = ['id' => $runId, 'thread_id' => $threadId, 'mode' => $mode, 'status' => 'requested', 'worker' => null, 'lease_expires_at' => null];
        $this->writeState($state);
    }

    /**
     * Seeds the candidate NP thread ids /api/admin/analysis/request-next
     * picks from, in this order (see fixtures/fake-analysis-api.php, which
     * queues the first one with no run yet).
     */
    private function seedNpCandidates(array $threadIds): void {
        $state = $this->readState();
        $state['np_candidate_thread_ids'] = $threadIds;
        $this->writeState($state);
    }

    private function setNextResultResponse(int $status, ?array $body = null): void {
        $state = $this->readState();
        $state['next_result_response'] = ['status' => $status, 'body' => $body];
        $this->writeState($state);
    }

    /** @return array{exitCode: int, output: string} */
    private function runWorker(array $extraArgs, bool $withServerArgs = true): array {
        $args = $withServerArgs
            ? ['--base-url=' . $this->baseUrl, '--token-file=' . $this->tokenFile, '--out=' . $this->outDir, '--claude-bin=' . $this->claudeBin, '--worker=test-worker']
            : [];
        $cmd = 'php ' . escapeshellarg($this->cliPath);
        foreach (array_merge($args, $extraArgs) as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' < /dev/null 2>&1', $outputLines, $exitCode);
        return ['exitCode' => $exitCode, 'output' => implode("\n", $outputLines)];
    }

    private function workerLog(): string {
        return (string) @file_get_contents($this->outDir . '/worker/worker.log');
    }

    private function readJson(string $path): array {
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "Not valid JSON: $path");
        return $decoded;
    }

    // -- help / argument validation --

    public function testHelpPrintsUsageWithoutTouchingAnything(): void {
        // :: Act
        $result = $this->runWorker(['--help'], withServerArgs: false);

        // :: Assert
        $this->assertEquals(0, $result['exitCode']);
        $this->assertStringContainsString('Usage: php tools/analysis-worker.php', $result['output']);
        $this->assertStringContainsString('--claude-bin=PATH', $result['output']);
        $this->assertDirectoryDoesNotExist($this->outDir);
    }

    public function testUnsafeBaseUrlIsRefused(): void {
        // :: Act
        $result = $this->runWorker(['--base-url=http://example.com', '--token-file=' . $this->tokenFile, '--out=' . $this->outDir, '--claude-bin=' . $this->claudeBin], withServerArgs: false);

        // :: Assert
        $this->assertEquals(1, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('Refusing to send the admin token', $result['output']);
        $this->assertDirectoryDoesNotExist($this->outDir);
    }

    // -- the main loop --

    public function testDrainingQueueOfTwoRunsPostsTwoDoneResults(): void {
        // :: Setup
        $this->seedThread('aaa', 'Valgprotokoll', [
            ['id' => 'em1', 'direction' => 'OUT', 'datetime_received' => '2023-09-12T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om valgprotokoll.'],
        ]);
        $this->seedThread('bbb', 'Moetebok', [
            ['id' => 'em2', 'direction' => 'OUT', 'datetime_received' => '2023-10-01T10:00:00+02:00', 'subject' => 'Innsynskrav 2', 'body_plain' => 'Vi ber om moetebok.'],
        ]);
        $this->seedRun(1, 'aaa');
        $this->seedRun(2, 'bbb');

        // :: Act
        $result = $this->runWorker([]);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $this->assertFileExists($this->outDir . '/worker/posted/1.json');
        $this->assertFileExists($this->outDir . '/worker/posted/2.json');
        $this->assertEquals([], glob($this->outDir . '/worker/pending/*.json'), 'No result should be left pending');
        $this->assertStringContainsString('nothing to claim, stopping', $this->workerLog());

        $posted1 = $this->readJson($this->outDir . '/worker/posted/1.json');
        $this->assertEquals('done', $posted1['status']);
        $this->assertNull($posted1['error']);
        $this->assertEquals(1, $posted1['schema_version']);
        $this->assertCount(1, $posted1['events'], json_encode($posted1['events'], JSON_PRETTY_PRINT));
        $event = $posted1['events'][0];
        $this->assertEquals('em1', $event['email_id']);
        $this->assertEquals(1, $event['position']);
        $this->assertEquals('unknown', $event['email_type']);
        $this->assertEquals(1, $event['attempts']);
        $this->assertNull($event['error']);
        $this->assertNotNull($event['thread_state']);
        $this->assertCount(1, $event['calls'], json_encode($event['calls'], JSON_PRETTY_PRINT));
        $call = $event['calls'][0];
        $this->assertEquals(1, $call['attempt']);
        $this->assertEquals('claude-opus-5-5', $call['model_resolved']);
        $this->assertEquals('fake-session', $call['session_id']);
        $this->assertEquals(0.01, $call['cost_usd']);
        $this->assertEquals(100, $call['input_tokens']);
        $this->assertEquals(50, $call['cache_creation_input_tokens']);
        $this->assertEquals(30, $call['cache_read_input_tokens']);
        $this->assertEquals(20, $call['output_tokens']);
        $this->assertEquals(5, $call['thinking_tokens']);
        $this->assertEquals('end_turn', $call['stop_reason']);
        $this->assertFalse($call['is_error']);

        $state = $this->readState();
        $this->assertEquals('done', $state['runs']['1']['status']);
        $this->assertEquals('done', $state['runs']['2']['status']);
        $this->assertCount(2, $state['posted_results'], json_encode($state['posted_results'], JSON_PRETTY_PRINT));
    }

    public function testThreadFlagSendsRequestThenClaimsThatThread(): void {
        // :: Setup
        $this->seedThread('ccc', 'One-off', [
            ['id' => 'em3', 'direction' => 'OUT', 'datetime_received' => '2023-11-01T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.'],
        ]);

        // :: Act
        $result = $this->runWorker(['--thread=ccc']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $log = $this->workerLog();
        $this->assertStringContainsString('request: thread ccc mode=incremental -> run 1', $log);
        $this->assertStringContainsString('claim: run 1 thread ccc', $log);
        $this->assertFileExists($this->outDir . '/worker/posted/1.json');

        $state = $this->readState();
        $this->assertEquals('ccc', $state['runs']['1']['thread_id']);
        $this->assertEquals('done', $state['runs']['1']['status']);
    }

    public function testIncrementalInputForFirstEventContainsStartState(): void {
        // :: Setup
        $this->seedThread('ddd', 'Incremental', [
            ['id' => 'em4', 'direction' => 'OUT', 'datetime_received' => '2023-09-01T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.', 'threadState' => [
                'schema_version' => 1,
                'request' => ['summary' => 'MARKER_PREVIOUS_STATE_SUMMARY', 'law_basis' => 'offentleglova', 'sent_at' => null],
                'items' => [['id' => '1', 'asked_for' => 'noe', 'status' => 'NOT_ANSWERED', 'denial_basis' => null, 'released_in_email_ids' => [], 'note' => '']],
                'waiting_for' => 'ENTITY', 'asks_to_us' => [], 'case_numbers' => [], 'dates' => [], 'complaints' => [], 'notes' => '', 'extra' => [],
            ]],
            ['id' => 'em5', 'direction' => 'IN', 'datetime_received' => '2023-09-10T10:00:00+02:00', 'subject' => 'Re: Innsynskrav', 'body_plain' => 'Her er svar.'],
        ]);
        $this->seedRun(1, 'ddd', 'incremental');

        // :: Act
        $result = $this->runWorker(['--once']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $posted = $this->readJson($this->outDir . '/worker/posted/1.json');
        $this->assertCount(1, $posted['events'], 'Only em5 is after the last email with a thread_state: ' . json_encode($posted['events'], JSON_PRETTY_PRINT));
        $this->assertEquals('em5', $posted['events'][0]['email_id']);
        $this->assertStringContainsString('MARKER_PREVIOUS_STATE_SUMMARY', $posted['events'][0]['calls'][0]['input_text']);
    }

    public function testInvalidTwiceGivesFailedRunPostedWithFailedEvent(): void {
        // :: Setup
        $this->seedThread('eee', 'Always invalid', [
            ['id' => 'em6', 'direction' => 'OUT', 'datetime_received' => '2023-12-01T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe. FAKE_INVALID_ALWAYS'],
        ]);
        $this->seedRun(1, 'eee');

        // :: Act
        $result = $this->runWorker(['--once']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $posted = $this->readJson($this->outDir . '/worker/posted/1.json');
        $this->assertEquals('failed', $posted['status']);
        $this->assertStringContainsString("'email_type' must be one of", (string) $posted['error']);
        $this->assertCount(1, $posted['events']);
        $event = $posted['events'][0];
        $this->assertEquals(2, $event['attempts']);
        $this->assertNotNull($event['error']);
        $this->assertNull($event['thread_state']);
        $this->assertNull($event['email_type']);
        $this->assertCount(2, $event['calls'], json_encode($event['calls'], JSON_PRETTY_PRINT));

        $state = $this->readState();
        $this->assertEquals('failed', $state['runs']['1']['status']);
    }

    public function test500OnPostLeavesFilePendingAndTheNextStartResendsIt(): void {
        // :: Setup
        $this->seedThread('fff', 'Transient prod failure', [
            ['id' => 'em7', 'direction' => 'OUT', 'datetime_received' => '2023-12-05T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.'],
        ]);
        $this->seedRun(1, 'fff');
        $this->setNextResultResponse(500);

        // :: Act
        $first = $this->runWorker(['--once']);

        // :: Assert
        $this->assertEquals(0, $first['exitCode'], $first['output']);
        $this->assertFileExists($this->outDir . '/worker/pending/1.json');
        $this->assertFileDoesNotExist($this->outDir . '/worker/posted/1.json');
        $this->assertStringContainsString('could not be posted', $this->workerLog());
        $this->assertEquals('claimed', $this->readState()['runs']['1']['status']);

        // :: Act (next start)
        $second = $this->runWorker(['--once']);

        // :: Assert
        $this->assertEquals(0, $second['exitCode'], $second['output']);
        $this->assertFileDoesNotExist($this->outDir . '/worker/pending/1.json');
        $this->assertFileExists($this->outDir . '/worker/posted/1.json');
        $this->assertStringContainsString('resend: run 1', $this->workerLog());
        $this->assertEquals('done', $this->readState()['runs']['1']['status']);
    }

    public function test400MovesToRejected(): void {
        // :: Setup
        $this->seedThread('ggg', 'Rejected by prod', [
            ['id' => 'em8', 'direction' => 'OUT', 'datetime_received' => '2023-12-06T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.'],
        ]);
        $this->seedRun(1, 'ggg');
        $this->setNextResultResponse(400, ['error' => 'bad request from test override']);

        // :: Act
        $result = $this->runWorker(['--once']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $this->assertFileExists($this->outDir . '/worker/rejected/1.json');
        $this->assertFileDoesNotExist($this->outDir . '/worker/pending/1.json');
        $this->assertFileDoesNotExist($this->outDir . '/worker/posted/1.json');
        $errorText = (string) file_get_contents($this->outDir . '/worker/rejected/1.error.txt');
        $this->assertStringContainsString('bad request from test override', $errorText);
        $this->assertStringContainsString('REJECTED', $this->workerLog());
    }

    public function testBudgetStopsClaimingBeforeTheThirdRun(): void {
        // :: Setup
        // "FAKE_COST=10" (see fixtures/fake-claude.php) makes each thread's
        // single event cost $10; with --max-budget-usd=15, two runs ($20
        // total) are processed, but the check before claiming a third
        // (already at $20) stops the worker with that run still 'requested'.
        foreach (['h1', 'h2', 'h3'] as $i => $threadId) {
            $this->seedThread($threadId, 'Budget ' . $threadId, [
                ['id' => 'em-' . $threadId, 'direction' => 'OUT', 'datetime_received' => '2023-12-0' . ($i + 1) . 'T10:00:00+02:00', 'subject' => 'Budget', 'body_plain' => 'FAKE_COST=10'],
            ]);
            $this->seedRun($i + 1, $threadId);
        }

        // :: Act
        $result = $this->runWorker(['--max-budget-usd=15']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('max-budget-usd 15 reached', $this->workerLog());

        $state = $this->readState();
        $this->assertEquals('done', $state['runs']['1']['status'], json_encode($state['runs'], JSON_PRETTY_PRINT));
        $this->assertEquals('done', $state['runs']['2']['status']);
        $this->assertEquals('requested', $state['runs']['3']['status'], 'The third run must never have been claimed once the budget was reached: ' . json_encode($state['runs'], JSON_PRETTY_PRINT));
        $this->assertCount(2, $state['posted_results'], json_encode($state['posted_results'], JSON_PRETTY_PRINT));
    }

    // -- --next-np (Change 7: "process next" for norske-postlister threads) --

    public function testNextNpProcessesCandidatesInOrderThenGives204AndStops(): void {
        // :: Setup
        $this->seedThread('np1', 'NP thread 1', [
            ['id' => 'np-em1', 'direction' => 'OUT', 'datetime_received' => '2023-09-12T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om valgprotokoll.'],
        ]);
        $this->seedThread('np2', 'NP thread 2', [
            ['id' => 'np-em2', 'direction' => 'OUT', 'datetime_received' => '2023-10-01T10:00:00+02:00', 'subject' => 'Innsynskrav 2', 'body_plain' => 'Vi ber om moetebok.'],
        ]);
        $this->seedNpCandidates(['np1', 'np2']);

        // :: Act
        // --limit=3 with only two candidates: the third request-next call
        // must find nothing left and stop cleanly.
        $result = $this->runWorker(['--next-np', '--limit=3']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $log = $this->workerLog();
        $this->assertStringContainsString('request-next: np -> run 1 thread np1', $log);
        $this->assertStringContainsString('request-next: np -> run 2 thread np2', $log);
        $this->assertStringContainsString('request-next: np -> none left', $log);
        $this->assertFileExists($this->outDir . '/worker/posted/1.json');
        $this->assertFileExists($this->outDir . '/worker/posted/2.json');

        $state = $this->readState();
        $this->assertEquals('done', $state['runs']['1']['status'], json_encode($state['runs'], JSON_PRETTY_PRINT));
        $this->assertEquals('np1', $state['runs']['1']['thread_id']);
        $this->assertEquals('done', $state['runs']['2']['status'], json_encode($state['runs'], JSON_PRETTY_PRINT));
        $this->assertEquals('np2', $state['runs']['2']['thread_id']);
    }

    public function testNextNpDefaultLimitProcessesOnlyOneThread(): void {
        // :: Setup
        $this->seedThread('np3', 'NP thread 3', [
            ['id' => 'np-em3', 'direction' => 'OUT', 'datetime_received' => '2023-11-01T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.'],
        ]);
        $this->seedThread('np4', 'NP thread 4', [
            ['id' => 'np-em4', 'direction' => 'OUT', 'datetime_received' => '2023-11-02T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe annet.'],
        ]);
        $this->seedNpCandidates(['np3', 'np4']);

        // :: Act
        $result = $this->runWorker(['--next-np']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $this->assertFileExists($this->outDir . '/worker/posted/1.json');
        $this->assertFileDoesNotExist($this->outDir . '/worker/posted/2.json');

        $state = $this->readState();
        $this->assertEquals('done', $state['runs']['1']['status'], json_encode($state['runs'], JSON_PRETTY_PRINT));
        $this->assertEquals('np3', $state['runs']['1']['thread_id']);
        $this->assertArrayNotHasKey('2', $state['runs'], json_encode($state['runs'], JSON_PRETTY_PRINT));
    }

    public function testNextNpWithThreadIsRefused(): void {
        // :: Act
        $result = $this->runWorker(['--next-np', '--thread=np1']);

        // :: Assert
        $this->assertEquals(1, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('--next-np cannot be combined with --thread', $result['output']);
    }

    // -- --reviews (step 2c "Change 9: review status and notes per run") --

    public function testReviewsPrintsReviewedRunsFromFakeProd(): void {
        // :: Setup
        $this->seedThread('rev1', 'Review test thread', [
            ['id' => 'rev-em1', 'direction' => 'OUT', 'datetime_received' => '2023-09-12T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om valgprotokoll.'],
        ]);
        $state = $this->readState();
        $state['runs']['1'] = [
            'id' => 1, 'thread_id' => 'rev1', 'mode' => 'incremental', 'status' => 'done',
            'worker' => 'test-worker', 'lease_expires_at' => null,
            'review_status' => 'WRONG', 'review_notes' => 'Missed the denial basis.',
            'reviewed_by' => 'admin-user', 'reviewed_at' => '2026-01-05T10:00:00+00:00',
            'model' => 'claude-opus-5-5', 'system_prompt_sha256' => str_repeat('a', 64),
            'finished_at' => '2026-01-05T09:00:00+00:00',
        ];
        $state['next_run_id'] = 2;
        $this->writeState($state);

        // :: Act
        $result = $this->runWorker(['--reviews']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('Thread: rev1 (Review test thread)', $result['output']);
        $this->assertStringContainsString('Review status: WRONG', $result['output']);
        $this->assertStringContainsString('Notes: Missed the denial basis.', $result['output']);
        $this->assertStringContainsString('System prompt: aaaaaaaa', $result['output']);
        $this->assertStringContainsString($this->baseUrl . '/thread-analysis/thread?id=rev1', $result['output']);
    }

    public function testReviewsFiltersByStatusOption(): void {
        // :: Setup
        $this->seedThread('rev2', 'Correct thread', [
            ['id' => 'rev-em2', 'direction' => 'OUT', 'datetime_received' => '2023-09-12T10:00:00+02:00', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om valgprotokoll.'],
        ]);
        $state = $this->readState();
        $state['runs']['1'] = [
            'id' => 1, 'thread_id' => 'rev2', 'mode' => 'incremental', 'status' => 'done',
            'worker' => 'test-worker', 'lease_expires_at' => null,
            'review_status' => 'CORRECT', 'review_notes' => null,
            'reviewed_by' => 'admin-user', 'reviewed_at' => '2026-01-05T10:00:00+00:00',
        ];
        $state['next_run_id'] = 2;
        $this->writeState($state);

        // :: Act
        $result = $this->runWorker(['--reviews', '--status=WRONG,MINOR_ISSUES']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);
        $this->assertStringNotContainsString('Correct thread', $result['output']);
    }

    public function testReviewsWithNextNpIsRefused(): void {
        // :: Act
        $result = $this->runWorker(['--reviews', '--next-np']);

        // :: Assert
        $this->assertEquals(1, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('--reviews cannot be combined with --thread or --next-np', $result['output']);
    }
}
