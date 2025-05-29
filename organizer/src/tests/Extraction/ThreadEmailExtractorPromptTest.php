<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractorPrompt.php';
require_once __DIR__ . '/../../class/Extraction/Prompts/PromptService.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../../class/Extraction/Prompts/OpenAiPrompt.php';

// Create a concrete implementation for testing
class TestThreadEmailExtractorPrompt extends ThreadEmailExtractorPrompt {
    protected function getPromptId(): string {
        return 'test_prompt';
    }
}

class TestThreadEmailExtractorPromptMock extends TestThreadEmailExtractorPrompt {
    public $next = null;
    public function setNext($next) {
        $this->next = $next;
    }

    public function findNextEmailForExtraction() {
        return $this->next;
    }
}

class ThreadEmailExtractorPromptTest extends PHPUnit\Framework\TestCase {
    private $extractionService;
    private $promptService;
    private $prompt;
    private $extractor;
    
    protected function setUp(): void {
        // Create mocks for the services
        $this->extractionService = $this->createMock(ThreadEmailExtractionService::class);
        $this->prompt = $this->createMock(OpenAiPrompt::class);
        
        // Configure the prompt mock
        $this->prompt->method('getPromptId')
            ->willReturn('test_prompt');
        $this->prompt->method('getPromptText')
            ->willReturn('This is a test prompt for extracting information from emails');
        $this->prompt->method('getPromptService')
            ->willReturn('openai');
        
        $this->promptService = $this->createMock(PromptService::class);
        $this->promptService->method('getAvailablePrompts')
            ->willReturn(['test_prompt' => $this->prompt]);
        
        // Create the extractor with the mock services
        $this->extractor = new TestThreadEmailExtractorPromptMock(
            $this->extractionService,
            $this->promptService
        );
    }
    
    public function testGetPromptId() {
        // :: Setup
        $reflection = new ReflectionClass(TestThreadEmailExtractorPrompt::class);
        $method = $reflection->getMethod('getPromptId');
        $method->setAccessible(true);
        
        // :: Act
        $result = $method->invoke($this->extractor);
        
        // :: Assert
        $this->assertEquals('test_prompt', $result, 'The prompt ID should be "test_prompt"');
    }
    
    public function testFindNextEmailForExtraction() {
        // :: Setup
        // Not using the mock since we want to run the real query
        $extractor = new TestThreadEmailExtractorPrompt(
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
    
    public function testProcessNextEmailExtractionNoEmails() {
        // :: Setup
        $extractor = $this->extractor;
        $extractor->setNext(null);
        
        // :: Act
        $result = $extractor->processNextEmailExtraction();
        
        // :: Assert
        $this->assertFalse($result['success'], 'Should return success=false when no emails are found');
        $this->assertEquals('No emails found that need extraction', $result['message'], 'Should return appropriate message when no emails are found');
    }
    
    public function testProcessNextEmailExtractionSuccess() {
        // :: Setup
        // Sample email data with source extraction
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email',
            'datetime_received' => '2025-04-21 12:00:00',
            'from_address' => 'sender@example.com',
            'to_address' => 'recipient@example.com',
            'source_extraction_id' => 123,
            'source_extracted_text' => 'This is the extracted text from the email body',
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
        $extraction->prompt_id = 'test_prompt';
        $extraction->prompt_text = 'test_prompt';
        $extraction->prompt_service = 'openai';
        
        // Create a partial mock to override methods
        $extractor = $this->extractor;
        $extractor->setNext($emailData);
        
        // Mocks
        $this->promptService->expects($this->once())
            ->method('run')
            ->with($this->prompt, $this->stringContains('Email Details:'))
            ->willReturn('AI extracted response');
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($emailData['email_id']),
                $this->anything(),
                $this->equalTo('openai'),
                $this->isNull(),
                $this->equalTo('test_prompt')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('AI extracted response')
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
        $this->assertEquals(strlen('AI extracted response'), $result['extracted_text_length'], 'Extracted text length should match');
    }
    
    public function testProcessNextEmailExtractionError() {
        // :: Setup
        // Sample email data with source extraction
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email',
            'datetime_received' => '2025-04-21 12:00:00',
            'from_address' => 'sender@example.com',
            'to_address' => 'recipient@example.com',
            'source_extraction_id' => 123,
            'source_extracted_text' => 'This is the extracted text from the email body',
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
        $extraction->prompt_id = 'test_prompt';
        $extraction->prompt_text = 'test_prompt';
        $extraction->prompt_service = 'openai';
        
        // Create a partial mock to override methods
        $extractor = $this->extractor;
        $extractor->setNext($emailData);
        
        // Mocks
        $exception = new \Exception('AI processing error');
        $this->promptService->expects($this->once())
            ->method('run')
            ->will($this->throwException($exception));
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($emailData['email_id']),
                $this->anything(),
                $this->equalTo('openai'),
                $this->isNull(),
                $this->equalTo('test_prompt')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo(null),
                $this->equalTo(jTraceEx($exception))
            )
            ->willReturn($extraction);
        
        // :: Act
        $result = $extractor->processNextEmailExtraction();
        
        // :: Assert
        $this->assertFalse($result['success'], 'Extraction should fail when an exception is thrown');
        $this->assertEquals('Failed to extract text from email.', $result['message'], 'Message should indicate extraction failure');
        $this->assertEquals($emailData['email_id'], $result['email_id'], 'Email ID should match');
        $this->assertEquals($emailData['thread_id'], $result['thread_id'], 'Thread ID should match');
        $this->assertEquals('AI processing error', $result['error'], 'Error message should match the exception message');
    }
    
    public function testPreparePromptInput() {
        // :: Setup
        // Create a reflection of the class to access protected methods
        $reflection = new ReflectionClass(TestThreadEmailExtractorPrompt::class);
        $method = $reflection->getMethod('preparePromptInput');
        $method->setAccessible(true);
        
        // Sample email data with source extraction
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'datetime_received' => '2025-04-21 12:00:00',
            'source_extraction_id' => 123,
            'source_extracted_text' => 'This is the extracted text from the email body',
            'source_prompt_text' => 'email_body',
            'source_attachment_id' => null,
            'thread_title' => 'Test Thread',
            'thread_entity_id' => 'test-entity-id',
            'thread_my_name' => 'Test User',
            'thread_my_email' => 'test@example.com'
        ];
        
        // :: Act
        $result = $method->invoke($this->extractor, $emailData);
        
        // :: Assert
        $this->assertStringContainsString('Thread Details:', $result, 'Output should contain thread details section');
        $this->assertStringContainsString('- Thread title: Test Thread', $result, 'Output should contain thread title');
        $this->assertStringContainsString('Email Details:', $result, 'Output should contain email details section');
        $this->assertStringContainsString('- Date: 2025-04-21 12:00:00', $result, 'Output should contain email date');
        $this->assertStringContainsString('- Direction: IN', $result, 'Output should contain email direction');
        $this->assertStringContainsString('- Source: Email body', $result, 'Output should indicate source is email body');
        $this->assertStringContainsString('This is the extracted text from the email body', $result, 'Output should contain the extracted text');
    }
}
