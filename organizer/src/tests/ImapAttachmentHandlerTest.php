<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapAttachmentHandler;
use Imap\ImapConnection;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapAttachmentHandler.php';

class ImapAttachmentHandlerTest extends TestCase {
    private $mockWrapper;
    private $connection;
    private $handler;
    private $testServer = '{imap.test.com:993/imap/ssl}';
    private $testEmail = 'test@test.com';
    private $testPassword = 'password123';
    private $debugLogs = [];

    protected function setUp(): void {
        $this->mockWrapper = $this->createMock(ImapWrapper::class);
        $this->connection = $this->getMockBuilder(ImapConnection::class)
            ->setConstructorArgs([
                $this->testServer,
                $this->testEmail,
                $this->testPassword,
                false,
                $this->mockWrapper
            ])
            ->onlyMethods(['logDebug'])
            ->getMock();

        // Capture debug logs
        $this->debugLogs = [];
        $this->connection->method('logDebug')
            ->willReturnCallback(function($message) {
                $this->debugLogs[] = $message;
            });

        $this->handler = new ImapAttachmentHandler($this->connection);

        // Setup default mock behavior
        $this->mockWrapper->method('lastError')->willReturn('');
    }

    private function createTestPart(): stdClass {
        $part = new stdClass();
        $part->ifdparameters = false;
        $part->ifparameters = false;
        return $part;
    }

    public function testProcessAttachmentsWithNoStructure(): void {
        // Setup mock connection
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        // Mock fetchstructure to return empty structure
        $emptyStructure = new stdClass();
        $this->mockWrapper->expects($this->once())
            ->method('fetchstructure')
            ->with($resource, 1, FT_UID)
            ->willReturn($emptyStructure);

        $attachments = $this->handler->processAttachments(1);
        $this->assertEmpty($attachments);

        fclose($resource);
    }

    public function testProcessAttachmentsWithValidStructure(): void {
        // Setup mock connection
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        // Create test structure with attachment
        $structure = new stdClass();
        $part = $this->createTestPart();
        
        // Setup dparameters
        $dParam = new stdClass();
        $dParam->attribute = 'filename';
        $dParam->value = 'test.pdf';
        $part->ifdparameters = true;
        $part->dparameters = [$dParam];
        
        // Setup parameters
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'test.pdf';
        $part->ifparameters = true;
        $part->parameters = [$param];
        
        $structure->parts = [$part];

        // Mock fetchstructure
        $this->mockWrapper->expects($this->once())
            ->method('fetchstructure')
            ->with($resource, 1, FT_UID)
            ->willReturn($structure);

        // Mock utf8 conversion
        $this->mockWrapper->method('utf8')
            ->willReturnCallback(function($str) { return $str; });

        $attachments = $this->handler->processAttachments(1);
        
        $this->assertCount(1, $attachments);
        $this->assertEquals('test.pdf', $attachments[0]->name);
        $this->assertEquals('pdf', $attachments[0]->filetype);

        fclose($resource);
    }

    public function testProcessAttachmentsWithDifferentFileTypes(): void {
        $validTypes = [
            'test.pdf' => 'pdf',
            'doc.docx' => 'docx',
            'image.jpg' => 'jpg',
            'data.xlsx' => 'xlsx',
            'archive.zip' => 'zip',
            'text.txt' => 'txt'
        ];

        foreach ($validTypes as $filename => $expectedType) {
            // Reset debug logs for each iteration
            $this->debugLogs = [];
            
            // Setup mock connection for each iteration
            $resource = fopen('php://memory', 'r');
            $this->mockWrapper = $this->createMock(ImapWrapper::class);
            $this->mockWrapper->method('open')->willReturn($resource);
            $this->mockWrapper->method('lastError')->willReturn('');
            
            $this->connection = $this->getMockBuilder(ImapConnection::class)
                ->setConstructorArgs([
                    $this->testServer,
                    $this->testEmail,
                    $this->testPassword,
                    false,
                    $this->mockWrapper
                ])
                ->onlyMethods(['logDebug'])
                ->getMock();

            $this->connection->method('logDebug')
                ->willReturnCallback(function($message) {
                    $this->debugLogs[] = $message;
                });

            $this->handler = new ImapAttachmentHandler($this->connection);
            $this->connection->openConnection();

            // Create test structure
            $structure = new stdClass();
            $part = $this->createTestPart();
            
            // Setup dparameters with the test filename
            $dParam = new stdClass();
            $dParam->attribute = 'filename';
            $dParam->value = $filename;
            $part->ifdparameters = true;
            $part->dparameters = [$dParam];
            
            // Also set up parameters with the same filename
            $param = new stdClass();
            $param->attribute = 'name';
            $param->value = $filename;
            $part->ifparameters = true;
            $part->parameters = [$param];
            
            $structure->parts = [$part];

            // Mock fetchstructure with expects() to ensure unique behavior for each iteration
            $this->mockWrapper->expects($this->once())
                ->method('fetchstructure')
                ->with($resource, 1, FT_UID)
                ->willReturn($structure);

            // Mock utf8 conversion
            $this->mockWrapper->method('utf8')
                ->willReturnCallback(function($str) { return $str; });

            $attachments = $this->handler->processAttachments(1);
            
            $this->assertCount(1, $attachments, "No attachment found for filename: $filename");
            $this->assertEquals($expectedType, $attachments[0]->filetype, 
                "Failed for filename: $filename\nDebug logs:\n" . implode("\n", $this->debugLogs));

            fclose($resource);
        }
    }

