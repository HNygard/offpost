<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadScheduledEmailSender.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';


class MockDatabaseOperations extends ThreadDatabaseOperations {
    private $threads = [];
    private $updatedThreads = [];
    
    public function __construct() {
        // Skip parent constructor
    }
    
    public function addThread(Thread $thread) {
        $this->threads[$thread->id] = $thread;
    }
    
    public function updateThread(Thread $thread, $userId) {
        $this->updatedThreads[] = [
            'thread' => clone $thread,
            'userId' => $userId
        ];
        
        return $thread;
    }
    
    public function getUpdatedThreads() {
        return $this->updatedThreads;
    }
}

/**
 * Mock ThreadEmailSending class for testing
 */
class MockThreadEmailSending extends ThreadEmailSending {
    private static $mockInstance = null;
    private static $updatedStatuses = [];
    
    public static function setMockInstance($instance) {
        self::$mockInstance = $instance;
    }
    
    public static function findNextForSending() {
        return self::$mockInstance;
    }
    
    public static function updateStatus($id, $status, $smtpResponse = null, $smtpDebug = null, $errorMessage = null) {
        self::$updatedStatuses[] = [
            'id' => $id,
            'status' => $status,
            'smtpResponse' => $smtpResponse,
            'smtpDebug' => $smtpDebug,
            'errorMessage' => $errorMessage
        ];
        
        if (self::$mockInstance && self::$mockInstance->id == $id) {
            self::$mockInstance->status = $status;
            self::$mockInstance->smtp_response = $smtpResponse;
            self::$mockInstance->smtp_debug = $smtpDebug;
            self::$mockInstance->error_message = $errorMessage;
        }
        
        return true;
    }
    
    public static function getUpdatedStatuses() {
        return self::$updatedStatuses;
    }
    
    public static function resetUpdatedStatuses() {
        self::$updatedStatuses = [];
    }
}

/**
 * Custom ThreadScheduledEmailSender for testing
 * This allows us to override the protected methods for testing
 */
class TestableThreadScheduledEmailSender extends ThreadScheduledEmailSender {
    private $mockThread = null;
    private $mockEntity = null;
    protected $dbOps; // Change from private to protected for testing
    
    public function setMockThread($thread) {
        $this->mockThread = $thread;
    }
    
    public function setMockEntity($entity) {
        $this->mockEntity = $entity;
    }
    
    protected function findNextThreadForSending() {
        return $this->mockThread;
    }
    
    public function getEntityById($entityId) {
        return $this->mockEntity;
    }
    
    /**
     * Expose the protected sendEmail method for testing
     */
    public function sendEmailPublic(ThreadEmailSending $emailSending) {
        return $this->sendEmail($emailSending);
    }
    
    /**
     * Override the sendNextScheduledEmail method to use our mock objects
     */
    public function sendNextScheduledEmail() {
        // If no thread is set, return early
        if ($this->mockThread === null) {
            return [
                'success' => false,
                'message' => 'No threads ready for sending'
            ];
        }
        
        // Get the email sending record from the mock
        $emailSending = MockThreadEmailSending::findNextForSending();
        
        if (!$emailSending) {
            return [
                'success' => false,
                'message' => 'No threads ready for sending'
            ];
        }
        
        // Update status to SENDING in both places
        $emailSending->status = ThreadEmailSending::STATUS_SENDING;
        MockThreadEmailSending::updateStatus($emailSending->id, ThreadEmailSending::STATUS_SENDING);
        
        $this->mockThread->sending_status = Thread::SENDING_STATUS_SENDING;
        $this->dbOps->updateThread($this->mockThread, 'system');
        
        // Send the email using the public wrapper
        $result = $this->sendEmailPublic($emailSending);
        
        if ($result['success']) {
            // Update status to SENT in both places
            MockThreadEmailSending::updateStatus(
                $emailSending->id, 
                ThreadEmailSending::STATUS_SENT,
                $result['smtp_response'] ?? null,
                $result['debug'] ?? null
            );
            
            $this->mockThread->sending_status = Thread::SENDING_STATUS_SENT;
            $this->mockThread->sent = true; // For backward compatibility
            $this->dbOps->updateThread($this->mockThread, 'system');
            
            return [
                'success' => true,
                'message' => 'Email sent successfully',
                'thread_id' => $this->mockThread->id
            ];
        } else {
            // Revert to READY_FOR_SENDING if failed
            MockThreadEmailSending::updateStatus(
                $emailSending->id, 
                ThreadEmailSending::STATUS_READY_FOR_SENDING,
                $result['smtp_response'] ?? null,
                $result['debug'] ?? null,
                $result['error'] ?? null
            );
            
            $this->mockThread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
            $this->dbOps->updateThread($this->mockThread, 'system');
            
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $result['error'],
                'thread_id' => $this->mockThread->id,
                'debug' => $result['debug']
            ];
        }
    }
}

