<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadCreationService.php';
require_once __DIR__ . '/../class/ThreadAuthorization.php';

/**
 * The creation rules shared by the start-thread page and the MCP start_thread tool.
 */
class ThreadCreationServiceTest extends TestCase {
    const ENTITY = '000000000-test-entity-development';
    const ENTITY_EMAIL = 'public-entity@dev.offpost.no';
    const USER = 'mcp-test-user';

    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    public function testCreateThreadQueuesRequestAndMakesOwner(): void {
        // :: Setup
        $service = new ThreadCreationService();

        // :: Act
        $thread = $service->createThread(
            self::ENTITY, 'Innsyn i journal', 'Jeg ber om innsyn.', self::USER,
            Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, null, true, false, ['mcp']
        );

        // :: Assert
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $thread->sending_status);
        $this->assertTrue(ThreadAuthorizationManager::isThreadOwner($thread->id, self::USER), 'Creator should be owner');
        $sendings = Database::query("SELECT * FROM thread_email_sendings WHERE thread_id = ?", [$thread->id]);
        $this->assertCount(1, $sendings, json_encode($sendings, JSON_PRETTY_PRINT));
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $sendings[0]['status']);
        $this->assertEquals(self::ENTITY_EMAIL, $sendings[0]['email_to']);
        $this->assertEquals('Innsyn i journal', $sendings[0]['email_subject']);
        $this->assertStringStartsWith("Jeg ber om innsyn.\n\n", $sendings[0]['email_content']);
        $this->assertEquals(['mcp'], Thread::loadFromDatabase($thread->id)->labels);
    }

    public function testCreateThreadStagingWhenNotSendNow(): void {
        // :: Setup
        $service = new ThreadCreationService();

        // :: Act
        $thread = $service->createThread(
            self::ENTITY, 'T', 'B', self::USER, Thread::REQUEST_LAW_BASIS_OTHER, null, false, false, []
        );

        // :: Assert
        $this->assertEquals(Thread::SENDING_STATUS_STAGING, $thread->sending_status);
        $sendings = Database::query("SELECT status FROM thread_email_sendings WHERE thread_id = ?", [$thread->id]);
        $this->assertCount(1, $sendings, json_encode($sendings, JSON_PRETTY_PRINT));
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $sendings[0]['status']);
    }

    public function testCreateThreadHistoryCarriesDetails(): void {
        // :: Setup
        $service = new ThreadCreationService();

        // :: Act
        $thread = $service->createThread(
            self::ENTITY, 'T', 'B', self::USER, Thread::REQUEST_LAW_BASIS_OTHER, null, false, false, [], ['via' => 'mcp']
        );

        // :: Assert
        $rows = Database::query("SELECT details FROM thread_history WHERE thread_id = ? AND action = 'created'", [$thread->id]);
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals(['via' => 'mcp'], json_decode($rows[0]['details'], true));
    }

    public function testCreateThreadHistoryDetailsNullWhenNoneGiven(): void {
        // :: Setup
        $service = new ThreadCreationService();

        // :: Act
        $thread = $service->createThread(
            self::ENTITY, 'T', 'B', self::USER, Thread::REQUEST_LAW_BASIS_OTHER, null, false, false, []
        );

        // :: Assert
        $rows = Database::query("SELECT details FROM thread_history WHERE thread_id = ? AND action = 'created'", [$thread->id]);
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertNull($rows[0]['details'], 'Page behaviour: no details when none are given');
    }

    public function testCreateThreadUnknownEntityThrows(): void {
        // :: Setup
        $service = new ThreadCreationService();

        // :: Act / Assert
        $this->expectException(InvalidArgumentException::class);
        $service->createThread(
            'no-such-entity', 'T', 'B', self::USER, Thread::REQUEST_LAW_BASIS_OTHER, null, true, false, []
        );
    }
}
