<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapAttachmentHandler;
use Imap\ImapConnection;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapAttachmentHandler.php';

class ImapAttachmentHandlerProcessAttachmentPartTest extends TestCase {
    private $mockWrapper;
    private $connection;
    private $handler;
    private $reflectionMethod;

    protected function setUp(): void {
        // :: Setup
        $this->mockWrapper = $this->createMock(ImapWrapper::class);
        $this->connection = $this->getMockBuilder(ImapConnection::class)
            ->setConstructorArgs([
                '{imap.test.com:993/imap/ssl}',
                'test@test.com',
                'password123',
                false,
                $this->mockWrapper
            ])
            ->onlyMethods(['logDebug', 'utf8'])
            ->getMock();

        $this->handler = new ImapAttachmentHandler($this->connection);

        // Use reflection to access private method
        $reflection = new ReflectionClass($this->handler);
        $this->reflectionMethod = $reflection->getMethod('processAttachmentPart');
        $this->reflectionMethod->setAccessible(true);

        // Setup default mock behavior
        $this->mockWrapper->method('lastError')->willReturn('');
        $this->connection->method('logDebug')->willReturn(null);
        $this->connection->method('utf8')->willReturnCallback(function($str) { return $str; });
    }

    private function createTestPart(): stdClass {
        $part = new stdClass();
        $part->ifdparameters = false;
        $part->ifparameters = false;
        $part->dparameters = [];
        $part->parameters = [];
        return $part;
    }

