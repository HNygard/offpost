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

    protected function setUp(): void {
        $this->mockWrapper = $this->createMock(ImapWrapper::class);
        $this->connection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            false,
            $this->mockWrapper
        );

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

    public function testSaveAttachment(): void {
        // Setup mock connection
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

        // Create test structure
        $structure = new stdClass();
        $part = $this->createTestPart();
        $part->encoding = 3; // BASE64
        $structure->parts = [$part];

        // Mock fetchstructure
        $this->mockWrapper->expects($this->once())
            ->method('fetchstructure')
            ->with($resource, 1, FT_UID)
            ->willReturn($structure);

        // Mock fetchbody to return base64 encoded content
        $this->mockWrapper->expects($this->once())
            ->method('fetchbody')
            ->with($resource, 1, '1', FT_UID)
            ->willReturn(base64_encode('test content'));

        // Create temp file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test_attachment');
        
        // Create test attachment object
        $attachment = new stdClass();
        $attachment->filename = 'test.txt';

        // Save attachment
        $this->handler->saveAttachment(1, 1, $attachment, $tempFile);

        // Verify content
        $this->assertEquals('test content', file_get_contents($tempFile));

        // Cleanup
        unlink($tempFile);
        fclose($resource);
    }

    public function testProcessAttachmentsWithDifferentFileTypes(): void {
        $validTypes = [
            'test.pdf' => 'pdf',
            'doc.docx' => 'pdf',
            'image.jpg' => 'pdf',
            'data.xlsx' => 'pdf',
            'archive.zip' => 'pdf',
            'text.txt' => 'pdf'
        ];

        foreach ($validTypes as $filename => $expectedType) {
            // Setup mock connection
            $resource = fopen('php://memory', 'r');
            $this->mockWrapper->method('open')->willReturn($resource);
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
            
            $structure->parts = [$part];

            // Mock fetchstructure
            $this->mockWrapper->method('fetchstructure')
                ->willReturn($structure);

            // Mock utf8 conversion
            $this->mockWrapper->method('utf8')
                ->willReturnCallback(function($str) { return $str; });

            $attachments = $this->handler->processAttachments(1);
            
            $this->assertCount(1, $attachments);
            $this->assertEquals($expectedType, $attachments[0]->filetype, "Failed for filename: $filename");

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
