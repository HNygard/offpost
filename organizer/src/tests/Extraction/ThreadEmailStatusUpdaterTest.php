<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../bootstrap.php');
require_once(__DIR__ . '/../../class/Extraction/ThreadEmailStatusUpdater.php');
require_once(__DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php');
require_once(__DIR__ . '/../../class/Thread.php');

class ThreadEmailStatusUpdaterTest extends TestCase {
    private $statusUpdater;
    private $extractionService;
    private $thread;
    private $emailId;
    private $entityId = '000000000-test-entity-development';

    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean database tables
        Database::execute("DELETE FROM thread_email_extractions");
        Database::execute("DELETE FROM thread_emails");
        
        // Create a test thread
        $this->thread = new Thread();
        $this->thread->title = "Test Thread for Status Updater";
        $this->thread->my_name = "Test User";
        $this->thread->my_email = "test" . mt_rand(0, 100) . time() . "@example.com";
        $this->thread->labels = [];
        $this->thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $this->thread->sent = false;
        $this->thread->archived = false;
        $this->thread->public = false;
        
        // Use createThread from bootstrap.php
        $this->thread = createThread($this->entityId, $this->thread);
        
        // Create extraction service and status updater
        $this->extractionService = new ThreadEmailExtractionService();
        $this->statusUpdater = new ThreadEmailStatusUpdater($this->extractionService);
    }

    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        parent::tearDown();
    }

    private function createTestEmail(string $statusType = 'unknown', string $autoClassification = null): int {
        $now = new DateTime();
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, description, content, auto_classification) 
            VALUES (?, ?, ?, 'incoming', ?, 'Test status', 'Test description', ?, ?) RETURNING id",
            [
                $this->thread->id,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                $statusType,
                'Test email content',
                $autoClassification
            ]
        );
        return $emailId;
    }

    public function testUpdateFromAISummarySuccess() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Forespørsel om kopi av dokument fra virksomheten";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertTrue($result, 'Update should succeed');
        
        // Verify the email was updated correctly
        $updatedEmail = Database::queryOne(
            "SELECT description, status_text, status_type, auto_classification FROM thread_emails WHERE id = ?",
            [$emailId]
        );
        
        $this->assertEquals($aiSummary, $updatedEmail['description'], 'Description should be updated');
        $this->assertEquals('ASKING_FOR_COPY', $updatedEmail['status_type'], 'Status type should be ASKING_FOR_COPY');
        $this->assertEquals('prompt', $updatedEmail['auto_classification'], 'Auto classification should be set to prompt');
        $this->assertNotEmpty($updatedEmail['status_text'], 'Status text should be generated');
    }

    public function testUpdateFromAISummarySkipsManuallyClassified() {
        // :: Setup - Create manually classified email
        $emailId = $this->createTestEmail('INFORMATION_RELEASE', null); // No auto_classification means manual
        $aiSummary = "Forespørsel om kopi av dokument";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertFalse($result, 'Update should be skipped for manually classified email');
        
        // Verify the email was not updated
        $email = Database::queryOne(
            "SELECT description, status_type, auto_classification FROM thread_emails WHERE id = ?",
            [$emailId]
        );
        
        $this->assertEquals('Test description', $email['description'], 'Description should not be changed');
        $this->assertEquals('INFORMATION_RELEASE', $email['status_type'], 'Status type should not be changed');
        $this->assertNull($email['auto_classification'], 'Auto classification should remain null');
    }

    public function testUpdateFromAISummaryWithEmptyParameters() {
        // :: Setup
        $emailId = $this->createTestEmail();
        
        // :: Act & Assert - Empty email ID
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Email ID and AI summary are required');
        $this->statusUpdater->updateFromAISummary(0, 'Valid summary');
        
        // :: Act & Assert - Empty AI summary
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Email ID and AI summary are required');
        $this->statusUpdater->updateFromAISummary($emailId, '');
    }

    public function testDetermineStatusTypeFromSummaryAskingForCopy() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Vi ber om kopi av dokumenter relatert til saken";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('ASKING_FOR_COPY', $email['status_type']);
    }

    public function testDetermineStatusTypeFromSummaryAskingForMoreTime() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Vi trenger mer tid til å behandle forespørselen din";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('ASKING_FOR_MORE_TIME', $email['status_type']);
    }

    public function testDetermineStatusTypeFromSummaryRequestRejected() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Vi må dessverre avslå forespørselen din";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('REQUEST_REJECTED', $email['status_type']);
    }

    public function testDetermineStatusTypeFromSummaryInformationRelease() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Dokumenter er sendt som vedlegg i denne e-posten";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('INFORMATION_RELEASE', $email['status_type']);
    }

    public function testDetermineStatusTypeFromSummaryUnknown() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $aiSummary = "Dette er en generell e-post uten spesifikt innhold";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $aiSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('unknown', $email['status_type']);
    }

    public function testGenerateStatusTextFromSummaryShort() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $shortSummary = "Kort e-post";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $shortSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_text FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals($shortSummary, $email['status_text'], 'Short summary should be used as-is');
    }

    public function testGenerateStatusTextFromSummaryLong() {
        // :: Setup
        $emailId = $this->createTestEmail();
        $longSummary = "Dette er en veldig lang e-post sammendrag som definitivt er lengre enn femti karakterer og bør bli truncated";
        
        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, $longSummary);
        
        // :: Assert
        $this->assertTrue($result);
        $email = Database::queryOne("SELECT status_text FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertStringEndsWith('...', $email['status_text'], 'Long summary should be truncated');
        $this->assertLessThanOrEqual(50, strlen($email['status_text']), 'Status text should not exceed 50 characters');
    }

    public function testProcessExtractionResultsSuccess() {
        // :: Setup
        // Create test emails
        $emailId1 = $this->createTestEmail();
        $emailId2 = $this->createTestEmail();
        
        // Create extractions
        $extraction1 = $this->extractionService->createExtraction($emailId1, 'Test prompt', 'openai', null, 'thread-email-summary');
        $extraction2 = $this->extractionService->createExtraction($emailId2, 'Test prompt', 'openai', null, 'thread-email-summary');
        
        // Update extractions with results
        $this->extractionService->updateExtractionResults($extraction1->extraction_id, 'Forespørsel om kopi av dokumenter');
        $this->extractionService->updateExtractionResults($extraction2->extraction_id, 'Vi trenger mer tid til behandling');
        
        // :: Act
        $result = $this->statusUpdater->processExtractionResults('thread-email-summary', 10);
        
        // :: Assert
        $this->assertEquals(2, $result['processed'], 'Should process 2 emails');
        $this->assertEquals(0, $result['skipped'], 'Should skip 0 emails');
        $this->assertEquals(0, count($result['errors']), 'Should have 0 errors');
        $this->assertEquals(2, $result['total_found'], 'Should find 2 extraction results');
        
        // Verify emails were updated
        $email1 = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId1]);
        $email2 = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId2]);
        $this->assertEquals('ASKING_FOR_COPY', $email1['status_type']);
        $this->assertEquals('ASKING_FOR_MORE_TIME', $email2['status_type']);
    }

    public function testProcessExtractionResultsWithManuallyClassified() {
        // :: Setup
        // Create one regular email and one manually classified
        $emailId1 = $this->createTestEmail();
        $emailId2 = $this->createTestEmail('INFORMATION_RELEASE', null); // Manually classified
        
        // Create extractions
        $extraction1 = $this->extractionService->createExtraction($emailId1, 'Test prompt', 'openai', null, 'thread-email-summary');
        $extraction2 = $this->extractionService->createExtraction($emailId2, 'Test prompt', 'openai', null, 'thread-email-summary');
        
        // Update extractions with results
        $this->extractionService->updateExtractionResults($extraction1->extraction_id, 'Forespørsel om kopi av dokumenter');
        $this->extractionService->updateExtractionResults($extraction2->extraction_id, 'Dette burde ikke oppdateres');
        
        // :: Act
        $result = $this->statusUpdater->processExtractionResults('thread-email-summary', 10);
        
        // :: Assert
        $this->assertEquals(1, $result['processed'], 'Should process 1 email');
        $this->assertEquals(1, $result['skipped'], 'Should skip 1 manually classified email');
        $this->assertEquals(0, count($result['errors']), 'Should have 0 errors');
    }

    public function testProcessExtractionResultsWithLimit() {
        // :: Setup
        // Create 3 test emails
        $emailIds = [];
        for ($i = 0; $i < 3; $i++) {
            $emailIds[] = $this->createTestEmail();
        }
        
        // Create extractions for all
        foreach ($emailIds as $emailId) {
            $extraction = $this->extractionService->createExtraction($emailId, 'Test prompt', 'openai', null, 'thread-email-summary');
            $this->extractionService->updateExtractionResults($extraction->extraction_id, 'Forespørsel om kopi av dokumenter');
        }
        
        // :: Act with limit of 2
        $result = $this->statusUpdater->processExtractionResults('thread-email-summary', 2);
        
        // :: Assert
        $this->assertEquals(2, $result['processed'], 'Should process only 2 emails due to limit');
        $this->assertEquals(0, $result['skipped'], 'Should skip 0 emails');
        $this->assertEquals(2, $result['total_found'], 'Should find 2 extraction results due to limit');
    }

    public function testProcessExtractionResultsWithErrors() {
        // :: Setup
        $emailId = $this->createTestEmail();
        
        // Create extraction with error
        $extraction = $this->extractionService->createExtraction($emailId, 'Test prompt', 'openai', null, 'thread-email-summary');
        $this->extractionService->updateExtractionResults($extraction->extraction_id, null, 'API Error');
        
        // :: Act
        $result = $this->statusUpdater->processExtractionResults('thread-email-summary', 10);
        
        // :: Assert
        $this->assertEquals(0, $result['processed'], 'Should process 0 emails');
        $this->assertEquals(0, $result['skipped'], 'Should skip 0 emails');
        $this->assertEquals(0, $result['total_found'], 'Should find 0 extraction results (errors are excluded)');
    }

    public function testProcessExtractionResultsWithDifferentPromptId() {
        // :: Setup
        $emailId = $this->createTestEmail();
        
        // Create extraction with different prompt ID
        $extraction = $this->extractionService->createExtraction($emailId, 'Test prompt', 'openai', null, 'different-prompt');
        $this->extractionService->updateExtractionResults($extraction->extraction_id, 'Some summary');
        
        // :: Act
        $result = $this->statusUpdater->processExtractionResults('thread-email-summary', 10);
        
        // :: Assert
        $this->assertEquals(0, $result['total_found'], 'Should find 0 results for different prompt ID');
    }

    public function testConstructorWithDependencyInjection() {
        // :: Setup
        $mockExtractionService = new ThreadEmailExtractionService();
        
        // :: Act
        $statusUpdater = new ThreadEmailStatusUpdater($mockExtractionService);
        
        // :: Assert
        $this->assertInstanceOf(ThreadEmailStatusUpdater::class, $statusUpdater, 'Should create instance with injected dependency');
    }

    public function testConstructorWithoutDependencyInjection() {
        // :: Act
        $statusUpdater = new ThreadEmailStatusUpdater();
        
        // :: Assert
        $this->assertInstanceOf(ThreadEmailStatusUpdater::class, $statusUpdater, 'Should create instance with default dependency');
    }

    public function testMultipleNorwegianKeywordPatterns() {
        // :: Setup & Test various Norwegian keyword patterns
        $testCases = [
            // ASKING_FOR_MORE_TIME patterns
            ['Vi ber om forlenge fristen', 'ASKING_FOR_MORE_TIME'],
            ['Kan vi få utsette behandlingen?', 'ASKING_FOR_MORE_TIME'],
            ['Trenger mer tid for gjennomgang', 'ASKING_FOR_MORE_TIME'],
            
            // ASKING_FOR_COPY patterns
            ['Kan vi få kopi av dokumentene?', 'ASKING_FOR_COPY'],
            ['Send oss kopi av relevante papirer', 'ASKING_FOR_COPY'],
            ['Vi ber om videresending av dokumenter', 'ASKING_FOR_COPY'],
            
            // REQUEST_REJECTED patterns
            ['Vi kan ikke imøtekomme forespørselen', 'REQUEST_REJECTED'],
            ['Forespørselen avslås herved', 'REQUEST_REJECTED'],
            ['Må avvise denne henvendelsen', 'REQUEST_REJECTED'],
            
            // INFORMATION_RELEASE patterns
            ['Informasjon er vedlagt i e-posten', 'INFORMATION_RELEASE'],
            ['Dokumenter sendt som vedlegg', 'INFORMATION_RELEASE'],
            ['Her er de forespurte dokumentene', 'INFORMATION_RELEASE'],
        ];
        
        foreach ($testCases as [$summary, $expectedStatus]) {
            // :: Setup
            $emailId = $this->createTestEmail();
            
            // :: Act
            $result = $this->statusUpdater->updateFromAISummary($emailId, $summary);
            
            // :: Assert
            $this->assertTrue($result, "Update should succeed for: $summary");
            $email = Database::queryOne("SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
            $this->assertEquals($expectedStatus, $email['status_type'], "Wrong status for: $summary");
        }
    }
}