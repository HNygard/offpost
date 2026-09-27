<?php
// organizer/src/tests/AnalyzeThreadsCliTest.php
//
// Runs tools/analyze-threads.php as a real subprocess against an invented,
// two/one-thread export fixture in a temp directory, with a fake
// --claude-bin (fixtures/fake-claude.php) so no real claude CLI call - and
// no cost - is ever made. See
// docs/superpowers/plans/2026-09-27-step2-2-thread-analysis.md.
use PHPUnit\Framework\TestCase;

class AnalyzeThreadsCliTest extends TestCase {
    private string $cliPath;
    private string $claudeBin;
    private string $baseDir;
    private string $exportDir;
    private string $outDir;

    protected function setUp(): void {
        parent::setUp();
        $this->cliPath = __DIR__ . '/../../../tools/analyze-threads.php';
        $this->claudeBin = __DIR__ . '/fixtures/fake-claude.php';
        $this->baseDir = sys_get_temp_dir() . '/analyze_threads_test_' . uniqid();
        $this->exportDir = $this->baseDir . '/thread-export';
        $this->outDir = $this->baseDir . '/thread-analysis';
        mkdir($this->exportDir . '/threads', 0777, true);
    }

    protected function tearDown(): void {
        parent::tearDown();
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

    /**
     * Writes an invented export file for one thread. $emails is a list of
     * ['id','direction','datetime_received','status_type','subject','body_plain']
     * (all invented data - never real email content).
     */
    private function writeExportThread(string $id, string $title, string $entityName, string $initialRequest, array $emails): void {
        $exportEmails = [];
        foreach ($emails as $email) {
            $exportEmails[] = [
                'id' => $email['id'],
                'email_type' => $email['direction'],
                'datetime_received' => $email['datetime_received'],
                'ignore' => false,
                'status_type' => $email['status_type'],
                'from' => $email['direction'] === 'OUT' ? 'oss@example.org' : 'post@example.org',
                'to' => $email['direction'] === 'OUT' ? ['post@example.org'] : ['oss@example.org'],
                'cc' => [],
                'subject' => $email['subject'],
                'body_plain' => $email['body_plain'],
                'attachments' => [],
            ];
        }
        $data = [
            'export_version' => 1,
            'exported_at' => '2026-09-27T10:00:00+02:00',
            'fingerprint' => 'fp-' . $id,
            'thread' => [
                'id' => $id,
                'entity_id' => 'entity-' . $id,
                'title' => $title,
                'initial_request' => $initialRequest,
            ],
            'entity' => ['entity_id' => 'entity-' . $id, 'name' => $entityName],
            'emails' => $exportEmails,
        ];
        file_put_contents(
            $this->exportDir . '/threads/' . $id . '.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /** @return array{exitCode: int, output: string} */
    private function runCli(array $args): array {
        $cmd = 'php ' . escapeshellarg($this->cliPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $outputLines, $exitCode);
        return ['exitCode' => $exitCode, 'output' => implode("\n", $outputLines)];
    }

    private function readJson(string $path): array {
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "Not valid JSON: $path");
        return $decoded;
    }

    public function testHelpPrintsUsageWithoutTouchingAnything(): void {
        // :: Act
        $result = $this->runCli(['--help']);

        // :: Assert
        $this->assertEquals(0, $result['exitCode']);
        $this->assertStringContainsString('Usage: php tools/analyze-threads.php', $result['output']);
        $this->assertStringContainsString('--claude-bin=PATH', $result['output']);
        $this->assertDirectoryDoesNotExist($this->outDir);
    }

    public function testRunProducesOutputFilesAndTotals(): void {
        // :: Setup
        $this->writeExportThread('aaa', 'Valgprotokoll 2023', 'Testkommune', 'Vi ber om valgprotokoll for 2023.', [
            ['id' => 'em1', 'direction' => 'OUT', 'datetime_received' => '2023-09-12T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om valgprotokoll for 2023.'],
            ['id' => 'em2', 'direction' => 'IN', 'datetime_received' => '2023-09-20T08:00:00+02:00', 'status_type' => 'REQUEST_RECEIPT', 'subject' => 'Re: Innsynskrav', 'body_plain' => 'Vi har mottatt din henvendelse.'],
        ]);
        $this->writeExportThread('bbb', 'Moetebok', 'Andre kommune', 'Vi ber om moetebok.', [
            ['id' => 'em3', 'direction' => 'OUT', 'datetime_received' => '2023-10-01T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Innsynskrav 2', 'body_plain' => 'Vi ber om moetebok.'],
        ]);

        // :: Act
        $result = $this->runCli([
            '--export=' . $this->exportDir, '--out=' . $this->outDir, '--run=testrun',
            '--claude-bin=' . $this->claudeBin, '--parallel=2',
        ]);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);

        $runInfo = $this->readJson($this->outDir . '/testrun/run.json');
        $this->assertEquals(
            [
                'threads' => 2, 'events' => 3, 'cost_usd' => 0.03,
                'input_tokens' => 300, 'cache_creation_input_tokens' => 150, 'cache_read_input_tokens' => 90,
                'output_tokens' => 60, 'thinking_tokens' => 15, 'total_input_tokens' => 540,
            ],
            $runInfo['totals'],
            json_encode($runInfo['totals'], JSON_PRETTY_PRINT)
        );
        $this->assertNull($runInfo['stopped_reason']);

        $aaa = $this->readJson($this->outDir . '/testrun/threads/aaa.json');
        $this->assertEquals('done', $aaa['status']);
        $this->assertCount(2, $aaa['events'], json_encode($aaa['events'], JSON_PRETTY_PRINT));
        $this->assertEquals('em1', $aaa['events'][0]['email_id']);
        $this->assertEquals('OUR_REQUEST', $aaa['events'][0]['email_type_actual']);
        $this->assertEquals(1, $aaa['events'][0]['attempts']);
        $this->assertEquals('WAITING_FOR_ENTITY', $aaa['events'][0]['derived_thread_state_type']);
        $this->assertNull($aaa['events'][0]['error']);
        $this->assertEquals(
            [
                'events' => 2, 'cost_usd' => 0.02,
                'input_tokens' => 200, 'cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 60,
                'output_tokens' => 40, 'thinking_tokens' => 10, 'total_input_tokens' => 360,
            ],
            $aaa['totals']
        );

        $bbb = $this->readJson($this->outDir . '/testrun/threads/bbb.json');
        $this->assertEquals('done', $bbb['status']);
        $this->assertCount(1, $bbb['events'], json_encode($bbb['events'], JSON_PRETTY_PRINT));
    }

    public function testResumeSkipsThreadsAlreadyDone(): void {
        // :: Setup
        $this->writeExportThread('aaa', 'Valgprotokoll 2023', 'Testkommune', 'Vi ber om valgprotokoll for 2023.', [
            ['id' => 'em1', 'direction' => 'OUT', 'datetime_received' => '2023-09-12T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om valgprotokoll for 2023.'],
        ]);
        $first = $this->runCli(['--export=' . $this->exportDir, '--out=' . $this->outDir, '--run=resumerun', '--claude-bin=' . $this->claudeBin]);
        $this->assertEquals(0, $first['exitCode'], $first['output']);

        // :: Act
        $second = $this->runCli(['--export=' . $this->exportDir, '--out=' . $this->outDir, '--run=resumerun', '--claude-bin=' . $this->claudeBin]);

        // :: Assert
        $this->assertEquals(0, $second['exitCode'], $second['output']);
        $runLog = (string) file_get_contents($this->outDir . '/resumerun/run.log');
        $this->assertStringContainsString('aaa already done, skipping', $runLog);

        $runInfo = $this->readJson($this->outDir . '/resumerun/run.json');
        $this->assertEquals(
            [
                'threads' => 1, 'events' => 1, 'cost_usd' => 0.01,
                'input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 30,
                'output_tokens' => 20, 'thinking_tokens' => 5, 'total_input_tokens' => 180,
            ],
            $runInfo['totals'],
            'Totals must not double after a resume that only skips done threads: ' . json_encode($runInfo['totals'], JSON_PRETTY_PRINT)
        );
    }

    public function testRetryThenFailRecordsTwoAttemptsAndFailsTheThread(): void {
        // :: Setup
        // FAKE_INVALID_ALWAYS (see fixtures/fake-claude.php) makes every
        // attempt for this thread's event invalid, so it fails after a retry.
        $this->writeExportThread('ddd', 'Always fails', 'Fail kommune', 'Vi ber om noe. FAKE_INVALID_ALWAYS', [
            ['id' => 'em5', 'direction' => 'OUT', 'datetime_received' => '2023-11-05T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.'],
        ]);

        // :: Act
        $result = $this->runCli(['--export=' . $this->exportDir, '--out=' . $this->outDir, '--run=failrun', '--claude-bin=' . $this->claudeBin]);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);

        $ddd = $this->readJson($this->outDir . '/failrun/threads/ddd.json');
        $this->assertEquals('failed', $ddd['status']);
        $this->assertCount(1, $ddd['events'], json_encode($ddd['events'], JSON_PRETTY_PRINT));
        $this->assertEquals(2, $ddd['events'][0]['attempts']);
        $this->assertNull($ddd['events'][0]['derived_thread_state_type']);
        $this->assertStringContainsString("'email_type' must be one of", (string) $ddd['events'][0]['error']);

        $runLog = (string) file_get_contents($this->outDir . '/failrun/run.log');
        $this->assertStringContainsString('ddd event 1/1 FAILED', $runLog);
    }

