<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapEmailProcessor;
use Imap\ImapConnection;
use Imap\ImapWrapper;
use OpenAI\OpenAISummarizer;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapEmailProcessor.php';
require_once __DIR__ . '/../class/OpenAISummarizer.php';

class ImapEmailProcessorTest extends TestCase {
    private $mockWrapper;
    private $connection;
    private $processor;
    private $tempCacheFile;
    private $testServer = '{imap.test.com:993/imap/ssl}';
    private $testEmail = 'test@test.com';
    private $testPassword = 'password123';
    private $mockSummarizer;

    protected function setUp(): void {
        $this->mockWrapper = $this->createMock(ImapWrapper::class);
        $this->mockSummarizer = $this->createMock(OpenAISummarizer::class);
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
        $this->processor = new ImapEmailProcessor($this->connection, $this->tempCacheFile, $this->mockSummarizer);

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

    public function testProcessEmails(): void {
        // Setup mock connection
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->connection->openConnection();

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
        $emails = $this->processor->processEmails('INBOX');

        // Verify results
        $this->assertCount(2, $emails);
        $this->assertEquals('Test Subject 1', $emails[0]->subject);
        $this->assertEquals('Test Subject 2', $emails[1]->subject);

        fclose($resource);
    }

    public function testGetEmailDirection(): void {
        $myEmail = 'test@example.com';
        
        // Test outgoing email
        $outgoingHeaders = $this->createEmailHeaders('test@example.com');
        $this->assertEquals('OUT', $this->processor->getEmailDirection($outgoingHeaders, $myEmail));
        
        // Test incoming email
        $incomingHeaders = $this->createEmailHeaders('sender@external.com');
        $this->assertEquals('IN', $this->processor->getEmailDirection($incomingHeaders, $myEmail));
    }

    public function testGenerateEmailFilename(): void {
        $myEmail = 'test@example.com';
        $date = '2023-12-25 10:30:00';
        
        // Test outgoing email filename
        $outgoingHeaders = $this->createEmailHeaders('test@example.com', $date);
        $expectedOutgoing = '2023-12-25_103000 - OUT';
        $this->assertEquals($expectedOutgoing, $this->processor->generateEmailFilename($outgoingHeaders, $myEmail));
        
        // Test incoming email filename
        $incomingHeaders = $this->createEmailHeaders('sender@external.com', $date);
        $expectedIncoming = '2023-12-25_103000 - IN';
        $this->assertEquals($expectedIncoming, $this->processor->generateEmailFilename($incomingHeaders, $myEmail));
    }

    public function testGetEmailAddresses(): void {
        $headers = $this->createComplexEmailHeaders();
        $expectedAddresses = [
            'to@example.com',
            'from@example.com',
            'reply@example.com',
            'sender@example.com'
        ];
        
        $addresses = $this->processor->getEmailAddresses($headers);
        sort($addresses);
        sort($expectedAddresses);
        
        $this->assertEquals($expectedAddresses, $addresses);
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
