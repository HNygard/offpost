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
             VALUES (gen_random_uuid(), ?, NOW(), 'test content', ?)",
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
        $this->assertEquals($rawEmail, $savedEmail['content'], 'Email should have the correct content');
    }
    
    public function testSaveAttachmentToDatabase() {
        // First create an email to attach to
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (id, thread_id, timestamp_received, content) 
             VALUES (gen_random_uuid(), ?, NOW(), 'test content') 
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
        
        // Call the method
        $attachmentId = $reflectionMethod->invoke(
            $this->threadEmailDatabaseSaver,
            $emailId,
            $attachment,
            'test-attachment-content'
        );
        
        // Verify the attachment was saved
        $this->assertNotEmpty($attachmentId, 'Should return a valid attachment ID');
        
        // Check that the attachment exists in the database
        $savedAttachment = Database::queryOne(
            "SELECT * FROM thread_email_attachments WHERE id = ?",
            [$attachmentId]
        );
        
        $this->assertNotNull($savedAttachment, 'Attachment should be saved in the database');
        $this->assertEquals($emailId, $savedAttachment['email_id'], 'Attachment should be associated with the test email');
        $this->assertEquals($attachment->name, $savedAttachment['name'], 'Attachment should have the correct name');
        $this->assertEquals($attachment->filename, $savedAttachment['filename'], 'Attachment should have the correct filename');
        $this->assertEquals($attachment->filetype, $savedAttachment['filetype'], 'Attachment should have the correct filetype');
        $this->assertEquals($attachment->location, $savedAttachment['location'], 'Attachment should have the correct location');
        $this->assertEquals('test-attachment-content', $savedAttachment['content'], 'Attachment should have the correct content');
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
}