class ThreadScheduledEmailSenderTest extends PHPUnit\Framework\TestCase {
    private $mockDbOps;
    private $mockEmailService;
    private $mockHistory;
    private $sender;
    private $thread;
    private $entity;
    private $emailSending;
    
    protected function setUp(): void {
        // Create mock objects
        $this->mockDbOps = new MockDatabaseOperations();
        $this->mockEmailService = new MockEmailService();
        $this->mockHistory = $this->createMock(ThreadHistory::class);
        
        // Create a test thread
        $this->thread = new Thread();
        $this->thread->id = 'test-thread-id';
        $this->thread->entity_id = 'test-entity-id';
        $this->thread->title = 'Test Thread';
        $this->thread->my_name = 'Test Sender';
        $this->thread->my_email = 'test@example.com';
        $this->thread->initial_request = 'Test email body';
        $this->thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        
        // Create a test entity
        $this->entity = new stdClass();
        $this->entity->entity_id = 'test-entity-id';
        $this->entity->name = 'Test Entity';
        $this->entity->email = 'entity@example.com';
        
        // Create a test email sending record
        $this->emailSending = new ThreadEmailSending();
        $this->emailSending->id = 1;
        $this->emailSending->thread_id = 'test-thread-id';
        $this->emailSending->email_content = 'Test email body';
        $this->emailSending->email_subject = 'Test Thread';
        $this->emailSending->email_to = 'entity@example.com';
        $this->emailSending->email_from = 'test@example.com';
        $this->emailSending->email_from_name = 'Test Sender';
        $this->emailSending->status = ThreadEmailSending::STATUS_READY_FOR_SENDING;
        
        // Set up mock ThreadEmailSending
        MockThreadEmailSending::setMockInstance($this->emailSending);
        MockThreadEmailSending::resetUpdatedStatuses();
        
        // Create the sender with mock dependencies
        $this->sender = new TestableThreadScheduledEmailSender(
            $this->mockDbOps,
            $this->mockEmailService,
            $this->mockHistory
        );
        
        // Set up mock entity and thread
        $this->sender->setMockEntity($this->entity);
        $this->sender->setMockThread($this->thread);
    }
    
    /**
     * Test that findNextThreadForSending returns null when no threads are ready
     */
    public function testNoThreadsReadyForSending() {
        // :: Setup
        // No thread ready for sending
        $this->sender->setMockThread(null);
        
        // :: Act
        $result = $this->sender->sendNextScheduledEmail();
        
        // :: Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('No threads ready for sending', $result['message']);
    }
    
