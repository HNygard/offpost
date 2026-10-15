<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadHistory.php');
require_once(__DIR__ . '/../class/ThreadEmailSending.php');

class ThreadHistoryFormatTest extends TestCase {
    private $threadHistory;

    protected function setUp(): void {
        parent::setUp();
        $this->threadHistory = new ThreadHistory();
    }

    public function testFormatActionForDisplay() {
        $testCases = [
            ['action' => 'created', 'details' => null, 'expected' => 'Created thread'],
            ['action' => 'edited', 'details' => null, 'expected' => 'Edited thread'],
            ['action' => 'edited', 'details' => json_encode(['labels' => ['urgent', 'important']]), 'expected' => 'Updated labels: urgent, important'],
            ['action' => 'edited', 'details' => json_encode(['title' => 'New Title']), 'expected' => 'Changed title to: New Title'],
            ['action' => 'archived', 'details' => null, 'expected' => 'Archived thread'],
            ['action' => 'unarchived', 'details' => null, 'expected' => 'Unarchived thread'],
            ['action' => 'made_public', 'details' => null, 'expected' => 'Made thread public'],
            ['action' => 'made_private', 'details' => null, 'expected' => 'Made thread private'],
            ['action' => 'sent', 'details' => null, 'expected' => 'Marked thread as sent'],
            ['action' => 'unsent', 'details' => null, 'expected' => 'Marked thread as not sent'],
            ['action' => 'user_added', 'details' => json_encode(['user_id' => 'test-user', 'is_owner' => true]), 'expected' => 'Added user: test-user as owner'],
            ['action' => 'user_added', 'details' => json_encode(['user_id' => 'test-user', 'is_owner' => false]), 'expected' => 'Added user: test-user as viewer'],
            ['action' => 'user_removed', 'details' => json_encode(['user_id' => 'test-user']), 'expected' => 'Removed user: test-user']
        ];

        foreach ($testCases as $testCase) {
            $result = $this->threadHistory->formatActionForDisplay($testCase['action'], $testCase['details']);
            $this->assertEquals($testCase['expected'], $result, "Failed formatting action '{$testCase['action']}'");
        }
    }
    
    public function testFormatActionForDisplayWithInvalidAction() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown action: unknown');
        $this->threadHistory->formatActionForDisplay('unknown', null);
    }

    public function testFormatHistoryEntry() {
        $now = new DateTime();
        $entry = [
            'action' => 'created',
            'user_id' => 'test-user',
            'created_at' => $now->format('Y-m-d H:i:s')
        ];

        $result = $this->threadHistory->formatHistoryEntry($entry);

        $this->assertEquals([
            'action' => 'Created thread',
            'user' => 'test-user',
            'date' => $now->format('Y-m-d H:i:s')
        ], $result);
    }

    public function testFormatDeletedEmailHistoryEntry() {
        // :: Setup
        $entry = [
            'action' => ThreadEmailSending::HISTORY_ACTION_DELETED,
            'details' => json_encode([
                'email_sending_id' => 42,
                'email_subject' => 'Reminder',
                'email_to' => 'recipient@example.com',
            ]),
            'user_id' => 'test-user',
            'created_at' => '2026-10-15 12:00:00',
        ];

        // :: Act
        $result = $this->threadHistory->formatHistoryEntry($entry);

        // :: Assert
        $this->assertEquals([
            'action' => 'Deleted staged email [42] to [recipient@example.com] with subject [Reminder]',
            'user' => 'test-user',
            'date' => '2026-10-15 12:00:00',
        ], $result);
    }

    public function testFormatDeletedEmailWithMissingDetails() {
        // :: Setup
        $testCases = [null, '{}', '{"email_sending_id":null,"email_subject":null,"email_to":null}', 'invalid JSON'];

        foreach ($testCases as $details) {
            // :: Act
            $result = $this->threadHistory->formatActionForDisplay(ThreadEmailSending::HISTORY_ACTION_DELETED, $details);

            // :: Assert
            $this->assertEquals(
                'Deleted staged email [?] to [Unknown recipient] with subject [No subject]',
                $result,
                'Deletion history details: ' . var_export($details, true)
            );
        }
    }

    public function testFormatHistoryEntryWithMissingData() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown action: ');
        $this->threadHistory->formatHistoryEntry([]);
    }
}
