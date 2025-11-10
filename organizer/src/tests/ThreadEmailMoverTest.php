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
                'entity_id' => '000000000-test-entity-development',
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
                        'archived' => true
                    ]
                ]
            ]
        ];

        $mapping = $this->threadEmailMover->buildEmailToFolderMapping($threads);

        // Verify mapping
        $this->assertArrayHasKey('test1@example.com', $mapping);
        $this->assertEquals('INBOX.000000000-test-entity-development - Thread 1', $mapping['test1@example.com']);
        
        // Archived threads should not be in mapping
        $this->assertArrayNotHasKey('test2@example.com', $mapping);
        
        // Verify dmarc@offpost.no is in mapping
        $this->assertArrayHasKey('dmarc@offpost.no', $mapping);
    }

    public function testProcessMailbox() {
        // Create test email data
        // Create mock ImapEmail
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        // Set up mock expectations
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test1@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Test - Thread 1');

        // Test email-to-folder mapping
        $emailToFolder = [
            'test1@example.com' => 'INBOX.Test - Thread 1'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder)['unmatched'];

        // Verify no unmatched addresses
        $this->assertEmpty($unmatchedAddresses);
    }

    public function testProcessMailboxWithMultipleEmailAddresses() {
        // Create mock ImapEmail with multiple addresses
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        // Set up mock expectations
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn([
                'sender1@example.com',
                'sender2@example.com',
                'sender3@example.com',
                'sender4@example.com'
            ]);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Test - Thread 1');

        // Test email-to-folder mapping for one of the addresses
        $emailToFolder = [
            'sender2@example.com' => 'INBOX.Test - Thread 1'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder)['unmatched'];

        // Only addresses not in emailToFolder should be returned as unmatched
        $this->assertCount(0, $unmatchedAddresses, 'No addresses should be unmatched since email was moved to a thread folder');
    }

    public function testProcessMailboxWithDmarcEmail() {
        // Create mock ImapEmail from DMARC
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        // Set up mock expectations
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn([self::DMARC_EMAIL]);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$mockEmail]);

        // DMARC emails should be left in INBOX
        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX');

        $emailToFolder = ['other@example.com' => 'INBOX.Test - Thread 1'];
        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder)['unmatched'];

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
        // Create mock ImapEmail
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        // Set up mock expectations
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->willThrowException(new Exception('Move failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Move failed');

        $emailToFolder = ['test@example.com' => 'INBOX.Test - Thread 1'];
        $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);
    }

    public function testProcessMailboxWithUnmatchedEmail() {
        // Create mock ImapEmail with unmatched address
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        // Set up mock expectations
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['unmatched@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX');

        // Empty email-to-folder mapping
        $emailToFolder = [];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder)['unmatched'];

        // Verify unmatched address is returned
        $this->assertCount(1, $unmatchedAddresses);
        $this->assertEquals('unmatched@example.com', $unmatchedAddresses[0]);
    }

    // Tests for non-INBOX mailboxes

    public function testProcessNonInboxMailboxWithMatchingThread() {
        // Test: Email in "INBOX.Test - Thread 1" that matches Thread 1 should stay in that folder
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test1@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Test - Thread 1')
            ->willReturn([$mockEmail]);

        // Email should be moved back to the same thread folder
        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Test - Thread 1');

        $emailToFolder = [
            'test1@example.com' => 'INBOX.Test - Thread 1'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX.Test - Thread 1', $emailToFolder)['unmatched'];

        // No unmatched addresses since the email matched its thread
        $this->assertEmpty($unmatchedAddresses);
    }

    public function testProcessNonInboxMailboxWithDifferentThread() {
        // Test: Email in "INBOX.Test - Thread 1" that matches Thread 2 should move to Thread 2
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test2@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Test - Thread 1')
            ->willReturn([$mockEmail]);

        // Email should be moved to Thread 2's folder
        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Test - Thread 2');

        $emailToFolder = [
            'test1@example.com' => 'INBOX.Test - Thread 1',
            'test2@example.com' => 'INBOX.Test - Thread 2'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX.Test - Thread 1', $emailToFolder)['unmatched'];

        // No unmatched addresses since the email matched a thread (Thread 2)
        $this->assertEmpty($unmatchedAddresses);
    }

    public function testProcessNonInboxMailboxWithNoMatch() {
        // Test: Email in "INBOX.Test - Thread 1" with no match should move to INBOX
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['unmatched@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Test - Thread 1')
            ->willReturn([$mockEmail]);

        // Email should be moved to INBOX (default folder)
        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX');

        $emailToFolder = [
            'test1@example.com' => 'INBOX.Test - Thread 1'
        ];

        $unmatchedAddresses = $this->threadEmailMover->processMailbox('INBOX.Test - Thread 1', $emailToFolder)['unmatched'];

        // The unmatched address should be reported
        $this->assertCount(1, $unmatchedAddresses);
        $this->assertEquals('unmatched@example.com', $unmatchedAddresses[0]);
    }

    public function testProcessNonInboxMailboxWithMultipleEmails() {
        // Test: Multiple emails in a thread folder with different destinations
        $mockEmail1 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail1->uid = 1;
        $mockEmail2 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail2->uid = 2;
        $mockEmail3 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail3->uid = 3;
        
        $mockEmail1->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['stays@example.com']);
        
        $mockEmail2->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['moves@example.com']);
        
        $mockEmail3->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['unmatched@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Entity - Thread A')
            ->willReturn([$mockEmail1, $mockEmail2, $mockEmail3]);

        // Use a callback matcher to verify the moves
        $expectedMoves = [
            [1, 'INBOX.Entity - Thread A'],  // stays in same folder
            [2, 'INBOX.Entity - Thread B'],  // moves to different thread
            [3, 'INBOX']                      // moves to INBOX
        ];
        $moveIndex = 0;
        
        $this->mockFolderManager->expects($this->exactly(3))
            ->method('moveEmail')
            ->willReturnCallback(function($uid, $folder) use (&$moveIndex, $expectedMoves) {
                $this->assertEquals($expectedMoves[$moveIndex][0], $uid, "Move #{$moveIndex}: UID mismatch");
                $this->assertEquals($expectedMoves[$moveIndex][1], $folder, "Move #{$moveIndex}: Folder mismatch");
                $moveIndex++;
            });

        $emailToFolder = [
            'stays@example.com' => 'INBOX.Entity - Thread A',
            'moves@example.com' => 'INBOX.Entity - Thread B'
        ];

        $result = $this->threadEmailMover->processMailbox('INBOX.Entity - Thread A', $emailToFolder);

        // Only the unmatched address should be reported
        $this->assertCount(1, $result['unmatched']);
        $this->assertEquals('unmatched@example.com', $result['unmatched'][0]);
    }

    public function testProcessSubfolderMailbox() {
        // Test: Processing emails from a subfolder like "INBOX.Projects.CustomerA - Thread"
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['customer@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Projects.CustomerA - Thread')
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX.Projects.CustomerB - Thread');

        $emailToFolder = [
            'customer@example.com' => 'INBOX.Projects.CustomerB - Thread'
        ];

        $result = $this->threadEmailMover->processMailbox('INBOX.Projects.CustomerA - Thread', $emailToFolder);

        // No unmatched addresses
        $this->assertEmpty($result['unmatched']);
    }
}
