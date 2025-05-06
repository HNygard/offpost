<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadDatabaseOperations.php';

class ThreadEmailSendingTest extends PHPUnit\Framework\TestCase {
    private $thread;
    private $dbOps;
    private $emailContent;
    private $emailSubject;
    private $emailTo;
    private $emailFromBase;
    private $emailFromName;
    private $entityId;
    
    protected function setUp(): void {
         // Clean database tables
        $db = new Database();
        $db->execute("DELETE FROM thread_email_sendings");
        
        // Set up test data
        $this->emailContent = 'Test email content';
        $this->emailSubject = 'Test email subject';
        $this->emailTo = 'recipient@example.com';
        $this->emailFromBase = 'sender_' . uniqid() . '@example.com';
        $this->emailFromName = 'Test Sender';
        $this->entityId = '000000000-test-entity-development'; // Use a test entity ID
        
        // Create a database operations instance
        $this->dbOps = new ThreadDatabaseOperations();
        
        // Create a test thread in the database
        $this->thread = new Thread();
        $this->thread->title = 'Test Thread';
        $this->thread->my_name = $this->emailFromName;
        $this->thread->my_email = $this->emailFromBase;
        $this->thread->labels = [];
        $this->thread->initial_request = $this->emailContent;
        $this->thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $this->thread->sent = false;
        $this->thread->archived = false;
        $this->thread->public = false;
        $this->thread->emails = [];
        
        // Insert the thread into the database
        $this->thread = $this->dbOps->createThread($this->entityId, $this->thread, 'test-user');
    }
    
    protected function tearDown(): void {
        // Clean up test records
        if ($this->thread && $this->thread->id) {
            // First clean up thread_email_sendings
            Database::execute(
                "DELETE FROM thread_email_sendings WHERE thread_id = ?",
                [$this->thread->id]
            );
            
            // Then clean up thread_history
            Database::execute(
                "DELETE FROM thread_history WHERE thread_id = ?",
                [$this->thread->id]
            );
            
            // Then clean up thread_email_history
            Database::execute(
                "DELETE FROM thread_email_history WHERE thread_id = ?",
                [$this->thread->id]
            );
            
            // Finally clean up the thread
            Database::execute(
                "DELETE FROM threads WHERE id = ?",
                [$this->thread->id]
            );
        }
    }
    
    /**
     * Test creating a new ThreadEmailSending record
     */
    public function testCreate() {
        // :: Setup - already done in setUp()
        
        // :: Act
        $emailSending = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent,
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName
        );
        
