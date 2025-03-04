<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadScheduledEmailSender.php';


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
 * Custom ThreadScheduledEmailSender for testing
 * This allows us to override the protected methods for testing
 */
class TestableThreadScheduledEmailSender extends ThreadScheduledEmailSender {
    private $mockThread = null;
    private $mockEntity = null;
    
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
}

class ThreadScheduledEmailSenderTest extends PHPUnit\Framework\TestCase {
    private $mockDbOps;
    private $mockEmailService;
    private $mockHistory;
    private $sender;
    private $thread;
    private $entity;
    
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
        
        // Create the sender with mock dependencies
        $this->sender = new TestableThreadScheduledEmailSender(
            $this->mockDbOps,
            $this->mockEmailService,
            $this->mockHistory
        );
        
        // Set up mock entity
        $this->sender->setMockEntity($this->entity);
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
        // :: Setup
        // Set up mock thread
        $this->sender->setMockThread($this->thread);
        
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
        
        // Verify the email was sent with correct parameters
        $sentEmails = $this->mockEmailService->getSentEmails();
        $this->assertCount(1, $sentEmails);
        $this->assertEquals($this->thread->my_email, $sentEmails[0]['from']);
        $this->assertEquals($this->thread->my_name, $sentEmails[0]['fromName']);
        $this->assertEquals('entity@example.com', $sentEmails[0]['to']);
        $this->assertEquals($this->thread->title, $sentEmails[0]['subject']);
        $this->assertEquals($this->thread->initial_request, $sentEmails[0]['body']);
        $this->assertEquals($this->thread->my_email, $sentEmails[0]['bcc']);
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
    }
}
