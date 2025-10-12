<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailAttachment.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';

class ThreadEmailDatabaseSaverTest extends PHPUnit\Framework\TestCase {
    private $db;
    private $mockConnection;
    private $mockEmailProcessor;
    private $mockAttachmentHandler;
    private $threadEmailDatabaseSaver;
    private $testThreadId;
    
    protected function setUp(): void {
        // Initialize database connection
        $this->db = Database::getInstance();
        
        // Start transaction for test isolation
        Database::beginTransaction();
        
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
        $uniqueEmail = 'test' . mt_rand(1000, 9999) . time() . '@example.com';
        $this->testThreadId = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread', 'Test User', ?) 
             RETURNING id",
            [$uniqueEmail]
        );
    }
    
    protected function tearDown(): void {
        // Rollback transaction to clean up test data
        if ($this->db) {
            Database::rollBack();
        }
    }
    
    public function testEmailExistsInDatabase() {
        // Use reflection to access private method
        $reflectionMethod = new ReflectionMethod(ThreadEmailDatabaseSaver::class, 'emailExistsInDatabase');
        $reflectionMethod->setAccessible(true);
        
        // First check that email doesn't exist
        $result = $reflectionMethod->invoke($this->threadEmailDatabaseSaver, $this->testThreadId, 'test-email-id');
        $this->assertFalse($result, 'Email should not exist in database initially');
        
        // Insert a test email
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, timestamp_received, content, id_old)
             VALUES (gen_random_uuid(), ?, NOW(), 'test content'::bytea, ?)",
            [$this->testThreadId, 'test-email-id']
        );
        
        // Now check that email exists
        $result = $reflectionMethod->invoke($this->threadEmailDatabaseSaver, $this->testThreadId, 'test-email-id');
        $this->assertTrue($result, 'Email should exist in database after insertion');
    }
    
    public function testSaveEmailToDatabase() {
        // Use reflection to access private method
        $reflectionMethod = new ReflectionMethod(ThreadEmailDatabaseSaver::class, 'saveEmailToDatabase');
        $reflectionMethod->setAccessible(true);
        
        // Create test data
        $email = new stdClass();
        $email->timestamp = time();
        $direction = 'incoming';
        $filename = 'test-filename-' . uniqid();
        $rawEmail = 'test-raw-email-content';
        
        // Call the method
        $emailId = $reflectionMethod->invoke(
            $this->threadEmailDatabaseSaver,
            $this->testThreadId,
            $email,
            $direction,
            $filename,
            $rawEmail,
            new stdClass()
        );
        
        // Verify the email was saved
        $this->assertNotEmpty($emailId, 'Should return a valid email ID');
        
        // Check that the email exists in the database
        $savedEmail = Database::queryOne(
            "SELECT * FROM thread_emails WHERE id = ?",
            [$emailId]
        );
        
        $this->assertNotNull($savedEmail, 'Email should be saved in the database');
        $this->assertEquals($this->testThreadId, $savedEmail['thread_id'], 'Email should be associated with the test thread');
        $this->assertEquals($direction, $savedEmail['email_type'], 'Email should have the correct direction');
        $this->assertEquals($filename, $savedEmail['id_old'], 'Email should have the correct id_old');
        $this->assertEquals($rawEmail, stream_get_contents($savedEmail['content']), 'Email should have the correct content');
    }
    
    public function testSaveAttachmentToDatabase() {
        // First create an email to attach to
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (id, thread_id, timestamp_received, content)
             VALUES (gen_random_uuid(), ?, NOW(), 'test content'::bytea)
             RETURNING id",
            [$this->testThreadId]
        );
        
        // Use reflection to access private method
        $reflectionMethod = new ReflectionMethod(ThreadEmailDatabaseSaver::class, 'saveAttachmentToDatabase');
        $reflectionMethod->setAccessible(true);
        
        // Create test data
        $attachment = new stdClass();
        $attachment->name = 'test-attachment-name';
        $attachment->filename = 'test-attachment-filename';
        $attachment->filetype = 'pdf';
        $attachment->location = 'test-location';
        
        // Create a test content with binary data (including 0xFF byte which is invalid in UTF-8)
        $testContent = "test-attachment-content" . chr(0xFF) . chr(0x00) . chr(0x01);
        
        // Call the method
        $attachmentId = $reflectionMethod->invoke(
            $this->threadEmailDatabaseSaver,
            $emailId,
            $attachment,
            $testContent
        );
        
        // Verify the attachment was saved
        $this->assertNotEmpty($attachmentId, 'Should return a valid attachment ID');
        
        // Check that the attachment exists in the database
        $savedAttachment = Database::queryOne(
            "SELECT email_id, name, filename, filetype, location, status_type, status_text, 
                    encode(content, 'hex') as content_hex
             FROM thread_email_attachments WHERE id = ?",
            [$attachmentId]
        );
        
        $this->assertNotNull($savedAttachment, 'Attachment should be saved in the database');
        $this->assertEquals($emailId, $savedAttachment['email_id'], 'Attachment should be associated with the test email');
        $this->assertEquals($attachment->name, $savedAttachment['name'], 'Attachment should have the correct name');
        $this->assertEquals($attachment->filename, $savedAttachment['filename'], 'Attachment should have the correct filename');
        $this->assertEquals($attachment->filetype, $savedAttachment['filetype'], 'Attachment should have the correct filetype');
        $this->assertEquals($attachment->location, $savedAttachment['location'], 'Attachment should have the correct location');
        
        // For binary content, we need to check the hex-encoded value
        // Convert our test content to hex for comparison
        $testContentHex = bin2hex($testContent);
        $this->assertEquals($testContentHex, $savedAttachment['content_hex'], 'Attachment should have the correct binary content');
    }
    
    public function testFinishThreadProcessing() {
        // Create a thread object with archived=true
        $thread = new stdClass();
        $thread->id = $this->testThreadId;
        $thread->archived = true;
        
        // Call the method
        $this->threadEmailDatabaseSaver->finishThreadProcessing($thread);
        
        // Verify the thread was archived
        $archivedThread = Database::queryOne(
            "SELECT archived FROM threads WHERE id = ?",
            [$this->testThreadId]
        );
        
        $this->assertTrue((bool)$archivedThread['archived'], 'Thread should be marked as archived');
    }
    
    /**
     * Test that email processing errors are persisted even when transaction is rolled back
     */
    public function testSaveEmailProcessingErrorPersistsOnRollback() {
        // :: Setup
        // Create a mock email that will trigger the multiple matching threads error
        $mockEmail = new stdClass();
        $mockEmail->uid = 'test-uid-123';
        $mockEmail->timestamp = time();
        $mockEmail->subject = 'Test Email Subject';
        $mockEmail->mailHeaders = new stdClass();
        
        // Mock getEmailAddresses to return emails that will match multiple threads
        $mockEmail->getEmailAddresses = function() {
            return ['test@example.com'];
        };
        
        // Create two threads with the same email to trigger multiple matches
        $email = 'multithread' . time() . '@example.com';
        $threadId1 = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread 1', 'Test User', ?) 
             RETURNING id",
            [$email]
        );
        $threadId2 = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email) 
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread 2', 'Test User', ?) 
             RETURNING id",
            [$email]
        );
        
        // Configure mocks to return our test email
        $this->mockEmailProcessor->method('getEmails')
            ->willReturn([$mockEmail]);
        
        $this->mockConnection->method('getRawEmail')
            ->willReturn('Raw email content');
        
        // Mock the email object to be callable
        $emailAddressesClosure = function($rawEmail) use ($email) {
            return [$email];
        };
        
        // Create a new mock email with callable method
        $testEmail = $this->getMockBuilder(stdClass::class)
            ->addMethods(['getEmailAddresses'])
            ->getMock();
        $testEmail->uid = 'test-uid-123';
        $testEmail->timestamp = time();
        $testEmail->subject = 'Test Email Subject';
        $testEmail->mailHeaders = new stdClass();
        $testEmail->method('getEmailAddresses')
            ->willReturn([$email]);
        
        // Configure mocks
        $this->mockEmailProcessor->method('getEmails')
            ->willReturn([$testEmail]);
        
        // :: Act
        $exceptionThrown = false;
        $emailIdentifier = null;
        try {
            $this->threadEmailDatabaseSaver->saveThreadEmails('INBOX.test');
        } catch (Exception $e) {
            $exceptionThrown = true;
            // Extract email identifier from the email
            $emailIdentifier = date('Y-m-d__His', $testEmail->timestamp) . '__' . md5($testEmail->subject);
        }
        
        // :: Assert
        $this->assertTrue($exceptionThrown, 'Exception should be thrown for multiple matching threads');
        
        // Verify the error was saved to the database even though transaction was rolled back
        $savedError = Database::queryOneOrNone(
            "SELECT * FROM thread_email_processing_errors WHERE email_identifier = ?",
            [$emailIdentifier]
        );
        
        $this->assertNotNull($savedError, 'Error record should be persisted even after transaction rollback');
        $this->assertEquals($emailIdentifier, $savedError['email_identifier'], 'Email identifier should match');
        $this->assertEquals('multiple_matching_threads', $savedError['error_type'], 'Error type should be multiple_matching_threads');
        $this->assertEquals($testEmail->subject, $savedError['email_subject'], 'Subject should match');
        $this->assertStringContainsString($email, $savedError['email_addresses'], 'Email addresses should contain the test email');
    }
}