    public function testProcessAttachmentPartWithNoAttachmentParameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNull($result, 'Should return null when no attachment parameters are present');
    }

    public function testProcessAttachmentPartWithFilenameInDparameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifdparameters = true;
        
        $dParam = new stdClass();
        $dParam->attribute = 'filename';
        $dParam->value = 'test-document.pdf';
        $part->dparameters = [$dParam];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object when filename is present');
        $this->assertEquals('test-document.pdf', $result->filename, 'Filename should be set correctly');
        $this->assertEquals('test-document.pdf', $result->name, 'Name should be set to filename when name is empty');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithFilenameStarInDparameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifdparameters = true;
        
        $dParam = new stdClass();
        $dParam->attribute = 'filename*';
        $dParam->value = 'iso-8859-1\'\'test%20document.pdf';
        $part->dparameters = [$dParam];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object when filename* is present');
        $this->assertEquals('test document.pdf', $result->filename, 'Filename should be decoded correctly');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithNameInParameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'spreadsheet.xlsx';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object when name is present');
        $this->assertEquals('spreadsheet.xlsx', $result->name, 'Name should be set correctly');
        $this->assertEquals('spreadsheet.xlsx', $result->filename, 'Filename should be set to name when filename is empty');
        $this->assertEquals('xlsx', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithNameStarInParameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name*';
        $param->value = 'iso-8859-1\'\'document%20with%20spaces.docx';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object when name* is present');
        $this->assertEquals('document with spaces.docx', $result->name, 'Name should be decoded correctly');
        $this->assertEquals('docx', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithBothNameAndFilename(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifdparameters = true;
        $part->ifparameters = true;
        
        $dParam = new stdClass();
        $dParam->attribute = 'filename';
        $dParam->value = 'actual-filename.pdf';
        $part->dparameters = [$dParam];

        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'display-name.pdf';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object when both name and filename are present');
        $this->assertEquals('display-name.pdf', $result->name, 'Name should be preserved');
        $this->assertEquals('actual-filename.pdf', $result->filename, 'Filename should be preserved');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithSpecialCases(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = '=?UTF-8?Q?Stortingsvalg_=2D_Valgstyrets=5Fm=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epdf?=';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object for special case');
        $this->assertEquals('Stortingsvalg - Valgstyrets-møtebok-1806-2021.pdf', $result->name, 'Special case should be handled correctly');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithUnsupportedFileType(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'unknown.xyz';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNull($result, 'Should return null for unsupported file type');
    }

    public function testProcessAttachmentPartWithEmptyFilename(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = '';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNull($result, 'Should return null when filename is empty');
    }

    public function testProcessAttachmentPartWithSpecialFileTypeHandling(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'document. pdf'; // Space in extension
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object for file with space in extension');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly after space normalization');
    }

    public function testProcessAttachmentPartWithKnownUnknownFileType(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = 'Valgstyrets_møtebok_4649_2021-11-18_test.rda'; // Known special case with .rda extension
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object for known unknown file type');
        $this->assertEquals('UNKNOWN', $result->filetype, 'File type should be UNKNOWN for special case');
    }

    public function testProcessAttachmentPartWithUtf8EncodedString(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'name';
        $param->value = '=?utf-8?B?' . base64_encode('test æøå.pdf') . '?=';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // Create new mock with proper UTF-8 handling
        $this->connection = $this->getMockBuilder(ImapConnection::class)
            ->setConstructorArgs([
                '{imap.test.com:993/imap/ssl}',
                'test@test.com',
                'password123',
                false,
                $this->mockWrapper
            ])
            ->onlyMethods(['logDebug', 'utf8'])
            ->getMock();

        $this->connection->method('logDebug')->willReturn(null);
        $this->connection->method('utf8')->willReturnCallback(function($str) {
            // Handle Base64 encoded UTF-8 strings like the actual method does
            if (preg_match_all('/(\=\?utf\-8\?B\?[A-Za-z0-9=]*\?=)/', $str, $matches)) {
                foreach ($matches[0] as $match) {
                    $decoded = preg_replace('/\=\?utf\-8\?B\?(.*?)\?\=/', '$1', $match);
                    $decoded = base64_decode($decoded);
                    $str = str_replace($match, $decoded, $str);
                }
            }
            return $str;
        });

        $this->handler = new ImapAttachmentHandler($this->connection);

        // Use reflection to access private method
        $reflection = new ReflectionClass($this->handler);
        $this->reflectionMethod = $reflection->getMethod('processAttachmentPart');
        $this->reflectionMethod->setAccessible(true);

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object for UTF-8 encoded string');
        $this->assertEquals('test æøå.pdf', $result->name, 'UTF-8 string should be decoded correctly');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithMultipleParameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param1 = new stdClass();
        $param1->attribute = 'charset';
        $param1->value = 'utf-8';

        $param2 = new stdClass();
        $param2->attribute = 'name';
        $param2->value = 'important.docx';

        $param3 = new stdClass();
        $param3->attribute = 'boundary';
        $param3->value = 'boundary123';

        $part->parameters = [$param1, $param2, $param3];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object when name is among multiple parameters');
        $this->assertEquals('important.docx', $result->name, 'Name should be extracted correctly from multiple parameters');
        $this->assertEquals('docx', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithCaseInsensitiveAttributes(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifparameters = true;
        
        $param = new stdClass();
        $param->attribute = 'NAME'; // Uppercase
        $param->value = 'case-test.txt';
        $part->parameters = [$param];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $this->assertNotNull($result, 'Should return attachment object for uppercase attribute name');
        $this->assertEquals('case-test.txt', $result->name, 'Name should be extracted correctly regardless of case');
        $this->assertEquals('txt', $result->filetype, 'File type should be determined correctly');
    }

    public function testProcessAttachmentPartWithContinuationParameters(): void {
        // :: Setup
        $part = $this->createTestPart();
        $part->ifdparameters = true;
        $part->ifparameters = true;
        
        // Setup filename continuation parameters
        $dParam0 = new stdClass();
        $dParam0->attribute = 'filename*0*';
        $dParam0->value = "iso-8859-1''Forel%F8pig%20svar%20-%20Innsynsforesp%F8rsel_%20Val";
        
        $dParam1 = new stdClass();
        $dParam1->attribute = 'filename*1';
        $dParam1->value = 'gstyrets skriftelige rutine for forsegling, oppbevaring og trans';
        
        $dParam2 = new stdClass();
        $dParam2->attribute = 'filename*2*';
        $dParam2->value = 'port%20av%20valgmateriell%20-%20jfr.%20valgsforskriften%20%A79-1';
        
        $dParam3 = new stdClass();
        $dParam3->attribute = 'filename*3';
        $dParam3->value = ' - samt rutine for valget i 2023 og 2025 (hvis denne er klar)  .';
        
        $dParam4 = new stdClass();
        $dParam4->attribute = 'filename*4';
        $dParam4->value = 'pdf';
        
        // The parts need to be sorted, so we have a mix of ordering here as a test
        $part->dparameters = [$dParam4, $dParam1, $dParam2, $dParam3, $dParam0];
        
        // Setup name continuation parameters
        $param0 = new stdClass();
        $param0->attribute = 'name*0*';
        $param0->value = "iso-8859-1''Forel%F8pig%20svar%20-%20Innsynsforesp%F8rsel_%20Valgsty";
        
        $param1 = new stdClass();
        $param1->attribute = 'name*1';
        $param1->value = 'rets skriftelige rutine for forsegling, oppbevaring og transport av ';
        
        $param2 = new stdClass();
        $param2->attribute = 'name*2*';
        $param2->value = 'valgmateriell%20-%20jfr.%20valgsforskriften%20%A79-1%20-%20samt%20ru';
        
        $param3 = new stdClass();
        $param3->attribute = 'name*3';
        $param3->value = 'tine for valget i 2023 og 2025 (hvis denne er klar)  .pdf';
        
        // The parts need to be sorted, so we have a mix of ordering here as a test
        $part->parameters = [$param2, $param1, $param3, $param0];

        $uid = 123;
        $partNumber = 1;

        // :: Act
        $result = $this->reflectionMethod->invokeArgs($this->handler, [$uid, $part, $partNumber]);

        // :: Assert
        $expectedFilename = 'Foreløpig svar - Innsynsforespørsel_ Valgstyrets skriftelige rutine for forsegling, oppbevaring og transport av valgmateriell - jfr. valgsforskriften §9-1 - samt rutine for valget i 2023 og 2025 (hvis denne er klar)  .pdf';
        $expectedName = 'Foreløpig svar - Innsynsforespørsel_ Valgstyrets skriftelige rutine for forsegling, oppbevaring og transport av valgmateriell - jfr. valgsforskriften §9-1 - samt rutine for valget i 2023 og 2025 (hvis denne er klar)  .pdf';
        
        $this->assertNotNull($result, 'Should return attachment object for continuation parameters');
        $this->assertEquals($expectedFilename, $result->filename, 'Filename should be decoded and concatenated correctly from continuation parameters');
        $this->assertEquals($expectedName, $result->name, 'Name should be decoded and concatenated correctly from continuation parameters');
        $this->assertEquals('pdf', $result->filetype, 'File type should be determined correctly');
    }
}