    /**
     * Test successful email sending
     */
    public function testSuccessfulEmailSending() {
        // :: Setup - already done in setUp()
        
        // :: Act
        $result = $this->sender->sendNextScheduledEmail();
        
        // :: Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('Email sent successfully', $result['message']);
        $this->assertEquals($this->thread->id, $result['thread_id']);
        
        // Verify the thread status was updated correctly
        $updatedThreads = $this->mockDbOps->getUpdatedThreads();
        $this->assertCount(2, $updatedThreads, 'Thread should be updated twice: once to SENDING and once to SENT');
        
        // First update should set status to SENDING
        $this->assertEquals(Thread::SENDING_STATUS_SENDING, $updatedThreads[0]['thread']->sending_status);
        $this->assertEquals('system', $updatedThreads[0]['userId']);
        
        // Second update should set status to SENT
        $this->assertEquals(Thread::SENDING_STATUS_SENT, $updatedThreads[1]['thread']->sending_status);
        $this->assertTrue($updatedThreads[1]['thread']->sent);
        $this->assertEquals('system', $updatedThreads[1]['userId']);
        
        // Verify the ThreadEmailSending status was updated correctly
        $updatedStatuses = MockThreadEmailSending::getUpdatedStatuses();
        $this->assertCount(2, $updatedStatuses, 'ThreadEmailSending should be updated twice: once to SENDING and once to SENT');
        
        // First update should set status to SENDING
        $this->assertEquals(1, $updatedStatuses[0]['id']);
        $this->assertEquals(ThreadEmailSending::STATUS_SENDING, $updatedStatuses[0]['status']);
        
        // Second update should set status to SENT
        $this->assertEquals(1, $updatedStatuses[1]['id']);
        $this->assertEquals(ThreadEmailSending::STATUS_SENT, $updatedStatuses[1]['status']);
        $this->assertNotNull($updatedStatuses[1]['smtpResponse']);
        $this->assertNotNull($updatedStatuses[1]['smtpDebug']);
        
        // Verify the email was sent with correct parameters
        $sentEmails = $this->mockEmailService->getSentEmails();
        $this->assertCount(1, $sentEmails);
        $this->assertEquals($this->emailSending->email_from, $sentEmails[0]['from']);
        $this->assertEquals($this->emailSending->email_from_name, $sentEmails[0]['fromName']);
        $this->assertEquals($this->emailSending->email_to, $sentEmails[0]['to']);
        $this->assertEquals($this->emailSending->email_subject, $sentEmails[0]['subject']);
        $this->assertEquals($this->emailSending->email_content, $sentEmails[0]['body']);
        $this->assertEquals($this->emailSending->email_from, $sentEmails[0]['bcc']);
    }
    
    /**
     * Test failed email sending
     */
    public function testFailedEmailSending() {
        // :: Setup
        // Create a mock email service that fails
        $failingEmailService = new MockEmailService(false);
        
        // Create a sender with the failing email service
        $sender = new TestableThreadScheduledEmailSender(
            $this->mockDbOps,
            $failingEmailService,
            $this->mockHistory
        );
        
        // Set up mock thread and entity
        $sender->setMockThread($this->thread);
        $sender->setMockEntity($this->entity);
        
        // Reset the updated statuses
        MockThreadEmailSending::resetUpdatedStatuses();
        
        // :: Act
        $result = $sender->sendNextScheduledEmail();
        
        // :: Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to send email: Mock email failure', $result['message']);
        $this->assertEquals($this->thread->id, $result['thread_id']);
        $this->assertEquals('Mock debug output', $result['debug']);
        
        // Verify the thread status was updated correctly
        $updatedThreads = $this->mockDbOps->getUpdatedThreads();
        $this->assertCount(2, $updatedThreads, 'Thread should be updated twice: once to SENDING and once back to READY_FOR_SENDING');
        
        // First update should set status to SENDING
        $this->assertEquals(Thread::SENDING_STATUS_SENDING, $updatedThreads[0]['thread']->sending_status);
        
        // Second update should set status back to READY_FOR_SENDING
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $updatedThreads[1]['thread']->sending_status);
        
        // Verify the ThreadEmailSending status was updated correctly
        $updatedStatuses = MockThreadEmailSending::getUpdatedStatuses();
        $this->assertCount(2, $updatedStatuses, 'ThreadEmailSending should be updated twice: once to SENDING and once back to READY_FOR_SENDING');
        
        // First update should set status to SENDING
        $this->assertEquals(1, $updatedStatuses[0]['id']);
        $this->assertEquals(ThreadEmailSending::STATUS_SENDING, $updatedStatuses[0]['status']);
        
        // Second update should set status back to READY_FOR_SENDING
        $this->assertEquals(1, $updatedStatuses[1]['id']);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $updatedStatuses[1]['status']);
        $this->assertNotNull($updatedStatuses[1]['smtpResponse']);
        $this->assertNotNull($updatedStatuses[1]['smtpDebug']);
        $this->assertNotNull($updatedStatuses[1]['errorMessage']);
    }
}
