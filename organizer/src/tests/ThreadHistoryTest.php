<?php

require_once __DIR__ . '/../class/ThreadHistory.php';
require_once __DIR__ . '/../class/Database.php';

class ThreadHistoryTest extends PHPUnit\Framework\TestCase {
    private $testThreadId = '123e4567-e89b-12d3-a456-426614174000';
    private $testUserId = 'test-user-123';
    private $history;

    protected function setUp(): void {
        Database::beginTransaction();

        // Clean up any existing test data
        Database::execute("DELETE FROM thread_history WHERE thread_id = ?", [$this->testThreadId]);
        Database::execute("DELETE FROM threads WHERE id = ?", [$this->testThreadId]);

        // Create a test thread
        Database::execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent, archived, labels, sent_comment, public) 
            VALUES (?, 'test-entity', 'Test Thread', 'Test User', 'test" . mt_rand(0, 100) . time() ."@example.com', false, false, NULL, NULL, false)",
            [$this->testThreadId]
        );

        $this->history = new ThreadHistory();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    public function testLogAction() {
        // :: Setup
        $action = 'created';

        // :: Act
        $result = $this->history->logAction($this->testThreadId, $action, $this->testUserId);

        // :: Assert
        $this->assertEquals(1, $result, 'Log action should return 1 on successful insert');

        $history = $this->history->getHistoryForThread($this->testThreadId);
        $this->assertCount(1, $history, 'History array: ' . json_encode($history, JSON_PRETTY_PRINT));
        $this->assertEquals($action, $history[0]['action'], 'Action should match what was logged');
        $this->assertEquals($this->testUserId, $history[0]['user_id'], 'User ID should match what was logged');
    }

    public function testLogActionWithDetails() {
        // :: Setup
        $details = ['title' => 'New Title'];
        $action = 'edited';

        // :: Act
        $result = $this->history->logAction($this->testThreadId, $action, $this->testUserId, $details);

        // :: Assert
        $this->assertEquals(1, $result, 'Log action should return 1 on successful insert');

        $history = $this->history->getHistoryForThread($this->testThreadId);
        $this->assertCount(1, $history, 'History array: ' . json_encode($history, JSON_PRETTY_PRINT));
        $this->assertEquals($action, $history[0]['action'], 'Action should match what was logged');
        $this->assertEquals($this->testUserId, $history[0]['user_id'], 'User ID should match what was logged');
        $this->assertJsonStringEqualsJsonString(
            json_encode($details),
            $history[0]['details'],
            'Details should be stored as JSON and match original data'
        );
    }

    public function testGetHistoryForThread() {
        // :: Setup
        $this->history->logAction($this->testThreadId, 'created', $this->testUserId);
        $this->history->logAction($this->testThreadId, 'edited', $this->testUserId, ['title' => 'Updated Title']);

        // :: Act
        $result = $this->history->getHistoryForThread($this->testThreadId);

        // :: Assert
        $this->assertCount(2, $result, 'History entries: ' . json_encode($result, JSON_PRETTY_PRINT));
        $this->assertEquals('created', $result[0]['action'], 'First entry should be created action');
        $this->assertEquals('edited', $result[1]['action'], 'Second entry should be edited action');
        $this->assertEquals($this->testUserId, $result[0]['user_id'], 'User ID should be consistent across entries');
        $this->assertEquals($this->testUserId, $result[1]['user_id'], 'User ID should be consistent across entries');
    }

    public function testFormatActionForDisplay() {
        // :: Setup
        $testCases = [
            ['action' => 'created', 'details' => null, 'expected' => 'Created thread'],
            ['action' => 'edited', 'details' => json_encode(['title' => 'New Title']), 'expected' => 'Changed title to: New Title'],
            ['action' => 'edited', 'details' => json_encode(['labels' => ['label1', 'label2']]), 'expected' => 'Updated labels: label1, label2'],
            ['action' => 'archived', 'details' => null, 'expected' => 'Archived thread'],
            ['action' => 'unarchived', 'details' => null, 'expected' => 'Unarchived thread'],
            ['action' => 'made_public', 'details' => null, 'expected' => 'Made thread public'],
            ['action' => 'made_private', 'details' => null, 'expected' => 'Made thread private'],
            ['action' => 'sent', 'details' => null, 'expected' => 'Marked thread as sent'],
            ['action' => 'unsent', 'details' => null, 'expected' => 'Marked thread as not sent']
        ];

        // :: Act & Assert
        foreach ($testCases as $testCase) {
            $this->assertEquals(
                $testCase['expected'],
                $this->history->formatActionForDisplay($testCase['action'], $testCase['details']),
                "Failed formatting action '{$testCase['action']}'"
            );
        }
    }

    public function testFormatActionForDisplayWithInvalidAction() {
        // :: Setup
        $invalidAction = 'invalid_action';

        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown action: ' . $invalidAction);
        $this->history->formatActionForDisplay($invalidAction, null);
    }

    public function testFormatHistoryEntry() {
        $entry = [
            'action' => 'edited',
            'user_id' => $this->testUserId,
            'created_at' => '2024-02-16 12:00:00',
            'details' => json_encode(['title' => 'New Title'])
        ];

        $expected = [
            'action' => 'Changed title to: New Title',
            'user' => $this->testUserId,
            'date' => '2024-02-16 12:00:00'
        ];

        $result = $this->history->formatHistoryEntry($entry);
        $this->assertEquals($expected, $result);
    }

    public function testFormatHistoryEntryWithMissingData() {
        $entry = [
            'action' => null,
            'created_at' => null
        ];

        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown action: ');
        $this->history->formatHistoryEntry($entry);
    }
}
