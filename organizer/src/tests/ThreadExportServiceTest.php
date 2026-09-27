<?php
// organizer/src/tests/ThreadExportServiceTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadExportService.php';

class ThreadExportServiceTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    /**
     * Creates a thread with fixed, fully-controlled column values: goes through
     * ThreadStorageManager (like the other DB-backed tests) so the row shape
     * matches production inserts, then overwrites created_at/updated_at (which
     * the schema defaults to CURRENT_TIMESTAMP) to fixed values, and deletes the
     * 'created' thread_history row createThread() logs (its created_at is
     * CURRENT_TIMESTAMP too, and it is part of the fingerprint - keeping it
     * would make the fingerprint non-deterministic across test runs).
     */
    private function createFixedThread(array $overrides = []): string {
        $thread = new Thread();
        $thread->title = $overrides['title'] ?? 'Export test thread';
        $thread->my_name = 'Test Person';
        $thread->my_email = 'test-person@example.com';
        $thread->labels = $overrides['labels'] ?? ['test-label'];
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = true;
        $thread->initial_request = 'Please release the documents.';
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->request_law_basis = Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
        $thread->request_follow_up_plan = Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;
        $thread->sentComment = null;
        $created = ThreadStorageManager::getInstance()->createThread(
            $overrides['entity_id'] ?? '000000000-test-entity-development',
            $thread,
            'test-user'
        );

        Database::execute(
            "UPDATE threads SET created_at = '2026-01-01T10:00:00+00:00', updated_at = '2026-01-01T10:00:00+00:00' WHERE id = ?",
            [$created->id]
        );
        Database::execute("DELETE FROM thread_history WHERE thread_id = ?", [$created->id]);

        return $created->id;
    }

    private function insertEmail(string $threadId, array $fields): string {
        return Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, created_at, ignore, email_type,
                 status_type, status_text, auto_classification, description, answer, content, imap_headers,
                 thread_state, thread_state_type, thread_state_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::bytea, ?, ?::jsonb, ?, ?) RETURNING id",
            [
                $threadId,
                $fields['timestamp_received'],
                $fields['datetime_received'],
                $fields['created_at'],
                ($fields['ignore'] ?? false) ? 't' : 'f',
                $fields['email_type'],
                $fields['status_type'] ?? null,
                $fields['status_text'] ?? null,
                $fields['auto_classification'] ?? null,
                $fields['description'] ?? null,
                $fields['answer'] ?? null,
                $fields['content'],
                $fields['imap_headers'] ?? null,
                $fields['thread_state'] ?? null,
                $fields['thread_state_type'] ?? null,
                $fields['thread_state_source'] ?? null,
            ]
        );
    }

    private const IN_EML = "From: Sender Name <sender@example.com>\r\n"
        . "To: recipient@example.com\r\n"
        . "Subject: Innsyn i saken\r\n"
        . "Date: Wed, 01 Jan 2026 10:00:00 +0000\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . "Her er dokumentene.";

    private const OUT_EML = "From: test-person@example.com\r\n"
        . "To: entity@example.com\r\n"
        . "Subject: Begjaering om innsyn\r\n"
        . "Date: Wed, 01 Jan 2026 09:00:00 +0000\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . "Jeg ber om innsyn.";

    public function testExportThreadFullShapeWithEmailsAttachmentExtractionsSendingAndHistory(): void {
        // :: Setup
        $threadId = $this->createFixedThread();

        $inImapHeaders = json_encode([
            'subject' => 'Innsyn i saken',
            'from' => [['mailbox' => 'sender', 'host' => 'example.com', 'personal' => 'Sender Name']],
            'to' => [['mailbox' => 'recipient', 'host' => 'example.com']],
            'cc' => [['mailbox' => 'cc-person', 'host' => 'example.com']],
        ]);
        $inThreadState = [
            'schema_version' => 1,
            'request' => ['summary' => 'Valgprotokoll', 'law_basis' => 'offentleglova', 'sent_at' => '2026-01-01'],
            'items' => [
                ['id' => '1', 'asked_for' => 'Valgprotokoll 2023', 'status' => 'RELEASED',
                 'denial_basis' => null, 'released_in_email_ids' => [], 'note' => ''],
            ],
            'waiting_for' => 'NOBODY',
            'asks_to_us' => [],
            'case_numbers' => [],
            'dates' => [],
            'complaints' => [],
            'notes' => '',
            'extra' => [],
        ];
        $inEmailId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-01-02T10:00:00+00:00',
            'datetime_received' => '2026-01-02T10:00:00+00:00',
            'created_at' => '2026-01-02T10:05:00+00:00',
            'email_type' => 'IN',
            'status_type' => 'INFORMATION_RELEASE',
            'status_text' => 'Documents released',
            'auto_classification' => null,
            'description' => 'Svar med dokumenter',
            'answer' => null,
            'content' => self::IN_EML,
            'imap_headers' => $inImapHeaders,
            'thread_state' => json_encode($inThreadState),
            'thread_state_type' => 'ANSWERED',
            'thread_state_source' => 'auto',
        ]);

        $outEmailId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-01-01T09:00:00+00:00',
            'datetime_received' => '2026-01-01T09:00:00+00:00',
            'created_at' => '2026-01-01T09:05:00+00:00',
            'email_type' => 'OUT',
            'status_type' => 'OUR_REQUEST',
            'status_text' => 'Sent',
            'content' => self::OUT_EML,
            'imap_headers' => null,
        ]);

        // Attachment on the IN email, with its own extraction.
        $attachmentId = Database::queryValue(
            "INSERT INTO thread_email_attachments
                (email_id, name, filename, filetype, location, status_type, status_text, size, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [$inEmailId, 'svar.pdf', 'svar.pdf', 'pdf', 'svar.pdf', 'unknown', 'uklassifisert-dok', 1234, '2026-01-02T10:10:00+00:00']
        );
        $attachmentExtractionId = Database::queryValue(
            "INSERT INTO thread_email_extractions
                (email_id, attachment_id, prompt_id, prompt_service, prompt_text, extracted_text, error_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING extraction_id",
            [$inEmailId, $attachmentId, 'doc-prompt', 'openai', 'Extract the text', 'PDF text content', null, '2026-01-02T10:11:00+00:00', '2026-01-02T10:12:00+00:00']
        );

        // Email-level extraction (attachment_id NULL) on the IN email.
        $emailExtractionId = Database::queryValue(
            "INSERT INTO thread_email_extractions
                (email_id, attachment_id, prompt_id, prompt_service, prompt_text, extracted_text, error_message, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?) RETURNING extraction_id",
            [$inEmailId, 'body-prompt', 'openai', 'Summarize the body', 'Summary text', null, '2026-01-02T10:13:00+00:00', '2026-01-02T10:14:00+00:00']
        );

        // thread_email_history row for the IN email.
        Database::execute(
            "INSERT INTO thread_email_history (thread_id, email_id, action, user_id, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$threadId, (string) $inEmailId, 'classified', 'test-user', json_encode(['status_type' => 'INFORMATION_RELEASE']), '2026-01-02T10:15:00+00:00']
        );

        // A sending.
        Database::execute(
            "INSERT INTO thread_email_sendings
                (thread_id, email_content, email_subject, email_to, email_from, email_from_name, status, smtp_response, smtp_debug, error_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $threadId, 'Jeg ber om innsyn.', 'Begjaering om innsyn', 'entity@example.com',
                'test-person@example.com', 'Test Person', 'SENT', '250 OK', 'debug', null,
                '2026-01-01T09:01:00+00:00', '2026-01-01T09:02:00+00:00',
            ]
        );

        // thread-level history row.
        Database::execute(
            "INSERT INTO thread_history (thread_id, action, user_id, details, created_at)
             VALUES (?, ?, ?, ?, ?)",
            [$threadId, 'sent', 'test-user', json_encode(['note' => 'sent to entity']), '2026-01-01T09:03:00+00:00']
        );

        // :: Act
        $export = ThreadExportService::exportThread($threadId);

        // :: Assert
        $this->assertNotNull($export);

        // exported_at is wall-clock "now" - assert its shape, then drop it so the
        // rest of the payload can be compared with one assertEquals.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $export['exported_at'],
            json_encode($export, JSON_PRETTY_PRINT)
        );
        unset($export['exported_at']);

        // threads.updated_at is maintained by the update_threads_updated_at
        // trigger (see 99999-database-schema-after-migrations.sql), which
        // overwrites any value an UPDATE statement supplies with
        // CURRENT_TIMESTAMP - so it cannot be pinned to a fixed value here.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $export['thread']['updated_at'],
            json_encode($export, JSON_PRETTY_PRINT)
        );
        unset($export['thread']['updated_at']);

        $inBody = ThreadEmailExtractorEmailBody::extractContentFromEmail(self::IN_EML);
        $outBody = ThreadEmailExtractorEmailBody::extractContentFromEmail(self::OUT_EML);

        $expected = [
            'export_version' => 1,
            'fingerprint' => $export['fingerprint'], // computed value asserted (non-empty, stable) below
            'thread' => [
                'id' => $threadId,
                'entity_id' => '000000000-test-entity-development',
                'title' => 'Export test thread',
                'my_name' => 'Test Person',
                'my_email' => 'test-person@example.com',
                'labels' => ['test-label'],
                'sent' => false,
                'archived' => false,
                'public' => true,
                'sent_comment' => null,
                'sending_status' => Thread::SENDING_STATUS_READY_FOR_SENDING,
                'initial_request' => 'Please release the documents.',
                'request_law_basis' => Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA,
                'request_follow_up_plan' => Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY,
                'created_at' => '2026-01-01T10:00:00+00:00',
                // 'updated_at' asserted separately above and removed from both sides.
            ],
            'entity' => [
                'entity_id' => '000000000-test-entity-development',
                'name' => Entity::getById('000000000-test-entity-development')->name,
                'email' => Entity::getById('000000000-test-entity-development')->email,
                'type' => Entity::getById('000000000-test-entity-development')->type,
                'org_num' => Entity::getById('000000000-test-entity-development')->org_num,
                'entity_id_norske_postlister' => Entity::getById('000000000-test-entity-development')->entity_id_norske_postlister,
            ],
            'emails' => [
                [
                    'id' => $outEmailId,
                    'email_type' => 'OUT',
                    'datetime_received' => '2026-01-01T09:00:00+00:00',
                    'timestamp_received' => '2026-01-01T09:00:00+00:00',
                    'created_at' => '2026-01-01T09:05:00+00:00',
                    'ignore' => false,
                    'status_type' => 'OUR_REQUEST',
                    'status_text' => 'Sent',
                    'auto_classification' => null,
                    'classification_source' => 'manual',
                    'description' => null,
                    'answer' => null,
                    'thread_state' => null,
                    'thread_state_type' => null,
                    'thread_state_source' => null,
                    'subject' => null,
                    'from' => null,
                    'to' => [],
                    'cc' => [],
                    'imap_headers' => null,
                    'body_plain' => $outBody->plain_text,
                    'body_html' => $outBody->html,
                    'body_parse_error' => null,
                    'eml_base64' => base64_encode(self::OUT_EML),
                    'extractions' => [],
                    'attachments' => [],
                    'history' => [],
                ],
                [
                    'id' => $inEmailId,
                    'email_type' => 'IN',
                    'datetime_received' => '2026-01-02T10:00:00+00:00',
                    'timestamp_received' => '2026-01-02T10:00:00+00:00',
                    'created_at' => '2026-01-02T10:05:00+00:00',
                    'ignore' => false,
                    'status_type' => 'INFORMATION_RELEASE',
                    'status_text' => 'Documents released',
                    'auto_classification' => null,
                    'classification_source' => 'manual',
                    'description' => 'Svar med dokumenter',
                    'answer' => null,
                    'thread_state' => $inThreadState,
                    'thread_state_type' => 'ANSWERED',
                    'thread_state_source' => 'auto',
                    'subject' => 'Innsyn i saken',
                    'from' => 'Sender Name <sender@example.com>',
                    'to' => ['recipient@example.com'],
                    'cc' => ['cc-person@example.com'],
                    'imap_headers' => json_decode($inImapHeaders, true),
                    'body_plain' => $inBody->plain_text,
                    'body_html' => $inBody->html,
                    'body_parse_error' => null,
                    'eml_base64' => base64_encode(self::IN_EML),
                    'extractions' => [
                        [
                            'extraction_id' => $emailExtractionId,
                            'prompt_id' => 'body-prompt',
                            'prompt_service' => 'openai',
                            'prompt_text' => 'Summarize the body',
                            'extracted_text' => 'Summary text',
                            'error_message' => null,
                            'created_at' => '2026-01-02T10:13:00+00:00',
                            'updated_at' => '2026-01-02T10:14:00+00:00',
                        ],
                    ],
                    'attachments' => [
                        [
                            'id' => $attachmentId,
                            'name' => 'svar.pdf',
                            'filename' => 'svar.pdf',
                            'filetype' => 'pdf',
                            'size' => 1234,
                            'location' => 'svar.pdf',
                            'status_type' => 'unknown',
                            'status_text' => 'uklassifisert-dok',
                            'created_at' => '2026-01-02T10:10:00+00:00',
                            'extractions' => [
                                [
                                    'extraction_id' => $attachmentExtractionId,
                                    'prompt_id' => 'doc-prompt',
                                    'prompt_service' => 'openai',
                                    'prompt_text' => 'Extract the text',
                                    'extracted_text' => 'PDF text content',
                                    'error_message' => null,
                                    'created_at' => '2026-01-02T10:11:00+00:00',
                                    'updated_at' => '2026-01-02T10:12:00+00:00',
                                ],
                            ],
                        ],
                    ],
                    'history' => [
                        [
                            'id' => $export['emails'][1]['history'][0]['id'] ?? null, // serial id, asserted present below
                            'thread_id' => $threadId,
                            'email_id' => (string) $inEmailId,
                            'action' => 'classified',
                            'user_id' => 'test-user',
                            'details' => ['status_type' => 'INFORMATION_RELEASE'],
                            'created_at' => '2026-01-02T10:15:00+00:00',
                        ],
                    ],
                ],
            ],
            'sendings' => [
                [
                    'id' => $export['sendings'][0]['id'] ?? null, // serial id, asserted present below
                    'thread_id' => $threadId,
                    'email_content' => 'Jeg ber om innsyn.',
                    'email_subject' => 'Begjaering om innsyn',
                    'email_to' => 'entity@example.com',
                    'email_from' => 'test-person@example.com',
                    'email_from_name' => 'Test Person',
                    'status' => 'SENT',
                    'smtp_response' => '250 OK',
                    'smtp_debug' => 'debug',
                    'error_message' => null,
                    'created_at' => '2026-01-01T09:01:00+00:00',
                    'updated_at' => '2026-01-01T09:02:00+00:00',
                ],
            ],
            'history' => [
                [
                    'id' => $export['history'][0]['id'] ?? null, // serial id, asserted present below
                    'thread_id' => $threadId,
                    'action' => 'sent',
                    'user_id' => 'test-user',
                    'details' => ['note' => 'sent to entity'],
                    'created_at' => '2026-01-01T09:03:00+00:00',
                ],
            ],
        ];

        $this->assertIsInt($export['emails'][1]['history'][0]['id'] ?? null, json_encode($export, JSON_PRETTY_PRINT));
        $this->assertIsInt($export['sendings'][0]['id'] ?? null, json_encode($export, JSON_PRETTY_PRINT));
        $this->assertIsInt($export['history'][0]['id'] ?? null, json_encode($export, JSON_PRETTY_PRINT));
        $this->assertNotEmpty($export['fingerprint']);
        $this->assertIsString($export['fingerprint']);

        $this->assertEquals($expected, $export, json_encode($export, JSON_PRETTY_PRINT));
    }

    public function testClassificationSourceForAllFourCases(): void {
        // :: Setup
        $threadId = $this->createFixedThread();

        $noneId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-02-01T09:00:00+00:00',
            'datetime_received' => '2026-02-01T09:00:00+00:00',
            'created_at' => '2026-02-01T09:00:00+00:00',
            'email_type' => 'IN',
            'status_type' => null,
            'content' => self::IN_EML,
        ]);
        $unknownId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-02-01T09:01:00+00:00',
            'datetime_received' => '2026-02-01T09:01:00+00:00',
            'created_at' => '2026-02-01T09:01:00+00:00',
            'email_type' => 'IN',
            'status_type' => 'unknown',
            'content' => self::IN_EML,
        ]);
        $manualId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-02-01T09:02:00+00:00',
            'datetime_received' => '2026-02-01T09:02:00+00:00',
            'created_at' => '2026-02-01T09:02:00+00:00',
            'email_type' => 'IN',
            'status_type' => 'INFORMATION_RELEASE',
            'auto_classification' => null,
            'content' => self::IN_EML,
        ]);
        $algoId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-02-01T09:03:00+00:00',
            'datetime_received' => '2026-02-01T09:03:00+00:00',
            'created_at' => '2026-02-01T09:03:00+00:00',
            'email_type' => 'IN',
            'status_type' => 'INFORMATION_RELEASE',
            'auto_classification' => 'algo',
            'content' => self::IN_EML,
        ]);
        $promptId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-02-01T09:04:00+00:00',
            'datetime_received' => '2026-02-01T09:04:00+00:00',
            'created_at' => '2026-02-01T09:04:00+00:00',
            'email_type' => 'IN',
            'status_type' => 'INFORMATION_RELEASE',
            'auto_classification' => 'prompt',
            'content' => self::IN_EML,
        ]);

        // :: Act
        $export = ThreadExportService::exportThread($threadId);
        $sourceById = [];
        foreach ($export['emails'] as $email) {
            $sourceById[$email['id']] = $email['classification_source'];
        }

        // :: Assert
        $this->assertEquals([
            $noneId => 'none',
            $unknownId => 'none',
            $manualId => 'manual',
            $algoId => 'algo',
            $promptId => 'prompt',
        ], $sourceById, json_encode($sourceById, JSON_PRETTY_PRINT));
    }

    public function testFingerprintChangesAfterStatusTypeChange(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $emailId = $this->insertEmail($threadId, [
            'timestamp_received' => '2026-03-01T09:00:00+00:00',
            'datetime_received' => '2026-03-01T09:00:00+00:00',
            'created_at' => '2026-03-01T09:00:00+00:00',
            'email_type' => 'IN',
            'status_type' => null,
            'content' => self::IN_EML,
        ]);

        // :: Act
        $fingerprintBefore = ThreadExportService::exportThread($threadId)['fingerprint'];
        Database::execute("UPDATE thread_emails SET status_type = 'INFORMATION_RELEASE' WHERE id = ?", [$emailId]);
        $fingerprintAfter = ThreadExportService::exportThread($threadId)['fingerprint'];

        // :: Assert
        $this->assertNotEquals($fingerprintBefore, $fingerprintAfter);
    }

    public function testListThreadsContainsThreadWithSameFingerprintAndEmailCount(): void {
        // :: Setup
        $threadId = $this->createFixedThread(['title' => 'List test thread', 'labels' => ['list-label']]);
        $this->insertEmail($threadId, [
            'timestamp_received' => '2026-04-01T09:00:00+00:00',
            'datetime_received' => '2026-04-01T09:00:00+00:00',
            'created_at' => '2026-04-01T09:00:00+00:00',
            'email_type' => 'IN',
            'status_type' => 'INFORMATION_RELEASE',
            'content' => self::IN_EML,
        ]);
        $this->insertEmail($threadId, [
            'timestamp_received' => '2026-04-01T08:00:00+00:00',
            'datetime_received' => '2026-04-01T08:00:00+00:00',
            'created_at' => '2026-04-01T08:00:00+00:00',
            'email_type' => 'OUT',
            'status_type' => 'OUR_REQUEST',
            'content' => self::OUT_EML,
        ]);

        // :: Act
        $exported = ThreadExportService::exportThread($threadId);
        $list = ThreadExportService::listThreads();
        $listRow = null;
        foreach ($list as $row) {
            if ($row['id'] === $threadId) {
                $listRow = $row;
            }
        }

        // :: Assert
        $this->assertNotNull($listRow, json_encode($list, JSON_PRETTY_PRINT));
        $this->assertEquals('List test thread', $listRow['title']);
        $this->assertEquals('000000000-test-entity-development', $listRow['entity_id']);
        $this->assertEquals(['list-label'], $listRow['labels']);
        $this->assertEquals(2, $listRow['email_count'], json_encode($listRow, JSON_PRETTY_PRINT));
        $this->assertEquals($exported['fingerprint'], $listRow['fingerprint']);
    }
}
