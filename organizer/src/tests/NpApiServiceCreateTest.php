<?php
// organizer/src/tests/NpApiServiceCreateTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';

class NpApiServiceCreateTest extends TestCase {
    const NP_ENTITY = '9999-test-entity-development';
    const LABELS = ['norske_postlister_no', 'document', 'document_id:2020-123-4'];

    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    public function testCreateThread(): void {
        $result = NpApiService::createThread(self::NP_ENTITY, 'Testtittel', 'Testinnhold', self::LABELS);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['existing']);
        $this->assertNotEmpty($result['thread_id']);
        $this->assertStringContainsString($result['thread_id'], $result['thread_url']);

        $thread = Thread::loadFromDatabase($result['thread_id']);
        $this->assertTrue($thread->public);
        $this->assertEquals('Testtittel', $thread->title);
        $this->assertContains('norske_postlister_no', $thread->labels);
        $this->assertContains('document_id:2020-123-4', $thread->labels);
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $thread->sending_status);
    }

    public function testDuplicateLabelReturnsExistingThread(): void {
        $first = NpApiService::createThread(self::NP_ENTITY, 'Tittel', 'Innhold', self::LABELS);
        $second = NpApiService::createThread(self::NP_ENTITY, 'Tittel', 'Innhold', self::LABELS);

        $this->assertFalse($second['created']);
        $this->assertTrue($second['existing']);
        $this->assertEquals($first['thread_id'], $second['thread_id']);
    }

    public function testUnknownEntityThrows(): void {
        $this->expectException(NpApiEntityNotFoundException::class);
        NpApiService::createThread('0000-finnes-ikke', 'Tittel', 'Innhold', self::LABELS);
    }

    public function testMissingDocOrCaseLabelThrows(): void {
        $this->expectException(NpApiValidationException::class);
        NpApiService::createThread(self::NP_ENTITY, 'Tittel', 'Innhold', ['norske_postlister_no']);
    }

    public function testEmptyTitleThrows(): void {
        $this->expectException(NpApiValidationException::class);
        NpApiService::createThread(self::NP_ENTITY, '', 'Innhold', self::LABELS);
    }

    public function testDailyCap(): void {
        // Lower the cap via test hook instead of creating 100 threads. The dev
        // DB is shared with e2e runs, which may have committed thread_history
        // rows for this user today, so measure the real baseline (same query
        // NpApiService uses) instead of assuming it's 0.
        $baseline = Database::queryValue(
            "SELECT count(*) FROM thread_history
             WHERE user_id = ? AND action = 'created' AND created_at >= date_trunc('day', now())",
            [NpApiService::THREAD_OWNER_USER_ID]
        );
        NpApiService::$dailyCapOverride = $baseline + 2;
        try {
            NpApiService::createThread(self::NP_ENTITY, 'T1', 'B', ['norske_postlister_no', 'document', 'document_id:2020-1-1']);
            NpApiService::createThread(self::NP_ENTITY, 'T2', 'B', ['norske_postlister_no', 'document', 'document_id:2020-2-1']);
            $this->expectException(NpApiCapExceededException::class);
            NpApiService::createThread(self::NP_ENTITY, 'T3', 'B', ['norske_postlister_no', 'document', 'document_id:2020-3-1']);
        } finally {
            NpApiService::$dailyCapOverride = null;
        }
    }

    public function testEntityWithNoEmailThrows(): void {
        $this->expectException(NpApiValidationException::class);
        NpApiService::createThread(
            '9998-test-entity-no-email',
            'Tittel',
            'Innhold',
            ['norske_postlister_no', 'document', 'document_id:2020-9-9']
        );
    }

    public function testWhitespacePaddedLabelDeduplicatesAgainstTrimmedStored(): void {
        $first = NpApiService::createThread(self::NP_ENTITY, 'Tittel', 'Innhold', self::LABELS);

        $second = NpApiService::createThread(
            self::NP_ENTITY,
            'Tittel',
            'Innhold',
            ['norske_postlister_no', 'document', '  document_id:2020-123-4  ']
        );

        $this->assertFalse($second['created']);
        $this->assertTrue($second['existing']);
        $this->assertEquals($first['thread_id'], $second['thread_id']);
    }

    public function testSameLabelDifferentEntityCreatesSeparateThread(): void {
        $otherNpEntity = '9997-test-entity-two';

        $first = NpApiService::createThread(self::NP_ENTITY, 'Tittel', 'Innhold', self::LABELS);
        $second = NpApiService::createThread($otherNpEntity, 'Tittel', 'Innhold', self::LABELS);

        $this->assertTrue($second['created']);
        $this->assertFalse($second['existing']);
        $this->assertNotEquals($first['thread_id'], $second['thread_id']);
    }

    public function testThreadEmailSendingRowContents(): void {
        $result = NpApiService::createThread(self::NP_ENTITY, 'Testtittel', 'Testinnhold', self::LABELS);

        $entity = Entity::getByNorskePostlisterId(self::NP_ENTITY);
        $sendings = ThreadEmailSending::getByThreadId($result['thread_id']);

        $this->assertCount(1, $sendings);
        $sending = $sendings[0];
        $this->assertEquals($entity->email, $sending->email_to);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $sending->status);
        $this->assertEquals('Testtittel', $sending->email_subject);
        $this->assertStringContainsString('Testinnhold', $sending->email_content);
        $this->assertStringContainsString("\n\n--\n", $sending->email_content);
        $this->assertNotEmpty($sending->email_from);
        $this->assertNotEmpty($sending->email_from_name);
        $this->assertStringContainsString($sending->email_from_name, $sending->email_content);
    }

    public function testRollbackOnMidTransactionThrowable(): void {
        // The setUp() transaction wraps every test for isolation, which means
        // NpApiService::createThread() sees Database::inTransaction() === true
        // and never owns (or rolls back) its own transaction - it defers to
        // the ambient one. To exercise the real commit/rollback path we
        // temporarily end that ambient transaction (nothing has been written
        // yet, so this is safe) and reopen one afterwards so tearDown()'s
        // rollBack() still has something to roll back.
        Database::commit();
        NpApiService::$forceThrowableForTest = true;
        try {
            $threw = false;
            try {
                NpApiService::createThread(self::NP_ENTITY, 'Tittel', 'Innhold', self::LABELS);
            } catch (Throwable $e) {
                $threw = true;
                $this->assertEquals('forced test failure', $e->getMessage());
            }
            $this->assertTrue($threw, 'Expected createThread to rethrow the forced Throwable');

            $existing = NpApiService::findExistingThread(
                Entity::getByNorskePostlisterId(self::NP_ENTITY)->entity_id,
                self::LABELS
            );
            $this->assertNull($existing, 'Thread insert should have been rolled back after the mid-transaction failure');
        } finally {
            NpApiService::$forceThrowableForTest = false;
            Database::beginTransaction();
        }
    }
}
