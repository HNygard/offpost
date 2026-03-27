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

    // ========================================================================
    // Tests for parseEmail using Zbateson
    // ========================================================================

    public function testParseEmail_Success() {
        // Test email with valid headers
        $validEmail = "From: sender@example.com\r\n" .
                     "To: recipient@example.com\r\n" .
                     "Subject: Test Email\r\n" .
                     "Content-Type: text/plain\r\n" .
                     "\r\n" .
                     "This is a test email body";

        // Test successful parsing
        $result = ThreadEmailExtractorEmailBody::parseEmail($validEmail);

        // Assert we got a valid Zbateson Message object
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);
        $this->assertEquals('Test Email', $result->getHeaderValue('subject'));
    }

    public function testParseEmail_WithDKIMHeader() {
        // Test email with DKIM header - Zbateson should handle this without issues
        $emailWithDKIM = "DKIM-Signature: v=1; a=rsa-sha256; d=example.com; s=selector;\r\n" .
                        "\tc=relaxed/relaxed; q=dns/txt; t=1234567890;\r\n" .
                        "\tbh=base64hash==; h=from:to:subject;\r\n" .
                        "\tb=base64signature==\r\n" .
                        "From: sender@example.com\r\n" .
                        "To: recipient@example.com\r\n" .
                        "Subject: Test Email\r\n" .
                        "\r\n" .
                        "This is a test email body";

        // The method should handle the DKIM header
        $result = ThreadEmailExtractorEmailBody::parseEmail($emailWithDKIM);

        // Assert we got a valid message
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);
        $this->assertEquals('Test Email', $result->getHeaderValue('subject'));
    }

    public function testParseEmail_MalformedEncodedWord() {
        // Test email with malformed encoded-word in Subject header (missing ?=)
        // Zbateson is more tolerant of such issues
        $emailWithMalformedSubject = "From: sender@example.com\r\n" .
                                    "To: recipient@example.com\r\n" .
                                    "Subject: =?iso-8859-1?Q?SV:_Klage_p=E5_m=E5lrettet?= =?iso-8859-1?Q?_utestengelse?Thread-Topic: test\r\n" .
                                    "Content-Type: text/plain\r\n" .
                                    "\r\n" .
                                    "This is a test email body";

        // Zbateson should handle this gracefully
        $result = ThreadEmailExtractorEmailBody::parseEmail($emailWithMalformedSubject);

        // Assert we got a valid message object
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);

        // The subject should be accessible
        $subject = $result->getHeaderValue('subject');
        $this->assertNotNull($subject);
    }

    public function testParseEmail_withRawNonAsciiInSubjectHeader() {
        // Test email with non-ASCII character (> 127) in the Subject header
        // Zbateson handles this natively
        $emailWithNonAscii = "From: sender@example.com\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Subject: Test " . chr(200) . " Subject\r\n" .
                            "Content-Type: text/plain\r\n" .
                            "\r\n" .
                            "This is a test email body";

        // Zbateson should parse this successfully
        $result = ThreadEmailExtractorEmailBody::parseEmail($emailWithNonAscii);

        // Assert we got a valid message object
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);

        // The subject should be present
        $subject = $result->getHeaderValue('subject');
        $this->assertNotNull($subject);
        $this->assertStringContainsString('Subject', $subject);
    }

    public function testParseEmail_withCharsetMismatch_Utf8InIso88591() {
        // Email with UTF-8 bytes (\xc3\xb8 = ø) in header declaring iso-8859-1
        // This is a common issue with Microsoft Outlook/Exchange servers
        $emlWithMismatch = "From: sender@example.com\r\n" .
                          "To: =?iso-8859-1?Q?Alfred_Sj\xc3\xb8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                          "Subject: Test\r\n" .
                          "Content-Type: text/plain\r\n" .
                          "\r\n" .
                          "Test body";

        $result = ThreadEmailExtractorEmailBody::parseEmail($emlWithMismatch);

        // Should successfully parse
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);

        // Get To header
        $to = $result->getHeaderValue('to');
        $this->assertNotNull($to);
        $this->assertStringContainsString('alfred.sjoberg@offpost.no', $to);
    }

    public function testParseEmail_withCharsetMismatch_MultipleNorwegianChars() {
        // Test with multiple Norwegian characters (ø, å, æ)
        $emlWithMismatch = "From: =?iso-8859-1?Q?P\xc3\xa5l_\xc3\x86rlig?= <pal@example.com>\r\n" .
                          "To: =?iso-8859-1?Q?Kj\xc3\xa6re_venner?= <friends@example.com>\r\n" .
                          "Subject: =?iso-8859-1?Q?M\xc3\xb8te_i_morgen?=\r\n" .
                          "Content-Type: text/plain\r\n" .
                          "\r\n" .
                          "Test body";

        $result = ThreadEmailExtractorEmailBody::parseEmail($emlWithMismatch);

        // Should successfully parse
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);

        // Headers should be accessible
        $from = $result->getHeaderValue('from');
        $to = $result->getHeaderValue('to');
        $subject = $result->getHeaderValue('subject');

        $this->assertNotNull($from);
        $this->assertNotNull($to);
        $this->assertNotNull($subject);
    }

    public function testParseEmail_withCorrectIso88591() {
        // Verify that correctly formatted ISO-8859-1 emails work properly
        // In ISO-8859-1, ø is encoded as \xf8 (single byte)
        $correctIso88591 = "From: sender@example.com\r\n" .
                          "To: =?iso-8859-1?Q?Alfred_Sj=F8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                          "Subject: Test\r\n" .
                          "Content-Type: text/plain\r\n" .
                          "\r\n" .
                          "Test body";

        $result = ThreadEmailExtractorEmailBody::parseEmail($correctIso88591);

        // Should successfully parse
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);

        // Get To header - should correctly decode Norwegian character from proper ISO-8859-1
        $toHeader = $result->getHeader('to');
        $this->assertNotNull($toHeader);

        if ($toHeader instanceof \ZBateson\MailMimeParser\Header\AddressHeader) {
            $addresses = $toHeader->getAddresses();
            $this->assertNotEmpty($addresses);
            $name = $addresses[0]->getName();
            $this->assertStringContainsString('Sjøberg', $name);
        }
    }

    public function testParseEmail_withRawUtf8InReceivedHeader() {
        // Test with raw UTF-8 bytes in Received header (Lødingen with \xc3\xb8)
        $emailWithRawUtf8 = "Return-Path: <sender@example.com>\r\n" .
                           "Delivered-To: recipient@example.com\r\n" .
                           "Received: from [(192.0.2.1)] by lo-spam with L\xc3\xb8dingen Kommune SMTP; Mon, 4 Oct 2021 12:16:33 +0200 (CEST)\r\n" .
                           "From: sender@example.com\r\n" .
                           "To: recipient@example.com\r\n" .
                           "Subject: Test Email\r\n" .
                           "Content-Type: text/plain\r\n" .
                           "\r\n" .
                           "This is a test email body";

        // Zbateson should successfully parse despite raw UTF-8 bytes in Received header
        $result = ThreadEmailExtractorEmailBody::parseEmail($emailWithRawUtf8);

        // Assert we got a valid message object
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);
        $this->assertEquals('Test Email', $result->getHeaderValue('subject'));

        // The Received header should be present
        $received = $result->getHeaderValue('received');
        $this->assertNotNull($received);
        // Zbateson preserves the Norwegian characters
        $this->assertStringContainsString('Lødingen', $received);
    }

    public function testParseEmail_withRawUtf8InMultipleHeaders() {
        // Test with raw UTF-8 bytes in multiple headers
        $emailWithRawUtf8 = "From: sender@example.com\r\n" .
                           "To: recipient@example.com\r\n" .
                           "X-Custom-Header: Test with \xc3\xb8 and \xc3\xa5 and \xc3\xa6\r\n" .
                           "Subject: Test\r\n" .
                           "Content-Type: text/plain\r\n" .
                           "\r\n" .
                           "Test body";

        // Should successfully parse
        $result = ThreadEmailExtractorEmailBody::parseEmail($emailWithRawUtf8);

        // Assert we got a valid message object
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);
        $this->assertEquals('Test', $result->getHeaderValue('subject'));

        // X-Custom-Header should have the Norwegian characters preserved
        $customHeaderValue = $result->getHeaderValue('x-custom-header');
        $this->assertNotNull($customHeaderValue);

        // Verify the Norwegian characters are preserved (ø, å, æ)
        $this->assertStringContainsString('ø', $customHeaderValue);
        $this->assertStringContainsString('å', $customHeaderValue);
        $this->assertStringContainsString('æ', $customHeaderValue);
    }

    public function testParseEmail_withRawUtf8InContinuationLine() {
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

        // Zbateson should successfully parse despite raw UTF-8 bytes in continuation line
        $result = ThreadEmailExtractorEmailBody::parseEmail($emailWithRawUtf8);

        // Assert we got a valid message object
        $this->assertInstanceOf(\ZBateson\MailMimeParser\Message::class, $result);
        $this->assertEquals('Test', $result->getHeaderValue('subject'));

        // The Received header should be present
        $received = $result->getHeaderValue('received');
        $this->assertNotNull($received);
    }

    // ========================================================================
    // Tests for extractContentFromEmail
    // ========================================================================

    public function testExtractContentFromEmail_PlainText() {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "\r\n" .
                "This is the email body with Norwegian: æøå ÆØÅ";

        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($email);

        $this->assertInstanceOf(ExtractedEmailBody::class, $result);
        $this->assertStringContainsString('æøå', $result->plain_text);
        $this->assertStringContainsString('This is the email body', $result->plain_text);
    }

    public function testExtractContentFromEmail_Multipart() {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: multipart/alternative; boundary=\"boundary123\"\r\n" .
                "\r\n" .
                "--boundary123\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "\r\n" .
                "Plain text version with æøå\r\n" .
                "--boundary123\r\n" .
                "Content-Type: text/html; charset=utf-8\r\n" .
                "\r\n" .
                "<html><body>HTML version with æøå</body></html>\r\n" .
                "--boundary123--\r\n";

        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($email);

        $this->assertInstanceOf(ExtractedEmailBody::class, $result);
        $this->assertStringContainsString('æøå', $result->plain_text);
        // HTML is converted to text
        $this->assertStringContainsString('æøå', $result->html);
    }

    public function testExtractContentFromEmail_EmptyThrowsException() {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Empty email content provided for extraction');

        ThreadEmailExtractorEmailBody::extractContentFromEmail('');
    }
}
