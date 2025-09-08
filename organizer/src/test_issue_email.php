<?php
// Test the exact email from the issue to verify the fix

// Include the stripProblematicHeaders function without running tests
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

// This is a close representation of the problematic email from the issue
$problematicEmailFromIssue = "Return-Path: <postmottak@varoy.kommune.no>\r\n" .
"Delivered-To: <removed>\r\n" .
"Received: from mx1.cst.mailpod11-cph3.one.com ([10.27.54.11])\r\n" .
"\tby mailstorage6.cst.mailpod11-cph3.one.com with LMTP\r\n" .
"\tid +DG/GzZCvWilWwAAclUnxw\r\n" .
"\t(envelope-from <postmottak@varoy.kommune.no>)\r\n" .
"\tfor <removed>; Sun, 07 Sep 2025 08:28:38 +0000\r\n" .
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
"X-HalOne-SA: -0.1\r\n" .
"X-HalOne-RefID: 155866::1757233718-4778B367-F89C0699/0/0\r\n" .
"X-HalOne-Spam-Probability: 0\r\n" .
"X-Forwarded-for: <removed>\r\n" .
"Authentication-Results: mx1.pub.mailpod11-cph3.one.com;\r\n" .
"\tspf=pass smtp.mailfrom=varoy.kommune.no smtp.remote-ip=40.107.159.84;\r\n" .
"\tdkim=pass header.d=varoy.kommune.no header.s=selector1 header.a=rsa-sha256 header.b=Ixmt1wMd;\r\n" .
"\tdmarc=pass header.from=varoy.kommune.no;\r\n" .
"DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=varoy.kommune.no;\r\n" .
" s=selector1;\r\n" .
" h=From:Date:Subject:Message-ID:Content-Type:MIME-Version:X-MS-Exchange-SenderADCheck;\r\n" .
" bh=rIv00cKr8Xj97WFZtdmVkJKsLtJVgVpaj8sxpPtmnM0=;\r\n" .
" b=Ixmt1wMdRUIpYKe6Ax+o/K+Dkf2Yd9yvg87DLuE74Ok8TNhq4Us4TxgYsnNOoQ2W7ZF7HeXYPV8IQk6CUocKfv7VwquK3e8LGgi99KIdk66i9X4hgwmRwbjQq4EwTl3t+ecN/QGAXK58Qcc0diyJ5zQ5JSsFicbHqwxRVwnpRtM=\r\n" .
"From: =?iso-8859-1?Q?Postmottak_V=E6r=F8y_kommune?=\r\n" .
"\t<postmottak@varoy.kommune.no>\r\n" .
"To: <removed>\r\n" .
"Subject: SV: Innsyn i lokaler for opptelling - Stortingsvalget 2025\r\n" .
"Thread-Topic: Innsyn i lokaler for opptelling - Stortingsvalget 2025\r\n" .
"Thread-Index: AQHcGH0UPla0AdtTCkejoKMbMA1gobSHcLDA\r\n" .
"Date: Sun, 7 Sep 2025 08:28:35 +0000\r\n" .
"Message-ID:\r\n" .
" <DU0PR10MB60330E8569C04997CBB198EEAE0DA@DU0PR10MB6033.EURPRD10.PROD.OUTLOOK.COM>\r\n" .
"Content-Type: text/plain; charset=\"iso-8859-1\"\r\n" .
"Content-Transfer-Encoding: quoted-printable\r\n" .
"MIME-Version: 1.0\r\n" .
"\r\n" .
"Hei,\r\n" .
"\r\n" .
"Svar p=E5 innsynsforesp=F8rsel vedr=F8rende stortingsvalget\r\n";

echo "Testing the exact problematic email from the issue...\n\n";

echo "Original email length: " . strlen($problematicEmailFromIssue) . " characters\n";
echo "Original contains DKIM-Signature: " . (strpos($problematicEmailFromIssue, 'DKIM-Signature') !== false ? 'YES' : 'NO') . "\n";
echo "Original contains Authentication-Results: " . (strpos($problematicEmailFromIssue, 'Authentication-Results') !== false ? 'YES' : 'NO') . "\n\n";

// Clean the email using our function
$cleanedEmail = stripProblematicHeaders($problematicEmailFromIssue);

echo "Cleaned email length: " . strlen($cleanedEmail) . " characters\n";
echo "Cleaned contains DKIM-Signature: " . (strpos($cleanedEmail, 'DKIM-Signature') !== false ? 'YES' : 'NO') . "\n";
echo "Cleaned contains Authentication-Results: " . (strpos($cleanedEmail, 'Authentication-Results') !== false ? 'YES' : 'NO') . "\n\n";

// Verify essential headers are preserved
$essentialHeaders = ['From:', 'To:', 'Subject:', 'Date:', 'Content-Type:', 'MIME-Version:'];
$allPreserved = true;

echo "Checking that essential headers are preserved:\n";
foreach ($essentialHeaders as $header) {
    $present = strpos($cleanedEmail, $header) !== false;
    echo "  $header " . ($present ? '✓' : '✗') . "\n";
    if (!$present) {
        $allPreserved = false;
    }
}

// Verify body content is preserved
$bodyPreserved = strpos($cleanedEmail, 'Svar p=E5 innsynsforesp=F8rsel') !== false;
echo "\nBody content preserved: " . ($bodyPreserved ? '✓' : '✗') . "\n";

if ($allPreserved && $bodyPreserved) {
    echo "\n✓ SUCCESS: Email successfully cleaned and should now parse without errors!\n";
} else {
    echo "\n✗ ERROR: Some essential content was lost during cleaning\n";
}

// Show a sample of the cleaned email
echo "\nFirst 500 characters of cleaned email:\n";
echo "=====================================\n";
echo substr($cleanedEmail, 0, 500) . "...\n";
echo "=====================================\n";