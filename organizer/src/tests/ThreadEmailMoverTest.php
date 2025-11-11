<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadEmailMover.php');
require_once(__DIR__ . '/../class/Database.php');

class ThreadEmailMoverTest extends TestCase {
    private const DMARC_EMAIL = 'dmarc@offpost.no';
    private $mockConnection;
    private $mockFolderManager;
    private $mockEmailProcessor;
    private $threadEmailMover;

    protected function setUp(): void {
        parent::setUp();
        
        // Skip ImapFolderStatus database operations for unit tests
        // This allows testing ThreadEmailMover without database dependency
        ImapFolderStatus::$skipDatabaseOperations = true;
        
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

    protected function tearDown(): void {
        // Reset the flag to not affect other tests
        ImapFolderStatus::$skipDatabaseOperations = false;
        parent::tearDown();
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
        $mockEmail->subject = 'Test Subject';
        $mockEmail->timestamp = time();
        
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

    /**
     * Integration test to verify error is saved to database for unmatched inbox emails
     * Note: This test requires a working database connection
     * 
     * @group integration
     */
    public function testProcessMailboxSavesErrorForUnmatchedInboxEmail() {
        // Create mock ImapEmail with unmatched address
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        $mockEmail->subject = 'Test Subject for Unmatched';
        $mockEmail->timestamp = time();
        
        // Set up mock expectations
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['unmatched-test@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, 'INBOX');

        // Start a database transaction for test isolation
        Database::beginTransaction();
        
        try {
            // Empty email-to-folder mapping
            $emailToFolder = [];

            $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

            // Verify unmatched address is returned
            $this->assertCount(1, $result['unmatched']);
            $this->assertEquals('unmatched-test@example.com', $result['unmatched'][0]);

            // Verify error was saved to database
            $emailIdentifier = date('Y-m-d__His', $mockEmail->timestamp) . '__' . md5($mockEmail->subject);
            $error = Database::queryOne(
                "SELECT * FROM thread_email_processing_errors WHERE email_identifier = ?",
                [$emailIdentifier]
            );

            $this->assertNotNull($error, 'Error should be saved to database');
            $this->assertEquals('Test Subject for Unmatched', $error['email_subject']);
            $this->assertEquals('unmatched-test@example.com', $error['email_addresses']);
            $this->assertEquals('unmatched_inbox_email', $error['error_type']);
            $this->assertEquals('INBOX', $error['folder_name']);
            $this->assertStringContainsString('No matching thread found', $error['error_message']);
        } finally {
            // Clean up: rollback the transaction
            Database::rollBack();
        }
    }

    // Tests for non-INBOX mailboxes

    /**
     * Test that non-INBOX mailboxes are not processed
     * This ensures emails are never moved out of folders other than INBOX
     */
    public function testProcessNonInboxMailboxDoesNothing() {
        // No emails should be fetched from non-INBOX mailboxes
        $this->mockEmailProcessor->expects($this->never())
            ->method('getEmails');

        // No emails should be moved
        $this->mockFolderManager->expects($this->never())
            ->method('moveEmail');

        // Test various non-INBOX mailboxes
        $nonInboxMailboxes = [
            'INBOX.Test - Thread 1',
            'INBOX.Sent',
            'INBOX.Projects.CustomerA - Thread',
        ];
        
        foreach ($nonInboxMailboxes as $mailbox) {
            $result = $this->threadEmailMover->processMailbox($mailbox, []);

            // Verify empty result
            $this->assertEmpty($result['unmatched'], "Non-INBOX mailbox '$mailbox' should return empty unmatched list");
            $this->assertFalse($result['maxed_out'], "Non-INBOX mailbox '$mailbox' should return maxed_out = false");
        }
    }


}
