<?php
// Simple test runner for DKIM fix without needing full vendor dependencies

// Mock the classes and functions we need
class ExtractedEmailBody {
    public $plain_text;
    public $html;
}

// Create a test version of the stripProblematicHeaders method
function stripProblematicHeaders($eml) {
    // List of headers that should be stripped to avoid parsing issues
    $problematicHeaders = [
        'DKIM-Signature',           // Can contain malformed data that breaks parsing
        'ARC-Seal',                 // Authentication headers not needed for content extraction
        'ARC-Message-Signature',    // Authentication headers not needed for content extraction
        'ARC-Authentication-Results', // Authentication headers not needed for content extraction
        'Authentication-Results',    // Authentication headers not needed for content extraction
    ];

    // Split email into header and body parts
    $parts = preg_split('/\r?\n\r?\n/', $eml, 2);
    if (count($parts) < 2) {
        // If there's no clear header/body separation, return as-is
        return $eml;
    }

    $headerPart = $parts[0];
    $bodyPart = $parts[1];

    // Process headers line by line
    $headerLines = preg_split('/\r?\n/', $headerPart);
    $cleanedHeaders = [];
    $skipCurrentHeader = false;

    foreach ($headerLines as $line) {
        // Check if this is a new header (starts at beginning of line with header name)
        if (preg_match('/^([A-Za-z-]+):\s*/', $line, $matches)) {
            $headerName = $matches[1];
            $skipCurrentHeader = in_array($headerName, $problematicHeaders);
            
            if (!$skipCurrentHeader) {
                $cleanedHeaders[] = $line;
            }
        } elseif (!$skipCurrentHeader && (substr($line, 0, 1) === ' ' || substr($line, 0, 1) === "\t")) {
            // This is a continuation line for a header we're keeping
            $cleanedHeaders[] = $line;
        }
        // If $skipCurrentHeader is true, we ignore both the header line and continuation lines
    }

    // Rebuild the email
    return implode("\n", $cleanedHeaders) . "\n\n" . $bodyPart;
}

// Test 1: Verify DKIM header is stripped
function testDkimHeaderStripping() {
    echo "Test 1: DKIM header stripping...\n";
    
    $emailWithDkim = "Return-Path: <test@example.com>\r\n" .
        "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=example.com;\r\n" .
        "\tb=somebase64data\r\n" .
        "From: sender@example.com\r\n" .
        "To: recipient@example.com\r\n" .
        "Subject: Test\r\n" .
        "\r\n" .
        "Body content\r\n";

    $cleanedEmail = stripProblematicHeaders($emailWithDkim);
    
    // Verify DKIM-Signature header is removed
    if (strpos($cleanedEmail, 'DKIM-Signature') !== false) {
        echo "FAIL: DKIM-Signature header was not stripped\n";
        echo "Cleaned email:\n" . $cleanedEmail . "\n";
        return false;
    }
    
    // Verify other headers are preserved
    if (strpos($cleanedEmail, 'From: sender@example.com') === false) {
        echo "FAIL: From header was incorrectly removed\n";
        return false;
    }
    
    // Verify body is preserved
    if (strpos($cleanedEmail, 'Body content') === false) {
        echo "FAIL: Email body was lost\n";
        return false;
    }
    
    echo "PASS: DKIM-Signature header properly stripped\n";
    return true;
}

// Test 2: Verify multi-line DKIM headers are properly handled
function testMultiLineDkimStripping() {
    echo "Test 2: Multi-line DKIM header stripping...\n";
    
    $emailWithMultiLineDkim = "Return-Path: <postmottak@varoy.kommune.no>\r\n" .
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

    $cleanedEmail = stripProblematicHeaders($emailWithMultiLineDkim);
    
    // Verify DKIM-Signature header and all its continuation lines are removed
    if (strpos($cleanedEmail, 'DKIM-Signature') !== false) {
        echo "FAIL: DKIM-Signature header was not stripped\n";
        echo "Problem area:\n";
        $lines = explode("\n", $cleanedEmail);
        foreach ($lines as $i => $line) {
            if (strpos($line, 'DKIM') !== false) {
                echo "Line $i: $line\n";
            }
        }
        return false;
    }
    
    // Check for any remnants of the DKIM signature
    if (strpos($cleanedEmail, 'D1RyvwGvMBQY371EWYO86jmKOmZwcKfiXURMrZaZkjz7') !== false) {
        echo "FAIL: DKIM signature data was not fully stripped\n";
        return false;
    }
    
    // Verify important headers are preserved
    if (strpos($cleanedEmail, 'From: =?iso-8859-1?Q?Postmottak_V=E6r=F8y_kommune?=') === false) {
        echo "FAIL: From header was incorrectly removed\n";
        return false;
    }
    
    if (strpos($cleanedEmail, 'Subject: Test Subject') === false) {
        echo "FAIL: Subject header was incorrectly removed\n";
        return false;
    }
    
    // Verify body is preserved
    if (strpos($cleanedEmail, 'Test body content.') === false) {
        echo "FAIL: Email body was lost\n";
        return false;
    }
    
    echo "PASS: Multi-line DKIM-Signature header properly stripped\n";
    return true;
}

// Test 3: Verify other authentication headers are stripped
function testOtherAuthHeadersStripping() {
    echo "Test 3: Other authentication headers stripping...\n";
    
    $emailWithAuthHeaders = "Return-Path: <test@example.com>\r\n" .
        "ARC-Seal: i=1; a=rsa-sha256; s=arcselector; d=microsoft.com;\r\n" .
        "\tb=somedata\r\n" .
        "ARC-Message-Signature: i=1; a=rsa-sha256; c=relaxed/relaxed;\r\n" .
        "\tb=moredata\r\n" .
        "Authentication-Results: mx.microsoft.com 1; spf=pass\r\n" .
        "From: sender@example.com\r\n" .
        "To: recipient@example.com\r\n" .
        "Subject: Test\r\n" .
        "\r\n" .
        "Body content\r\n";

    $cleanedEmail = stripProblematicHeaders($emailWithAuthHeaders);
    
    // Verify authentication headers are removed
    $authHeaders = ['ARC-Seal', 'ARC-Message-Signature', 'Authentication-Results'];
    foreach ($authHeaders as $header) {
        if (strpos($cleanedEmail, $header) !== false) {
            echo "FAIL: $header was not stripped\n";
            return false;
        }
    }
    
    // Verify other headers are preserved
    if (strpos($cleanedEmail, 'From: sender@example.com') === false) {
        echo "FAIL: From header was incorrectly removed\n";
        return false;
    }
    
    echo "PASS: Authentication headers properly stripped\n";
    return true;
}

// Run all tests
echo "Running DKIM header stripping tests...\n\n";

$tests = [
    'testDkimHeaderStripping',
    'testMultiLineDkimStripping', 
    'testOtherAuthHeadersStripping'
];

$passed = 0;
$total = count($tests);

foreach ($tests as $test) {
    if ($test()) {
        $passed++;
    }
    echo "\n";
}

echo "Results: $passed/$total tests passed\n";

if ($passed === $total) {
    echo "All tests PASSED! ✓\n";
    exit(0);
} else {
    echo "Some tests FAILED! ✗\n";
    exit(1);
}