    public function testProcessAttachmentsWithUnknownTypeIsKept(): void {
        // Setup mock connection
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        // Create test structure
        $structure = new stdClass();
        $part = $this->createTestPart();
        
        // Setup parameters
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'test.xyz';
        $part->ifparameters = true;
        $part->parameters = [$param];
        
        $structure->parts = [$part];

        // Mock fetchstructure
        $this->mockWrapper->method('fetchstructure')
            ->willReturn($structure);

        // Mock utf8 conversion
        $this->mockWrapper->method('utf8')
            ->willReturnCallback(function($str) { return $str; });

        $attachments = $this->handler->processAttachments(1);

        $this->assertCount(1, $attachments, json_encode($attachments, JSON_PRETTY_PRINT));
        $this->assertEquals('test.xyz', $attachments[0]->name);
        $this->assertEquals('xyz', $attachments[0]->filetype);
        $this->assertEquals(1, $attachments[0]->partNumber);

        fclose($resource);
    }

    private function createNamedPart(string $name): stdClass {
        $part = $this->createTestPart();
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = $name;
        $part->ifparameters = true;
        $part->parameters = [$param];
        return $part;
    }

    public function testProcessAttachmentsKeepsMimePartNumberWhenEarlierPartIsSkipped(): void {
        // :: Setup
        // Body (part 1), a part without name that is not an attachment (part 2), a pdf (part 3).
        // The pdf content must be fetched from part 3, not from the skipped part 2.
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        $structure = new stdClass();
        $structure->parts = [
            $this->createTestPart(),
            $this->createTestPart(),
            $this->createNamedPart('Mobilabonnement Gjesdal.pdf'),
        ];
        $this->mockWrapper->method('fetchstructure')->willReturn($structure);

        // :: Act
        $attachments = $this->handler->processAttachments(1);

        // :: Assert
        $this->assertCount(1, $attachments, json_encode($attachments, JSON_PRETTY_PRINT));
        $this->assertEquals('Mobilabonnement Gjesdal.pdf', $attachments[0]->name);
        $this->assertEquals(3, $attachments[0]->partNumber, 'Part number must be the MIME part, not the index among kept attachments');

        fclose($resource);
    }

    public function testProcessAttachmentsWithWebpImage(): void {
        // :: Setup
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        $structure = new stdClass();
        $structure->parts = [
            $this->createTestPart(),
            $this->createNamedPart('Organisasjonskart (2).webp'),
            $this->createNamedPart('Mobilabonnement Gjesdal.pdf'),
        ];
        $this->mockWrapper->method('fetchstructure')->willReturn($structure);

        // :: Act
        $attachments = $this->handler->processAttachments(1);

        // :: Assert
        $this->assertCount(2, $attachments, json_encode($attachments, JSON_PRETTY_PRINT));
        $this->assertEquals('Organisasjonskart (2).webp', $attachments[0]->name);
        $this->assertEquals('webp', $attachments[0]->filetype);
        $this->assertEquals(2, $attachments[0]->partNumber);
        $this->assertEquals('Mobilabonnement Gjesdal.pdf', $attachments[1]->name);
        $this->assertEquals(3, $attachments[1]->partNumber);

        fclose($resource);
    }

