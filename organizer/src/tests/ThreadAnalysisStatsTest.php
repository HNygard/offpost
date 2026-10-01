<?php
// organizer/src/tests/ThreadAnalysisStatsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisStats.php';
require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisRepository.php';

/**
 * Unit tests for ThreadAnalysisStats, on the test database. These tests run
 * against the same database as ThreadAnalysisRepositoryTest (see
 * tests/bootstrap.php - DB_NAME defaults to the dev database, not a separate
 * empty one), inside a transaction rolled back in tearDown(). Because
 * several of the queries here aggregate across *all* rows of a table rather
 * than one thread, some assertions compare a baseline taken before the
 * fixture is inserted against the total after, rather than asserting an
 * absolute count - the exact style CLAUDE.md's testing rules call for when
 * "the order of elements may vary" or output isn't otherwise deterministic
 * from a fixed fixture alone.
 */
class ThreadAnalysisStatsTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private int $threadCounter = 0;

    private function createFixedThread(): string {
        $this->threadCounter++;
        $thread = new Thread();
        $thread->title = 'Analysis stats test thread ' . $this->threadCounter;
        $thread->my_name = 'Test Person';
        $thread->my_email = "test-stats-person-{$this->threadCounter}@example.com";
        $thread->labels = [];
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

    private function insertEmail(string $threadId, string $timestampReceived, ?string $statusType = null, ?string $autoClassification = null): string {
        return Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, content, status_type, auto_classification)
             VALUES (?, ?, ?, ?::bytea, ?, ?) RETURNING id",
            [$threadId, $timestampReceived, $timestampReceived, 'Body text', $statusType, $autoClassification]
        );
    }

    private function insertRun(string $threadId, string $status, string $requestedAt, array $overrides = []): int {
        return (int) Database::queryValue(
            "INSERT INTO thread_analysis_runs
                (thread_id, status, mode, requested_by, requested_at, claimed_at, finished_at, lease_expires_at, worker, model, system_prompt_sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $threadId,
                $status,
                $overrides['mode'] ?? 'incremental',
                $overrides['requested_by'] ?? 'test-user',
                $requestedAt,
                $overrides['claimed_at'] ?? null,
                $overrides['finished_at'] ?? null,
                $overrides['lease_expires_at'] ?? null,
                $overrides['worker'] ?? null,
                $overrides['model'] ?? null,
                $overrides['system_prompt_sha256'] ?? null,
            ]
        );
    }

    private function insertEvent(int $runId, string $emailId, int $position, array $overrides = []): int {
        return (int) Database::queryValue(
            "INSERT INTO thread_analysis_events
                (run_id, email_id, \"position\", email_type, email_note, email_type_gap, thread_state, derived_thread_state_type, attempts, error)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?) RETURNING id",
            [
                $runId,
                $emailId,
                $position,
                $overrides['email_type'] ?? 'INFORMATION_RELEASE',
                $overrides['email_note'] ?? null,
                $overrides['email_type_gap'] ?? null,
                $overrides['thread_state'] ?? null,
                $overrides['derived_thread_state_type'] ?? null,
                $overrides['attempts'] ?? 1,
                $overrides['error'] ?? null,
            ]
        );
    }

    private function insertCall(int $runId, int $eventId, array $overrides = []): void {
        Database::execute(
            "INSERT INTO thread_analysis_claude_code_calls
                (run_id, event_id, attempt, input_text, model, cost_usd, input_tokens, cache_creation_input_tokens,
                 cache_read_input_tokens, output_tokens, thinking_tokens, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $runId,
                $eventId,
                $overrides['attempt'] ?? 1,
                $overrides['input_text'] ?? 'input',
                $overrides['model'] ?? 'claude-opus-5-5',
                $overrides['cost_usd'] ?? 0.01,
                $overrides['input_tokens'] ?? 1,
                $overrides['cache_creation_input_tokens'] ?? 2,
                $overrides['cache_read_input_tokens'] ?? 3,
                $overrides['output_tokens'] ?? 4,
                $overrides['thinking_tokens'] ?? 5,
                $overrides['created_at'] ?? date('Y-m-d H:i:s'),
            ]
        );
    }

    // :: getUsageTotals - cost sums

    public function testGetUsageTotalsSumsCostAndTokensAcrossTodayAndAllTime(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');
        $eventId = $this->insertEvent($runId, $emailId, 1);
        $before = ThreadAnalysisStats::getUsageTotals();

        // :: Act
        $this->insertCall($runId, $eventId, [
            'cost_usd' => 0.5, 'input_tokens' => 10, 'cache_creation_input_tokens' => 20,
            'cache_read_input_tokens' => 30, 'output_tokens' => 40, 'thinking_tokens' => 50,
        ]);
        $after = ThreadAnalysisStats::getUsageTotals();

        // :: Assert
        foreach (['today', 'all_time'] as $period) {
            $this->assertEquals(1, $after[$period]['calls'] - $before[$period]['calls'], "period: $period");
            $this->assertEqualsWithDelta(0.5, $after[$period]['cost_usd'] - $before[$period]['cost_usd'], 0.0000001, "period: $period");
            $this->assertEquals(10, $after[$period]['input_tokens'] - $before[$period]['input_tokens'], "period: $period");
            $this->assertEquals(20, $after[$period]['cache_creation_input_tokens'] - $before[$period]['cache_creation_input_tokens'], "period: $period");
            $this->assertEquals(30, $after[$period]['cache_read_input_tokens'] - $before[$period]['cache_read_input_tokens'], "period: $period");
            $this->assertEquals(40, $after[$period]['output_tokens'] - $before[$period]['output_tokens'], "period: $period");
            $this->assertEquals(50, $after[$period]['thinking_tokens'] - $before[$period]['thinking_tokens'], "period: $period");
        }
    }

    public function testGetUsageTotalsExcludesCallsOlderThanSevenDaysFromThatBucket(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');
        $eventId = $this->insertEvent($runId, $emailId, 1);
        $before = ThreadAnalysisStats::getUsageTotals();

        // :: Act
        $this->insertCall($runId, $eventId, [
            'cost_usd' => 0.5, 'created_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ]);
        $after = ThreadAnalysisStats::getUsageTotals();

        // :: Assert
        $this->assertEqualsWithDelta(0.5, $after['all_time']['cost_usd'] - $before['all_time']['cost_usd'], 0.0000001);
        $this->assertEquals(0, $after['today']['calls'] - $before['today']['calls']);
        $this->assertEquals(0, $after['last_7_days']['calls'] - $before['last_7_days']['calls']);
    }

    public function testGetCostByModelGroupsByModel(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');
        $eventId = $this->insertEvent($runId, $emailId, 1);
        $model = 'test-model-' . uniqid();

        // :: Act
        $this->insertCall($runId, $eventId, ['model' => $model, 'cost_usd' => 0.3, 'attempt' => 1]);
        $this->insertCall($runId, $eventId, ['model' => $model, 'cost_usd' => 0.2, 'attempt' => 2]);
        $rows = ThreadAnalysisStats::getCostByModel();

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['model'] === $model));
        $this->assertCount(1, $matching, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals(2, $matching[0]['calls']);
        $this->assertEqualsWithDelta(0.5, $matching[0]['cost_usd'], 0.0000001);
    }

    public function testGetCostBySystemPromptTracksFirstUsedAndSum(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $promptText = 'test system prompt ' . uniqid();
        $sha = ThreadAnalysisRepository::saveSystemPrompt($promptText);
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00', ['system_prompt_sha256' => $sha]);
        $eventId = $this->insertEvent($runId, $emailId, 1);

        // :: Act
        $this->insertCall($runId, $eventId, ['cost_usd' => 0.4]);
        $rows = ThreadAnalysisStats::getCostBySystemPrompt();

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['sha256'] === $sha));
        $this->assertCount(1, $matching, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals(1, $matching[0]['calls']);
        $this->assertEqualsWithDelta(0.4, $matching[0]['cost_usd'], 0.0000001);
        $promptRow = Database::queryOne("SELECT created_at FROM thread_analysis_system_prompts WHERE sha256 = ?", [$sha]);
        $this->assertEquals($promptRow['created_at'], $matching[0]['first_used_at']);
    }

    // :: getRunCountsByStatus

    public function testGetRunCountsByStatusCountsARequestedRun(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $before = ThreadAnalysisStats::getRunCountsByStatus();

        // :: Act
        $this->insertRun($threadId, 'requested', '2026-01-01T08:00:00+00:00');
        $after = ThreadAnalysisStats::getRunCountsByStatus();

        // :: Assert
        $beforeCount = $before['requested'] ?? 0;
        $afterCount = $after['requested'] ?? 0;
        $this->assertEquals(1, $afterCount - $beforeCount);
    }

    // :: getQueue - requested/claimed only, oldest first

    public function testGetQueueListsOnlyRequestedAndClaimedRunsForThread(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $requestedRunId = $this->insertRun($threadId, 'requested', '2026-01-01T08:00:00+00:00');
        $this->insertRun($threadId, 'done', '2026-01-01T07:00:00+00:00');

        // :: Act
        $queue = ThreadAnalysisStats::getQueue();

        // :: Assert
        $matching = array_values(array_filter($queue, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(1, $matching, json_encode($queue, JSON_PRETTY_PRINT));
        $this->assertEquals($requestedRunId, (int) $matching[0]['id']);
        $this->assertEquals('requested', $matching[0]['status']);
    }

    // :: getRecentRuns - review fields

    public function testGetRecentRunsReturnsReviewFieldsForReviewedAndUnreviewedRuns(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $unreviewedId = $this->insertRun($threadId, 'done', '2026-01-01T07:00:00+00:00');
        $reviewedId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');
        Database::execute(
            "UPDATE thread_analysis_runs
             SET review_status = 'WRONG', reviewed_by = 'reviewer-1', reviewed_at = '2026-01-02T09:00:00+00:00'
             WHERE id = ?",
            [$reviewedId]
        );

        // :: Act
        $runs = ThreadAnalysisStats::getRecentRuns(1000);

        // :: Assert
        $byId = [];
        foreach ($runs as $run) {
            $byId[(int) $run['id']] = $run;
        }
        $this->assertEquals('WRONG', $byId[$reviewedId]['review_status'], json_encode($runs, JSON_PRETTY_PRINT));
        $this->assertEquals('reviewer-1', $byId[$reviewedId]['reviewed_by']);
        $this->assertNotNull($byId[$reviewedId]['reviewed_at']);
        $this->assertEquals('NOT_REVIEWED', $byId[$unreviewedId]['review_status']);
        $this->assertNull($byId[$unreviewedId]['reviewed_by']);
        $this->assertNull($byId[$unreviewedId]['reviewed_at']);
    }

    // :: getEmailTypeGaps - the gap query

    public function testGetEmailTypeGapsFindsEventsWithANonEmptyGap(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00');
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00');
        $this->insertEvent($runId, $emailId, 1, ['email_type' => 'INFORMATION_RELEASE', 'email_type_gap' => 'expected a receipt first']);
        $this->insertEvent($runId, $emailId, 2, ['email_type' => 'REQUEST_REJECTED', 'email_type_gap' => '']);

        // :: Act
        $gaps = ThreadAnalysisStats::getEmailTypeGaps(100);

        // :: Assert
        $matching = array_values(array_filter($gaps, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(1, $matching, json_encode($gaps, JSON_PRETTY_PRINT));
        $this->assertEquals($emailId, $matching[0]['email_id']);
        $this->assertEquals(1, (int) $matching[0]['position']);
        $this->assertEquals('INFORMATION_RELEASE', $matching[0]['email_type']);
        $this->assertEquals('expected a receipt first', $matching[0]['email_type_gap']);
    }

    // :: getDisagreements - the disagreement query

    public function testGetDisagreementsFindsEventWhoseEmailTypeDiffersFromProdManualClassification(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00', 'RESPONSE_TO_REQUEST', null);
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00', ['finished_at' => '2026-01-01T08:05:00+00:00']);
        $this->insertEvent($runId, $emailId, 1, ['email_type' => 'INFORMATION_RELEASE']);

        // :: Act
        $rows = ThreadAnalysisStats::getDisagreements(100);

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(1, $matching, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals($emailId, $matching[0]['email_id']);
        $this->assertEquals('INFORMATION_RELEASE', $matching[0]['analysis_email_type']);
        $this->assertEquals('RESPONSE_TO_REQUEST', $matching[0]['prod_status_type']);
        $this->assertEquals('manual', $matching[0]['prod_classification_source']);
    }

    public function testGetDisagreementsReportsAlgoClassificationSource(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00', 'RESPONSE_TO_REQUEST', 'algo');
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00', ['finished_at' => '2026-01-01T08:05:00+00:00']);
        $this->insertEvent($runId, $emailId, 1, ['email_type' => 'INFORMATION_RELEASE']);

        // :: Act
        $rows = ThreadAnalysisStats::getDisagreements(100);

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(1, $matching, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals('algo', $matching[0]['prod_classification_source']);
    }

    public function testGetDisagreementsExcludesMatchingClassification(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00', 'INFORMATION_RELEASE', null);
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00', ['finished_at' => '2026-01-01T08:05:00+00:00']);
        $this->insertEvent($runId, $emailId, 1, ['email_type' => 'INFORMATION_RELEASE']);

        // :: Act
        $rows = ThreadAnalysisStats::getDisagreements(100);

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(0, $matching, json_encode($rows, JSON_PRETTY_PRINT));
    }

    public function testGetDisagreementsExcludesUnknownAndLegacyProdValues(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailIdUnknown = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00', 'unknown', null);
        $emailIdLegacy = $this->insertEmail($threadId, '2026-01-01T09:01:00+00:00', 'info', null);
        $emailIdUnclassified = $this->insertEmail($threadId, '2026-01-01T09:02:00+00:00', null, null);
        $runId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00', ['finished_at' => '2026-01-01T08:05:00+00:00']);
        $this->insertEvent($runId, $emailIdUnknown, 1, ['email_type' => 'INFORMATION_RELEASE']);
        $this->insertEvent($runId, $emailIdLegacy, 2, ['email_type' => 'INFORMATION_RELEASE']);
        $this->insertEvent($runId, $emailIdUnclassified, 3, ['email_type' => 'INFORMATION_RELEASE']);

        // :: Act
        $rows = ThreadAnalysisStats::getDisagreements(100);

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(0, $matching, json_encode($rows, JSON_PRETTY_PRINT));
    }

    public function testGetDisagreementsOnlyUsesLatestDoneRunPerThread(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, '2026-01-01T09:00:00+00:00', 'INFORMATION_RELEASE', null);
        $olderRunId = $this->insertRun($threadId, 'done', '2026-01-01T07:00:00+00:00', ['finished_at' => '2026-01-01T07:05:00+00:00']);
        $this->insertEvent($olderRunId, $emailId, 1, ['email_type' => 'REQUEST_REJECTED']); // would disagree, but is not the latest run
        $newerRunId = $this->insertRun($threadId, 'done', '2026-01-01T08:00:00+00:00', ['finished_at' => '2026-01-01T08:05:00+00:00']);
        $this->insertEvent($newerRunId, $emailId, 1, ['email_type' => 'INFORMATION_RELEASE']); // agrees

        // :: Act
        $rows = ThreadAnalysisStats::getDisagreements(100);

        // :: Assert
        $matching = array_values(array_filter($rows, fn(array $row): bool => $row['thread_id'] === $threadId));
        $this->assertCount(0, $matching, json_encode($rows, JSON_PRETTY_PRINT));
    }
}
