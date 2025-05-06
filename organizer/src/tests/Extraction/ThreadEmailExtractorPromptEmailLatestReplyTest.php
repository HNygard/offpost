<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractorPromptEmailLatestReply.php';
require_once __DIR__ . '/../../class/Extraction/Prompts/PromptService.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../../class/Extraction/Prompts/EmailLatestReplyPrompt.php';

class ThreadEmailExtractorPromptEmailLatestReplyMock extends ThreadEmailExtractorPromptEmailLatestReply {
    public $next = null;
    public function setNext($next) {
        $this->next = $next;
    }

    public function findNextEmailForExtraction() {
        return $this->next;
    }
}

class ThreadEmailExtractorPromptEmailLatestReplyTest extends PHPUnit\Framework\TestCase {
    private $extractionService;
    private $promptService;
    private $prompt;
    private $extractor;
    
    protected function setUp(): void {
        // Create mocks for the services
        $this->extractionService = $this->createMock(ThreadEmailExtractionService::class);
        $this->prompt = new EmailLatestReplyPrompt();
        $this->promptService = $this->createMock(PromptService::class);
        $this->promptService->method('getAvailablePrompts')
            ->willReturn(['email-latest-reply' => $this->prompt]);
        
        // Create the extractor with the mock services
        $this->extractor = new ThreadEmailExtractorPromptEmailLatestReplyMock(
            $this->extractionService,
            $this->promptService
        );
    }
    
    public function testGetPromptId() {
        // :: Setup
        $reflection = new ReflectionClass(ThreadEmailExtractorPromptEmailLatestReply::class);
        $method = $reflection->getMethod('getPromptId');
        $method->setAccessible(true);
        
        // :: Act
        $result = $method->invoke($this->extractor);
        
        // :: Assert
        $this->assertEquals('email-latest-reply', $result, 'The prompt ID should be "email-latest-reply"');
    }
    
    public function testFindNextEmailForExtraction() {
        // :: Setup
        // Not using the mock since we want to run the real query
        $extractor = new ThreadEmailExtractorPromptEmailLatestReply(
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
        // :: Setup
        // Sample email data with source extraction
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => 'unknown',
            'status_text' => 'Test email with latest reply',
            'datetime_received' => '2025-04-21 12:00:00',
            'from_address' => 'sender@example.com',
            'to_address' => 'recipient@example.com',
            'source_extraction_id' => 123,
            'source_extracted_text' => "> Ser deg i morgen\n\nSer deg i morgen ja",
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
        $extraction->prompt_id = 'email-latest-reply';
        $extraction->prompt_text = 'email-latest-reply';
        $extraction->prompt_service = 'openai';
        
        // Create a partial mock to override methods
        $extractor = $this->extractor;
        $extractor->setNext($emailData);
        
        // Mocks
        $this->promptService->expects($this->once())
            ->method('run')
            ->with($this->prompt, $this->stringContains('Email Details:'))
            ->willReturn('Ser deg i morgen ja');
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($emailData['email_id']),
                $this->stringContains('The task is to find the text in the email.'),
                $this->equalTo('openai'),
                $this->isNull(),
                $this->equalTo('email-latest-reply')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('Ser deg i morgen ja')
            )
            ->willReturn($extraction);
        
        // :: Act
        $result = $extractor->processNextEmailExtraction();
        
        // :: Assert
        $this->assertTrue($result['success'], 'Extraction should be successful. Result: ' . json_encode($result));
        $this->assertEquals('Successfully extracted text from email', $result['message']);
        $this->assertEquals($emailData['email_id'], $result['email_id']);
        $this->assertEquals($emailData['thread_id'], $result['thread_id']);
        $this->assertEquals($extraction->extraction_id, $result['extraction_id']);
    }
    
    public function testProcessNextEmailExtractionNoLatestReply() {
        // :: Setup
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => 'unknown',
            'status_text' => 'Test email without latest reply',
            'datetime_received' => '2025-04-21 12:00:00',
            'subject' => 'Test Subject without latest reply',
            'from_address' => 'sender@example.com',
            'to_address' => 'recipient@example.com',
            'source_extraction_id' => 123,
            'source_extracted_text' => 'This is just a forwarded message without any new content',
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
        $extraction->prompt_id = 'email-latest-reply';
        $extraction->prompt_text = 'email-latest-reply';
        $extraction->prompt_service = 'openai';
        
        // Create a partial mock to override methods
        $extractor = $this->extractor;
        $extractor->setNext($emailData);
        
        // Mocks
        $this->promptService->expects($this->once())
            ->method('run')
            ->with($this->prompt, $this->stringContains('Email Details:'))
            ->willReturn('');
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($emailData['email_id']),
                $this->stringContains('The task is to find the text in the email.'),
                $this->equalTo('openai'),
                $this->isNull(),
                $this->equalTo('email-latest-reply')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('')
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
    
    public function testProcessNextEmailExtractionError() {
        // :: Setup
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'email_type' => 'IN',
            'status_type' => 'unknown',
            'status_text' => 'Test email with error',
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
        $extraction->prompt_id = 'email-latest-reply';
        $extraction->prompt_text = 'email-latest-reply';
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
                $this->stringContains('The task is to find the text in the email.'),
                $this->equalTo('openai'),
                $this->isNull(),
                $this->equalTo('email-latest-reply')
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
}