    private function createUnnamedPart(int $type, string $subtype): stdClass {
        $part = $this->createTestPart();
        $part->type = $type;
        $part->ifsubtype = true;
        $part->subtype = $subtype;
        return $part;
    }

    public function testProcessAttachmentsKeepsUnnamedPartsAfterTheBody(): void {
        // :: Setup
        // multipart/mixed: body (part 1), then a forwarded email, a pdf and an
        // image of a type we have no extension for, none of them with a name.
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        $structure = new stdClass();
        $structure->type = TYPEMULTIPART;
        $structure->subtype = 'MIXED';
        $structure->parts = [
            $this->createUnnamedPart(TYPETEXT, 'PLAIN'),
            $this->createUnnamedPart(TYPEMESSAGE, 'RFC822'),
            $this->createUnnamedPart(TYPEAPPLICATION, 'PDF'),
            $this->createUnnamedPart(TYPEIMAGE, 'X-SOMETHING'),
        ];
        $this->mockWrapper->method('fetchstructure')->willReturn($structure);

        // :: Act
        $attachments = $this->handler->processAttachments(1);

        // :: Assert
        $summary = array_map(fn($a) => [$a->name, $a->filename, $a->filetype, $a->partNumber], $attachments);
        $this->assertEquals([
            ['unnamed-part-2.eml', 'unnamed-part-2.eml', 'eml', 2],
            ['unnamed-part-3.pdf', 'unnamed-part-3.pdf', 'pdf', 3],
            ['unnamed-part-4', 'unnamed-part-4', 'UNKNOWN', 4],
        ], $summary);

        fclose($resource);
    }

    public function testProcessAttachmentsSkipsNestedMultipartWithoutName(): void {
        // :: Setup
        // multipart/mixed whose part 2 is itself a multipart: not one file, skipped.
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        $structure = new stdClass();
        $structure->type = TYPEMULTIPART;
        $structure->subtype = 'MIXED';
        $structure->parts = [
            $this->createUnnamedPart(TYPETEXT, 'PLAIN'),
            $this->createUnnamedPart(TYPEMULTIPART, 'ALTERNATIVE'),
            $this->createNamedPart('Mobilabonnement Gjesdal.pdf'),
        ];
        $this->mockWrapper->method('fetchstructure')->willReturn($structure);

        // :: Act
        $attachments = $this->handler->processAttachments(1);

        // :: Assert
        $summary = array_map(fn($a) => [$a->name, $a->filetype, $a->partNumber], $attachments);
        $this->assertEquals([['Mobilabonnement Gjesdal.pdf', 'pdf', 3]], $summary);

        fclose($resource);
    }

    public function testProcessAttachmentsIgnoresUnnamedPartsOfAlternativeBody(): void {
        // :: Setup
        // multipart/alternative: the unnamed parts are the plain and html versions
        // of the body, not attachments.
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        $structure = new stdClass();
        $structure->type = TYPEMULTIPART;
        $structure->subtype = 'ALTERNATIVE';
        $structure->parts = [
            $this->createUnnamedPart(TYPETEXT, 'PLAIN'),
            $this->createUnnamedPart(TYPETEXT, 'HTML'),
        ];
        $this->mockWrapper->method('fetchstructure')->willReturn($structure);

        // :: Act
        $attachments = $this->handler->processAttachments(1);

        // :: Assert
        $this->assertEquals([], $attachments);

        fclose($resource);
    }