    public function testRetryThenValidSucceedsOnTheSecondAttempt(): void {
        // :: Setup
        // FAKE_INVALID_ONCE makes the first attempt invalid, but fake-claude.php
        // always answers valid once it sees the CLI's "was invalid" retry text.
        $this->writeExportThread('ccc', 'Retry once', 'Retry kommune', 'Vi ber om noe. FAKE_INVALID_ONCE', [
            ['id' => 'em4', 'direction' => 'OUT', 'datetime_received' => '2023-11-01T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Innsynskrav', 'body_plain' => 'Vi ber om noe.'],
        ]);

        // :: Act
        $result = $this->runCli(['--export=' . $this->exportDir, '--out=' . $this->outDir, '--run=retryrun', '--claude-bin=' . $this->claudeBin]);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);

        $ccc = $this->readJson($this->outDir . '/retryrun/threads/ccc.json');
        $this->assertEquals('done', $ccc['status']);
        $this->assertCount(1, $ccc['events'], json_encode($ccc['events'], JSON_PRETTY_PRINT));
        $this->assertEquals(2, $ccc['events'][0]['attempts']);
        $this->assertNull($ccc['events'][0]['error']);
        $this->assertEquals('WAITING_FOR_ENTITY', $ccc['events'][0]['derived_thread_state_type']);
        // Cost/usage sum both attempts (fake-claude.php charges 0.01 per call).
        $this->assertEquals(0.02, $ccc['events'][0]['cost_usd']);
        $this->assertEquals(200, $ccc['events'][0]['usage']['input_tokens']);
    }

    public function testBudgetStopLeavesLaterThreadsUntouched(): void {
        // :: Setup
        // "eee" costs $10 per event (FAKE_COST=10) and has two events, so it
        // alone reaches the $15 budget; "fff" must then never be started.
        $this->writeExportThread('eee', 'Budget A', 'Budget kommune A', 'FAKE_COST=10', [
            ['id' => 'em6', 'direction' => 'OUT', 'datetime_received' => '2023-12-01T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Budget A', 'body_plain' => 'FAKE_COST=10'],
            ['id' => 'em7', 'direction' => 'IN', 'datetime_received' => '2023-12-02T10:00:00+02:00', 'status_type' => 'REQUEST_RECEIPT', 'subject' => 'Budget A 2', 'body_plain' => 'FAKE_COST=10'],
        ]);
        $this->writeExportThread('fff', 'Budget B', 'Budget kommune B', 'FAKE_COST=10', [
            ['id' => 'em8', 'direction' => 'OUT', 'datetime_received' => '2023-12-03T10:00:00+02:00', 'status_type' => 'OUR_REQUEST', 'subject' => 'Budget B', 'body_plain' => 'FAKE_COST=10'],
        ]);

        // :: Act
        $result = $this->runCli([
            '--export=' . $this->exportDir, '--out=' . $this->outDir, '--run=budgetrun',
            '--claude-bin=' . $this->claudeBin, '--parallel=1', '--max-budget-usd=15',
            '--thread=eee', '--thread=fff',
        ]);

        // :: Assert
        $this->assertEquals(0, $result['exitCode'], $result['output']);

        $runInfo = $this->readJson($this->outDir . '/budgetrun/run.json');
        $this->assertEquals('budget', $runInfo['stopped_reason']);
        $this->assertEquals(
            [
                'threads' => 1, 'events' => 2, 'cost_usd' => 20.0,
                'input_tokens' => 200, 'cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 60,
                'output_tokens' => 40, 'thinking_tokens' => 10, 'total_input_tokens' => 360,
            ],
            $runInfo['totals'],
            json_encode($runInfo['totals'], JSON_PRETTY_PRINT)
        );

        $eee = $this->readJson($this->outDir . '/budgetrun/threads/eee.json');
        $this->assertEquals('done', $eee['status']);
        $this->assertCount(2, $eee['events']);

        $this->assertFileDoesNotExist($this->outDir . '/budgetrun/threads/fff.json');
    }
}
