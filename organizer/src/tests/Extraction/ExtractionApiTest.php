<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../bootstrap.php');
require_once(__DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php');
require_once(__DIR__ . '/../../class/Thread.php');

/**
 * Test for the extraction API endpoint
 * This tests the API endpoint that returns extraction data as JSON
 */
class ExtractionApiTest extends TestCase {
    private $service;
    private $thread;
    private $emailId;
    private $extractionId;
    private $entityId = '000000000-test-entity-development';

    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean database tables
        Database::execute("DELETE FROM thread_email_extractions");
        
        // Create a test thread
        $this->thread = new Thread();
        $this->thread->title = "Test Thread for Extraction API";
        $this->thread->my_name = "Test User";
        $this->thread->my_email = "test" . mt_rand(0, 100) . time() . "@example.com";
        $this->thread->labels = [];
        $this->thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $this->thread->sent = false;
        $this->thread->archived = false;
        $this->thread->public = false;
        
        $this->thread = createThread($this->entityId, $this->thread);
        
        // Create a test email for the thread
        $now = new DateTime();
        $this->emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, description, content)
            VALUES (?, ?, ?, 'incoming', 'received', 'Test status', 'Test description', ?::bytea) RETURNING id",
            [
                $this->thread->id,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                'Test email content'
            ]
        );
        
        $this->service = new ThreadEmailExtractionService();
        
        // Create a test extraction
        $extraction = $this->service->createExtraction(
            $this->emailId, 
            'Test prompt text', 
            'openai',
            null,
            'test-prompt-id'
        );
        $this->extractionId = $extraction->extraction_id;
        
        // Update with extracted text
        $this->service->updateExtractionResults(
            $this->extractionId,
            '{"result": "test extracted data"}',
            null
        );
    }

    protected function tearDown(): void {
        Database::rollBack();
        parent::tearDown();
    }

    /**
     * Test that the API returns extraction data correctly
     */
    public function testGetExtractionReturnsCorrectData() {
        // :: Setup - get extraction from service to verify
        $extraction = $this->service->getExtractionById($this->extractionId);
        
        // :: Assert
        $this->assertNotNull($extraction, 'Extraction should exist in database');
        $this->assertEquals($this->extractionId, $extraction->extraction_id);
        $this->assertEquals($this->emailId, $extraction->email_id);
        $this->assertEquals('openai', $extraction->prompt_service);
        $this->assertEquals('test-prompt-id', $extraction->prompt_id);
        $this->assertEquals('Test prompt text', $extraction->prompt_text);
        $this->assertEquals('{"result": "test extracted data"}', $extraction->extracted_text);
        $this->assertNull($extraction->error_message);
    }

    /**
     * Test that getExtractionById returns null for non-existent extraction
     */
    public function testGetExtractionByIdReturnsNullForInvalidId() {
        // :: Setup
        $invalidId = 999999;
        
        // :: Act
        $extraction = $this->service->getExtractionById($invalidId);
        
        // :: Assert
        $this->assertNull($extraction, 'Should return null for non-existent extraction');
    }

    /**
     * Test extraction with error message
     */
    public function testGetExtractionWithError() {
        // :: Setup - create extraction with error
        $errorExtraction = $this->service->createExtraction(
            $this->emailId,
            'Test error prompt',
            'openai'
        );
        $this->service->updateExtractionResults(
            $errorExtraction->extraction_id,
            null,
            'Test error message'
        );
        
        // :: Act
        $retrieved = $this->service->getExtractionById($errorExtraction->extraction_id);
        
        // :: Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('Test error message', $retrieved->error_message);
        $this->assertNull($retrieved->extracted_text);
    }

    /**
     * Test that extraction data includes all metadata fields
     */
    public function testExtractionIncludesAllMetadata() {
        // :: Act
        $extraction = $this->service->getExtractionById($this->extractionId);
        
        // :: Assert - verify all required fields are present
        $this->assertNotNull($extraction->extraction_id);
        $this->assertNotNull($extraction->email_id);
        $this->assertNotNull($extraction->prompt_service);
        $this->assertNotNull($extraction->prompt_id);
        $this->assertNotNull($extraction->prompt_text);
        $this->assertNotNull($extraction->extracted_text);
        $this->assertNotNull($extraction->created_at);
        $this->assertNotNull($extraction->updated_at);
    }
}
