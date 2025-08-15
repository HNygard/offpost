<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractorPromptCopyAskingFor.php';
require_once __DIR__ . '/../../class/Extraction/Prompts/PromptService.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../../class/Extraction/Prompts/CopyAskingForPrompt.php';

class ThreadEmailExtractorPromptCopyAskingForMock extends ThreadEmailExtractorPromptCopyAskingFor {
    public $next = null;
    public function setNext($next) {
        $this->next = $next;
    }

    public function findNextEmailForExtraction() {
        return $this->next;
    }
    
    public function enrichEmailWithDetails($email) {
        // For tests, return the email data with required email detail fields
        return array_merge($email, [
            'email_subject' => 'Test Subject',
            'email_from_address' => 'test@example.com',
            'email_to_addresses' => ['recipient@example.com'],
            'email_cc_addresses' => []
        ]);
    }
}
class ThreadEmailExtractorPromptCopyAskingForTest extends PHPUnit\Framework\TestCase {
    private $extractionService;
    private $promptService;
    private $prompt;
    private $extractor;
    
    protected function setUp(): void {
        // Create mocks for the services
        $this->extractionService = $this->createMock(ThreadEmailExtractionService::class);
        $this->prompt = new CopyAskingForPrompt();
        $this->promptService = $this->createMock(PromptService::class);
        $this->promptService->method('getAvailablePrompts')
            ->willReturn(['copy-asking-for' => $this->prompt]);
        
        // Create the extractor with the mock services
        $this->extractor = new ThreadEmailExtractorPromptCopyAskingForMock(
            $this->extractionService,
            $this->promptService
        );
    }
    
    public function testGetPromptId() {
        // :: Setup
        $reflection = new ReflectionClass(ThreadEmailExtractorPromptCopyAskingFor::class);
        $method = $reflection->getMethod('getPromptId');
        $method->setAccessible(true);
        
        // :: Act
        $result = $method->invoke($this->extractor);
        
        // :: Assert
        $this->assertEquals('copy-asking-for', $result, 'The prompt ID should be "copy-asking-for"');
    }
    
    public function testFindNextEmailForExtraction() {
        // :: Setup
        // Not using the mock since we want to run the real query
        $extractor = new ThreadEmailExtractorPromptCopyAskingFor(
            $this->extractionService,
            $this->promptService
        );
        
        // :: Act
        $result = $extractor->findNextEmailForExtraction();
        
        // :: Assert
        if ($result == null) {
            // No result in DB.
            $this->assertNull($result);
        }
        else {
            // Some random result in DB.
            $this->assertIsArray($result, 'Result should be an array');
            $this->assertArrayHasKey('email_id', $result, 'Result should contain email_id');
            $this->assertArrayHasKey('thread_id', $result, 'Result should contain thread_id');
        }
    }
    
    public function testProcessNextEmailExtractionSuccess() {
        // Sample email data with source extraction
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email requesting copy',
            'datetime_received' => '2025-04-21 12:00:00',
            'from_address' => 'sender@example.com',
            'to_address' => 'recipient@example.com',
            'source_extraction_id' => 123,
            'source_extracted_text' => 'We have not received the email. Can we get a copy?',
            'source_prompt_text' => 'email_body',
            'source_attachment_id' => null,
            'thread_title' => 'Test Thread',
            'thread_entity_id' => 'test-entity-id',
            'thread_my_name' => 'Test User',
            'thread_my_email' => 'test@example.com'
        ];
        
        // Sample extraction
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = 456;
        $extraction->email_id = $emailData['email_id'];
        $extraction->prompt_id = 'copy-asking-for';
        $extraction->prompt_text = 'copy-asking-for';
        $extraction->prompt_service = 'openai';
        
        // Create a partial mock to override methods
        $extractor = $this->extractor;
        $extractor->setNext($emailData);
        
        
        // Mocks
        $this->promptService->expects($this->once())
            ->method('run')
            ->with($this->prompt, $this->stringContains('Email Details:'))
            ->willReturn('{"is_requesting_copy": true, "copy_request_description": "copy of the initial request"}');
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($emailData['email_id']),
                $this->stringContains('The task is to determine whether the sender is explicitly asking for a copy'),
                // $promptService
                $this->equalTo('openai'),
                // $attachmentId
                $this->isNull(),
                // $promptId
                $this->equalTo('copy-asking-for')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('{"is_requesting_copy": true, "copy_request_description": "copy of the initial request"}')
            )
            ->willReturn($extraction);
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertTrue($result['success'], 'Extraction should be successful. Result: ' . json_encode($result));
        $this->assertEquals('Successfully extracted text from email', $result['message']);
        $this->assertEquals($emailData['email_id'], $result['email_id']);
        $this->assertEquals($emailData['thread_id'], $result['thread_id']);
        $this->assertEquals($extraction->extraction_id, $result['extraction_id']);
    }
    
    public function testProcessNextEmailExtractionNoCopyAskingFor() {
        // :: Setup
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email without copy request',
            'datetime_received' => '2025-04-21 12:00:00',
            'subject' => 'Test Subject without copy request',
            'from_address' => 'sender@example.com',
            'to_address' => 'recipient@example.com',
            'source_extraction_id' => 123,
            'source_extracted_text' => 'We have not received the email.',
            'source_prompt_text' => 'email_body',
            'source_attachment_id' => null,
            'thread_title' => 'Test Thread',
            'thread_entity_id' => 'test-entity-id',
            'thread_my_name' => 'Test User',
            'thread_my_email' => 'test@example.com'
        ];
        
        // Sample extraction
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = 456;
        $extraction->email_id = $emailData['email_id'];
        $extraction->prompt_id = 'copy-asking-for';
        $extraction->prompt_text = 'copy-asking-for';
        $extraction->prompt_service = 'openai';
        
        // Create a partial mock to override methods
        $extractor = $this->extractor;
        $extractor->setNext($emailData);
        
        // Mocks
        $this->promptService->expects($this->once())
            ->method('run')
            ->with($this->prompt, $this->stringContains('Email Details:'))
            ->willReturn('{"is_requesting_copy": false, "copy_request_description": ""}');
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($emailData['email_id']),
                $this->stringContains('The task is to determine whether the sender is explicitly asking for a copy'),
                $this->equalTo('openai'),
                $this->isNull(),
                $this->equalTo('copy-asking-for')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('{"is_requesting_copy": false, "copy_request_description": ""}')
            )
            ->willReturn($extraction);
        
        // :: Act
        $result = $extractor->processNextEmailExtraction();
        
        // :: Assert
        $this->assertTrue($result['success'], 'Extraction should be successful. Result: ' . json_encode($result));
        $this->assertEquals('Successfully extracted text from email', $result['message'], 'Message should indicate successful extraction');
        $this->assertEquals($emailData['email_id'], $result['email_id'], 'Email ID should match');
        $this->assertEquals($emailData['thread_id'], $result['thread_id'], 'Thread ID should match');
        $this->assertEquals($extraction->extraction_id, $result['extraction_id'], 'Extraction ID should match');
    }
}
