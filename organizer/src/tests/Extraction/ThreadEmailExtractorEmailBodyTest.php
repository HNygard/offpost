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

    public function testReadLaminasMessage_withErrorHandling_Success() {
        // Test email with valid headers
        $validEmail = "From: sender@example.com\r\n" .
                     "To: recipient@example.com\r\n" .
                     "Subject: Test Email\r\n" .
                     "Content-Type: text/plain\r\n" .
                     "\r\n" .
                     "This is a test email body";

        // Test successful parsing
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($validEmail);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $this->assertEquals('Test Email', $result->getHeader('subject')->getFieldValue());
    }

    public function testReadLaminasMessage_withErrorHandling_InvalidHeader() {
        // Test email with problematic DKIM header
        $emailWithBadHeader = "DKIM-Signature: v=1; a=rsa-sha256; invalid base64///\r\n" .
                            "From: sender@example.com\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Subject: Test Email\r\n" .
                            "\r\n" .
                            "This is a test email body";

        // The method should handle the invalid header by stripping it
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithBadHeader);
        
        // Assert we got a valid Laminas Mail Message object despite the bad header
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $this->assertEquals('Test Email', $result->getHeader('subject')->getFieldValue());
    }

    public function testReadLaminasMessage_withErrorHandling_EmptyContent() {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage("preg_split(): Argument #2 (\$subject) must be of type string, array given");
        
        ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling(['raw' => '']);
    }

    public function testReadLaminasMessage_withErrorHandling_CompletelyInvalidEmail() {
        // Test with completely invalid email format that can't be parsed even after stripping headers
        $invalidEmail = "This is not an email at all\r\n" .
                       "Just some random text\r\n" .
                       "Without any valid headers";

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage("preg_split(): Argument #2 (\$subject) must be of type string, array given");
        
        ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling(['raw' => $invalidEmail]);
    }

    public function testReadLaminasMessage_withErrorHandling_MalformedEncodedWord() {
        // Test email with malformed encoded-word in Subject header (missing ?=)
        // This is based on the actual issue reported - encoded word missing closing ?= before next header
        $emailWithMalformedSubject = "From: sender@example.com\r\n" .
                                    "To: recipient@example.com\r\n" .
                                    "Subject: =?iso-8859-1?Q?SV:_Klage_p=E5_m=E5lrettet?= =?iso-8859-1?Q?_utestengelse?Thread-Topic: test\r\n" .
                                    "Content-Type: text/plain\r\n" .
                                    "\r\n" .
                                    "This is a test email body";

        // The method should handle the malformed encoded-word by fixing it
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithMalformedSubject);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // The subject should be parseable now
        $this->assertTrue($result->getHeaders()->has('subject'));
        
        // Verify that the complete subject content is preserved
        // =?iso-8859-1?Q?SV:_Klage_p=E5_m=E5lrettet?= decodes to "SV: Klage på målrettet"
        // =?iso-8859-1?Q?_utestengelse?= decodes to " utestengelse"
        $subject = $result->getHeader('subject')->getFieldValue();
        $this->assertEquals('SV: Klage på målrettet utestengelse', $subject);
    }

    public function testReadLaminasMessage_withErrorHandling_MalformedEncodedWordInline() {
        // Test email with malformed encoded-word on a single line
        $emailWithMalformedSubject = "From: sender@example.com\r\n" .
                                    "To: recipient@example.com\r\n" .
                                    "Subject: =?iso-8859-1?Q?Test_Subject?Thread-Topic: something\r\n" .
                                    "Content-Type: text/plain\r\n" .
                                    "\r\n" .
                                    "This is a test email body";

        // The method should handle the malformed encoded-word by fixing it
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithMalformedSubject);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $this->assertTrue($result->getHeaders()->has('subject'));
        
        // Verify that the complete subject content is preserved
        // =?iso-8859-1?Q?Test_Subject?= decodes to "Test Subject"
        $subject = $result->getHeader('subject')->getFieldValue();
        $this->assertEquals('Test Subject', $subject);
    }

    public function testReadLaminasMessage_withErrorHandling_NonAsciiCharacterDebugInfo() {
        // Test email with non-ASCII character (> 127) in the Subject header
        // This should be encoded using encoded-word format but isn't
        $emailWithNonAscii = "From: sender@example.com\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Subject: Test " . chr(200) . " Subject\r\n" .  // Character with ord > 127
                            "Content-Type: text/plain\r\n" .
                            "\r\n" .
                            "This is a test email body";

        try {
            ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithNonAscii);
            $this->fail('Expected Exception to be thrown for non-ASCII character in header');
        } catch (Exception $e) {
            $message = $e->getMessage();
            
            // Build expected message format
            $expectedMessage = "Failed to parse email due to problematic header: Subject\n"
                . "Original error: Invalid header value detected\n"
                . "New error: Invalid header value detected\n\n"
                . "CHARACTER ANALYSIS:\n"
                . "Found 1 problematic character(s) in header value:\n\n"
                . "Issue #1:\n"
                . "  Position: 5\n"
                . "  Character: " . chr(200) . " (ASCII: 200 / 0xC8)\n"
                . "  Reason: Non-ASCII character (ord > 127) - should use encoded-word format\n"
                . "  Context: ...Test [\\xC8] Subject...\n\n"
                . "Partial EML up to this header:\n"
                . "From: sender@example.com\n"
                . "To: recipient@example.com\n"
                . "Subject: Test " . chr(200) . " Subject";
            
            $this->assertEquals($expectedMessage, $message);
        }
    }

    public function testReadLaminasMessage_withUtf8InIso88591EncodedWord() {
        // Test the exact scenario from the error log - UTF-8 bytes in ISO-8859-1 encoded word
        // \xc3\xb8 is UTF-8 encoding of Norwegian letter 'ø'
        $emailWithCharsetMismatch = "From: sender@example.com\r\n" .
                                   "To: =?iso-8859-1?Q?Alfred_Sj\xc3\xb8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                                   "Subject: Test\r\n" .
                                   "Content-Type: text/plain\r\n" .
                                   "\r\n" .
                                   "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithCharsetMismatch);
        
        // Should parse successfully
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // Should decode the name correctly
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Alfred Sjøberg', $to);
        $this->assertStringContainsString('alfred.sjoberg@offpost.no', $to);
    }

    public function testReadLaminasMessage_withMultipleNorwegianCharacters() {
        // Test various Norwegian characters: å (U+00E5), æ (U+00E6), ø (U+00F8)
        // UTF-8 encodings: å = \xc3\xa5, æ = \xc3\xa6, ø = \xc3\xb8
        
        // Test å (aring)
        $emailWithAring = "From: sender@example.com\r\n" .
                         "To: =?iso-8859-1?Q?Hyll\xc3\xa5s?= <hyllaas@example.com>\r\n" .
                         "Subject: Test\r\n" .
                         "Content-Type: text/plain\r\n" .
                         "\r\n" .
                         "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithAring);
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Hyllås', $to);
        
        // Test æ (ae ligature)
        $emailWithAe = "From: sender@example.com\r\n" .
                      "To: =?iso-8859-1?Q?K\xc3\xa6re?= <kaere@example.com>\r\n" .
                      "Subject: Test\r\n" .
                      "Content-Type: text/plain\r\n" .
                      "\r\n" .
                      "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithAe);
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Kære', $to);
        
        // Test ø in subject line
        $emailWithOInSubject = "From: sender@example.com\r\n" .
                              "To: recipient@example.com\r\n" .
                              "Subject: =?iso-8859-1?Q?Br\xc3\xb8nn\xc3\xb8ysund?=\r\n" .
                              "Content-Type: text/plain\r\n" .
                              "\r\n" .
                              "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithOInSubject);
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $subject = $result->getHeader('subject')->getFieldValue();
        $this->assertStringContainsString('Brønnøysund', $subject);
    }

    public function testReadLaminasMessage_backwardCompatibilityWithCorrectHeaders() {
        // Ensure correctly formatted headers still work
        
        // Correctly formatted UTF-8 header
        $correctUtf8 = "From: sender@example.com\r\n" .
                      "To: =?utf-8?Q?Alfred_Sj=C3=B8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                      "Subject: Test\r\n" .
                      "Content-Type: text/plain\r\n" .
                      "\r\n" .
                      "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($correctUtf8);
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Alfred Sjøberg', $to);
        
        // Correctly formatted ISO-8859-1 header (with proper ISO-8859-1 encoding of ø)
        $correctIso = "From: sender@example.com\r\n" .
                     "To: =?iso-8859-1?Q?Alfred_Sj=F8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                     "Subject: Test\r\n" .
                     "Content-Type: text/plain\r\n" .
                     "\r\n" .
                     "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($correctIso);
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Alfred Sjøberg', $to);
    }
}
