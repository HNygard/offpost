<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadCreationService.php';
require_once __DIR__ . '/../class/ThreadReplyService.php';

/**
 * The reply rules shared by the thread-reply page and the MCP send_reply tool.
 */
class ThreadReplyServiceTest extends TestCase {
    const ENTITY = '000000000-test-entity-development';
    const ENTITY_EMAIL = 'public-entity@dev.offpost.no';
    const USER = 'mcp-test-user';

    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function createThread(): Thread {
        $created = (new ThreadCreationService())->createThread(
            self::ENTITY, 'T', 'B', self::USER, Thread::REQUEST_LAW_BASIS_OTHER, null, false, false, []
        );
        return Thread::loadFromDatabase($created->id);
    }

    private function historyDetails(string $threadId, string $action): array {
        $rows = Database::query("SELECT details FROM thread_history WHERE thread_id = ? AND action = ?", [$threadId, $action]);
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        return json_decode($rows[0]['details'], true);
    }

    public function testQueueReplyReadyForSending(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act
        $sending = (new ThreadReplyService())->queueReply(
            $thread, 'Re: T', 'Svar', ' ' . strtoupper(self::ENTITY_EMAIL) . ' ', self::USER, true
        );

        // :: Assert
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $sending->status);
        $row = Database::queryOne("SELECT * FROM thread_email_sendings WHERE id = ?", [$sending->id]);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $row['status']);
        $this->assertEquals(self::ENTITY_EMAIL, $row['email_to']);
        $this->assertEquals($thread->my_email, $row['email_from']);
        $this->assertEquals('Svar', $row['email_content']);
        $this->assertEquals(
            ['email_sending_ids' => [$sending->id], 'recipient' => self::ENTITY_EMAIL, 'subject' => 'Re: T'],
            $this->historyDetails($thread->id, 'thread_reply_created_and_queued_sending')
        );
    }

    public function testQueueReplyDraft(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act
        $sending = (new ThreadReplyService())->queueReply(
            $thread, 'Re: T', 'Svar', self::ENTITY_EMAIL, self::USER, false
        );

        // :: Assert
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $sending->status);
        $this->assertEquals(
            ['email_sending_ids' => [$sending->id], 'recipient' => self::ENTITY_EMAIL, 'subject' => 'Re: T'],
            $this->historyDetails($thread->id, 'thread_reply_draft')
        );
    }

    public function testQueueReplyInvalidRecipientThrows(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act / Assert
        $this->expectException(ThreadReplyValidationException::class);
        (new ThreadReplyService())->queueReply($thread, 'S', 'B', 'stranger@example.com', self::USER, true);
    }

    public function testQueueReplyEmptyBodyThrows(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act / Assert
        $this->expectException(ThreadReplyValidationException::class);
        (new ThreadReplyService())->queueReply($thread, 'S', '', self::ENTITY_EMAIL, self::USER, true);
    }

    public function testQueueReplyWhitespaceBodyThrows(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act / Assert
        $this->expectException(ThreadReplyValidationException::class);
        (new ThreadReplyService())->queueReply($thread, 'S', "  \n ", self::ENTITY_EMAIL, self::USER, true);
    }

    public function testQueueReplyWhitespaceSubjectThrows(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act / Assert
        $this->expectException(ThreadReplyValidationException::class);
        (new ThreadReplyService())->queueReply($thread, '   ', 'B', self::ENTITY_EMAIL, self::USER, true);
    }

    public function testQueueReplyHistoryMergesDetails(): void {
        // :: Setup
        $thread = $this->createThread();

        // :: Act
        $sending = (new ThreadReplyService())->queueReply(
            $thread, 'Re: T', 'Svar', self::ENTITY_EMAIL, self::USER, true, ['via' => 'mcp']
        );

        // :: Assert
        $this->assertEquals(
            ['email_sending_ids' => [$sending->id], 'recipient' => self::ENTITY_EMAIL, 'subject' => 'Re: T', 'via' => 'mcp'],
            $this->historyDetails($thread->id, 'thread_reply_created_and_queued_sending')
        );
    }
}
