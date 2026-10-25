<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/MimeEmailReader.php';

class MimeEmailReaderTest extends TestCase {

    public function testXForwardedForCommaInQuotedDisplayNameDoesNotSplitTheAddress(): void {
        // :: Setup
        $eml = "X-Forwarded-For: \"Doe, Jane\" <Jane@offpost.no>, plain@offpost.no\r\n"
            . "From: a@b.no\r\nSubject: x\r\n\r\nBody\r\n";

        // :: Act
        $addresses = MimeEmailReader::xForwardedFor($eml);

        // :: Assert
        $this->assertEquals(['jane@offpost.no', 'plain@offpost.no'], $addresses);
    }

    public function testDeclaredUtf16BodyIsDecodedNotTakenAsUtf8(): void {
        // :: Setup
        // "Hei" in UTF-16LE is H\0e\0i\0 - valid UTF-8 bytes, but not the text
        $eml = "From: a@b.no\r\nSubject: x\r\nContent-Type: text/plain; charset=UTF-16LE\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\nSABlAGkA\r\n";

        // :: Act
        $body = MimeEmailReader::body($eml);

        // :: Assert
        $this->assertEquals('Hei', $body->plain_text);
    }

    public function testDeclaredIso2022JpBodyIsDecoded(): void {
        // :: Setup
        // ISO-2022-JP is 7-bit with escape sequences, so it also passes as UTF-8
        $eml = "From: a@b.no\r\nSubject: x\r\nContent-Type: text/plain; charset=ISO-2022-JP\r\n\r\n"
            . mb_convert_encoding('こんにちは', 'ISO-2022-JP', 'UTF-8') . "\r\n";

        // :: Act
        $body = MimeEmailReader::body($eml);

        // :: Assert
        $this->assertEquals('こんにちは', $body->plain_text);
    }

    public function testUtf8BodyMislabelledAsIso88591StaysUtf8(): void {
        // :: Setup
        // Common in Norwegian mail: declared iso-8859-1, sent as UTF-8
        $eml = "From: a@b.no\r\nSubject: x\r\nContent-Type: text/plain; charset=iso-8859-1\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\nSøknad om innsyn\r\n";

        // :: Act
        $body = MimeEmailReader::body($eml);

        // :: Assert
        $this->assertEquals('Søknad om innsyn', $body->plain_text);
    }
}
