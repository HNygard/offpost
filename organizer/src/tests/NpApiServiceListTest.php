<?php
// organizer/src/tests/NpApiServiceListTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';

class NpApiServiceListTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    public function testListContainsCreatedThreadWithNpEntityId(): void {
        // 9999-test-entity-development is type "test" in entities_test.json, so it is
        // deliberately excluded from Entity::getAllNorskePostlisterIds() (see
        // EntityNpLookupTest::testGetAllNorskePostlisterIdsExcludesTestEntities) even
        // though NpApiService::createThread() still resolves it fine. So a thread's
        // entity_id_norske_postlister can legitimately be set to an id that is absent
        // from supported_entities - those two lists are independent.
        $labels = ['norske_postlister_no', 'document', 'document_id:2021-55-2'];
        $created = NpApiService::createThread('9999-test-entity-development', 'Tittel', 'Innhold', $labels);

        $result = NpApiService::listNpThreads();

        $threadIds = array_column($result['threads'], 'thread_id');
        $this->assertContains($created['thread_id'], $threadIds);

        $thread = $result['threads'][array_search($created['thread_id'], $threadIds)];
        $this->assertEquals('9999-test-entity-development', $thread['entity_id_norske_postlister']);
        $this->assertContains('document_id:2021-55-2', $thread['labels']);
        $this->assertArrayHasKey('status', $thread);
        $this->assertArrayHasKey('emails', $thread);
        $this->assertEquals(0, $thread['email_count_in']);
        $this->assertEquals(0, $thread['email_count_out']);
        $this->assertSame([], $thread['emails']);
        $this->assertStringContainsString($created['thread_id'], $thread['thread_url']);
    }

    public function testSupportedEntitiesContainsNonTestNpEntity(): void {
        // 9996-test-municipality is type "municipality" (not "test"), so unlike
        // 9999-test-entity-development above it must appear in supported_entities.
        $labels = ['norske_postlister_no', 'document', 'document_id:2021-66-1'];
        NpApiService::createThread('9996-test-municipality', 'Tittel', 'Innhold', $labels);

        $result = NpApiService::listNpThreads();

        $this->assertContains('9996-test-municipality', $result['supported_entities']);
    }

    public function testThreadsWithoutNpLabelExcluded(): void {
        $before = count(NpApiService::listNpThreads()['threads']);

        // A thread without the norske_postlister_no label must not appear.
        $thread = new Thread();
        $thread->title = 'Uvedkommende';
        $thread->my_name = 'Test Person';
        $thread->my_email = 'test@offpost.no';
        $thread->labels = ['annet'];
        $thread->initial_request = 'x';
        $thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = true;
        $thread->emails = [];
        ThreadStorageManager::getInstance()->createThread('000000000-test-entity-development', $thread, 'test-user');

        $this->assertCount($before, NpApiService::listNpThreads()['threads']);
    }

    public function testArchivedNpThreadExcluded(): void {
        $labels = ['norske_postlister_no', 'document', 'document_id:2021-77-3'];
        $created = NpApiService::createThread('9999-test-entity-development', 'Tittel', 'Innhold', $labels);

        Database::execute('UPDATE threads SET archived = true WHERE id = ?', [$created['thread_id']]);

        $threadIds = array_column(NpApiService::listNpThreads()['threads'], 'thread_id');
        $this->assertNotContains($created['thread_id'], $threadIds);
    }
}
