<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadEmailMover.php');

class ThreadEmailMoverTest extends TestCase {
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
                'title_prefix' => 'Test',
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
        $this->assertEquals('INBOX.Test - Thread 1', $mapping['test1@example.com']);
        
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
            ->method('processEmails')
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
            ->method('processEmails')
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
