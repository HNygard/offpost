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
            VALUES (?, 'test-entity', 'Test Thread', 'Test User', 'test@example.com', false, false, NULL, NULL, false)",
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
        // Test created action
        $this->assertEquals(
            'Created thread',
            $this->history->formatActionForDisplay('created', null)
        );

        // Test edited action with title change
        $this->assertEquals(
            'Changed title to: New Title',
            $this->history->formatActionForDisplay('edited', json_encode(['title' => 'New Title']))
        );

        // Test edited action with labels change
        $this->assertEquals(
            'Updated labels: label1, label2',
            $this->history->formatActionForDisplay('edited', json_encode(['labels' => ['label1', 'label2']]))
        );

        // Test archived action
        $this->assertEquals(
            'Archived thread',
            $this->history->formatActionForDisplay('archived', null)
        );

        // Test unarchived action
        $this->assertEquals(
            'Unarchived thread',
            $this->history->formatActionForDisplay('unarchived', null)
        );

        // Test made public action
        $this->assertEquals(
            'Made thread public',
            $this->history->formatActionForDisplay('made_public', null)
        );

        // Test made private action
        $this->assertEquals(
            'Made thread private',
            $this->history->formatActionForDisplay('made_private', null)
        );

        // Test sent action
        $this->assertEquals(
            'Marked thread as sent',
            $this->history->formatActionForDisplay('sent', null)
        );

        // Test unsent action
        $this->assertEquals(
            'Marked thread as not sent',
            $this->history->formatActionForDisplay('unsent', null)
        );

        // Test unknown action
        $this->assertEquals(
            'Unknown action',
            $this->history->formatActionForDisplay('invalid_action', null)
        );
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

        $result = $this->history->formatHistoryEntry($entry);
        
        $this->assertEquals('Unknown action', $result['action']);
        $this->assertEquals('Unknown user', $result['user']);
        $this->assertNotEmpty($result['date']); // Should still get a date even if not provided
    }
}
