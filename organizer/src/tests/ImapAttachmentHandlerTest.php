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
            ->will($this->returnCallback(function($message) {
                $this->debugLogs[] = $message;
            }));

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
                ->will($this->returnCallback(function($message) {
                    $this->debugLogs[] = $message;
                }));

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

    public function testProcessAttachmentsWithUnsupportedType(): void {
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
        
        $this->assertEmpty($attachments);

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
