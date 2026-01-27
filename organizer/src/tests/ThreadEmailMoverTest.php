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
        
        // Skip ThreadEmailMover database operations for unit tests
        ThreadEmailMover::$skipDatabaseOperations = true;
        
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
        // Reset the flags to not affect other tests
        ImapFolderStatus::$skipDatabaseOperations = false;
        ThreadEmailMover::$skipDatabaseOperations = false;
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

        $this->mockConnection->expects($this->once())
            ->method('getRawEmail')
            ->with($mockEmail->uid)
            ->willReturn('Raw email content');

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->willThrowException(new Exception('Move failed'));

        // The error should be caught and processing should continue
        $emailToFolder = ['test@example.com' => 'INBOX.Test - Thread 1'];
        $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);
        
        // Should return successfully despite the error
        $this->assertIsArray($result);
        $this->assertArrayHasKey('unmatched', $result);
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
        // Enable database operations for this integration test
        ThreadEmailMover::$skipDatabaseOperations = false;
        
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
     * Data provider for non-INBOX mailbox tests
     * 
     * @return array Test cases with [description, sourceMailbox, emailAddresses, emailToFolderMapping, expectedTargetFolder, expectedUnmatchedCount, expectedUnmatchedEmail]
     */
    public static function nonInboxMailboxProvider(): array {
        return [
            'email matching current thread stays in folder' => [
                'INBOX.Test - Thread 1',
                ['test1@example.com'],
                ['test1@example.com' => 'INBOX.Test - Thread 1'],
                'INBOX.Test - Thread 1',
                0,
                null
            ],
            'email matching different thread moves to that thread' => [
                'INBOX.Test - Thread 1',
                ['test2@example.com'],
                [
                    'test1@example.com' => 'INBOX.Test - Thread 1',
                    'test2@example.com' => 'INBOX.Test - Thread 2'
                ],
                'INBOX.Test - Thread 2',
                0,
                null
            ],
            'unmatched email moves to INBOX' => [
                'INBOX.Test - Thread 1',
                ['unmatched@example.com'],
                ['test1@example.com' => 'INBOX.Test - Thread 1'],
                'INBOX',
                1,
                'unmatched@example.com'
            ],
        ];
    }

    /**
     * @dataProvider nonInboxMailboxProvider
     */
    public function testProcessNonInboxMailbox(
        string $sourceMailbox,
        array $emailAddresses,
        array $emailToFolderMapping,
        string $expectedTargetFolder,
        int $expectedUnmatchedCount,
        ?string $expectedUnmatchedEmail
    ) {
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        $mockEmail->subject = 'Test Subject';
        $mockEmail->timestamp = time();
        
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn($emailAddresses);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with($sourceMailbox)
            ->willReturn([$mockEmail]);

        $this->mockFolderManager->expects($this->once())
            ->method('moveEmail')
            ->with(1, $expectedTargetFolder);

        $result = $this->threadEmailMover->processMailbox($sourceMailbox, $emailToFolderMapping);

        $this->assertCount($expectedUnmatchedCount, $result['unmatched']);
        if ($expectedUnmatchedEmail !== null) {
            $this->assertEquals($expectedUnmatchedEmail, $result['unmatched'][0]);
        }
    }

    public function testProcessNonInboxMailboxWithMultipleEmails() {
        // Test: Multiple emails in a thread folder with different destinations
        $mockEmail1 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail1->uid = 1;
        $mockEmail1->subject = 'Test Subject 1';
        $mockEmail1->timestamp = time();
        
        $mockEmail2 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail2->uid = 2;
        $mockEmail2->subject = 'Test Subject 2';
        $mockEmail2->timestamp = time();
        
        $mockEmail3 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail3->uid = 3;
        $mockEmail3->subject = 'Test Subject 3';
        $mockEmail3->timestamp = time();
        
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

    /**
     * Integration test to verify that manually mapped emails in thread_email_mapping are handled correctly
     * 
     * @group integration
     */
    public function testProcessMailboxWithMappedEmail() {
        // Enable database operations for this integration test
        ThreadEmailMover::$skipDatabaseOperations = false;
        
        // Create a test thread
        $testThreadEmail = 'testmapped' . mt_rand(1000, 9999) . time() . '@example.com';
        
        Database::beginTransaction();
        
        try {
            // Create a test thread
            $threadId = Database::queryValue(
                "INSERT INTO threads (id, entity_id, title, my_name, my_email, archived) 
                 VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Mapped Test Thread', 'Test User', ?, FALSE) 
                 RETURNING id",
                [$testThreadEmail]
            );
            
            // Create mock email with different address
            $mockEmail = $this->createMock(\Imap\ImapEmail::class);
            $mockEmail->uid = 1;
            $mockEmail->subject = 'Test Subject for Mapping';
            $mockEmail->timestamp = time();
            
            // Generate email identifier (same format as in ThreadEmailMover)
            $emailIdentifier = date('Y-m-d__His', $mockEmail->timestamp) . '__' . md5($mockEmail->subject);
            
            // Create mapping for this email to the thread
            Database::execute(
                "INSERT INTO thread_email_mapping (thread_id, email_identifier) VALUES (?, ?)",
                [$threadId, $emailIdentifier]
            );
            
            // Set up mock to return an unrelated email address (not matching thread.my_email)
            $mockEmail->expects($this->once())
                ->method('getEmailAddresses')
                ->willReturn(['unrelated@example.com']);
            
            $this->mockConnection->expects($this->once())
                ->method('getRawEmail')
                ->with($mockEmail->uid)
                ->willReturn('Raw email content');
                
            $this->mockEmailProcessor->expects($this->once())
                ->method('getEmails')
                ->with('INBOX')
                ->willReturn([$mockEmail]);

            // The email should be moved to the mapped thread's folder
            $expectedFolder = 'INBOX.000000000-test-entity-development - Mapped Test Thread';
            $this->mockFolderManager->expects($this->once())
                ->method('moveEmail')
                ->with(1, $expectedFolder);

            // Empty email-to-folder mapping (so without mapping table, it would be unmatched)
            $emailToFolder = [];

            $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

            // Verify no unmatched addresses since the mapping was used
            $this->assertEmpty($result['unmatched'], 'Email should be matched via mapping table');
        } finally {
            // Clean up: rollback the transaction
            Database::rollBack();
        }
    }

    /**
     * Integration test to verify that mapping table takes precedence over email address matching
     * 
     * @group integration
     */
    public function testProcessMailboxMappingTakesPrecedence() {
        // Enable database operations for this integration test
        ThreadEmailMover::$skipDatabaseOperations = false;
        
        // Create two test threads
        $thread1Email = 'thread1' . mt_rand(1000, 9999) . time() . '@example.com';
        $thread2Email = 'thread2' . mt_rand(1000, 9999) . time() . '@example.com';
        
        Database::beginTransaction();
        
        try {
            // Create thread 1
            $thread1Id = Database::queryValue(
                "INSERT INTO threads (id, entity_id, title, my_name, my_email, archived) 
                 VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Thread 1', 'User 1', ?, FALSE) 
                 RETURNING id",
                [$thread1Email]
            );
            
            // Create thread 2
            $thread2Id = Database::queryValue(
                "INSERT INTO threads (id, entity_id, title, my_name, my_email, archived) 
                 VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Thread 2', 'User 2', ?, FALSE) 
                 RETURNING id",
                [$thread2Email]
            );
            
            // Create mock email
            $mockEmail = $this->createMock(\Imap\ImapEmail::class);
            $mockEmail->uid = 1;
            $mockEmail->subject = 'Test Precedence';
            $mockEmail->timestamp = time();
            
            // Generate email identifier
            $emailIdentifier = date('Y-m-d__His', $mockEmail->timestamp) . '__' . md5($mockEmail->subject);
            
            // Map this email to thread 1
            Database::execute(
                "INSERT INTO thread_email_mapping (thread_id, email_identifier) VALUES (?, ?)",
                [$thread1Id, $emailIdentifier]
            );
            
            // Set up mock to return thread 2's email address
            $mockEmail->expects($this->once())
                ->method('getEmailAddresses')
                ->willReturn([$thread2Email]);
            
            $this->mockConnection->expects($this->once())
                ->method('getRawEmail')
                ->with($mockEmail->uid)
                ->willReturn('Raw email content');
                
            $this->mockEmailProcessor->expects($this->once())
                ->method('getEmails')
                ->with('INBOX')
                ->willReturn([$mockEmail]);

            // The email should be moved to thread 1's folder (via mapping), not thread 2 (via email address)
            $expectedFolder = 'INBOX.000000000-test-entity-development - Thread 1';
            $this->mockFolderManager->expects($this->once())
                ->method('moveEmail')
                ->with(1, $expectedFolder);

            // Build email-to-folder mapping that includes thread 2
            $emailToFolder = [
                $thread2Email => 'INBOX.000000000-test-entity-development - Thread 2'
            ];

            $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);

            // Verify no unmatched addresses
            $this->assertEmpty($result['unmatched'], 'Email should be matched via mapping table');
        } finally {
            // Clean up: rollback the transaction
            Database::rollBack();
        }
    }

    public function testProcessMailboxContinuesAfterSingleError() {
        // Create two mock emails - first one fails, second should succeed
        $mockEmail1 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail1->uid = 1;
        
        $mockEmail2 = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail2->uid = 2;
        
        $mockEmail1->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test1@example.com']);
        
        $mockEmail2->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn(['test2@example.com']);
            
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn([$mockEmail1, $mockEmail2]);

        $this->mockConnection->expects($this->exactly(2))
            ->method('getRawEmail')
            ->willReturn('Raw email content');

        // First move fails, second succeeds
        $this->mockFolderManager->expects($this->exactly(2))
            ->method('moveEmail')
            ->willReturnCallback(function($uid, $folder) {
                if ($uid === 1) {
                    throw new Exception('Move failed for email 1');
                }
                // Second call succeeds (no exception)
            });

        $emailToFolder = [
            'test1@example.com' => 'INBOX.Test - Thread 1',
            'test2@example.com' => 'INBOX.Test - Thread 2'
        ];
        
        $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);
        
        // Should complete successfully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('unmatched', $result);
    }

    public function testProcessMailboxStopsAfterMaxErrors() {
        // Create 7 mock emails, all will fail to move
        $mockEmails = [];
        for ($i = 1; $i <= 7; $i++) {
            $mockEmail = $this->createMock(\Imap\ImapEmail::class);
            $mockEmail->uid = $i;
            // Only first 5 emails will have their methods called
            if ($i <= 5) {
                $mockEmail->expects($this->once())
                    ->method('getEmailAddresses')
                    ->willReturn(["test{$i}@example.com"]);
            }
            $mockEmails[] = $mockEmail;
        }
        
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn($mockEmails);

        // Only 5 emails will be processed before stopping
        $this->mockConnection->expects($this->exactly(5))
            ->method('getRawEmail')
            ->willReturn('Raw email content');

        // All move attempts will fail, but only 5 will be attempted
        $this->mockFolderManager->expects($this->exactly(5))
            ->method('moveEmail')
            ->willThrowException(new Exception('Move failed'));

        $emailToFolder = [];
        for ($i = 1; $i <= 7; $i++) {
            $emailToFolder["test{$i}@example.com"] = "INBOX.Test - Thread {$i}";
        }
        
        $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);
        
        // Should complete but stop after 5 errors
        $this->assertIsArray($result);
        $this->assertArrayHasKey('unmatched', $result);
    }

    public function testProcessMailboxMixedErrorsAndSuccesses() {
        // Create 8 emails: errors on 1,3,5,7 (4 errors total), successes on 2,4,6,8
        $mockEmails = [];
        for ($i = 1; $i <= 8; $i++) {
            $mockEmail = $this->createMock(\Imap\ImapEmail::class);
            $mockEmail->uid = $i;
            $mockEmail->expects($this->once())
                ->method('getEmailAddresses')
                ->willReturn(["test{$i}@example.com"]);
            $mockEmails[] = $mockEmail;
        }
        
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX')
            ->willReturn($mockEmails);

        $this->mockConnection->expects($this->exactly(8))
            ->method('getRawEmail')
            ->willReturn('Raw email content');

        // Fail on odd UIDs, succeed on even UIDs
        $this->mockFolderManager->expects($this->exactly(8))
            ->method('moveEmail')
            ->willReturnCallback(function($uid, $folder) {
                if ($uid % 2 === 1) {
                    throw new Exception("Move failed for email {$uid}");
                }
                // Even UIDs succeed
            });

        $emailToFolder = [];
        for ($i = 1; $i <= 8; $i++) {
            $emailToFolder["test{$i}@example.com"] = "INBOX.Test - Thread {$i}";
        }
        
        $result = $this->threadEmailMover->processMailbox('INBOX', $emailToFolder);
        
        // Should complete successfully with 4 errors and 4 successes
        $this->assertIsArray($result);
        $this->assertArrayHasKey('unmatched', $result);
    }
}
