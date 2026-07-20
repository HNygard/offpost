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
        // Lower the cap via test hook instead of creating 100 threads.
        NpApiService::$dailyCapOverride = 2;
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
}
