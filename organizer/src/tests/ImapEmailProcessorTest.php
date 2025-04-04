<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapEmailProcessor;
use Imap\ImapConnection;
use Imap\ImapWrapper;
use Imap\ImapEmail;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/../class/Imap/ImapEmail.php';

class ImapEmailProcessorTest extends TestCase {
    private $mockWrapper;
    private $connection;
    private $processor;
    private $tempCacheFile;
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
        
        // Create temporary cache file
        $this->tempCacheFile = sys_get_temp_dir() . '/test-cache-' . uniqid() . '.json';
        
        // Initialize processor
        $this->processor = new ImapEmailProcessor($this->connection, $this->tempCacheFile);

        // Setup default mock behavior for connection closing
        $this->mockWrapper->method('close')->willReturn(true);
        $this->mockWrapper->method('lastError')->willReturn('');
    }

    protected function tearDown(): void {
        if (file_exists($this->tempCacheFile)) {
            unlink($this->tempCacheFile);
        }
    }

    public function testNeedsUpdateWithNewFolder(): void {
        $this->assertTrue($this->processor->needsUpdate('TestFolder'));
    }

    public function testNeedsUpdateWithCachedFolder(): void {
        // First update should create cache entry
        $this->processor->updateFolderCache('TestFolder');
        
        // Should not need update when no timestamp provided
        $this->assertFalse($this->processor->needsUpdate('TestFolder'));
        
        // Should need update when old timestamp provided
        $oldTimestamp = date('Y-m-d H:i:s', time() + 86400); // 1 day in future
        $this->assertTrue($this->processor->needsUpdate('TestFolder', $oldTimestamp));
        
        // Should not need update when past timestamp provided
        $pastTimestamp = date('Y-m-d H:i:s', time() - 86400); // 1 day in past
        $this->assertFalse($this->processor->needsUpdate('TestFolder', $pastTimestamp));
    }

    public function testGetEmails(): void {
        // Setup mock connection with specific folder
        $resource = fopen('php://memory', 'r');
        $testFolder = 'TestFolder';
        
        // Expect openConnection to be called with the specific folder
        $this->mockWrapper->expects($this->once())
            ->method('open')
            ->with(
                $this->stringContains($testFolder),
                $this->equalTo($this->testEmail),
                $this->equalTo($this->testPassword)
            )
            ->willReturn($resource);

        // Mock search results
        $this->mockWrapper->expects($this->once())
            ->method('search')
            ->with($resource, "ALL", SE_UID)
            ->willReturn([1, 2]); // Return two message UIDs

        // Mock message number conversion
        $this->mockWrapper->method('msgno')
            ->willReturnMap([
                [$resource, 1, 1],
                [$resource, 2, 2]
            ]);

        // Create test headers
        $headers1 = $this->createTestHeaders('Test Subject 1', 'sender1@test.com');
        $headers2 = $this->createTestHeaders('Test Subject 2', 'sender2@test.com');

        // Mock headerinfo calls
        $this->mockWrapper->method('headerinfo')
            ->willReturnMap([
                [$resource, 1, $headers1],
                [$resource, 2, $headers2]
            ]);

        // Mock body retrieval
        $this->mockWrapper->method('body')
            ->willReturn('Test email body');

        // Mock UTF-8 conversion
        $this->mockWrapper->method('utf8')
            ->willReturnCallback(function($str) { return $str; });

        // Process emails
        $emails = $this->processor->getEmails($testFolder);

        // Verify results
        $this->assertCount(2, $emails);
        $this->assertEquals('Test Subject 1', $emails[0]->subject);
        $this->assertEquals('Test Subject 2', $emails[1]->subject);

        fclose($resource);
    }

    public function testGetEmailDirection(): void {
        $myEmail = 'test@example.com';
        
        // Create ImapEmail instance with outgoing headers
        $outgoingHeaders = $this->createEmailHeaders('test@example.com');
        $outgoingEmail = new ImapEmail();
        $outgoingEmail->mailHeaders = $outgoingHeaders;
        
        // Test outgoing email
        $this->assertEquals('OUT', $outgoingEmail->getEmailDirection($myEmail));
        
        // Create ImapEmail instance with incoming headers
        $incomingHeaders = $this->createEmailHeaders('sender@external.com');
        $incomingEmail = new ImapEmail();
        $incomingEmail->mailHeaders = $incomingHeaders;
        
        // Test incoming email
        $this->assertEquals('IN', $incomingEmail->getEmailDirection($myEmail));
    }

    public function testGenerateEmailFilename(): void {
        $myEmail = 'test@example.com';
        $date = '2023-12-25 10:30:00';
        
        // Create ImapEmail instance with outgoing headers
        $outgoingHeaders = $this->createEmailHeaders('test@example.com', $date);
        $outgoingEmail = new ImapEmail();
        $outgoingEmail->mailHeaders = $outgoingHeaders;
        $outgoingEmail->date = $date;
        
        // Test outgoing email filename
        $expectedOutgoing = '2023-12-25_103000 - OUT';
        $this->assertEquals($expectedOutgoing, $outgoingEmail->generateEmailFilename($myEmail));
        
        // Create ImapEmail instance with incoming headers
        $incomingHeaders = $this->createEmailHeaders('sender@external.com', $date);
        $incomingEmail = new ImapEmail();
        $incomingEmail->mailHeaders = $incomingHeaders;
        $incomingEmail->date = $date;
        
        // Test incoming email filename
        $expectedIncoming = '2023-12-25_103000 - IN';
        $this->assertEquals($expectedIncoming, $incomingEmail->generateEmailFilename($myEmail));
    }

    public function testGetEmailAddresses(): void {
        $headers = $this->createComplexEmailHeaders();
        $expectedAddresses = [
            'to@example.com',
            'from@example.com',
            'reply@example.com',
            'sender@example.com'
        ];
        
        $email = new ImapEmail();
        $email->mailHeaders = $headers;
        
        $addresses = $email->getEmailAddresses();
        sort($addresses);
        sort($expectedAddresses);
        
        $this->assertEquals($expectedAddresses, $addresses);
    }
    
    public function testGetEmailAddressesSequentialKeys(): void {
        // :: Setup
        // Create headers with duplicate addresses to trigger array_unique behavior
        $headers = $this->createComplexEmailHeaders();
        
        // Add a duplicate address in the 'to' field
        $duplicate = new stdClass();
        $duplicate->mailbox = 'from'; // Same as from@example.com
        $duplicate->host = 'example.com';
        $headers->to[] = $duplicate;
        
        $email = new ImapEmail();
        $email->mailHeaders = $headers;
        
        // :: Act
        $addresses = $email->getEmailAddresses();
        
        // :: Assert
        // Check that the keys are sequential (0, 1, 2, 3)
        $expectedKeys = range(0, count($addresses) - 1);
        $actualKeys = array_keys($addresses);
        
        $this->assertEquals($expectedKeys, $actualKeys, 
            "Email addresses array should have sequential keys. Got: " . 
            json_encode($addresses, JSON_PRETTY_PRINT));
    }

    public function testGetEmailAddressesWithCc(): void {
        // :: Setup
        // Create headers with CC addresses
        $headers = $this->createComplexEmailHeaders();
        
        // Add CC addresses
        $cc1 = new stdClass();
        $cc1->mailbox = 'cc1';
        $cc1->host = 'example.com';
        
        $cc2 = new stdClass();
        $cc2->mailbox = 'cc2';
        $cc2->host = 'example.com';
        
        $headers->cc = [$cc1, $cc2];
        
        $email = new ImapEmail();
        $email->mailHeaders = $headers;
        
        // :: Act
        $addresses = $email->getEmailAddresses();
        
        // :: Assert
        $expectedAddresses = [
            'to@example.com',
            'from@example.com',
            'reply@example.com',
            'sender@example.com',
            'cc1@example.com',
            'cc2@example.com'
        ];
        
        sort($addresses);
        sort($expectedAddresses);
        
        $this->assertEquals($expectedAddresses, $addresses, 
            "Email addresses should include CC addresses. Got: " . 
            json_encode($addresses, JSON_PRETTY_PRINT));
    }

    private function createTestHeaders($subject, $fromEmail): object {
        $headers = new stdClass();
        $headers->subject = $subject;
        $headers->date = '2023-12-25 10:30:00';
        $headers->toaddress = 'recipient@test.com';
        $headers->fromaddress = $fromEmail;
        $headers->senderaddress = $fromEmail;
        $headers->reply_toaddress = $fromEmail;

        list($mailbox, $host) = explode('@', $fromEmail);
        $from = new stdClass();
        $from->mailbox = $mailbox;
        $from->host = $host;
        $headers->from = [$from];

        return $headers;
    }

    private function createEmailHeaders(string $fromEmail, string $date = '2023-12-25 10:30:00'): object {
        $headers = new stdClass();
        
        list($mailbox, $host) = explode('@', $fromEmail);
        
        $from = new stdClass();
        $from->mailbox = $mailbox;
        $from->host = $host;
        
        $headers->from = [$from];
        $headers->date = $date;
        
        return $headers;
    }

    private function createComplexEmailHeaders(): object {
        $headers = new stdClass();
        
        // Create to address
        $to = new stdClass();
        $to->mailbox = 'to';
        $to->host = 'example.com';
        $headers->to = [$to];
        
        // Create from address
        $from = new stdClass();
        $from->mailbox = 'from';
        $from->host = 'example.com';
        $headers->from = [$from];
        
        // Create reply-to address
        $replyTo = new stdClass();
        $replyTo->mailbox = 'reply';
        $replyTo->host = 'example.com';
        $headers->reply_to = [$replyTo];
        
        // Create sender address
        $sender = new stdClass();
        $sender->mailbox = 'sender';
        $sender->host = 'example.com';
        $headers->sender = [$sender];
        
        return $headers;
    }
}