    public static function unencodedTransferEncodings(): array {
        return ['7bit' => [0], '8bit' => [1], 'binary' => [2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unencodedTransferEncodings')]
    public function testGetAttachmentContentReturnsUnencodedPartAsIs(int $encoding): void {
        // :: Setup
        // .ics, .htm, .eml and other text attachments are often sent without base64.
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        $part = $this->createNamedPart('invitasjon.ics');
        $part->encoding = $encoding;
        $structure = new stdClass();
        $structure->parts = [$this->createTestPart(), $part];
        $this->mockWrapper->method('fetchstructure')->willReturn($structure);
        $this->mockWrapper->method('fetchbody')->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

        // :: Act
        $content = $this->handler->getAttachmentContent(1, 2);

        // :: Assert
        $this->assertEquals("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", $content);

        fclose($resource);
    }

    private function invokeDecodeUtf8String(string $input): string {
        $method = new \ReflectionMethod($this->handler, 'decodeUtf8String');
        $method->setAccessible(true);
        return $method->invoke($this->handler, $input);
    }

    public function testDecodeIso88591QuotedPrintableFilename(): void {
        // :: Setup
        // Multi-part encoded filename from production (Issue #153)
        $encoded = '=?iso-8859-1?Q?Klage_p=E5_m=E5lrettet_utestengelse_av_journalister_fra_po?= =?iso-8859-1?Q?stjournal.pdf?=';

        // :: Act
        $decoded = $this->invokeDecodeUtf8String($encoded);

        // :: Assert
        $this->assertStringContainsString('Klage på målrettet', $decoded,
            "Norwegian characters not decoded. Got: $decoded");
        $this->assertStringContainsString('.pdf', $decoded,
            "File extension not preserved. Got: $decoded");
        $this->assertStringNotContainsString('=?', $decoded,
            "MIME encoded-word markers still present. Got: $decoded");
        $extension = pathinfo($decoded, PATHINFO_EXTENSION);
        $this->assertEquals('pdf', $extension,
            "pathinfo() could not extract extension from decoded filename. Got: $decoded");
    }

    public function testDecodeUtf8Base64Filename(): void {
        // :: Setup
        $encoded = '=?UTF-8?B?' . base64_encode('Dokumentår.pdf') . '?=';

        // :: Act
        $decoded = $this->invokeDecodeUtf8String($encoded);

        // :: Assert
        $this->assertEquals('Dokumentår.pdf', $decoded,
            "UTF-8 Base64 filename not decoded correctly. Got: $decoded");
    }

    public function testDecodeRfc2231ParameterEncoding(): void {
        // :: Setup
        $encoded = "iso-8859-1''Dokument%20med%20%E6%F8%E5.pdf";

        // :: Act
        $decoded = $this->invokeDecodeUtf8String($encoded);

        // :: Assert
        $this->assertStringContainsString('Dokument med', $decoded,
            "RFC 2231 parameter not decoded. Got: $decoded");
        $this->assertStringContainsString('.pdf', $decoded,
            "File extension not preserved. Got: $decoded");
        $this->assertStringNotContainsString('%E6', $decoded,
            "Percent-encoded characters still present. Got: $decoded");
    }

    public function testDecodePlainFilename(): void {
        // :: Setup
        $plain = 'simple-document.pdf';

        // :: Act
        $decoded = $this->invokeDecodeUtf8String($plain);

        // :: Assert
        $this->assertEquals('simple-document.pdf', $decoded,
            "Plain filename should pass through unchanged. Got: $decoded");
    }

    public function testProcessAttachmentsWithSpecialEncodings(): void {
        $testCases = [
            // Test ISO-8859-1 encoded string
            "iso-8859-1''test%20%E6%F8.pdf" => "test æø.pdf",
            // Test Base64 encoded UTF-8 string
            "=?utf-8?B?" . base64_encode("test æø.pdf") . "?=" => "test æø.pdf",
            // Test plain string
            "test.pdf" => "test æø.pdf"
        ];

        foreach ($testCases as $input => $expected) {
            // Setup mock connection
            $resource = fopen('php://memory', 'r');
            $this->mockWrapper->method('open')->willReturn($resource);
            $this->connection->openConnection();

            // Create test structure
            $structure = new stdClass();
            $part = $this->createTestPart();
            
            // Setup parameters
            $param = new stdClass();
            $param->attribute = 'name';
            $param->value = $input;
            $part->ifparameters = true;
            $part->parameters = [$param];
            
            $structure->parts = [$part];

            // Mock fetchstructure
            $this->mockWrapper->method('fetchstructure')
                ->willReturn($structure);

            // Mock utf8 conversion
            $this->mockWrapper->method('utf8')
                ->willReturnCallback(function($str) { 
                    // Special handling for Base64 encoded strings
                    if (strpos($str, '=?utf-8?B?') === 0) {
                        $str = preg_replace('/\=\?utf\-8\?B\?(.*?)\?\=/', '$1', $str);
                        return base64_decode($str);
                    }
                    return $str; 
                });

            $attachments = $this->handler->processAttachments(1);
            
            $this->assertCount(1, $attachments);
            $this->assertEquals($expected, $attachments[0]->name, "Failed for input: $input");

            fclose($resource);
        }
    }
}
