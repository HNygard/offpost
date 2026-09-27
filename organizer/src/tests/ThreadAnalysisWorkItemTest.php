<?php
// organizer/src/tests/ThreadAnalysisWorkItemTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadAnalysis/ThreadAnalysisWorkItem.php';

class ThreadAnalysisWorkItemTest extends TestCase {
    private function email(string $id, string $datetimeReceived, bool $ignore = false, ?array $threadState = null): array {
        return [
            'id' => $id,
            'datetime_received' => $datetimeReceived,
            'ignore' => $ignore,
            'thread_state' => $threadState,
        ];
    }

    public function testFullReturnsEveryEmailWithNullStartState(): void {
        // :: Setup
        $emails = [
            $this->email('e1', '2026-01-01T09:00:00+00:00'),
            $this->email('e2', '2026-01-02T09:00:00+00:00'),
        ];

        // :: Act
        $result = ThreadAnalysisWorkItem::selectEmails($emails, 'full');

        // :: Assert
        $this->assertEquals(
            ['start_state' => null, 'start_after_email_id' => null, 'email_ids' => ['e1', 'e2']],
            $result,
            json_encode($result, JSON_PRETTY_PRINT)
        );
    }

    public function testIncrementalFromTheMiddle(): void {
        // :: Setup
        $state = ['schema_version' => 1, 'notes' => 'state after e2'];
        $emails = [
            $this->email('e1', '2026-01-01T09:00:00+00:00'),
            $this->email('e2', '2026-01-02T09:00:00+00:00', false, $state),
            $this->email('e3', '2026-01-03T09:00:00+00:00'),
            $this->email('e4', '2026-01-04T09:00:00+00:00'),
        ];

        // :: Act
        $result = ThreadAnalysisWorkItem::selectEmails($emails, 'incremental');

        // :: Assert
        $this->assertEquals(
            ['start_state' => $state, 'start_after_email_id' => 'e2', 'email_ids' => ['e3', 'e4']],
            $result,
            json_encode($result, JSON_PRETTY_PRINT)
        );
    }

    public function testIncrementalWithNoEarlierStateBehavesLikeFull(): void {
        // :: Setup
        $emails = [
            $this->email('e1', '2026-01-01T09:00:00+00:00'),
            $this->email('e2', '2026-01-02T09:00:00+00:00'),
        ];

        // :: Act
        $result = ThreadAnalysisWorkItem::selectEmails($emails, 'incremental');

        // :: Assert
        $this->assertEquals(
            ['start_state' => null, 'start_after_email_id' => null, 'email_ids' => ['e1', 'e2']],
            $result,
            json_encode($result, JSON_PRETTY_PRINT)
        );
    }

    public function testIncrementalWithNothingNewGivesEmptyEmailIds(): void {
        // :: Setup
        $state = ['schema_version' => 1, 'notes' => 'final state'];
        $emails = [
            $this->email('e1', '2026-01-01T09:00:00+00:00'),
            $this->email('e2', '2026-01-02T09:00:00+00:00', false, $state),
        ];

        // :: Act
        $result = ThreadAnalysisWorkItem::selectEmails($emails, 'incremental');

        // :: Assert
        $this->assertEquals(
            ['start_state' => $state, 'start_after_email_id' => 'e2', 'email_ids' => []],
            $result,
            json_encode($result, JSON_PRETTY_PRINT)
        );
    }

    public function testIgnoredEmailsLeftOut(): void {
        // :: Setup
        $emails = [
            $this->email('e1', '2026-01-01T09:00:00+00:00'),
            $this->email('e2', '2026-01-02T09:00:00+00:00', true),
            $this->email('e3', '2026-01-03T09:00:00+00:00'),
        ];

        // :: Act
        $result = ThreadAnalysisWorkItem::selectEmails($emails, 'full');

        // :: Assert
        $this->assertEquals(['e1', 'e3'], $result['email_ids'], json_encode($result, JSON_PRETTY_PRINT));
    }

    public function testOrderingTiesBrokenById(): void {
        // :: Setup - same datetime_received, ids given out of order.
        $emails = [
            $this->email('b', '2026-01-01T09:00:00+00:00'),
            $this->email('a', '2026-01-01T09:00:00+00:00'),
        ];

        // :: Act
        $result = ThreadAnalysisWorkItem::selectEmails($emails, 'full');

        // :: Assert
        $this->assertEquals(['a', 'b'], $result['email_ids'], json_encode($result, JSON_PRETTY_PRINT));
    }
}
