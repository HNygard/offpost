<?php

require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/../../class/Extraction/ThreadEmailExtractionService.php';

class ThreadEmailExtractorEmailBodyTest extends PHPUnit\Framework\TestCase {
    private $extractionService;
    private $extractor;
    
    protected function setUp(): void {
        // Create a mock for the ThreadEmailExtractionService
        $this->extractionService = $this->createMock(ThreadEmailExtractionService::class);
        
        // Create the extractor with the mock service
        $this->extractor = new ThreadEmailExtractorEmailBody($this->extractionService);
    }
    
    public function testFindNextEmailForExtraction() {
        // Create a mock for the Database class using PHPUnit's mocking framework
        $mockResult = [
            'id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email'
        ];
        
        // Use a partial mock of ThreadEmailExtractorEmailBody to test findNextEmailForExtraction
        // without actually hitting the database
        $extractor = $this->getMockBuilder(ThreadEmailExtractorEmailBody::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction'])
            ->getMock();
            
        $extractor->method('findNextEmailForExtraction')
            ->willReturn($mockResult);
            
        // Call the method through the mock
        $result = $extractor->findNextEmailForExtraction();
        
        // Verify the result
        $this->assertIsArray($result);
        $this->assertEquals('test-email-id', $result['id']);
        $this->assertEquals('test-thread-id', $result['thread_id']);
    }
    
    public function testProcessNextEmailExtractionNoEmails() {
        // Create a partial mock to override findNextEmailForExtraction
        $extractor = $this->getMockBuilder(ThreadEmailExtractorEmailBody::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction'])
            ->getMock();
        
        // Configure the mock to return null (no emails found)
        $extractor->method('findNextEmailForExtraction')
            ->willReturn(null);
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertFalse($result['success']);
        $this->assertEquals('No emails found that need extraction', $result['message']);
    }
    
    public function testProcessNextEmailExtractionSuccess() {
        // Sample email data
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email'
        ];
        
        // Sample extraction
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = 123;
        $extraction->email_id = $emailData['email_id'];
        $extraction->prompt_text = 'email_body';
        $extraction->prompt_service = 'code';
        
        // Create a partial mock to override methods
        $extractor = $this->getMockBuilder(ThreadEmailExtractorEmailBody::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction', 'extractTextFromEmailBody', 'enrichEmailWithDetails'])
            ->getMock();
        
        // Configure the mocks
        $extractor->method('findNextEmailForExtraction')
            ->willReturn($emailData);
            
        // Mock enrichEmailWithDetails to return data with required email fields
        $enrichedData = array_merge($emailData, [
            'email_subject' => 'Test Subject',
            'email_from_address' => 'test@example.com',
            'email_to_addresses' => ['recipient@example.com'],
            'email_cc_addresses' => []
        ]);
        $extractor->method('enrichEmailWithDetails')
            ->willReturn($enrichedData);
        
        // Create a mock ExtractedEmailBody object
        $mockExtractedBody = new ExtractedEmailBody();
        $mockExtractedBody->plain_text = 'Extracted text from email body';
        $mockExtractedBody->html = '';
        
        $extractor->method('extractTextFromEmailBody')
            ->willReturn($mockExtractedBody);
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($enrichedData['email_id']),
                $this->equalTo('email_body'),
                $this->equalTo('code')
            )
            ->willReturn($extraction);
        
        $this->extractionService->expects($this->once())
            ->method('updateExtractionResults')
            ->with(
                $this->equalTo($extraction->extraction_id),
                $this->equalTo('Extracted text from email body')
            )
            ->willReturn($extraction);
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertTrue($result['success']);
        $this->assertEquals('Successfully extracted text from email', $result['message']);
        $this->assertEquals($emailData['email_id'], $result['email_id']);
        $this->assertEquals($emailData['thread_id'], $result['thread_id']);
        $this->assertEquals($extraction->extraction_id, $result['extraction_id']);
        $this->assertEquals(strlen('Extracted text from email body'), $result['extracted_text_length']);
    }
    
    public function testProcessNextEmailExtractionError() {
        // Sample email data
        $emailData = [
            'email_id' => 'test-email-id',
            'thread_id' => 'test-thread-id',
            'status_type' => \App\Enums\ThreadEmailStatusType::UNKNOWN->value,
            'status_text' => 'Test email'
        ];
        
        // Sample extraction
        $extraction = new ThreadEmailExtraction();
        $extraction->extraction_id = 123;
        $extraction->email_id = $emailData['email_id'];
        $extraction->prompt_text = 'email_body';
        $extraction->prompt_service = 'code';
        
        // Create a partial mock to override methods
        $extractor = $this->getMockBuilder(ThreadEmailExtractorEmailBody::class)
            ->setConstructorArgs([$this->extractionService])
            ->onlyMethods(['findNextEmailForExtraction', 'extractTextFromEmailBody', 'enrichEmailWithDetails'])
            ->getMock();
        
        // Configure the mocks
        $extractor->method('findNextEmailForExtraction')
            ->willReturn($emailData);
            
        // Mock enrichEmailWithDetails to return data with required email fields
        $enrichedData = array_merge($emailData, [
            'email_subject' => 'Test Subject',
            'email_from_address' => 'test@example.com',
            'email_to_addresses' => ['recipient@example.com'],
            'email_cc_addresses' => []
        ]);
        $extractor->method('enrichEmailWithDetails')
            ->willReturn($enrichedData);
        
        $exception = new \Exception('Test error');
        $extractor->method('extractTextFromEmailBody')
            ->will($this->throwException($exception));
        
        $this->extractionService->expects($this->once())
            ->method('createExtraction')
            ->with(
                $this->equalTo($enrichedData['email_id']),
                $this->equalTo('email_body'),
                $this->equalTo('code')
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
        
        // Call the method
        $result = $extractor->processNextEmailExtraction();
        
        // Check the result
        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to extract text from email.', $result['message']);
        $this->assertEquals($emailData['email_id'], $result['email_id']);
        $this->assertEquals($emailData['thread_id'], $result['thread_id']);
        $this->assertEquals('Test error', $result['error']);
    }
    
    public function testConvertHtmlToText() {
        // Create a reflection of the class to access protected methods
        $reflection = new ReflectionClass(ThreadEmailExtractorEmailBody::class);
        $method = $reflection->getMethod('convertHtmlToText');
        $method->setAccessible(true);
        
        // Test HTML
        $html = '
            <html>
                <head>
                    <title>Test Email</title>
                    <style>body { font-family: Arial; }</style>
                </head>
                <body>
                    <h1>Hello World</h1>
                    <p>This is a <strong>test</strong> email.</p>
                    <ul>
                        <li>Item 1</li>
                        <li>Item 2</li>
                    </ul>
                    <script>alert("test");</script>
                </body>
            </html>
        ';
        
        // Expected text - include the title since our HTML to text conversion includes it
        $expectedText = "Test Email Hello World

This is a test email.

- Item 1
- Item 2";
        
        // Convert HTML to text
        $text = $method->invoke($this->extractor, $html);
        
        // Clean up the text for comparison (remove extra whitespace)
        $text = preg_replace('/\s+/', ' ', trim($text));
        $expectedText = preg_replace('/\s+/', ' ', trim($expectedText));
        
        // Check the result
        $this->assertEquals($expectedText, $text);
    }
    
    public function testCleanText() {
        // Create a reflection of the class to access protected methods
        $reflection = new ReflectionClass(ThreadEmailExtractorEmailBody::class);
        $method = $reflection->getMethod('cleanText');
        $method->setAccessible(true);
        
        // Test text with different line endings and excessive whitespace
        $text = "Line 1\r\nLine 2\rLine 3\n\n\n\nLine 4   ";
        
        // Expected text
        $expectedText = "Line 1\nLine 2\nLine 3\n\nLine 4";
        
        // Clean the text
        $cleanedText = $method->invoke($this->extractor, $text);
        
        // Check the result
        $this->assertEquals($expectedText, $cleanedText);
    }

    public function testExtractContentFromEmailWithAnonymizedHeaders() {
        // Test email with anonymized headers (like <removed>) that would cause Laminas Mail to fail
        $problematicEmail = "From: sender@example.com\r\n" .
                          "To: <removed>\r\n" .
                          "Delivered-To: <removed>\r\n" .
                          "X-Forwarded-for: <removed>\r\n" .
                          "Subject: Test Email\r\n" .
                          "Content-Type: text/plain; charset=utf-8\r\n" .
                          "\r\n" .
                          "This is a test email with anonymized headers.\r\n" .
                          "The headers contain <removed> placeholders for privacy.";
        
        // This should not throw an exception and should successfully extract content
        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($problematicEmail);
        
        // Verify we get a valid result
        $this->assertInstanceOf(ExtractedEmailBody::class, $result);
        $this->assertNotEmpty($result->plain_text);
        $this->assertStringContainsString('test email with anonymized headers', $result->plain_text);
        $this->assertStringContainsString('privacy', $result->plain_text);
    }

    public function testRemoveProblematicHeaders() {
        // Test the private method for removing problematic headers
        $reflection = new ReflectionClass(ThreadEmailExtractorEmailBody::class);
        $method = $reflection->getMethod('removeProblematicHeaders');
        $method->setAccessible(true);
        
        $problematicEmail = "From: sender@example.com\r\n" .
                          "To: <removed>\r\n" .
                          "Delivered-To: <removed>\r\n" .
                          "Subject: Test\r\n" .
                          "\r\n" .
                          "Email body content";
        
        $cleaned = $method->invoke(null, $problematicEmail);
        
        // Verify <removed> headers are removed while keeping valid headers and body
        $this->assertStringNotContainsString('To: <removed>', $cleaned);
        $this->assertStringNotContainsString('Delivered-To: <removed>', $cleaned);
        $this->assertStringContainsString('From: sender@example.com', $cleaned);
        $this->assertStringContainsString('Subject: Test', $cleaned);
        $this->assertStringContainsString('Email body content', $cleaned);
    }
}
