<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';

class ThreadEmailMappingTest extends PHPUnit\Framework\TestCase {
    private $db;
    private $mockConnection;
    private $mockEmailProcessor;
    private $mockAttachmentHandler;
    private $threadEmailDatabaseSaver;
    private $testThreadId;
    private $testThreadEmail;
    
    protected function setUp(): void {
        // Initialize database connection
        $this->db = Database::getInstance();
        
        // Note: We don't start a transaction here because saveThreadEmails starts its own transaction
        
        // Create mock objects for IMAP components
        $this->mockConnection = $this->createMock(\Imap\ImapConnection::class);
        $this->mockEmailProcessor = $this->createMock(\Imap\ImapEmailProcessor::class);
        $this->mockAttachmentHandler = $this->createMock(\Imap\ImapAttachmentHandler::class);
        
        // Create the class under test
        $this->threadEmailDatabaseSaver = new ThreadEmailDatabaseSaver(
            $this->mockConnection,
            $this->mockEmailProcessor,
            $this->mockAttachmentHandler
        );
        
        // Create a test thread in the database with a unique email
        $this->testThreadEmail = 'test' . mt_rand(1000, 9999) . time() . '@example.com';
        $this->testThreadId = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread', 'Test User', ?) 
             RETURNING id",
            [$this->testThreadEmail]
        );
    }
    
    protected function tearDown(): void {
        // No need to rollback transaction here as saveThreadEmails handles its own transactions
    }
    
    /**
     * Test that we can add a mapping and it's correctly used
     */
    public function testAddEmailMapping() {
        // :: Setup
        // Create a mapped email address
        $mappedEmail = 'mapped' . mt_rand(1000, 9999) . time() . '@example.com';
        
        // Create a mock ImapEmail that will use the mapped email
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        $mockEmail->timestamp = time();
        $mockEmail->subject = 'Test Email';
        $mockEmail->mailHeaders = (object)[
            'subject' => 'Test Email',
            'from' => [(object)['mailbox' => 'sender', 'host' => 'example.com']]
        ];
        
        // Generate the email identifier the same way ThreadEmailDatabaseSaver does
        $email_identifier = date('Y-m-d__His', $mockEmail->timestamp) . '__' . md5($mockEmail->subject);
        
        // Add the mapping to the database
        Database::execute(
            "INSERT INTO thread_email_mapping (thread_id, email_identifier) VALUES (?, ?)",
            [$this->testThreadId, $email_identifier]
        );
        
        // Set up the mock to return our mapped email
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn([$mappedEmail]);
            
        $mockEmail->expects($this->once())
            ->method('getEmailDirection')
            ->with($this->testThreadEmail)
            ->willReturn('IN');
            
        $mockEmail->expects($this->once())
            ->method('generateEmailFilename')
            ->with($this->testThreadEmail)
            ->willReturn('test_email_1');
            
        // Set up the email processor to return our mock email
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Test')
            ->willReturn([$mockEmail]);
            
        // Set up the connection to return raw email content
        $this->mockConnection->expects($this->once())
            ->method('getRawEmail')
            ->with($mockEmail->uid)
            ->willReturn('Raw email content');
            
        // Set up the attachment handler to return no attachments
        $this->mockAttachmentHandler->expects($this->once())
            ->method('processAttachments')
            ->with($mockEmail->uid)
            ->willReturn([]);
            
        // :: Act
        // Call the method under test
        $savedEmails = $this->threadEmailDatabaseSaver->saveThreadEmails('INBOX.Test');
        
        // :: Assert
        // Verify that an email was saved
        $this->assertCount(1, $savedEmails, 'One email should be saved');
        
        // Verify that the email was saved to the correct thread
        $savedEmail = Database::queryOne(
            "SELECT thread_id FROM thread_emails WHERE id = ?",
            [$savedEmails[0]]
        );
        $this->assertEquals($this->testThreadId, $savedEmail['thread_id'], 'Email should be saved to the mapped thread');
    }
    
    /**
     * Test that the mapping takes precedence over the default my_email matching
     */
    public function testMappingPrecedence() {
        // :: Setup
        // Create a second thread with a different email
        $secondThreadEmail = 'second' . mt_rand(1000, 9999) . time() . '@example.com';
        $secondThreadId = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Second Thread', 'Second User', ?) 
             RETURNING id",
            [$secondThreadEmail]
        );
        
        // Create a mock ImapEmail that will use the second thread's email
        $mockEmail = $this->createMock(\Imap\ImapEmail::class);
        $mockEmail->uid = 1;
        $mockEmail->timestamp = time();
        $mockEmail->subject = 'Test Email for Precedence Test'; // Changed subject to ensure unique email identifier
        $mockEmail->mailHeaders = (object)[
            'subject' => 'Test Email for Precedence Test', // Changed subject to match
            'from' => [(object)['mailbox' => 'sender', 'host' => 'example.com']]
        ];
        
        // Generate the email identifier the same way ThreadEmailDatabaseSaver does
        $email_identifier = date('Y-m-d__His', $mockEmail->timestamp) . '__' . md5($mockEmail->subject);
        
        // Create a mapping that maps the email identifier to the first thread
        Database::execute(
            "INSERT INTO thread_email_mapping (thread_id, email_identifier) VALUES (?, ?)",
            [$this->testThreadId, $email_identifier]
        );
        
        // Set up the mock to return the second thread's email
        $mockEmail->expects($this->once())
            ->method('getEmailAddresses')
            ->willReturn([$secondThreadEmail]);
            
        // The thread found will be the first thread (due to mapping), so getEmailDirection should be called with the first thread's email
        $mockEmail->expects($this->once())
            ->method('getEmailDirection')
            ->with($this->testThreadEmail)
            ->willReturn('IN');
            
        $mockEmail->expects($this->once())
            ->method('generateEmailFilename')
            ->with($this->testThreadEmail)
            ->willReturn('test_email_1');
            
        // Set up the email processor to return our mock email
        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmails')
            ->with('INBOX.Test')
            ->willReturn([$mockEmail]);
            
        // Set up the connection to return raw email content
        $this->mockConnection->expects($this->once())
            ->method('getRawEmail')
            ->with($mockEmail->uid)
            ->willReturn('Raw email content');
            
        // Set up the attachment handler to return no attachments
        $this->mockAttachmentHandler->expects($this->once())
            ->method('processAttachments')
            ->with($mockEmail->uid)
            ->willReturn([]);
            
        // :: Act
        // Call the method under test
        $savedEmails = $this->threadEmailDatabaseSaver->saveThreadEmails('INBOX.Test');
        
        // :: Assert
        // Verify that an email was saved
        $this->assertCount(1, $savedEmails, 'One email should be saved');
        
        // Verify that the email was saved to the first thread (due to mapping) and not the second thread
        $savedEmail = Database::queryOne(
            "SELECT thread_id FROM thread_emails WHERE id = ?",
            [$savedEmails[0]]
        );
        $this->assertEquals($this->testThreadId, $savedEmail['thread_id'], 'Email should be saved to the mapped thread, not the thread with matching my_email');
        $this->assertNotEquals($secondThreadId, $savedEmail['thread_id'], 'Email should not be saved to the thread with matching my_email');
    }
    
    /**
     * Test that multiple mappings for the same email address are not allowed
     */
    public function testUniqueConstraint() {
        // :: Setup
        // Create a mapped email address
        $mappedEmail = 'unique' . mt_rand(1000, 9999) . time() . '@example.com';
        
        // Add the mapping to the database
        Database::execute(
            "INSERT INTO thread_email_mapping (thread_id, email_identifier) VALUES (?, ?)",
            [$this->testThreadId, $mappedEmail]
        );
        
        // Create a second thread
        $secondThreadId = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Second Thread', 'Second User', ?) 
             RETURNING id",
            ['second' . mt_rand(1000, 9999) . time() . '@example.com']
        );
        
        // :: Act & Assert
        // Try to add the same mapping to the second thread, which should fail
        $this->expectException(\Exception::class);
        Database::execute(
            "INSERT INTO thread_email_mapping (thread_id, email_identifier) VALUES (?, ?)",
            [$secondThreadId, $mappedEmail]
        );
    }
}
