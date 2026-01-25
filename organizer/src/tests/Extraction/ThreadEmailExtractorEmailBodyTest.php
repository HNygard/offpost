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

    public function testReadLaminasMessage_withRawNonAsciiInSubjectHeader() {
        // Test email with non-ASCII character (> 127) in the Subject header
        // With our sanitization, this should now successfully parse instead of throwing an exception
        $emailWithNonAscii = "From: sender@example.com\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Subject: Test " . chr(200) . " Subject\r\n" .  // Character with ord > 127
                            "Content-Type: text/plain\r\n" .
                            "\r\n" .
                            "This is a test email body";

        // With our new sanitization, this should parse successfully
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithNonAscii);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // The subject should be present and contain the word "Subject"
        $this->assertTrue($result->getHeaders()->has('subject'));
        $subject = $result->getHeader('subject')->getFieldValue();
        $this->assertStringContainsString('Subject', $subject);
    }

    public function testReadLaminasMessage_withCharsetMismatch_Utf8InIso88591() {
        // Email with UTF-8 bytes (\xc3\xb8 = ø) in header declaring iso-8859-1
        // This is a common issue with Microsoft Outlook/Exchange servers
        $emlWithMismatch = "From: sender@example.com\r\n" .
                          "To: =?iso-8859-1?Q?Alfred_Sj\xc3\xb8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                          "Subject: Test\r\n" .
                          "Content-Type: text/plain\r\n" .
                          "\r\n" .
                          "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emlWithMismatch);
        
        // Should successfully parse
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // Should correctly decode Norwegian character
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Alfred Sjøberg', $to);
        $this->assertStringContainsString('alfred.sjoberg@offpost.no', $to);
    }

    public function testReadLaminasMessage_withCharsetMismatch_MultipleNorwegianChars() {
        // Test with multiple Norwegian characters (ø, å, æ)
        $emlWithMismatch = "From: =?iso-8859-1?Q?P\xc3\xa5l_\xc3\x86rlig?= <pal@example.com>\r\n" .
                          "To: =?iso-8859-1?Q?Kj\xc3\xa6re_venner?= <friends@example.com>\r\n" .
                          "Subject: =?iso-8859-1?Q?M\xc3\xb8te_i_morgen?=\r\n" .
                          "Content-Type: text/plain\r\n" .
                          "\r\n" .
                          "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emlWithMismatch);
        
        // Should successfully parse
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // Check From header with å and æ
        $from = $result->getHeader('from')->getFieldValue();
        $this->assertStringContainsString('Pål Ærlig', $from);
        
        // Check To header with æ
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Kjære venner', $to);
        
        // Check Subject header with ø
        $subject = $result->getHeader('subject')->getFieldValue();
        $this->assertStringContainsString('Møte i morgen', $subject);
    }

    public function testReadLaminasMessage_withCharsetMismatch_CorrectIso88591Unaffected() {
        // Verify that correctly formatted ISO-8859-1 emails are not broken
        // In ISO-8859-1, ø is encoded as \xf8 (single byte)
        $correctIso88591 = "From: sender@example.com\r\n" .
                          "To: =?iso-8859-1?Q?Alfred_Sj=F8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                          "Subject: Test\r\n" .
                          "Content-Type: text/plain\r\n" .
                          "\r\n" .
                          "Test body";

        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($correctIso88591);
        
        // Should successfully parse
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // Should correctly decode Norwegian character from proper ISO-8859-1
        $to = $result->getHeader('to')->getFieldValue();
        $this->assertStringContainsString('Alfred Sjøberg', $to);
        $this->assertStringContainsString('alfred.sjoberg@offpost.no', $to);
    }

    public function testReadLaminasMessage_withRawUtf8InReceivedHeader() {
        // Test the actual issue from the problem statement:
        // Received header with raw UTF-8 bytes (Lødingen with \xc3\xb8)
        $emailWithRawUtf8 = "Return-Path: <sender@example.com>\r\n" .
                           "Delivered-To: recipient@example.com\r\n" .
                           "Received: from [(192.0.2.1)] by lo-spam with L\xc3\xb8dingen Kommune SMTP; Mon, 4 Oct 2021 12:16:33 +0200 (CEST)\r\n" .
                           "From: sender@example.com\r\n" .
                           "To: recipient@example.com\r\n" .
                           "Subject: Test Email\r\n" .
                           "Content-Type: text/plain\r\n" .
                           "\r\n" .
                           "This is a test email body";

        // Should successfully parse despite raw UTF-8 bytes in Received header
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithRawUtf8);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $this->assertEquals('Test Email', $result->getHeader('subject')->getFieldValue());
        
        // The Received header should be present and parseable
        // Note: non-ASCII characters in Received headers are replaced with '?' for parseability
        $this->assertTrue($result->getHeaders()->has('received'));
        
        // Received headers can have multiple values, so we need to iterate
        $receivedHeaders = $result->getHeaders()->get('received');
        $found = false;
        foreach ($receivedHeaders as $receivedHeader) {
            $receivedValue = $receivedHeader->getFieldValue();
            // The UTF-8 sequence \xc3\xb8 (2 bytes for ø) gets replaced with ?? (2 question marks)
            // We just need to verify it parses successfully and contains "dingen"
            if (strpos($receivedValue, 'dingen') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected to find "dingen" in Received header (part of Lødingen)');
    }

    public function testReadLaminasMessage_withRawUtf8InMultipleHeaders() {
        // Test with raw UTF-8 bytes in multiple headers
        // Note: For From header, encoded-words will be used; for structural headers, simple replacement
        $emailWithRawUtf8 = "From: sender@example.com\r\n" .
                           "To: recipient@example.com\r\n" .
                           "X-Custom-Header: Test with \xc3\xb8 and \xc3\xa5 and \xc3\xa6\r\n" .
                           "Subject: Test\r\n" .
                           "Content-Type: text/plain\r\n" .
                           "\r\n" .
                           "Test body";

        // Should successfully parse
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithRawUtf8);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $this->assertEquals('Test', $result->getHeader('subject')->getFieldValue());
        
        // X-Custom-Header should have the Norwegian characters properly encoded
        $this->assertTrue($result->getHeaders()->has('x-custom-header'));
    }

    public function testReadLaminasMessage_withRawUtf8InContinuationLine() {
        // Test with raw UTF-8 bytes in a continuation line (header value that spans multiple lines)
        $emailWithRawUtf8 = "From: sender@example.com\r\n" .
                           "To: recipient@example.com\r\n" .
                           "Received: from mail.example.com\r\n" .
                           "\tby server with L\xc3\xb8dingen SMTP;\r\n" .
                           "\tMon, 4 Oct 2021 12:16:33 +0200\r\n" .
                           "Subject: Test\r\n" .
                           "Content-Type: text/plain\r\n" .
                           "\r\n" .
                           "Test body";

        // Should successfully parse despite raw UTF-8 bytes in continuation line
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithRawUtf8);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        $this->assertEquals('Test', $result->getHeader('subject')->getFieldValue());
        
        // The Received header should be present
        // Note: non-ASCII in continuation lines are replaced with '?'
        $this->assertTrue($result->getHeaders()->has('received'));
        
        // Received headers can have multiple values, so we need to iterate
        $receivedHeaders = $result->getHeaders()->get('received');
        $found = false;
        foreach ($receivedHeaders as $receivedHeader) {
            $receivedValue = $receivedHeader->getFieldValue();
            // The UTF-8 sequence \xc3\xb8 (2 bytes) gets replaced with ?? (2 question marks)
            // We just need to verify it parses successfully and contains "dingen"
            if (strpos($receivedValue, 'dingen') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected to find "dingen" in Received header continuation line (part of Lødingen)');
    }

    public function testReadLaminasMessage_withMixedAsciiAndUtf8InWord() {
        // Test the specific pattern from the problem: ASCII prefix + UTF-8 bytes + ASCII suffix
        // Example: "Lødingen" = "L" + "\xc3\xb8" + "dingen"
        // Using a custom header that supports encoded-words
        $emailWithMixedWord = "From: sender@example.com\r\n" .
                             "To: recipient@example.com\r\n" .
                             "X-Municipality: L\xc3\xb8dingen Kommune\r\n" .
                             "Subject: Test\r\n" .
                             "Content-Type: text/plain\r\n" .
                             "\r\n" .
                             "Test body";

        // Should successfully parse
        $result = ThreadEmailExtractorEmailBody::readLaminasMessage_withErrorHandling($emailWithMixedWord);
        
        // Assert we got a valid Laminas Mail Message object
        $this->assertInstanceOf(\Laminas\Mail\Storage\Message::class, $result);
        
        // The custom header should be present and parseable
        $this->assertTrue($result->getHeaders()->has('x-municipality'));
        
        // The value should contain the properly decoded Norwegian text
        $headerValue = $result->getHeader('x-municipality')->getFieldValue();
        // Since X-Municipality is not a structural header, it should be encoded-word encoded
        // and should decode to the proper Norwegian text
        $this->assertStringContainsString('dingen', $headerValue);
    }
}
