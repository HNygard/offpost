<?php
// organizer/src/tests/ThreadEmailSendingDeleteStagedTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/NpApiService.php';

/**
 * ThreadEmailSending::deleteStaged() against the database: only STAGING rows
 * are deleted, and each deletion is logged in thread_history. Rolled back
 * after each test.
 */
class ThreadEmailSendingDeleteStagedTest extends TestCase {
    private string $threadId;

    protected function setUp(): void {
        Database::beginTransaction();
        $created = NpApiService::createThread(
            '9999-test-entity-development', 'Innsyn i dokument 2026/1-1', 'Innhold',
            ['norske_postlister_no', 'document', 'document_id:2026-1-1']
        );
        $this->threadId = $created['thread_id'];
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function createSending(string $status): ThreadEmailSending {
        return ThreadEmailSending::create(
            $this->threadId, 'Purring', 'Purring - Innsyn i dokument 2026/1-1',
            'public-entity@dev.offpost.no', 'sender@dev.offpost.no', 'Kari Nordmann', $status
        );
    }

    private function deletionHistory(): array {
        return Database::query(
            "SELECT user_id, details FROM thread_history WHERE thread_id = ? AND action = ? ORDER BY id",
            [$this->threadId, ThreadEmailSending::HISTORY_ACTION_DELETED]
        );
    }

    public function testStagedSendingIsDeletedAndLogged(): void {
        // :: Setup
        $sending = $this->createSending(ThreadEmailSending::STATUS_STAGING);

        // :: Act
        $deleted = ThreadEmailSending::deleteStaged($sending->id, 'dev-user-id');

        // :: Assert
        $this->assertTrue($deleted);
        $this->assertNull(ThreadEmailSending::getById($sending->id));
        $history = $this->deletionHistory();
        $this->assertCount(1, $history, json_encode($history, JSON_PRETTY_PRINT));
        $this->assertEquals('dev-user-id', $history[0]['user_id']);
        $this->assertEquals([
            'email_sending_id' => (int)$sending->id,
            'email_subject' => 'Purring - Innsyn i dokument 2026/1-1',
            'email_to' => 'public-entity@dev.offpost.no',
        ], json_decode($history[0]['details'], true));
    }

    /**
     * @dataProvider notStagedStatuses
     */
    public function testSendingNotInStagingIsLeftAlone(string $status): void {
        // :: Setup
        $sending = $this->createSending($status);

        // :: Act
        $deleted = ThreadEmailSending::deleteStaged($sending->id, 'dev-user-id');

        // :: Assert
        $this->assertFalse($deleted);
        $this->assertEquals($status, ThreadEmailSending::getById($sending->id)->status);
        $this->assertEquals([], $this->deletionHistory());
    }

    public static function notStagedStatuses(): array {
        return [
            'ready for sending' => [ThreadEmailSending::STATUS_READY_FOR_SENDING],
            'sending' => [ThreadEmailSending::STATUS_SENDING],
            'sent' => [ThreadEmailSending::STATUS_SENT],
        ];
    }

    public function testUnknownIdReturnsFalse(): void {
        // :: Act
        $deleted = ThreadEmailSending::deleteStaged(999999999, 'dev-user-id');

        // :: Assert
        $this->assertFalse($deleted);
    }
}
