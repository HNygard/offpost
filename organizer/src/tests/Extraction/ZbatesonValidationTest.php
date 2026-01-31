<?php

/**
 * Validation test for zbateson/mail-mime-parser handling of problematic email patterns.
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

    /**
     * Helper to parse email with zbateson
     */
    private function parseWithZbateson(string $rawEmail): Message {
        return $this->parser->parse($rawEmail, false);
    }

    // ========================================================================
    // Test 1: Malformed encoded-word (missing ?= before next header)
    // ========================================================================

    public function testMalformedEncodedWord_MissingClosingDelimiter(): void {
        // This pattern causes issues - encoded word missing ?= delimiter
        // before another header starts on the same line
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: =?iso-8859-1?Q?SV:_Klage_p=E5_m=E5lrettet?= =?iso-8859-1?Q?_utestengelse?Thread-Topic: test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        // Zbateson should successfully parse this
        $this->assertNotNull($zbatesonMessage, "Zbateson should parse the email");

        // Check if subject is accessible
        $subject = $zbatesonMessage->getHeaderValue('subject');
        $this->assertNotNull($subject, "Subject header should be accessible");

        // Document what zbateson actually returns for this case
        echo "\n[Malformed encoded-word] Zbateson Subject: " . var_export($subject, true) . "\n";
    }

    public function testMalformedEncodedWord_InlineWithoutSpace(): void {
        // Encoded word missing ?= directly followed by header name
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: =?iso-8859-1?Q?Test_Subject?Thread-Topic: something\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse the email");

        $subject = $zbatesonMessage->getHeaderValue('subject');
        echo "\n[Inline malformed] Zbateson Subject: " . var_export($subject, true) . "\n";
    }

    // ========================================================================
    // Test 2: Charset mismatch (UTF-8 bytes in ISO-8859-1 declared headers)
    // ========================================================================

    public function testCharsetMismatch_Utf8InIso88591(): void {
        // UTF-8 bytes (\xc3\xb8 = ø) in header declaring iso-8859-1
        // Common issue with Microsoft Outlook/Exchange
        $email = "From: sender@example.com\r\n" .
                "To: =?iso-8859-1?Q?Alfred_Sj\xc3\xb8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse the email");

        $to = $zbatesonMessage->getHeaderValue('to');
        $this->assertNotNull($to, "To header should be accessible");

        echo "\n[Charset mismatch UTF-8/ISO-8859-1] Zbateson To: " . var_export($to, true) . "\n";

        // Check if Norwegian ø is preserved
        $containsOslash = strpos($to, 'ø') !== false || strpos($to, "\xc3\xb8") !== false;
        echo "[Charset mismatch] Contains ø or UTF-8 bytes: " . ($containsOslash ? "YES" : "NO") . "\n";
    }

    public function testCharsetMismatch_MultipleNorwegianChars(): void {
        // Multiple Norwegian characters with charset mismatch
        $email = "From: =?iso-8859-1?Q?P\xc3\xa5l_\xc3\x86rlig?= <pal@example.com>\r\n" .
                "To: =?iso-8859-1?Q?Kj\xc3\xa6re_venner?= <friends@example.com>\r\n" .
                "Subject: =?iso-8859-1?Q?M\xc3\xb8te_i_morgen?=\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse the email");

        $from = $zbatesonMessage->getHeaderValue('from');
        $to = $zbatesonMessage->getHeaderValue('to');
        $subject = $zbatesonMessage->getHeaderValue('subject');

        echo "\n[Multiple Norwegian chars]\n";
        echo "  From: " . var_export($from, true) . "\n";
        echo "  To: " . var_export($to, true) . "\n";
        echo "  Subject: " . var_export($subject, true) . "\n";
    }

    public function testCorrectIso88591_NotBroken(): void {
        // Verify correctly formatted ISO-8859-1 is not broken
        // ø in ISO-8859-1 is \xf8 (=F8 in quoted-printable)
        $email = "From: sender@example.com\r\n" .
                "To: =?iso-8859-1?Q?Alfred_Sj=F8berg?= <alfred.sjoberg@offpost.no>\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse the email");

        // For address headers, we need to check the address list to get the decoded name
        $toHeader = $zbatesonMessage->getHeader('to');
        $toValue = $toHeader ? $toHeader->getValue() : null;

        echo "\n[Correct ISO-8859-1] Zbateson To header value: " . var_export($toValue, true) . "\n";

        if ($toHeader instanceof \ZBateson\MailMimeParser\Header\AddressHeader) {
            $addresses = $toHeader->getAddresses();
            echo "[Correct ISO-8859-1] Address count: " . count($addresses) . "\n";
            if (!empty($addresses)) {
                $addr = $addresses[0];
                $name = $addr->getName();
                $emailAddr = $addr->getEmail();
                echo "[Correct ISO-8859-1] Zbateson To Name: " . var_export($name, true) . "\n";
                echo "[Correct ISO-8859-1] Zbateson To Email: " . var_export($emailAddr, true) . "\n";
                $this->assertStringContainsString('Sjøberg', $name, "Correct ISO-8859-1 should decode properly");
                return;
            }
        }

        // Fallback: check header value contains decoded text
        $this->assertStringContainsString('Sjøberg', $toValue ?? '', "Correct ISO-8859-1 should decode properly");
    }

    // ========================================================================
    // Test 3: Raw non-ASCII bytes in headers (no encoding at all)
    // ========================================================================

    public function testRawNonAscii_InSubject(): void {
        // Raw non-ASCII byte (chr(200)) in Subject header without any encoding
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test " . chr(200) . " Subject\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse email with raw non-ASCII");

        $subject = $zbatesonMessage->getHeaderValue('subject');

        echo "\n[Raw non-ASCII chr(200)] Zbateson Subject: " . var_export($subject, true) . "\n";
    }

    public function testRawUtf8_InReceivedHeader(): void {
        // Raw UTF-8 bytes in Received header (Lødingen with \xc3\xb8)
        $email = "Return-Path: <sender@example.com>\r\n" .
                "Delivered-To: recipient@example.com\r\n" .
                "Received: from [(192.0.2.1)] by lo-spam with L\xc3\xb8dingen Kommune SMTP; Mon, 4 Oct 2021 12:16:33 +0200 (CEST)\r\n" .
                "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test Email\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse email with raw UTF-8 in Received");

        $subject = $zbatesonMessage->getHeaderValue('subject');
        $received = $zbatesonMessage->getHeaderValue('received');

        echo "\n[Raw UTF-8 in Received header]\n";
        echo "  Subject: " . var_export($subject, true) . "\n";
        echo "  Received: " . var_export($received, true) . "\n";

        // Check if Lødingen is preserved
        $containsLodingen = strpos($received ?? '', 'Lødingen') !== false;
        echo "  Contains 'Lødingen': " . ($containsLodingen ? "YES" : "NO") . "\n";
    }

    public function testRawUtf8_InCustomHeader(): void {
        // Raw UTF-8 bytes in custom header
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "X-Custom-Header: Test with \xc3\xb8 and \xc3\xa5 and \xc3\xa6\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse email with raw UTF-8 in custom header");

        $customHeader = $zbatesonMessage->getHeaderValue('x-custom-header');

        echo "\n[Raw UTF-8 in custom header] X-Custom-Header: " . var_export($customHeader, true) . "\n";

        // Check for Norwegian characters
        $hasOslash = strpos($customHeader ?? '', 'ø') !== false;
        $hasAring = strpos($customHeader ?? '', 'å') !== false;
        $hasAe = strpos($customHeader ?? '', 'æ') !== false;

        echo "  ø present: " . ($hasOslash ? "YES" : "NO") . "\n";
        echo "  å present: " . ($hasAring ? "YES" : "NO") . "\n";
        echo "  æ present: " . ($hasAe ? "YES" : "NO") . "\n";
    }

    // ========================================================================
    // Test 4: Continuation lines with non-ASCII
    // ========================================================================

    public function testRawUtf8_InContinuationLine(): void {
        // Raw UTF-8 in a folded/continuation header line
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Received: from mail.example.com\r\n" .
                "\tby server with L\xc3\xb8dingen SMTP;\r\n" .
                "\tMon, 4 Oct 2021 12:16:33 +0200\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain\r\n" .
                "\r\n" .
                "Test body";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse email with raw UTF-8 in continuation line");

        $received = $zbatesonMessage->getHeaderValue('received');

        echo "\n[Raw UTF-8 in continuation line] Received: " . var_export($received, true) . "\n";
    }

    // ========================================================================
    // Test 5: Real test emails from data/test-emails/
    // ========================================================================

    public function testRealEmail_BccWithXForwardedFor(): void {
        $emailPath = '/organizer-data/test-emails/bcc-with-x-forwarded-for-header.eml';

        if (!file_exists($emailPath)) {
            $this->fail("Test email file not found: $emailPath");
        }

        $rawEmail = file_get_contents($emailPath);

        $zbatesonMessage = $this->parseWithZbateson($rawEmail);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse real email");

        $from = $zbatesonMessage->getHeaderValue('from');
        $subject = $zbatesonMessage->getHeaderValue('subject');
        $body = $zbatesonMessage->getTextContent();

        echo "\n[Real email: bcc-with-x-forwarded-for]\n";
        echo "  From: " . var_export($from, true) . "\n";
        echo "  Subject: " . var_export($subject, true) . "\n";
        echo "  Body length: " . strlen($body ?? '') . " chars\n";

        // This email has Norwegian characters in headers
        // Check if they're properly decoded
        $this->assertNotNull($from, "From header should be present");
        $this->assertNotNull($subject, "Subject header should be present");
    }

    public function testRealEmail_DmarcWithoutContentTransferEncoding(): void {
        $emailPath = '/organizer-data/test-emails/dmarc-without-content-transfer-encoding.eml';

        if (!file_exists($emailPath)) {
            $this->fail("Test email file not found: $emailPath");
        }

        $rawEmail = file_get_contents($emailPath);

        $zbatesonMessage = $this->parseWithZbateson($rawEmail);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse DMARC email");

        $from = $zbatesonMessage->getHeaderValue('from');
        $subject = $zbatesonMessage->getHeaderValue('subject');

        echo "\n[Real email: dmarc-without-content-transfer-encoding]\n";
        echo "  From: " . var_export($from, true) . "\n";
        echo "  Subject: " . var_export($subject, true) . "\n";

        $this->assertNotNull($from, "From header should be present");
        $this->assertNotNull($subject, "Subject header should be present");
    }

    public function testRealEmail_AttachmentWithStrangeCharacters(): void {
        $emailPath = '/organizer-data/test-emails/attachment-with-strange-characters.eml';

        if (!file_exists($emailPath)) {
            $this->fail("Test email file not found: $emailPath");
        }

        $rawEmail = file_get_contents($emailPath);

        $zbatesonMessage = $this->parseWithZbateson($rawEmail);

        $this->assertNotNull($zbatesonMessage, "Zbateson should parse email with strange attachment names");

        $from = $zbatesonMessage->getHeaderValue('from');
        $subject = $zbatesonMessage->getHeaderValue('subject');

        echo "\n[Real email: attachment-with-strange-characters]\n";
        echo "  From: " . var_export($from, true) . "\n";
        echo "  Subject: " . var_export($subject, true) . "\n";

        // Check attachment handling
        $attachmentCount = $zbatesonMessage->getAttachmentCount();
        echo "  Attachment count: " . $attachmentCount . "\n";

        if ($attachmentCount > 0) {
            $attachment = $zbatesonMessage->getAttachmentPart(0);
            if ($attachment) {
                $filename = $attachment->getFilename();
                echo "  First attachment filename: " . var_export($filename, true) . "\n";
            }
        }

        $this->assertNotNull($from, "From header should be present");
        $this->assertNotNull($subject, "Subject header should be present");
    }

    // ========================================================================
    // Test 6: Body extraction
    // ========================================================================

    public function testBodyExtraction_PlainText(): void {
        $email = "From: sender@example.com\r\n" .
                "To: recipient@example.com\r\n" .
                "Subject: Test\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "\r\n" .
                "This is the email body with Norwegian: æøå ÆØÅ";

        $zbatesonMessage = $this->parseWithZbateson($email);

        $body = $zbatesonMessage->getTextContent();

        echo "\n[Body extraction - plain text] Body: " . var_export($body, true) . "\n";

        $this->assertStringContainsString('æøå', $body, "Norwegian characters should be preserved in body");
    }

    public function testBodyExtraction_Multipart(): void {
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

        $zbatesonMessage = $this->parseWithZbateson($email);

        $textBody = $zbatesonMessage->getTextContent();
        $htmlBody = $zbatesonMessage->getHtmlContent();

        echo "\n[Body extraction - multipart]\n";
        echo "  Text body: " . var_export($textBody, true) . "\n";
        echo "  HTML body: " . var_export($htmlBody, true) . "\n";

        $this->assertNotNull($textBody, "Text body should be extracted");
        $this->assertNotNull($htmlBody, "HTML body should be extracted");
    }
}
