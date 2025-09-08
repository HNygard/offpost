<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';

/**
 * Test for fixing DKIM-Signature header parsing issues
 */
class ThreadEmailHeaderProcessingTest extends PHPUnit\Framework\TestCase {
    // Sample email with problematic DKIM-Signature header (similar to the one from the issue)
    protected $problematicEmail = "Return-Path: <postmottak@varoy.kommune.no>\r\n" .
        "Delivered-To: <test@example.com>\r\n" .
        "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n" .
        "\td=custmx.one.com; s=20201015;\r\n" .
        "\th=mime-version:content-transfer-encoding:content-type:in-reply-to:references:\r\n" .
        "\t message-id:date:subject:to:from:x-halone-refid:x-halone-sa:from:x-halone-sa:\r\n" .
        "\t x-halone-refid;\r\n" .
        "\tbh=rIv00cKr8Xj97WFZtdmVkJKsLtJVgVpaj8sxpPtmnM0=;\r\n" .
        "\tbÑRyvwGvMBQY371EWYO86jmKOmZwcKfiXURMrZaZkjz7dUf1Hq8mCHVcn+dPWm8dk3vSipkYLDQJH\r\n" .
        "\t iXL6nkuCFGUJz/uPGXWaVqB1skd6V0jHR17zvGQBFyIZyMsVOibl0XvY9bmZwf7Is6jCLUm2OJJ/Uo\r\n" .
        "\t EdxSemT/iRaznT2cNZU0tU40umIm5HTQxw2lL/ltAhfvDKfSrITTwXbelqMk0GjsdXgI309XYqm1cQ\r\n" .
        "\t BLObJxLrARR2ZzHmkERv267dTCjzJm3JV1GQJK4JAWO3iDzJBB6FG8njUCz5tAQvpeRPu+GnTLKFMq\r\n" .
        "\t sZ+DzQgUPHuBimow2XjATT3yZQ3JfEA==\r\n" .
        "From: =?iso-8859-1?Q?Postmottak_V=E6r=F8y_kommune?= <postmottak@varoy.kommune.no>\r\n" .
        "To: <test@example.com>\r\n" .
        "Subject: Test Subject\r\n" .
        "Date: Sun, 7 Sep 2025 08:28:35 +0000\r\n" .
        "Content-Type: text/plain; charset=\"iso-8859-1\"\r\n" .
        "Content-Transfer-Encoding: quoted-printable\r\n" .
        "MIME-Version: 1.0\r\n" .
        "\r\n" .
        "Test body content.\r\n";

    public function testDkimSignatureHeaderCausesException() {

        // With the fix, this should now work instead of throwing an exception
        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($this->problematicEmail);
        $this->assertStringContainsString('Test body content.', $result->plain_text, "DKIM-Signature header should be handled properly without throwing an exception");
        $this->assertStringNotContainsString('ERROR', $result->plain_text, "Email should parse successfully without error");
    }

    public function testEmailWithoutDkimHeaderWorks() {
        // Same email but without the DKIM-Signature header
        $cleanEmail = "Return-Path: <postmottak@varoy.kommune.no>\r\n" .
            "Delivered-To: <test@example.com>\r\n" .
            "From: =?iso-8859-1?Q?Postmottak_V=E6r=F8y_kommune?= <postmottak@varoy.kommune.no>\r\n" .
            "To: <test@example.com>\r\n" .
            "Subject: Test Subject\r\n" .
            "Date: Sun, 7 Sep 2025 08:28:35 +0000\r\n" .
            "Content-Type: text/plain; charset=\"iso-8859-1\"\r\n" .
            "Content-Transfer-Encoding: quoted-printable\r\n" .
            "MIME-Version: 1.0\r\n" .
            "\r\n" .
            "Test body content.\r\n";

        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($cleanEmail);
        $this->assertNotNull($result, "Email without DKIM-Signature should parse successfully");
    }

    public function testDkimSignatureHeaderIsStripped() {
        // Test that the stripProblematicHeaders method actually removes DKIM-Signature
        $emailWithDkim = "Return-Path: <test@example.com>\r\n" .
            "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=example.com;\r\n" .
            "\tb=somebase64data\r\n" .
            "From: sender@example.com\r\n" .
            "To: recipient@example.com\r\n" .
            "Subject: Test\r\n" .
            "\r\n" .
            "Body content\r\n";

        // Use reflection to access the private method
        $reflection = new ReflectionClass('ThreadEmailExtractorEmailBody');
        $method = $reflection->getMethod('stripProblematicHeaders');
        $method->setAccessible(true);
        
        $cleanedEmail = $method->invoke(null, $emailWithDkim);
        
        // Verify DKIM-Signature header is removed
        $this->assertStringContainsString('DKIM-Signature: REMOVED', $cleanedEmail, "DKIM-Signature header should be stripped");
        
        // Verify other headers are preserved
        $this->assertStringContainsString('From: sender@example.com', $cleanedEmail, "From header should be preserved");
        
        // Verify body is preserved
        $this->assertStringContainsString('Body content', $cleanedEmail, "Email body should be preserved");
    }

    public function testLaminasMailLibraryDirectCallThrowsExceptionWithProblematicDkim() {
        // Expect exception when calling Laminas Mail library directly without header stripping
        $this->expectException(Laminas\Mail\Header\Exception\InvalidArgumentException::class);
        new \Laminas\Mail\Storage\Message(['raw' => $this->problematicEmail]);
    }

}