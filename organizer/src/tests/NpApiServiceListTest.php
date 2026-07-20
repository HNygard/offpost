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

    public function testEmailsAttachmentsAndUnknownEmailType(): void {
        $labels = ['norske_postlister_no', 'document', 'document_id:2021-88-4'];
        $created = NpApiService::createThread('9999-test-entity-development', 'Tittel', 'Innhold', $labels);
        $threadId = $created['thread_id'];

        // IN email with an RFC-2047-encoded subject ("Test med æøå" in UTF-8 base64),
        // with a normal 'pdf' attachment and a 'docx' attachment.
        // Timestamps are inserted with an explicit UTC offset so the instant is
        // unambiguous regardless of the DB session's/PHP's default timezone; the
        // service converts back to a unix int via strtotime(), which honors the
        // offset in whatever string Postgres returns.
        $ts1 = 1700000000;
        $encodedSubject = '=?UTF-8?B?VGVzdCBtZWQgw6bDuMOl?=';
        $emailWithSubjectId = Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
             VALUES (?, ?, ?, ?, ?::bytea, ?) RETURNING id",
            [
                $threadId, gmdate('Y-m-d\TH:i:sP', $ts1), gmdate('Y-m-d\TH:i:sP', $ts1),
                'IN', 'content', json_encode(['subject' => $encodedSubject]),
            ]
        );
        Database::execute(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location)
             VALUES (?, ?, ?, ?, ?)",
            [$emailWithSubjectId, 'a.pdf', 'a.pdf', 'pdf', 'a.pdf']
        );
        Database::execute(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location)
             VALUES (?, ?, ?, ?, ?)",
            [$emailWithSubjectId, 'a.docx', 'a.docx', 'docx', 'a.docx']
        );
        // Bogus/unmapped filetype must not throw - falls back to octet-stream.
        Database::execute(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location)
             VALUES (?, ?, ?, ?, ?)",
            [$emailWithSubjectId, 'a.xyz123', 'a.xyz123', 'xyz123', 'a.xyz123']
        );

        // OUT email with NULL imap_headers -> subject must be null.
        $ts2 = 1700000100;
        $emailNullHeadersId = Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
             VALUES (?, ?, ?, ?, ?::bytea, NULL) RETURNING id",
            [$threadId, gmdate('Y-m-d\TH:i:sP', $ts2), gmdate('Y-m-d\TH:i:sP', $ts2), 'OUT', 'content']
        );

        // Email with an unknown/unrecognized email_type must be silently skipped
        // from the emails list, without affecting the thread or the other emails.
        $ts3 = 1700000200;
        Database::execute(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
             VALUES (?, ?, ?, ?, ?::bytea, NULL)",
            [$threadId, gmdate('Y-m-d\TH:i:sP', $ts3), gmdate('Y-m-d\TH:i:sP', $ts3), 'BOGUS_TYPE', 'content']
        );

        $result = NpApiService::listNpThreads();
        $threadIds = array_column($result['threads'], 'thread_id');
        $thread = $result['threads'][array_search($threadId, $threadIds)];

        $this->assertCount(2, $thread['emails'], 'unknown email_type row must be omitted');

        $inEmail = null;
        $outEmail = null;
        foreach ($thread['emails'] as $email) {
            if ($email['email_type'] === 'IN') {
                $inEmail = $email;
            } elseif ($email['email_type'] === 'OUT') {
                $outEmail = $email;
            }
        }
        $this->assertNotNull($inEmail, 'IN email must be present');
        $this->assertNotNull($outEmail, 'OUT email must be present');

        // Decoded RFC-2047 subject.
        $this->assertEquals('Test med æøå', $inEmail['subject']);
        // Timestamps come back as unix ints.
        $this->assertIsInt($inEmail['timestamp']);
        $this->assertEquals(1700000000, $inEmail['timestamp']);

        // NULL imap_headers -> null subject.
        $this->assertNull($outEmail['subject']);
        $this->assertIsInt($outEmail['timestamp']);
        $this->assertEquals(1700000100, $outEmail['timestamp']);

        // Attachment content types: known extensions map correctly, unknown
        // falls back to octet-stream rather than throwing.
        $attachmentsByName = [];
        foreach ($inEmail['attachments'] as $att) {
            $attachmentsByName[$att['name']] = $att['content_type'];
        }
        $this->assertEquals('application/pdf', $attachmentsByName['a.pdf']);
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $attachmentsByName['a.docx']
        );
        $this->assertEquals('application/octet-stream', $attachmentsByName['a.xyz123']);
    }
}
