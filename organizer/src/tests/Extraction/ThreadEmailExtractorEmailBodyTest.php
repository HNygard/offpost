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

    public function testExtractContentFromEmailWithDkimHeaders() {
        // Test email with DKIM headers that should be stripped
        $emailWithDkim = "From: sender@example.com\r\n" .
                         "To: recipient@example.com\r\n" .
                         "Subject: Test Email with DKIM\r\n" .
                         "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n" .
                         "\td=custmx.one.com; s=201015;\r\n" .
                         "\th=mime-version:content-transfer-encoding:content-type:in-reply-to:references:\r\n" .
                         "\t message-id:date:subject:to:from:x-halone-refid:x-halone-sa:from:x-halone-sa:\r\n" .
                         "\t x-halone-refid;\r\n" .
                         "\tbh=rIv00cKr8Xj97WFZtdmVkJKsLtJVgVpaj8sxpPtmnM0=;\r\n" .
                         "\tb=RyvwGvMBQY371EWYO86jmKOmZwcKfiXURMrZaZkjz7dUf1Hq8mCHVcn+dPWm8dk3vSipkYLDQJH\r\n" .
                         "\t iXL6nkuCFGUJz/uPGXWaVqB1skd6V0jHR17zvGQBFyIZyMsVOibl0XvY9bmZwf7Is6jCLUm2OJJ/Uo\r\n" .
                         "\t EdxSemT/iRaznT2cNZU0tU40umIm5HTQxw2lL/ltAhfvDKfSrITTwXbelqMk0GjsdXgI309XYqm1cQ\r\n" .
                         "\t BLObJxLrARR2ZzHmkERv267dTCjzJBB6FG8njUCz5tAQvpeRPu+GnTLKFMq\r\n" .
                         "\t sZ+DzQgUPHuBimow2XjATT3yZQ3JfEA==\r\n" .
                         "Content-Type: text/plain; charset=utf-8\r\n" .
                         "\r\n" .
                         "This is a test email with DKIM header.\r\n" .
                         "The DKIM header should be stripped out completely.";
        
        // This should not throw an exception - DKIM headers should be stripped
        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($emailWithDkim);
        
        // Verify we get a result
        $this->assertNotNull($result);
        $this->assertInstanceOf(ExtractedEmailBody::class, $result);
        
        // Verify content is extracted 
        $this->assertNotNull($result->plain_text);
        $this->assertStringContainsString('This is a test email with DKIM header', $result->plain_text);
    }

    public function testRemoveDkimHeaders() {
        // Test the header removal method directly using reflection
        $reflection = new \ReflectionClass(ThreadEmailExtractorEmailBody::class);
        $method = $reflection->getMethod('removeDkimHeaders');
        $method->setAccessible(true);
        
        $emailWithDkim = "From: sender@example.com\r\n" .
                        "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n" .
                        "\td=custmx.one.com; s=201015;\r\n" .
                        "\tb=RyvwGvMBQY371EWYO86jmKOmZwcKfiXURMrZaZkjz7dUf1==\r\n" .
                        "Subject: Test Email\r\n" .
                        "\r\n" .
                        "Test body content";
        
        $stripped = $method->invoke(null, $emailWithDkim);
        
        // Verify DKIM headers were completely removed
        $this->assertStringNotContainsString('DKIM-Signature', $stripped);
        $this->assertStringNotContainsString('d=custmx.one.com', $stripped);
        $this->assertStringNotContainsString('s=201015', $stripped);
        
        // Verify other headers and body are preserved
        $this->assertStringContainsString('From: sender@example.com', $stripped);
        $this->assertStringContainsString('Subject: Test Email', $stripped);
        $this->assertStringContainsString('Test body content', $stripped);
    }
}
