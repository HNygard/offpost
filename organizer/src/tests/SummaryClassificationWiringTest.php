<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorPromptSummary.php';

/**
 * Regression: ThreadEmailStatusUpdater existed but had no callers - summary
 * extractions never became classifications. The wiring lives in
 * ThreadEmailExtractorPromptSummary::applySummaryClassification.
 */
class SummaryClassificationWiringTest extends TestCase {
    private ThreadEmailExtractionService $service;

    protected function setUp(): void {
        // The extractor constructor requires an OpenAI key to build its prompt
        // service; applySummaryClassification never calls the API.
        putenv('OPENAI_API_KEY=test-dummy-key');
        Database::beginTransaction();
        $this->service = new ThreadEmailExtractionService();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function makeThreadWithEmail(string $emailType): array {
        $created = NpApiService::createThread('9999-test-entity-development', 'T', 'B',
            ['norske_postlister_no', 'document', 'document_id:2032-' . random_int(1000, 999999) . '-1']);
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
             VALUES (?, now(), now(), ?, ?::bytea, NULL) RETURNING id",
            [$created['thread_id'], $emailType, 'content']
        );
        return [$created['thread_id'], $emailId];
    }

    private function makeSummaryExtraction(string $emailId, string $summary): int {
        $extraction = $this->service->createExtraction($emailId, 'summary', 'openai', null, 'thread-email-summary');
        $this->service->updateExtractionResults($extraction->extraction_id, $summary);
        return $extraction->extraction_id;
    }

    public function testIncomingEmailGetsClassifiedFromSummary(): void {
        [, $emailId] = $this->makeThreadWithEmail('IN');
        $extractionId = $this->makeSummaryExtraction($emailId, 'Dokumentene er vedlagt i svaret fra kommunen.');

        $extractor = new ThreadEmailExtractorPromptSummary($this->service);
        $this->assertTrue($extractor->applySummaryClassification($emailId, $extractionId));

        $row = Database::queryOneOrNone("SELECT status_type, auto_classification FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('INFORMATION_RELEASE', $row['status_type']);
        $this->assertEquals('prompt', $row['auto_classification']);
    }

    public function testOutgoingEmailIsNotClassified(): void {
        [, $emailId] = $this->makeThreadWithEmail('OUT');
        $extractionId = $this->makeSummaryExtraction($emailId, 'Dokumentene er vedlagt.');

        $extractor = new ThreadEmailExtractorPromptSummary($this->service);
        $this->assertFalse($extractor->applySummaryClassification($emailId, $extractionId));

        $row = Database::queryOneOrNone("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertTrue($row['status_type'] === null || in_array($row['status_type'], ['unknown', 'UNKNOWN']));
    }

    public function testManualClassificationIsNotOverwritten(): void {
        [$threadId, $emailId] = $this->makeThreadWithEmail('IN');
        ThreadStorageManager::getInstance()->updateEmailClassification(
            $threadId, $emailId, 'REQUEST_REJECTED', 'Avslag', false, '', null);
        $extractionId = $this->makeSummaryExtraction($emailId, 'Dokumentene er vedlagt.');

        $extractor = new ThreadEmailExtractorPromptSummary($this->service);
        $this->assertFalse($extractor->applySummaryClassification($emailId, $extractionId));

        $row = Database::queryOneOrNone("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('REQUEST_REJECTED', $row['status_type']);
    }

    public function testAvslagSummaryMapsToRejected(): void {
        [, $emailId] = $this->makeThreadWithEmail('IN');
        $extractionId = $this->makeSummaryExtraction($emailId, 'Kommunen avslår innsynskravet.');

        $extractor = new ThreadEmailExtractorPromptSummary($this->service);
        $this->assertTrue($extractor->applySummaryClassification($emailId, $extractionId));

        $row = Database::queryOneOrNone("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('REQUEST_REJECTED', $row['status_type']);
    }
}
