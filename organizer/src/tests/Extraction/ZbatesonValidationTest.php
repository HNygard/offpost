<?php

/**
 * Tests for zbateson/mail-mime-parser handling of problematic email patterns.
 *
 * Test patterns are based on real issues:
 * - Malformed encoded-words (missing ?=)
 * - Charset mismatches (UTF-8 bytes in ISO-8859-1 headers)
 * - Raw non-ASCII bytes in headers
 * - Norwegian characters (æ, ø, å)
 */

use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

class ZbatesonValidationTest extends PHPUnit\Framework\TestCase {

    private ?MailMimeParser $parser = null;

    protected function setUp(): void {
        if (!class_exists(MailMimeParser::class)) {
            $this->fail(
                "zbateson/mail-mime-parser is not installed. Run:\n" .
                "cd organizer/src && composer install"
            );
        }
        $this->parser = new MailMimeParser();
    }

    private function parseWithZbateson(string $rawEmail): Message {
        return $this->parser->parse($rawEmail, false);
    }

    // ========================================================================
    // Malformed encoded-words
    // ========================================================================

    public function testMalformedEncodedWord_MissingClosingDelimiter(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: =?iso-8859-1?Q?SV:_Klage_p=E5_m=E5lrettet?= =?iso-8859-1?Q?_utestengelse?Thread-Topic: test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $subject = $message->getHeaderValue('subject');
        $this->assertNotNull($subject);
        // Zbateson parses the malformed header, preserving what it can
        $this->assertStringContainsString('SV: Klage på målrettet', $subject);
    }

    public function testMalformedEncodedWord_InlineWithoutSpace(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: =?iso-8859-1?Q?Test_Subject?Thread-Topic: something\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $subject = $message->getHeaderValue('subject');
        $this->assertNotNull($subject);
    }

    // ========================================================================
    // Charset mismatch (UTF-8 bytes in ISO-8859-1 declared headers)
    // ========================================================================

    public function testCharsetMismatch_Utf8InIso88591(): void {
        // UTF-8 bytes (\xc3\xb8 = ø) in header declaring iso-8859-1
        $email = "From: sender@example.com\r\n" .
                "To: =?iso-8859-1?Q?Alfred_Sj\xc3\xb8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $to = $message->getHeaderValue('to');
        $this->assertNotNull($to);
        $this->assertStringContainsString('alfred.sjoberg@offpost.no', $to);
    }

    public function testCharsetMismatch_MultipleNorwegianChars(): void {
        $email = "From: =?iso-8859-1?Q?P\xc3\xa5l_\xc3\x86rlig?= <pal@example.com>\r\n" .
                "To: =?iso-8859-1?Q?Kj\xc3\xa6re_venner?= <friends@example.com>\r\n" .
                "Subject: =?iso-8859-1?Q?M\xc3\xb8te_i_morgen?=\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $this->assertNotNull($message->getHeaderValue('from'));
        $this->assertNotNull($message->getHeaderValue('to'));
        $this->assertNotNull($message->getHeaderValue('subject'));
    }

    public function testCorrectIso88591_DecodesProperlyToUtf8(): void {
        // Correctly formatted ISO-8859-1: ø = \xf8 = =F8 in QP
        $email = "From: sender@example.com\r\n" .
                "To: =?iso-8859-1?Q?Alfred_Sj=F8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $toHeader = $message->getHeader('to');
        $this->assertNotNull($toHeader);

        if ($toHeader instanceof \ZBateson\MailMimeParser\Header\AddressHeader) {
            $addresses = $toHeader->getAddresses();
            $this->assertNotEmpty($addresses);
            $name = $addresses[0]->getName();
            $this->assertStringContainsString('Sjøberg', $name);
        }
    }

    // ========================================================================
    // Raw non-ASCII bytes in headers (no encoding)
    // ========================================================================

    public function testRawNonAscii_InSubject(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test " . chr(200) . " Subject\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $subject = $message->getHeaderValue('subject');
        $this->assertNotNull($subject);
        $this->assertStringContainsString('Test', $subject);
        $this->assertStringContainsString('Subject', $subject);
    }

    public function testRawUtf8_InReceivedHeader(): void {
        // Raw UTF-8 bytes in Received header (Lødingen with \xc3\xb8)
        $email = "Return-Path: <sender@example.com>\r\n" .
                "Received: from [(192.0.2.1)] by lo-spam with L\xc3\xb8dingen Kommune SMTP; Mon, 4 Oct 2021 12:16:33 +0200 (CEST)\r\n" .
                "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test Email\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $this->assertEquals('Test Email', $message->getHeaderValue('subject'));

        $received = $message->getHeaderValue('received');
        $this->assertNotNull($received);
        $this->assertStringContainsString('Lødingen', $received);
    }

    public function testRawUtf8_InCustomHeader(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "X-Custom-Header: Test with \xc3\xb8 and \xc3\xa5 and \xc3\xa6\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $customHeader = $message->getHeaderValue('x-custom-header');
        $this->assertNotNull($customHeader);
        $this->assertStringContainsString('ø', $customHeader);
        $this->assertStringContainsString('å', $customHeader);
        $this->assertStringContainsString('æ', $customHeader);
    }

    public function testRawUtf8_InContinuationLine(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Received: from mail.example.com\r\n" .
                "\tby server with L\xc3\xb8dingen SMTP;\r\n" .
                "\tMon, 4 Oct 2021 12:16:33 +0200\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message);
        $this->assertEquals('Test', $message->getHeaderValue('subject'));
        $this->assertNotNull($message->getHeaderValue('received'));
    }

    // ========================================================================
    // Body extraction
    // ========================================================================

    public function testBodyExtraction_PlainTextWithNorwegianCharacters(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "\r\n" .
                "This is the email body with Norwegian: æøå ÆØÅ";

        $message = $this->parseWithZbateson($email);

        $body = $message->getTextContent();
        $this->assertStringContainsString('æøå', $body);
        $this->assertStringContainsString('ÆØÅ', $body);
    }

    public function testBodyExtraction_MultipartAlternative(): void {
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

        $message = $this->parseWithZbateson($email);

        $this->assertNotNull($message->getTextContent());
        $this->assertNotNull($message->getHtmlContent());
        $this->assertStringContainsString('æøå', $message->getTextContent());
        $this->assertStringContainsString('æøå', $message->getHtmlContent());
    }
}
