<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Extraction/EmailParseSnapshot.php';

/**
 * Pins everything Offpost reads from an EML through the mail parser library.
 *
 * The fixtures in tests/fixtures/email-parsing/ are recorded from the current
 * parser. Any parser change that alters what we extract fails here, and the
 * diff shows exactly which field changed for which email. See
 * docs/email-parsing.md for how to (re)record a fixture.
 */
class EmailParseSnapshotTest extends TestCase {

    public function testSnapshotOfSimpleMultipartEmail(): void {
        // :: Setup
        $eml = "From: Ola Nordmann <ola@example.com>\r\n"
            . "To: Kari <kari@example.com>\r\n"
            . "X-Forwarded-For: Forwarded@Offpost.no\r\n"
            . "Subject: =?UTF-8?Q?Innsyn_i_s=C3=B8knad?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"b1\"\r\n"
            . "\r\n"
            . "--b1\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Hei p\xC3\xA5 deg\r\n"
            . "--b1\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "\r\n"
            . "<p>Hei <b>p\xC3\xA5</b> deg</p>\r\n"
            . "--b1--\r\n";

        // :: Act
        $snapshot = EmailParseSnapshot::fromEml($eml);

        // :: Assert
        $this->assertEquals([
            'subject' => 'Innsyn i søknad',
            'x_forwarded_for' => ['forwarded@offpost.no'],
            'plain_text' => 'Hei på deg',
            // HTML-derived text is not trimmed, unlike plain_text
            'html' => "Hei på deg\n\n\n",
            'parse_error' => null,
        ], $snapshot);
    }

    public function testSnapshotOfUnparseableEmailRecordsErrorInsteadOfTrace(): void {
        // :: Setup
        // An invalid From address fails parsing of the whole email
        $eml = "From: <<<>>>@@@\r\nSubject: x\r\n\r\nBody\r\n";

        // :: Act
        $snapshot = EmailParseSnapshot::fromEml($eml);

        // :: Assert
        $this->assertNull($snapshot['plain_text'], json_encode($snapshot, JSON_PRETTY_PRINT));
        $this->assertNull($snapshot['html'], json_encode($snapshot, JSON_PRETTY_PRINT));
        $this->assertEquals(
            'Exception: Failed to parse email due to problematic header on line 1',
            $snapshot['parse_error'],
            'Only the first trace line (class: message) is kept, so snapshots are machine-independent'
        );
    }

    public function testSnapshotRecordsExceptionThatEscapesExtraction(): void {
        // :: Setup
        // multipart without boundary: the parser throws outside extractContentFromEmail()'s try
        $eml = "From: a@b.no\r\nSubject: x\r\nContent-Type: multipart/mixed\r\n\r\nBody\r\n";

        // :: Act
        $snapshot = EmailParseSnapshot::fromEml($eml);

        // :: Assert
        $this->assertEquals([
            'subject' => 'x',
            'x_forwarded_for' => [],
            'plain_text' => null,
            'html' => null,
            'parse_error' => 'UNCAUGHT Laminas\Mail\Storage\Exception\RuntimeException: no boundary found in content type to split message',
        ], $snapshot);
    }

    public function testInvalidUtf8IsKeptAsBase64(): void {
        // :: Act
        $safe = EmailParseSnapshot::jsonSafe(['subject' => "S\xF8knad", 'list' => ["ok", "\xFF"], 'none' => null]);

        // :: Assert
        $this->assertEquals([
            'subject' => ['invalid_utf8_base64' => base64_encode("S\xF8knad")],
            'list' => ['ok', ['invalid_utf8_base64' => base64_encode("\xFF")]],
            'none' => null,
        ], $safe);
        $this->assertNotFalse(json_encode($safe), 'Snapshot must always be JSON-encodable');
    }

    public function testRawLatin1SubjectIsCurrentlyTurnedIntoBrokenEncodedWord(): void {
        // :: Setup
        // Current behaviour, pinned on purpose (not desired): the header sanitizer
        // wraps the raw 0xF8 byte in an encoded-word without the closing "=",
        // which the parser then shows literally
        $eml = "From: a@b.no\r\nSubject: S\xF8knad\r\n\r\nBody\r\n";

        // :: Act
        $snapshot = EmailParseSnapshot::fromEml($eml);

        // :: Assert
        $this->assertEquals('=?UTF-8?Q?S=F8knad?', $snapshot['subject']);
    }

    public function testSubjectFromImapHeadersGoesThroughSameDecoding(): void {
        // :: Act
        $subject = EmailParseSnapshot::subjectFromImapHeaders(json_encode(['subject' => '=?iso-8859-1?Q?S=F8knad_om_innsyn?=']));

        // :: Assert
        $this->assertEquals('Søknad om innsyn', $subject);
    }

    public function testSubjectFromImapHeadersWithReplacementParser(): void {
        // :: Setup
        $headers = json_encode(['subject' => '=?UTF-8?B?U8O4a25hZA==?= om innsyn']);

        // :: Act
        $old = EmailParseSnapshot::subjectFromImapHeaders($headers);
        $new = EmailParseSnapshot::subjectFromImapHeaders($headers, EmailParseSnapshot::PARSER_MIME);
        $none = EmailParseSnapshot::subjectFromImapHeaders(json_encode(['from' => 'x']), EmailParseSnapshot::PARSER_MIME);

        // :: Assert
        $this->assertEquals('Søknad om innsyn', $old);
        $this->assertEquals('Søknad om innsyn', $new);
        $this->assertEquals('', $none, 'No subject in the IMAP headers gives "" like getEmailSubjectFromImapHeaders()');
    }

    #[DataProvider('fixtureFiles')]
    public function testFixtureSnapshotIsUnchanged(string $emlFile): void {
        // :: Setup
        $expectedFile = preg_replace('/\.eml$/', '.json', $emlFile);
        $this->assertFileExists($expectedFile, 'Record the snapshot with: php bin/email-parse-snapshot.php --record ' . $emlFile);
        $expected = json_decode(file_get_contents($expectedFile), true);

        // :: Act
        $actual = EmailParseSnapshot::fromEml(file_get_contents($emlFile));

        // :: Assert
        $this->assertEquals($expected, $actual, 'Parsed result changed for ' . basename($emlFile));
    }

    public static function fixtureFiles(): array {
        $files = glob(__DIR__ . '/../fixtures/email-parsing/*.eml');
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }
        return $cases;
    }

    public function testFixtureCorpusIsNotEmpty(): void {
        // Guards against the data provider silently finding no files (wrong path)
        $this->assertGreaterThanOrEqual(20, count(self::fixtureFiles()), json_encode(array_keys(self::fixtureFiles()), JSON_PRETTY_PRINT));
    }
}
