<?php
// organizer/src/tests/ThreadAnalysisRepositoryTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisRepository.php';

class ThreadAnalysisRepositoryTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private int $threadCounter = 0;

    private function createFixedThread(array $labels = []): string {
        $this->threadCounter++;
        $thread = new Thread();
        $thread->title = 'Analysis test thread';
        $thread->my_name = 'Test Person';
        $thread->my_email = "test-person-{$this->threadCounter}@example.com";
        $thread->labels = $labels;
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = true;
        $thread->initial_request = 'Please release the documents.';
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->request_law_basis = Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
        $thread->request_follow_up_plan = Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;
        $thread->sentComment = null;
        $created = ThreadStorageManager::getInstance()->createThread(
            '000000000-test-entity-development',
            $thread,
            'test-user'
        );
        return $created->id;
    }

    /** A thread carrying NpApiService::NP_LABEL, for requestNextNpThread() tests. */
    private function createNpThread(): string {
        return $this->createFixedThread([NpApiService::NP_LABEL]);
    }

    private function insertEmail(string $threadId, string $timestampReceived, ?string $threadStateSource = null, bool $ignore = false): string {
        return Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, content, thread_state_source, ignore)
             VALUES (?, ?, ?, ?::bytea, ?, ?) RETURNING id",
            [$threadId, $timestampReceived, $timestampReceived, 'Body text', $threadStateSource, $ignore ? 't' : 'f']
        );
    }

    private function insertRun(string $threadId, string $status, string $requestedAt, array $overrides = []): int {
        return (int) Database::queryValue(
            "INSERT INTO thread_analysis_runs
                (thread_id, status, mode, requested_by, requested_at, claimed_at, lease_expires_at, worker)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $threadId,
                $status,
                $overrides['mode'] ?? 'incremental',
                $overrides['requested_by'] ?? 'test-user',
                $requestedAt,
                $overrides['claimed_at'] ?? null,
                $overrides['lease_expires_at'] ?? null,
                $overrides['worker'] ?? null,
            ]
        );
    }

    private const VALID_THREAD_STATE = [
        'schema_version' => 1,
        'request' => ['summary' => 'Valgprotokoll', 'law_basis' => 'offentleglova', 'sent_at' => null],
        'items' => [
            ['id' => '1', 'asked_for' => 'Valgprotokoll 2023', 'status' => 'RELEASED',
             'denial_basis' => null, 'released_in_email_ids' => [], 'note' => ''],
        ],
        'waiting_for' => 'NOBODY',
        'asks_to_us' => [],
        'case_numbers' => [],
        'dates' => [],
        'complaints' => [],
        'notes' => '',
        'extra' => [],
    ];

    private function validCall(int $attempt = 1): array {
        return [
            'attempt' => $attempt,
            'input_text' => 'the input',
            'json_schema' => ['type' => 'object'],
            'response' => ['ok' => true],
            'model' => 'claude-opus-5-5',
            'model_resolved' => 'claude-opus-5-5',
            'session_id' => 'session-1',
            'claude_code_version' => '2.1.283',
            'input_tokens' => 2,
            'cache_creation_input_tokens' => 6363,
            'cache_read_input_tokens' => 0,
            'output_tokens' => 1243,
            'thinking_tokens' => 98,
            'cost_usd' => 0.076,
            'duration_ms' => 12600,
            'duration_api_ms' => 12100,
            'is_error' => false,
            'stop_reason' => 'end_turn',
        ];
    }

    private function validEvent(string $emailId, int $position, array $overrides = []): array {
        return array_merge([
            'email_id' => $emailId,
            'position' => $position,
            'email_type' => 'INFORMATION_RELEASE',
            'email_note' => 'note',
            'email_type_gap' => '',
            'thread_state' => self::VALID_THREAD_STATE,
            'attempts' => 1,
            'error' => null,
            'calls' => [$this->validCall()],
        ], $overrides);
    }

    // :: Requesting

    public function testRequestRunCreatesARun(): void {
        // :: Setup
        $threadId = $this->createFixedThread();

        // :: Act
        $runId = ThreadAnalysisRepository::requestRun($threadId, 'incremental', 'test-user');

        // :: Assert
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('requested', $run['status'], json_encode($run, JSON_PRETTY_PRINT));
        $this->assertEquals($threadId, $run['thread_id']);
        $this->assertEquals('incremental', $run['mode']);
        $this->assertEquals('test-user', $run['requested_by']);
    }

    public function testRequestRunReturnsSameIdWhileOpen(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $firstRunId = ThreadAnalysisRepository::requestRun($threadId, 'incremental', 'test-user');

        // :: Act
        $secondRunId = ThreadAnalysisRepository::requestRun($threadId, 'full', 'another-user');

        // :: Assert
        $this->assertEquals($firstRunId, $secondRunId);
        $runs = ThreadAnalysisRepository::getRunsForThread($threadId);
        $this->assertCount(1, $runs, json_encode($runs, JSON_PRETTY_PRINT));
    }

    public function testRequestRunCreatesNewRunAfterDone(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $firstRunId = $this->insertRun($threadId, 'done', '2026-01-01T10:00:00+00:00');

        // :: Act
        $secondRunId = ThreadAnalysisRepository::requestRun($threadId, 'incremental', 'test-user');

        // :: Assert
        $this->assertNotEquals($firstRunId, $secondRunId);
        $runs = ThreadAnalysisRepository::getRunsForThread($threadId);
        $this->assertCount(2, $runs, json_encode($runs, JSON_PRETTY_PRINT));
    }

    // :: Claiming

    public function testClaimNextTakesOldestRunFirst(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $olderRunId = $this->insertRun($threadId, 'requested', '2026-01-01T10:00:00+00:00');
        $newerRunId = $this->insertRun($threadId, 'requested', '2026-01-02T10:00:00+00:00');

        // :: Act
        $claimed = ThreadAnalysisRepository::claimNext('worker-1', 600);

        // :: Assert
        $this->assertNotNull($claimed);
        $this->assertEquals($olderRunId, (int) $claimed['id'], json_encode($claimed, JSON_PRETTY_PRINT));
        $this->assertNotEquals($newerRunId, (int) $claimed['id']);
        $this->assertEquals('claimed', $claimed['status']);
        $this->assertEquals('worker-1', $claimed['worker']);
        $this->assertNotNull($claimed['claimed_at']);
        $this->assertNotNull($claimed['lease_expires_at']);
    }

    public function testClaimNextSkipsDoneAndFailedRuns(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $this->insertRun($threadId, 'done', '2026-01-01T09:00:00+00:00');
        $this->insertRun($threadId, 'failed', '2026-01-01T09:30:00+00:00');
        $requestedRunId = $this->insertRun($threadId, 'requested', '2026-01-01T10:00:00+00:00');

        // :: Act
        $claimed = ThreadAnalysisRepository::claimNext('worker-1', 600);

        // :: Assert
        $this->assertNotNull($claimed);
        $this->assertEquals($requestedRunId, (int) $claimed['id'], json_encode($claimed, JSON_PRETTY_PRINT));
    }

    public function testClaimNextRetakesRunWithExpiredLease(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $expiredRunId = $this->insertRun($threadId, 'claimed', '2026-01-01T10:00:00+00:00', [
            'claimed_at' => '2026-01-01T10:00:00+00:00',
            'worker' => 'worker-1',
        ]);
        // The lease has already expired - set explicitly in the past by SQL.
        Database::execute(
            "UPDATE thread_analysis_runs SET lease_expires_at = '2026-01-01T10:05:00+00:00' WHERE id = ?",
            [$expiredRunId]
        );

        // :: Act
        $claimed = ThreadAnalysisRepository::claimNext('worker-2', 600);

        // :: Assert
        $this->assertNotNull($claimed);
        $this->assertEquals($expiredRunId, (int) $claimed['id'], json_encode($claimed, JSON_PRETTY_PRINT));
        $this->assertEquals('worker-2', $claimed['worker']);
    }

    public function testClaimThreadTakesOnlyGivenThreadsRun(): void {
        // :: Setup
        $threadIdA = $this->createFixedThread();
        $threadIdB = $this->createFixedThread();
        $this->insertRun($threadIdA, 'requested', '2026-01-01T09:00:00+00:00');
        $runIdB = $this->insertRun($threadIdB, 'requested', '2026-01-01T10:00:00+00:00');

        // :: Act
        $claimed = ThreadAnalysisRepository::claimThread($threadIdB, 'worker-1', 600);

        // :: Assert
        $this->assertNotNull($claimed);
        $this->assertEquals($runIdB, (int) $claimed['id'], json_encode($claimed, JSON_PRETTY_PRINT));
        $this->assertEquals($threadIdB, $claimed['thread_id']);
    }

    // :: requestNextNpThread (Change 7: "process next" for norske-postlister threads)

    public function testRequestNextNpThreadOnlyPicksNpLabelledThreads(): void {
        // :: Setup
        $npThreadId = $this->createNpThread();
        $this->insertEmail($npThreadId, '2026-02-01T09:00:00+00:00');
        $otherThreadId = $this->createFixedThread(['some-other-label']);
        // The non-NP thread has the newer email but must never be picked.
        $this->insertEmail($otherThreadId, '2026-02-02T09:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $this->assertEquals($npThreadId, $picked['thread_id'], json_encode($picked, JSON_PRETTY_PRINT));
    }

    public function testRequestNextNpThreadSkipsThreadWithAnyRunRegardlessOfStatus(): void {
        // :: Setup
        $threadDone = $this->createNpThread();
        $this->insertEmail($threadDone, '2026-02-10T09:00:00+00:00');
        $this->insertRun($threadDone, 'done', '2026-01-01T08:00:00+00:00');

        $threadFailed = $this->createNpThread();
        $this->insertEmail($threadFailed, '2026-02-09T09:00:00+00:00');
        $this->insertRun($threadFailed, 'failed', '2026-01-01T08:00:00+00:00');

        $threadNone = $this->createNpThread();
        // Its only non-ignored email is older than the two above, but they
        // are excluded outright for already having a run.
        $this->insertEmail($threadNone, '2026-02-01T09:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $this->assertEquals($threadNone, $picked['thread_id'], json_encode($picked, JSON_PRETTY_PRINT));
    }

    public function testRequestNextNpThreadSkipsThreadWithNoNonIgnoredEmails(): void {
        // :: Setup
        $threadAllIgnored = $this->createNpThread();
        $this->insertEmail($threadAllIgnored, '2026-02-15T09:00:00+00:00', null, true);
        $threadWithEmail = $this->createNpThread();
        $this->insertEmail($threadWithEmail, '2026-02-01T09:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $this->assertEquals($threadWithEmail, $picked['thread_id'], json_encode($picked, JSON_PRETTY_PRINT));
    }

    public function testRequestNextNpThreadUsesLatestNonIgnoredEmailPerThread(): void {
        // :: Setup
        $threadA = $this->createNpThread();
        $this->insertEmail($threadA, '2026-02-01T09:00:00+00:00');
        // A later email on the same thread, but ignored - must not count
        // towards its "latest" datetime_received.
        $this->insertEmail($threadA, '2026-02-20T09:00:00+00:00', null, true);
        $threadB = $this->createNpThread();
        $this->insertEmail($threadB, '2026-02-05T09:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $this->assertEquals($threadB, $picked['thread_id'], json_encode($picked, JSON_PRETTY_PRINT));
    }

    public function testRequestNextNpThreadPicksNewestLatestEmailFirst(): void {
        // :: Setup
        $older = $this->createNpThread();
        $this->insertEmail($older, '2026-02-01T09:00:00+00:00');
        $newer = $this->createNpThread();
        $this->insertEmail($newer, '2026-02-10T09:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $this->assertEquals($newer, $picked['thread_id'], json_encode($picked, JSON_PRETTY_PRINT));
    }

    public function testRequestNextNpThreadBreaksTiesByThreadId(): void {
        // :: Setup
        $threadA = $this->createNpThread();
        $this->insertEmail($threadA, '2026-02-01T09:00:00+00:00');
        $threadB = $this->createNpThread();
        $this->insertEmail($threadB, '2026-02-01T09:00:00+00:00');
        $expected = strcmp($threadA, $threadB) > 0 ? $threadA : $threadB;

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $this->assertEquals($expected, $picked['thread_id'], json_encode($picked, JSON_PRETTY_PRINT));
    }

    public function testRequestNextNpThreadReturnsNullWhenNoneLeft(): void {
        // :: Setup
        $threadId = $this->createNpThread();
        $this->insertEmail($threadId, '2026-02-01T09:00:00+00:00');
        $this->insertRun($threadId, 'requested', '2026-01-01T08:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('incremental', 'token');

        // :: Assert
        $this->assertNull($picked);
    }

    public function testRequestNextNpThreadCreatesRunWithGivenMode(): void {
        // :: Setup
        $threadId = $this->createNpThread();
        $this->insertEmail($threadId, '2026-02-01T09:00:00+00:00');

        // :: Act
        $picked = ThreadAnalysisRepository::requestNextNpThread('full', 'token');

        // :: Assert
        $this->assertNotNull($picked);
        $run = ThreadAnalysisRepository::getRun($picked['run_id']);
        $this->assertEquals('full', $run['mode'], json_encode($run, JSON_PRETTY_PRINT));
        $this->assertEquals('requested', $run['status']);
        $this->assertEquals('token', $run['requested_by']);
        $this->assertEquals($threadId, $run['thread_id']);
        $this->assertEquals($threadId, $picked['thread_id']);
    }

    // :: saveSystemPrompt

    public function testSaveSystemPromptIsIdempotent(): void {
        // :: Setup
        $text = 'You are an analysis assistant.';

        // :: Act
        $sha1 = ThreadAnalysisRepository::saveSystemPrompt($text);
        $sha2 = ThreadAnalysisRepository::saveSystemPrompt($text);

        // :: Assert
        $this->assertEquals($sha1, $sha2);
        $rows = Database::query("SELECT * FROM thread_analysis_system_prompts WHERE sha256 = ?", [$sha1]);
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals($text, $rows[0]['text']);
    }

    // :: saveResult done

    public function testSaveResultDoneStoresEventsAndCallsExactlyAndSetsRunFields(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId1 = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $emailId2 = $this->insertEmail($threadId, '2026-01-02T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);

        $result = [
            'status' => 'done',
            'error' => null,
            'model' => 'claude-opus-5-5',
            'system_prompt' => 'You are an analysis assistant.',
            'schema_version' => 1,
            'events' => [
                $this->validEvent($emailId1, 1),
                $this->validEvent($emailId2, 2, ['email_note' => 'second note']),
            ],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('done', $run['status'], json_encode($run, JSON_PRETTY_PRINT));
        $this->assertEquals('claude-opus-5-5', $run['model']);
        $this->assertEquals(1, $run['schema_version']);
        $this->assertNotNull($run['finished_at']);
        $this->assertNull($run['error']);
        $expectedSha = hash('sha256', 'You are an analysis assistant.');
        $this->assertEquals($expectedSha, $run['system_prompt_sha256']);

        $events = ThreadAnalysisRepository::getEventsForRun($runId);
        $this->assertCount(2, $events, json_encode($events, JSON_PRETTY_PRINT));

        $this->assertEquals($emailId1, $events[0]['email_id']);
        $this->assertEquals(1, $events[0]['position']);
        $this->assertEquals('INFORMATION_RELEASE', $events[0]['email_type']);
        $this->assertEquals('note', $events[0]['email_note']);
        $this->assertEquals('', $events[0]['email_type_gap']);
        $this->assertEquals(self::VALID_THREAD_STATE, $events[0]['thread_state'], json_encode($events[0], JSON_PRETTY_PRINT));
        $this->assertEquals('ANSWERED', $events[0]['derived_thread_state_type']);
        $this->assertEquals(1, $events[0]['attempts']);
        $this->assertNull($events[0]['error']);

        $this->assertEquals($emailId2, $events[1]['email_id']);
        $this->assertEquals(2, $events[1]['position']);
        $this->assertEquals('second note', $events[1]['email_note']);

        $calls = Database::query(
            "SELECT * FROM thread_analysis_claude_code_calls WHERE run_id = ? ORDER BY event_id ASC",
            [$runId]
        );
        $this->assertCount(2, $calls, json_encode($calls, JSON_PRETTY_PRINT));
        $call = $calls[0];
        $this->assertEquals(1, $call['attempt']);
        $this->assertEquals('the input', $call['input_text']);
        $this->assertEquals(['type' => 'object'], json_decode($call['json_schema'], true));
        $this->assertEquals(['ok' => true], json_decode($call['response'], true));
        $this->assertEquals('claude-opus-5-5', $call['model']);
        $this->assertEquals('claude-opus-5-5', $call['model_resolved']);
        $this->assertEquals('session-1', $call['session_id']);
        $this->assertEquals('2.1.283', $call['claude_code_version']);
        $this->assertEquals(2, $call['input_tokens']);
        $this->assertEquals(6363, $call['cache_creation_input_tokens']);
        $this->assertEquals(0, $call['cache_read_input_tokens']);
        $this->assertEquals(1243, $call['output_tokens']);
        $this->assertEquals(98, $call['thinking_tokens']);
        $this->assertEquals(0.076, (float) $call['cost_usd']);
        $this->assertEquals(12600, $call['duration_ms']);
        $this->assertEquals(12100, $call['duration_api_ms']);
        $this->assertFalse($call['is_error']);
        $this->assertEquals('end_turn', $call['stop_reason']);
    }

    public function testSaveResultDoneWithNoEventsIsAccepted(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);
        $result = [
            'status' => 'done', 'error' => null, 'model' => 'claude-opus-5-5',
            'system_prompt' => 'prompt', 'schema_version' => 1, 'events' => [],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('done', $run['status'], json_encode($run, JSON_PRETTY_PRINT));
        $events = ThreadAnalysisRepository::getEventsForRun($runId);
        $this->assertCount(0, $events, json_encode($events, JSON_PRETTY_PRINT));
    }

    public function testSaveResultDoneIgnoresWorkerSuppliedDerivedThreadStateType(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);

        $event = $this->validEvent($emailId, 1);
        // A worker cannot send this field at all through the documented result
        // format, but prod must derive it itself regardless of what arrives -
        // simulate a worker that tries anyway.
        $event['derived_thread_state_type'] = 'CLOSED';

        $result = [
            'status' => 'done', 'error' => null, 'model' => 'claude-opus-5-5',
            'system_prompt' => 'prompt', 'schema_version' => 1, 'events' => [$event],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $events = ThreadAnalysisRepository::getEventsForRun($runId);
        $this->assertEquals('ANSWERED', $events[0]['derived_thread_state_type'], json_encode($events, JSON_PRETTY_PRINT));
    }

    public function testSaveResultDoneAppliesStateToThreadEmails(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);
        $result = [
            'status' => 'done', 'error' => null, 'model' => 'claude-opus-5-5',
            'system_prompt' => 'prompt', 'schema_version' => 1,
            'events' => [$this->validEvent($emailId, 1)],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $email = Database::queryOne("SELECT * FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals(self::VALID_THREAD_STATE, json_decode($email['thread_state'], true), json_encode($email, JSON_PRETTY_PRINT));
        $this->assertEquals('ANSWERED', $email['thread_state_type']);
        $this->assertEquals('auto', $email['thread_state_source']);
    }

    public function testSaveResultDoneLeavesManualEmailUntouched(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00', 'manual');
        Database::execute(
            "UPDATE thread_emails SET thread_state_type = 'DENIED', thread_state = '{}'::jsonb WHERE id = ?",
            [$emailId]
        );
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);
        $result = [
            'status' => 'done', 'error' => null, 'model' => 'claude-opus-5-5',
            'system_prompt' => 'prompt', 'schema_version' => 1,
            'events' => [$this->validEvent($emailId, 1)],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $email = Database::queryOne("SELECT * FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('manual', $email['thread_state_source'], json_encode($email, JSON_PRETTY_PRINT));
        $this->assertEquals('DENIED', $email['thread_state_type']);
        $this->assertEquals([], json_decode($email['thread_state'], true));
    }

    // :: saveResult failed

    public function testSaveResultFailedStoresRowsAndAppliesNothing(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId1 = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $emailId2 = $this->insertEmail($threadId, '2026-01-02T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);

        $failedEvent = $this->validEvent($emailId2, 2, [
            'thread_state' => null,
            'error' => 'model call failed after 2 attempts',
            'attempts' => 2,
        ]);

        $result = [
            'status' => 'failed', 'error' => 'model call failed after 2 attempts',
            'model' => 'claude-opus-5-5', 'system_prompt' => 'prompt', 'schema_version' => 1,
            'events' => [$this->validEvent($emailId1, 1), $failedEvent],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('failed', $run['status'], json_encode($run, JSON_PRETTY_PRINT));
        $this->assertEquals('model call failed after 2 attempts', $run['error']);

        $events = ThreadAnalysisRepository::getEventsForRun($runId);
        $this->assertCount(2, $events, json_encode($events, JSON_PRETTY_PRINT));
        $this->assertNull($events[1]['thread_state']);
        $this->assertEquals('model call failed after 2 attempts', $events[1]['error']);

        $email1 = Database::queryOne("SELECT * FROM thread_emails WHERE id = ?", [$emailId1]);
        $email2 = Database::queryOne("SELECT * FROM thread_emails WHERE id = ?", [$emailId2]);
        $this->assertNull($email1['thread_state_source'], json_encode($email1, JSON_PRETTY_PRINT));
        $this->assertNull($email2['thread_state_source'], json_encode($email2, JSON_PRETTY_PRINT));
    }

    public function testSaveResultFailedEventWithoutEmailTypeIsStored(): void {
        // :: Setup
        // The model's answer was invalid, so the failed event has no email_type.
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00',
            'lease_expires_at' => '2026-01-01T08:31:00+00:00',
            'worker' => 'worker-1',
        ]);
        $failedEvent = $this->validEvent($emailId, 1, [
            'email_type' => null,
            'email_note' => null,
            'email_type_gap' => null,
            'thread_state' => null,
            'error' => 'invalid answer after 2 attempts',
            'attempts' => 2,
        ]);
        $result = [
            'status' => 'failed', 'error' => 'invalid answer after 2 attempts',
            'model' => 'claude-opus-5-5', 'system_prompt' => 'prompt', 'schema_version' => 1,
            'events' => [$failedEvent],
        ];

        // :: Act
        ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);

        // :: Assert
        $events = ThreadAnalysisRepository::getEventsForRun($runId);
        $this->assertCount(1, $events, json_encode($events, JSON_PRETTY_PRINT));
        $this->assertNull($events[0]['email_type']);
        $this->assertEquals('invalid answer after 2 attempts', $events[0]['error']);
        $this->assertEquals('failed', ThreadAnalysisRepository::getRun($runId)['status']);
    }

    // :: Validation errors

    private function countRows(string $table): int {
        return (int) Database::queryValue("SELECT count(*) FROM $table");
    }

    public function testSaveResultThrowsWhenRunNotClaimed(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'requested', '2026-01-01T08:00:00+00:00');
        $result = ['status' => 'done', 'error' => null, 'model' => 'm', 'system_prompt' => 'p', 'schema_version' => 1, 'events' => [$this->validEvent($emailId, 1)]];
        $eventsBefore = $this->countRows('thread_analysis_events');

        // :: Act
        try {
            ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals("saveResult: run $runId is not claimed (status: 'requested')", $e->getMessage());
        }
        $this->assertEquals($eventsBefore, $this->countRows('thread_analysis_events'));
    }

    public function testSaveResultThrowsWhenWrongWorker(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00', 'lease_expires_at' => '2026-01-01T08:31:00+00:00', 'worker' => 'worker-1',
        ]);
        $result = ['status' => 'done', 'error' => null, 'model' => 'm', 'system_prompt' => 'p', 'schema_version' => 1, 'events' => [$this->validEvent($emailId, 1)]];
        $eventsBefore = $this->countRows('thread_analysis_events');

        // :: Act
        try {
            ThreadAnalysisRepository::saveResult($runId, 'worker-2', $result);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals("saveResult: run $runId is claimed by 'worker-1', not 'worker-2'", $e->getMessage());
        }
        $this->assertEquals($eventsBefore, $this->countRows('thread_analysis_events'));
    }

    public function testSaveResultThrowsWhenEmailFromAnotherThread(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $otherThreadId = $this->createFixedThread();
        $otherEmailId = $this->insertEmail($otherThreadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00', 'lease_expires_at' => '2026-01-01T08:31:00+00:00', 'worker' => 'worker-1',
        ]);
        $result = ['status' => 'done', 'error' => null, 'model' => 'm', 'system_prompt' => 'p', 'schema_version' => 1, 'events' => [$this->validEvent($otherEmailId, 1)]];
        $eventsBefore = $this->countRows('thread_analysis_events');

        // :: Act
        try {
            ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals("saveResult: email '$otherEmailId' does not belong to thread '$threadId'", $e->getMessage());
        }
        $this->assertEquals($eventsBefore, $this->countRows('thread_analysis_events'));
    }

    public function testSaveResultThrowsWhenPositionGap(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId1 = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $emailId2 = $this->insertEmail($threadId, '2026-01-02T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00', 'lease_expires_at' => '2026-01-01T08:31:00+00:00', 'worker' => 'worker-1',
        ]);
        $result = [
            'status' => 'done', 'error' => null, 'model' => 'm', 'system_prompt' => 'p', 'schema_version' => 1,
            'events' => [$this->validEvent($emailId1, 1), $this->validEvent($emailId2, 3)],
        ];
        $eventsBefore = $this->countRows('thread_analysis_events');

        // :: Act
        try {
            ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals("saveResult: event positions must start at 1 with no gaps; expected 2, got 3", $e->getMessage());
        }
        $this->assertEquals($eventsBefore, $this->countRows('thread_analysis_events'));
    }

    public function testSaveResultThrowsWhenThreadStateInvalid(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00', 'lease_expires_at' => '2026-01-01T08:31:00+00:00', 'worker' => 'worker-1',
        ]);
        $badState = self::VALID_THREAD_STATE;
        unset($badState['waiting_for']);
        $result = [
            'status' => 'done', 'error' => null, 'model' => 'm', 'system_prompt' => 'p', 'schema_version' => 1,
            'events' => [$this->validEvent($emailId, 1, ['thread_state' => $badState])],
        ];
        $eventsBefore = $this->countRows('thread_analysis_events');

        // :: Act
        try {
            ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals(
                "saveResult: event for email '$emailId' has an invalid 'thread_state': ThreadState: missing required key 'waiting_for'",
                $e->getMessage()
            );
        }
        $this->assertEquals($eventsBefore, $this->countRows('thread_analysis_events'));
    }

    public function testSaveResultThrowsWhenEmailTypeInvalid(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00', 'lease_expires_at' => '2026-01-01T08:31:00+00:00', 'worker' => 'worker-1',
        ]);
        $result = [
            'status' => 'done', 'error' => null, 'model' => 'm', 'system_prompt' => 'p', 'schema_version' => 1,
            'events' => [$this->validEvent($emailId, 1, ['email_type' => 'NOT_A_REAL_TYPE'])],
        ];
        $eventsBefore = $this->countRows('thread_analysis_events');

        // :: Act
        try {
            ThreadAnalysisRepository::saveResult($runId, 'worker-1', $result);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals(
                "saveResult: event for email '$emailId' has an invalid 'email_type': \"NOT_A_REAL_TYPE\"",
                $e->getMessage()
            );
        }
        $this->assertEquals($eventsBefore, $this->countRows('thread_analysis_events'));
    }

    // :: Reviews (Change 9: review status and notes per run)

    public function testSaveReviewSavesAndReadsBack(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');

        // :: Act
        ThreadAnalysisRepository::saveReview($runId, 'MINOR_ISSUES', 'Missed a case number.', 'admin-user');

        // :: Assert
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('MINOR_ISSUES', $run['review_status'], json_encode($run, JSON_PRETTY_PRINT));
        $this->assertEquals('Missed a case number.', $run['review_notes']);
        $this->assertEquals('admin-user', $run['reviewed_by']);
        $this->assertNotNull($run['reviewed_at']);
    }

    public function testSaveReviewThrowsOnInvalidStatus(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');

        // :: Act
        try {
            ThreadAnalysisRepository::saveReview($runId, 'NOT_A_REAL_STATUS', null, 'admin-user');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals(
                "saveReview: 'status' must be one of NOT_REVIEWED, CORRECT, MINOR_ISSUES, WRONG, got \"NOT_A_REAL_STATUS\"",
                $e->getMessage()
            );
        }
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('NOT_REVIEWED', $run['review_status'], json_encode($run, JSON_PRETTY_PRINT));
    }

    public function testSaveReviewRefusesReviewingAClaimedRun(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $runId = $this->insertRun($threadId, 'claimed', '2026-01-01T08:00:00+00:00', [
            'claimed_at' => '2026-01-01T08:01:00+00:00', 'lease_expires_at' => '2026-01-01T08:31:00+00:00', 'worker' => 'worker-1',
        ]);

        // :: Act
        try {
            ThreadAnalysisRepository::saveReview($runId, 'CORRECT', null, 'admin-user');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // :: Assert
            $this->assertEquals("saveReview: run $runId is not done or failed (status: 'claimed')", $e->getMessage());
        }
        $run = ThreadAnalysisRepository::getRun($runId);
        $this->assertEquals('NOT_REVIEWED', $run['review_status'], json_encode($run, JSON_PRETTY_PRINT));
    }

    public function testGetReviewsFiltersByStatus(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $correctRunId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');
        ThreadAnalysisRepository::saveReview($correctRunId, 'CORRECT', null, 'admin-user');
        $wrongThreadId = $this->createFixedThread();
        $wrongRunId = $this->insertRun($wrongThreadId, 'done', '2026-01-01T08:00:00+00:00');
        ThreadAnalysisRepository::saveReview($wrongRunId, 'WRONG', 'Missed the denial basis.', 'admin-user');

        // :: Act
        $reviews = ThreadAnalysisRepository::getReviews(['WRONG'], 100);

        // :: Assert
        $matching = array_values(array_filter($reviews, fn(array $row): bool => (int) $row['id'] === $wrongRunId || (int) $row['id'] === $correctRunId));
        $this->assertCount(1, $matching, json_encode($reviews, JSON_PRETTY_PRINT));
        $this->assertEquals($wrongRunId, (int) $matching[0]['id']);
        $this->assertEquals('WRONG', $matching[0]['review_status']);
        $this->assertEquals('Missed the denial basis.', $matching[0]['review_notes']);
        $this->assertEquals($wrongThreadId, $matching[0]['thread_id']);
    }
}
