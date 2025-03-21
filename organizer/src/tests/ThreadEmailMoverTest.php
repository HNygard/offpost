<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadEmailMover.php');

class ThreadEmailMoverTest extends TestCase {
    private const DMARC_EMAIL = 'dmarc@offpost.no';
    private $mockConnection;
    private $mockFolderManager;
    private $mockEmailProcessor;
    private $threadEmailMover;

    protected function setUp(): void {
        parent::setUp();
        
        // Create mocks
        $this->mockConnection = $this->createMock(ImapConnection::class);
        $this->mockFolderManager = $this->createMock(ImapFolderManager::class);
        $this->mockEmailProcessor = $this->createMock(ImapEmailProcessor::class);
        
        // Initialize ThreadEmailMover with mocks
        $this->threadEmailMover = new ThreadEmailMover(
            $this->mockConnection,
            $this->mockFolderManager,
            $this->mockEmailProcessor
        );
    }

    public function testBuildEmailToFolderMapping() {
        // Create test thread data
        $threads = [
            (object)[
                'entity_id' => 'test-entity',
                'threads' => [
                    (object)[
                        'title' => 'Thread 1',
                        'my_email' => 'test1@example.com',
                        'archived' => false
                    ],
                    (object)[
                        'title' => 'Thread 2',
                        'my_email' => 'test2@example.com',
                        'archived' => true
                    ],
                    (object)[
                        'title' => 'Thread 3',
                        'my_email' => 'dmarc@offpost.no', // Should be skipped
                        'archived' => false
                    ]
                ]
            ]
        ];

        $mapping = $this->threadEmailMover->buildEmailToFolderMapping($threads);

        // Verify mapping
        $this->assertArrayHasKey('test1@example.com', $mapping);
        $this->assertEquals('INBOX.test-entity - Thread 1', $mapping['test1@example.com']);
        
        // Archived threads should not be in mapping
        $this->assertArrayNotHasKey('test2@example.com', $mapping);
        
        // Verify dmarc@offpost.no is not in mapping
        $this->assertArrayNotHasKey('dmarc@offpost.no', $mapping);
    }

    public function testProcessMailbox() {
        // Create test email data
        $testEmail = (object)[
            'uid' => 1,
            'mailHeaders' => (object)[
                'from' => 'test1@example.com',
                'to' => 'entity@example.com'
            ]
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailAddresses')
            ->with($testEmail->mailHeaders)
            ->willReturn(['test1@example.com']);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Test - Thread 1');

        // Test email-to-folder mapping
        $emailToFolder = [
            'test1@example.com' => 'INBOX.Test - Thread 1'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

        // Verify no unmatched addresses
        $this->assertEmpty($unmatchedAddresses);
    }

    public function testProcessMailboxWithMultipleEmailAddresses() {
        // Create test email with multiple addresses
        $testEmail = (object)[
            'uid' => 1,
            'mailHeaders' => (object)[
                'from' => 'sender1@example.com',
                'to' => 'entity@example.com, sender2@example.com',
                'cc' => 'sender3@example.com, sender4@example.com'
            ]
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailAddresses')
            ->with($testEmail->mailHeaders)
            ->willReturn([
                'sender1@example.com',
                'sender2@example.com',
                'sender3@example.com',
                'sender4@example.com'
            ]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Test - Thread 1');

        // Test email-to-folder mapping for one of the addresses
        $emailToFolder = [
            'sender2@example.com' => 'INBOX.Test - Thread 1'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

        // Only addresses not in emailToFolder should be returned as unmatched
        $this->assertCount(0, $unmatchedAddresses, 'No addresses should be unmatched since email was moved to a thread folder');
    }

    public function testProcessMailboxWithDmarcEmail() {
        // Create test email from DMARC
        $testEmail = (object)[
            'uid' => 1,
            'mailHeaders' => (object)[
                'from' => self::DMARC_EMAIL,
                'to' => 'entity@example.com'
            ]
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailAddresses')
            ->with($testEmail->mailHeaders)
            ->willReturn([self::DMARC_EMAIL]);

        // DMARC emails should be left in INBOX
        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX');

        $emailToFolder = ['other@example.com' => 'INBOX.Test - Thread 1'];
        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

        // DMARC emails should not be included in unmatched addresses
        $this->assertEmpty($unmatchedAddresses);
    }

    public function testProcessMailboxWithConnectionError() {
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willThrowException(new Exception('Connection failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Connection failed');

        $this->threadEmailMover->processMailbox('INBOX', []);
    }

    public function testProcessMailboxWithMoveError() {
        $testEmail = (object)[
            'uid' => 1,
            'mailHeaders' => (object)[
                'from' => 'test@example.com',
                'to' => 'entity@example.com'
            ]
        ];

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test@example.com']);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->willThrowException(new Exception('Move failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Move failed');

        $emailToFolder = ['test@example.com' => 'INBOX.Test - Thread 1'];
        $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);
    }

    public function testProcessMailboxWithUnmatchedEmail() {
        // Create test email data with unmatched address
        $testEmail = (object)[
            'uid' => 1,
            'mailHeaders' => (object)[
                'from' => 'unmatched@example.com',
                'to' => 'entity@example.com'
            ]
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailAddresses')
            ->with($testEmail->mailHeaders)
            ->willReturn(['unmatched@example.com']);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX');

        // Empty email-to-folder mapping
        $emailToFolder = [];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

        // Verify unmatched address is returned
        $this->assertCount(1, $unmatchedAddresses);
        $this->assertEquals('unmatched@example.com', $unmatchedAddresses[0]);
    }
}