        // :: Assert
        $this->assertNotNull($emailSending, 'Should create a ThreadEmailSending record');
        $this->assertEquals($this->thread->id, $emailSending->thread_id, 'Thread ID should match');
        $this->assertEquals($this->emailContent, $emailSending->email_content, 'Email content should match');
        $this->assertEquals($this->emailSubject, $emailSending->email_subject, 'Email subject should match');
        $this->assertEquals($this->emailTo, $emailSending->email_to, 'Email to should match');
        $this->assertEquals($this->emailFromBase, $emailSending->email_from, 'Email from should match');
        $this->assertEquals($this->emailFromName, $emailSending->email_from_name, 'Email from name should match');
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $emailSending->status, 'Status should default to STAGING');
    }
    
    /**
     * Test creating a ThreadEmailSending record with a specific status
     */
    public function testCreateWithStatus() {
        // :: Act
        $emailSending = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent,
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName,
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );
        
        // :: Assert
        $this->assertNotNull($emailSending, 'Should create a ThreadEmailSending record');
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $emailSending->status, 
            'Status should be set to READY_FOR_SENDING');
    }
    
    /**
     * Test finding the next email ready for sending
     */
    public function testFindNextForSending() {
        // :: Setup
        // Create a record with READY_FOR_SENDING status
        $emailSending = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent,
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName,
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );
        
        // :: Act
        $nextEmailSending = ThreadEmailSending::findNextForSending();
        
        // :: Assert
        $this->assertNotNull($nextEmailSending, 'Should find a record ready for sending');
        $this->assertEquals($emailSending->id, $nextEmailSending->id, 'Should find the correct record');
    }
    
    /**
     * Test updating the status of a ThreadEmailSending record
     */
    public function testUpdateStatus() {
        // :: Setup
        // Create a record
        $emailSending = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent,
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName
        );
        
        // :: Act
        $result = ThreadEmailSending::updateStatus(
            $emailSending->id,
            ThreadEmailSending::STATUS_SENDING
        );
        
        // :: Assert
        $this->assertTrue($result, 'Update should succeed');
        
        // Verify the status was updated
        $updatedEmailSending = ThreadEmailSending::getById($emailSending->id);
        $this->assertEquals(ThreadEmailSending::STATUS_SENDING, $updatedEmailSending->status, 
            'Status should be updated to SENDING');
    }
    
    /**
     * Test updating the status with SMTP response and debug info
     */
    public function testUpdateStatusWithSmtpInfo() {
        // :: Setup
        // Create a record
        $emailSending = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent,
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName
        );
        
        $smtpResponse = 'SMTP response';
        $smtpDebug = 'SMTP debug output';
        
        // :: Act
        $result = ThreadEmailSending::updateStatus(
            $emailSending->id,
            ThreadEmailSending::STATUS_SENT,
            $smtpResponse,
            $smtpDebug
        );
        
        // :: Assert
        $this->assertTrue($result, 'Update should succeed');
        
        // Verify the status and SMTP info were updated
        $updatedEmailSending = ThreadEmailSending::getById($emailSending->id);
        $this->assertEquals(ThreadEmailSending::STATUS_SENT, $updatedEmailSending->status, 
            'Status should be updated to SENT');
        $this->assertEquals($smtpResponse, $updatedEmailSending->smtp_response, 
            'SMTP response should be recorded');
        $this->assertEquals($smtpDebug, $updatedEmailSending->smtp_debug, 
            'SMTP debug output should be recorded');
    }
    
    /**
     * Test updating the status with an error message
     */
    public function testUpdateStatusWithError() {
        // :: Setup
        // Create a record
        $emailSending = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent,
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName
        );
        
        $errorMessage = 'Error sending email';
        
        // :: Act
        $result = ThreadEmailSending::updateStatus(
            $emailSending->id,
            ThreadEmailSending::STATUS_READY_FOR_SENDING,
            null,
            null,
            $errorMessage
        );
        
        // :: Assert
        $this->assertTrue($result, 'Update should succeed');
        
        // Verify the status and error message were updated
        $updatedEmailSending = ThreadEmailSending::getById($emailSending->id);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $updatedEmailSending->status, 
            'Status should be updated to READY_FOR_SENDING');
        $this->assertEquals($errorMessage, $updatedEmailSending->error_message, 
            'Error message should be recorded');
    }
    
    /**
     * Test getting ThreadEmailSending records by thread ID
     */
    public function testGetByThreadId() {
        // :: Setup
        // Create multiple records for the same thread
        $emailSending1 = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent . ' 1',
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase,
            $this->emailFromName
        );
        
        $emailSending2 = ThreadEmailSending::create(
            $this->thread->id,
            $this->emailContent . ' 2',
            $this->emailSubject,
            $this->emailTo,
            $this->emailFromBase . '.2',
            $this->emailFromName
        );
        
        // :: Act
        $emailSendings = ThreadEmailSending::getByThreadId($this->thread->id);
        
        // :: Assert
        $this->assertCount(2, $emailSendings, 'Should find two records for the thread');
        
        // Verify the records are in the correct order (by created_at)
        $this->assertEquals($emailSending1->id, $emailSendings[0]->id, 'First record should be first created');
        $this->assertEquals($emailSending2->id, $emailSendings[1]->id, 'Second record should be second created');
    }
}
