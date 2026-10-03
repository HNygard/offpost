<?php
// organizer/src/tests/AttachmentExtractionStatsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Extraction/AttachmentExtractionStats.php';

/**
 * Unit tests for AttachmentExtractionStats, on the test database, inside a
 * transaction rolled back in tearDown(). Assertions only look at rows of the
 * fixture's own filetype, since the survey aggregates across all attachments.
 */
class AttachmentExtractionStatsTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private int $threadCounter = 0;

    private function createFixedThread(): string {
        $this->threadCounter++;
        $thread = new Thread();
        $thread->title = 'Attachment stats test thread ' . $this->threadCounter;
        $thread->my_name = 'Test Person';
        $thread->my_email = "test-attach-stats-person-{$this->threadCounter}@example.com";
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = true;
        $thread->initial_request = 'Please release the documents.';
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->request_law_basis = Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
        $thread->request_follow_up_plan = Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;
        $thread->sentComment = null;
        $created = ThreadStorageManager::getInstance()->createThread(
            '000000000-test-entity-development',
            $thread,
            'test-user'
        );
        return $created->id;
    }

    private function insertEmail(string $threadId, string $timestampReceived): string {
        return Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, content)
             VALUES (?, ?, ?, ?::bytea) RETURNING id",
            [$threadId, $timestampReceived, $timestampReceived, 'Body text']
        );
    }

    private function insertAttachment(string $emailId, string $name, int $size): string {
        return Database::queryValue(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, size)
             VALUES (?, ?, ?, 'testsurveytype', 'loc', ?) RETURNING id",
            [$emailId, $name, 'file.bin', $size]
        );
    }

    private function insertExtraction(string $emailId, string $attachmentId, ?string $text, ?string $error): void {
        Database::execute(
            "INSERT INTO thread_email_extractions (email_id, prompt_id, prompt_text, prompt_service, extracted_text, error_message, attachment_id)
             VALUES (?, 'p', 'prompt', 'test', ?, ?, ?)",
            [$emailId, $text, $error, $attachmentId]
        );
    }

    public function testGetAttachmentTypeStatsCountsEachAttachmentInOneCategory(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $email2020 = $this->insertEmail($threadId, '2020-05-01 10:00:00');
        $email2021 = $this->insertEmail($threadId, '2021-05-01 10:00:00');

        // With text: two extractions, one blank and one with text.
        $withText = $this->insertAttachment($email2020, 'a.pdf', 1000);
        $this->insertExtraction($email2020, $withText, '  ', null);
        $this->insertExtraction($email2020, $withText, 'Some text', null);
        // No text: extraction ran, blank result, no error. Also has an empty name.
        $noText = $this->insertAttachment($email2020, '', 3000);
        $this->insertExtraction($email2020, $noText, null, null);
        // Failed: only extractions with errors.
        $failed = $this->insertAttachment($email2020, 'c.pdf', 2000);
        $this->insertExtraction($email2020, $failed, null, 'Boom');
        // Not extracted: no extraction at all.
        $this->insertAttachment($email2020, 'd.pdf', 2000);
        // Other year.
        $other = $this->insertAttachment($email2021, 'e.pdf', 500);
        $this->insertExtraction($email2021, $other, 'Text', null);

        // :: Act
        $rows = AttachmentExtractionStats::getAttachmentTypeStats();

        // :: Assert
        $mine = array_values(array_filter($rows, fn(array $row): bool => $row['filetype'] === 'testsurveytype'));
        $this->assertEquals([
            [
                'filetype' => 'testsurveytype', 'year' => 2021, 'count' => 1, 'with_text' => 1,
                'no_text' => 0, 'failed' => 0, 'not_extracted' => 0, 'empty_name' => 0, 'avg_size_bytes' => 500,
            ],
            [
                'filetype' => 'testsurveytype', 'year' => 2020, 'count' => 4, 'with_text' => 1,
                'no_text' => 1, 'failed' => 1, 'not_extracted' => 1, 'empty_name' => 1, 'avg_size_bytes' => 2000,
            ],
        ], $mine, json_encode($rows, JSON_PRETTY_PRINT));
    }
}
