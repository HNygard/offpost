<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../bootstrap.php');
require_once(__DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php');
require_once(__DIR__ . '/../../class/Thread.php');

class ThreadEmailExtractionServiceTest extends TestCase {
    private $service;
    private $thread;
    private $emailId;
    private $attachmentId;
    private $entityId = '000000000-test-entity-development';

    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean database tables
        Database::execute("DELETE FROM thread_email_extractions");
        
        // Create a test thread
        $this->thread = new Thread();
        $this->thread->title = "Test Thread for Email Extraction";
        $this->thread->my_name = "Test User";
        $this->thread->my_email = "test" . mt_rand(0, 100) . time() . "@example.com";
        $this->thread->labels = [];
        $this->thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $this->thread->sent = false;
        $this->thread->archived = false;
        $this->thread->public = false;
        
        // Use createThread from bootstrap.php
        $this->thread = createThread($this->entityId, $this->thread);
        
        // Create a test email for the thread
        $now = new DateTime();
        $this->emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, description, content) 
            VALUES (?, ?, ?, 'incoming', 'received', 'Test status', 'Test description', ?) RETURNING id",
            [
                $this->thread->id,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                'Test email content'
            ]
        );
        
        // Create a test attachment for the email
        $this->attachmentId = Database::queryValue(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, status_type, status_text, location) 
            VALUES (?, 'test.pdf', 'test.pdf', 'application/pdf', 'info', 'Test attachment status', '/test/path/test.pdf') RETURNING id",
            [$this->emailId]
        );
        
        $this->service = new ThreadEmailExtractionService();
    }

    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        parent::tearDown();
    }

    public function testCreateExtraction() {
        // :: Setup
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        // :: Act
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService);
        
        // :: Assert
        $this->assertNotNull($extraction, 'Extraction should not be null');
        $this->assertNotNull($extraction->extraction_id, 'Extraction ID should be set');
        $this->assertEquals($this->emailId, $extraction->email_id, 'Email ID should match');
        $this->assertEquals($promptText, $extraction->prompt_text, 'Prompt text should match');
        $this->assertEquals($promptService, $extraction->prompt_service, 'Prompt service should match');
        $this->assertNull($extraction->attachment_id, 'Attachment ID should be null');
        $this->assertNull($extraction->prompt_id, 'Prompt ID should be null');
        $this->assertNull($extraction->extracted_text, 'Extracted text should be null');
        $this->assertNull($extraction->error_message, 'Error message should be null');
        $this->assertNotNull($extraction->created_at, 'Created at should be set');
        $this->assertNotNull($extraction->updated_at, 'Updated at should be set');
    }

    public function testCreateExtractionWithAttachment() {
        // :: Setup
        $promptText = 'Extract text from this PDF';
        $promptService = 'azure';
        $promptId = 'pdf-extraction-v1';
        
        // :: Act
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService, $this->attachmentId, $promptId);
        
        // :: Assert
        $this->assertNotNull($extraction, 'Extraction should not be null');
        $this->assertEquals($this->emailId, $extraction->email_id, 'Email ID should match');
        $this->assertEquals($this->attachmentId, $extraction->attachment_id, 'Attachment ID should match');
        $this->assertEquals($promptId, $extraction->prompt_id, 'Prompt ID should match');
        $this->assertEquals($promptText, $extraction->prompt_text, 'Prompt text should match');
        $this->assertEquals($promptService, $extraction->prompt_service, 'Prompt service should match');
    }

    public function testCreateExtractionWithInvalidEmail() {
        // :: Setup
        $invalidEmailId = '00000000-0000-0000-0000-000000000000';
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error creating extraction');
        
        $this->service->createExtraction($invalidEmailId, $promptText, $promptService);
    }

    public function testCreateExtractionWithMissingParameters() {
        // :: Act & Assert - Missing prompt text
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Prompt text is required');
        $this->service->createExtraction($this->emailId, '', 'openai');
        
        // :: Act & Assert - Missing prompt service
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Prompt service is required');
        $this->service->createExtraction($this->emailId, 'Extract text', '');
    }

    public function testUpdateExtractionResults() {
        // :: Setup
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService);
        $extractionId = $extraction->extraction_id;
        
        $extractedText = 'This is the extracted text from the email.';
        
        // :: Act
        $updatedExtraction = $this->service->updateExtractionResults($extractionId, $extractedText);
        
        // :: Assert
        $this->assertEquals($extractionId, $updatedExtraction->extraction_id, 'Extraction ID should match');
        $this->assertEquals($this->emailId, $updatedExtraction->email_id, 'Email ID should match');
        $this->assertEquals($promptText, $updatedExtraction->prompt_text, 'Prompt text should match');
        $this->assertEquals($promptService, $updatedExtraction->prompt_service, 'Prompt service should match');
        $this->assertEquals($extractedText, $updatedExtraction->extracted_text, 'Extracted text should match');
        $this->assertNull($updatedExtraction->error_message, 'Error message should be null');
    }

    public function testUpdateExtractionResultsWithError() {
        // :: Setup
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService);
        $extractionId = $extraction->extraction_id;
        
        $errorMessage = 'Failed to extract text: API error';
        
        // :: Act
        $updatedExtraction = $this->service->updateExtractionResults($extractionId, null, $errorMessage);
        
        // :: Assert
        $this->assertEquals($extractionId, $updatedExtraction->extraction_id, 'Extraction ID should match');
        $this->assertNull($updatedExtraction->extracted_text, 'Extracted text should be null');
        $this->assertEquals($errorMessage, $updatedExtraction->error_message, 'Error message should match');
    }

    public function testUpdateExtractionResultsWithInvalidId() {
        // :: Setup
        $invalidExtractionId = 9999;
        $extractedText = 'This is the extracted text from the email.';
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expected 1 row, got 0');
        $this->service->updateExtractionResults($invalidExtractionId, $extractedText);
    }

    public function testUpdateExtractionResultsWithMissingParameters() {
        // :: Setup
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService);
        $extractionId = $extraction->extraction_id;
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Either extracted text or error message must be provided');
        
        $this->service->updateExtractionResults($extractionId, null, null);
    }

    public function testGetExtractionById() {
        // :: Setup
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService);
        $extractionId = $extraction->extraction_id;
        
        // :: Act
        $retrievedExtraction = $this->service->getExtractionById($extractionId);
        
        // :: Assert
        $this->assertNotNull($retrievedExtraction, 'Retrieved extraction should not be null');
        $this->assertEquals($extractionId, $retrievedExtraction->extraction_id, 'Extraction ID should match');
        $this->assertEquals($this->emailId, $retrievedExtraction->email_id, 'Email ID should match');
        $this->assertEquals($promptText, $retrievedExtraction->prompt_text, 'Prompt text should match');
        $this->assertEquals($promptService, $retrievedExtraction->prompt_service, 'Prompt service should match');
    }

    public function testGetExtractionByIdWithInvalidId() {
        // :: Setup
        $invalidExtractionId = 9999;
        
        // :: Act
        $retrievedExtraction = $this->service->getExtractionById($invalidExtractionId);
        
        // :: Assert
        $this->assertNull($retrievedExtraction, 'Retrieved extraction should be null for invalid ID');
    }

    public function testGetExtractionsForEmail() {
        // :: Setup
        // Create multiple extractions for the same email
        $this->service->createExtraction($this->emailId, 'Extract subject', 'openai');
        $this->service->createExtraction($this->emailId, 'Extract sender info', 'azure');
        $this->service->createExtraction($this->emailId, 'Extract dates', 'openai');
        
        // :: Act
        $extractions = $this->service->getExtractionsForEmail($this->emailId);
        
        // :: Assert
        $this->assertCount(3, $extractions, 'Should retrieve 3 extractions for the email');
        $this->assertEquals($this->emailId, $extractions[0]->email_id, 'Email ID should match');
        
        // Verify all extractions are present (order may vary in test environment)
        $promptTexts = array_map(function($extraction) { return $extraction->prompt_text; }, $extractions);
        $this->assertContains('Extract dates', $promptTexts, 'Should contain "Extract dates" prompt');
        $this->assertContains('Extract sender info', $promptTexts, 'Should contain "Extract sender info" prompt');
        $this->assertContains('Extract subject', $promptTexts, 'Should contain "Extract subject" prompt');
    }

    public function testGetExtractionsForEmailWithNoExtractions() {
        // :: Setup
        $emailId = '550e8400-e29b-41d4-a716-446655440002'; // No extractions for this email
        
        // :: Act
        $extractions = $this->service->getExtractionsForEmail($emailId);
        
        // :: Assert
        $this->assertCount(0, $extractions, 'Should retrieve 0 extractions for the email');
    }

    public function testGetExtractionsForAttachment() {
        // :: Setup
        // Use the email ID created in setUp
        
        // Get the actual attachment ID from the database
        $attachmentResult = Database::query("SELECT id FROM thread_email_attachments WHERE email_id = ? LIMIT 1", [$this->emailId]);
        $attachmentId = $attachmentResult[0]['id'];
        
        // Create multiple extractions for the same attachment
        $this->service->createExtraction($this->emailId, 'Extract PDF text', 'openai', $attachmentId);
        $this->service->createExtraction($this->emailId, 'Extract PDF tables', 'azure', $attachmentId);
        
        // :: Act
        $extractions = $this->service->getExtractionsForAttachment($attachmentId);
        
        // :: Assert
        $this->assertCount(2, $extractions, 'Should retrieve 2 extractions for the attachment');
        $this->assertEquals($attachmentId, $extractions[0]->attachment_id, 'Attachment ID should match');
        
        // Verify all extractions are present (order may vary in test environment)
        $promptTexts = array_map(function($extraction) { return $extraction->prompt_text; }, $extractions);
        $this->assertContains('Extract PDF tables', $promptTexts, 'Should contain "Extract PDF tables" prompt');
        $this->assertContains('Extract PDF text', $promptTexts, 'Should contain "Extract PDF text" prompt');
    }

    public function testGetExtractionsForAttachmentWithNoExtractions() {
        // :: Setup
        $attachmentId = '00000000-0000-0000-0000-000000000000'; // No extractions for this attachment
        
        // :: Act
        $extractions = $this->service->getExtractionsForAttachment($attachmentId);
        
        // :: Assert
        $this->assertCount(0, $extractions, 'Should retrieve 0 extractions for the attachment');
    }

    public function testDeleteExtraction() {
        // :: Setup
        // Use the email ID created in setUp
        $promptText = 'Extract key information from this email';
        $promptService = 'openai';
        
        $extraction = $this->service->createExtraction($this->emailId, $promptText, $promptService);
        $extractionId = $extraction->extraction_id;
        
        // :: Act
        $result = $this->service->deleteExtraction($extractionId);
        
        // :: Assert
        $this->assertTrue($result, 'Delete should return true for successful deletion');
        $this->assertNull($this->service->getExtractionById($extractionId), 'Extraction should no longer exist');
    }

    public function testDeleteExtractionWithInvalidId() {
        // :: Setup
        $invalidExtractionId = 9999;
        
        // :: Act
        $result = $this->service->deleteExtraction($invalidExtractionId);
        
        // :: Assert
        $this->assertFalse($result, 'Delete should return false for non-existent extraction');
    }
}
