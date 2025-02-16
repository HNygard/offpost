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
        $result = $this->history->logAction($this->testThreadId, 'created', $this->testUserId);
        $this->assertEquals(1, $result);

        $history = $this->history->getHistoryForThread($this->testThreadId);
        $this->assertCount(1, $history);
        $this->assertEquals('created', $history[0]['action']);
        $this->assertEquals($this->testUserId, $history[0]['user_id']);
    }

    public function testLogActionWithDetails() {
        $details = ['title' => 'New Title'];
        $result = $this->history->logAction($this->testThreadId, 'edited', $this->testUserId, $details);
        $this->assertEquals(1, $result);

        $history = $this->history->getHistoryForThread($this->testThreadId);
        $this->assertCount(1, $history);
        $this->assertEquals('edited', $history[0]['action']);
        $this->assertEquals($this->testUserId, $history[0]['user_id']);
        $this->assertJsonStringEqualsJsonString(json_encode($details), $history[0]['details']);
    }

    public function testGetHistoryForThread() {
        // Create multiple history entries
        $this->history->logAction($this->testThreadId, 'created', $this->testUserId);
        $this->history->logAction($this->testThreadId, 'edited', $this->testUserId, ['title' => 'Updated Title']);

        $result = $this->history->getHistoryForThread($this->testThreadId);
        $this->assertCount(2, $result);
        $this->assertEquals('created', $result[0]['action'], "Second entry should be created");
        $this->assertEquals('edited', $result[1]['action'], "First entry should be edited");
        $this->assertEquals($this->testUserId, $result[0]['user_id']);
        $this->assertEquals($this->testUserId, $result[1]['user_id']);
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
