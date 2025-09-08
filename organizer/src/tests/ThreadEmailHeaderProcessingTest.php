<?php

require_once __DIR__ . '/../class/Extraction/ThreadEmailExtractorEmailBody.php';

/**
 * Test for fixing DKIM-Signature header parsing issues
 */
class ThreadEmailHeaderProcessingTest {

    public function testDkimSignatureHeaderCausesException() {
        // Sample email with problematic DKIM-Signature header (similar to the one from the issue)
        $problematicEmail = "Return-Path: <postmottak@varoy.kommune.no>\r\n" .
            "Delivered-To: <test@example.com>\r\n" .
            "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n" .
            "\td=custmx.one.com; s=20201015;\r\n" .
            "\th=mime-version:content-transfer-encoding:content-type:in-reply-to:references:\r\n" .
            "\t message-id:date:subject:to:from:x-halone-refid:x-halone-sa:from:x-halone-sa:\r\n" .
            "\t x-halone-refid;\r\n" .
            "\tbh=rIv00cKr8Xj97WFZtdmVkJKsLtJVgVpaj8sxpPtmnM0=;\r\n" .
            "\tb=D1RyvwGvMBQY371EWYO86jmKOmZwcKfiXURMrZaZkjz7dUf1Hq8mCHVcn+dPWm8dk3vSipkYLDQJH\r\n" .
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

        // With the fix, this should now work instead of throwing an exception
        $result = ThreadEmailExtractorEmailBody::extractContentFromEmail($problematicEmail);
        echo "Test passed: DKIM-Signature header is now handled properly\n";
        return true;
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
        echo "Test passed: Email without DKIM-Signature parses successfully\n";
        return true;
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
        if (strpos($cleanedEmail, 'DKIM-Signature') !== false) {
            echo "ERROR: DKIM-Signature header was not stripped\n";
            return false;
        }
        
        // Verify other headers are preserved
        if (strpos($cleanedEmail, 'From: sender@example.com') === false) {
            echo "ERROR: From header was incorrectly removed\n";
            return false;
        }
        
        // Verify body is preserved
        if (strpos($cleanedEmail, 'Body content') === false) {
            echo "ERROR: Email body was lost\n";
            return false;
        }
        
        echo "Test passed: DKIM-Signature header is properly stripped while preserving other content\n";
        return true;
    }

    public function runAllTests() {
        echo "Running DKIM-Signature header processing tests...\n";
        
        try {
            $test1 = $this->testDkimSignatureHeaderCausesException();
        } catch (Exception $e) {
            echo "Test 1 failed with exception: " . $e->getMessage() . "\n";
            $test1 = false;
        }
        
        try {
            $test2 = $this->testEmailWithoutDkimHeaderWorks();
        } catch (Exception $e) {
            echo "Test 2 failed with exception: " . $e->getMessage() . "\n";
            $test2 = false;
        }
        
        try {
            $test3 = $this->testDkimSignatureHeaderIsStripped();
        } catch (Exception $e) {
            echo "Test 3 failed with exception: " . $e->getMessage() . "\n";
            $test3 = false;
        }
        
        if ($test1 && $test2 && $test3) {
            echo "All tests passed!\n";
            return true;
        } else {
            echo "Some tests failed!\n";
            return false;
        }
    }
}

// Run the tests if this file is executed directly
if (basename(__FILE__) == basename($_SERVER["SCRIPT_NAME"])) {
    $test = new ThreadEmailHeaderProcessingTest();
    $test->runAllTests();
}