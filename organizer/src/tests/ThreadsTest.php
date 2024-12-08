<?php

use PHPUnit\Framework\TestCase;
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');

class MockEmailService implements IEmailService {
    private $shouldSucceed;
    private $lastError = '';
    public $lastEmailData;

    public function __construct($shouldSucceed = true) {
        $this->shouldSucceed = $shouldSucceed;
    }

    public function sendEmail($from, $fromName, $to, $subject, $body, $bcc = null) {
        $this->lastEmailData = [
            'from' => $from,
            'fromName' => $fromName,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'bcc' => $bcc
        ];
        if (!$this->shouldSucceed) {
            $this->lastError = 'Mock email failure';
        }
        return $this->shouldSucceed;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getDebugOutput() {
        return 'Mock debug output';
    }
}

// Mock version of saveEntityThreads for testing
function saveEntityThreads($entityId, $entity_threads) {
    global $mockSavedThreads;
    $mockSavedThreads[$entityId] = $entity_threads;
}

// Mock version of createThread for testing
function createThread($entityId, $entityTitlePrefix, $thread) {
    global $mockSavedThreads;
    $existingThreads = getThreadsForEntity($entityId);
    if ($existingThreads == null) {
        $existingThreads = new Threads();
        $existingThreads->entity_id = $entityId;
        $existingThreads->title_prefix = $entityTitlePrefix;
        $existingThreads->threads = array();
    }
    $existingThreads->threads[] = $thread;
    
    $mockSavedThreads[$entityId] = $existingThreads;
    return $thread;
}

// Mock version of getThreadsForEntity for testing
function getThreadsForEntity($entityId) {
    global $mockSavedThreads;
    return isset($mockSavedThreads[$entityId]) ? $mockSavedThreads[$entityId] : null;
}

class ThreadsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        global $mockSavedThreads;
        $mockSavedThreads = [];
    }

    public function testSaveEntityThreads() {
        global $mockSavedThreads;
        
        // Arrange
        $entityId = 'test-entity';
        $threads = new Threads();
        $threads->entity_id = $entityId;
        $threads->title_prefix = 'Test';
        $threads->threads = [];

        // Act
        saveEntityThreads($entityId, $threads);

        // Assert
        $this->assertArrayHasKey($entityId, $mockSavedThreads);
        $savedThreads = $mockSavedThreads[$entityId];
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertEquals('Test', $savedThreads->title_prefix);
        $this->assertIsArray($savedThreads->threads);
    }

    public function testCreateThreadForNewEntity() {
        global $mockSavedThreads;
        
        // Arrange
        $entityId = 'test-entity';
        $titlePrefix = 'Test Prefix';
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->emails = [];

        // Act
        $result = createThread($entityId, $titlePrefix, $thread);

        // Assert
        $this->assertArrayHasKey($entityId, $mockSavedThreads);
        $savedThreads = $mockSavedThreads[$entityId];
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertEquals($titlePrefix, $savedThreads->title_prefix);
        $this->assertCount(1, $savedThreads->threads);
        $this->assertEquals($thread, $savedThreads->threads[0]);
        $this->assertEquals($thread, $result);
    }

    public function testCreateThreadForExistingEntity() {
        global $mockSavedThreads;
        
        // Arrange
        $entityId = 'test-entity';
        $titlePrefix = 'Test Prefix';
        
        // Create existing threads
        $existingThreads = new Threads();
        $existingThreads->entity_id = $entityId;
        $existingThreads->title_prefix = $titlePrefix;
        $existingThreads->threads = [];
        
        $existingThread = new Thread();
        $existingThread->title = 'Existing Thread';
        $existingThread->my_name = 'Test User';
        $existingThread->my_email = 'test@example.com';
        $existingThread->labels = [];
        $existingThread->sent = true;
        $existingThread->archived = false;
        $existingThread->emails = [];
        
        $existingThreads->threads[] = $existingThread;
        $mockSavedThreads[$entityId] = $existingThreads;

        // Create new thread to add
        $newThread = new Thread();
        $newThread->title = 'New Thread';
        $newThread->my_name = 'Test User';
        $newThread->my_email = 'test@example.com';
        $newThread->labels = [];
        $newThread->sent = false;
        $newThread->archived = false;
        $newThread->emails = [];

        // Act
        $result = createThread($entityId, $titlePrefix, $newThread);

        // Assert
        $this->assertArrayHasKey($entityId, $mockSavedThreads);
        $savedThreads = $mockSavedThreads[$entityId];
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertEquals($titlePrefix, $savedThreads->title_prefix);
        $this->assertCount(2, $savedThreads->threads);
        $this->assertEquals($existingThread, $savedThreads->threads[0]);
        $this->assertEquals($newThread, $savedThreads->threads[1]);
        $this->assertEquals($newThread, $result);
    }

    public function testSendThreadEmail() {
        // Arrange
        $thread = new stdClass();
        $thread->my_email = 'test@example.com';
        $thread->my_name = 'Test User';
        $thread->sent = false;

        $emailService = new MockEmailService(true);

        // Act
        $result = sendThreadEmail(
            $thread,
            'recipient@example.com',
            'Test Subject',
            'Test Body',
            'entity1',
            new Threads(),
            $emailService
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('test@example.com', $emailService->lastEmailData['from']);
        $this->assertEquals('Test User', $emailService->lastEmailData['fromName']);
        $this->assertEquals('recipient@example.com', $emailService->lastEmailData['to']);
        $this->assertEquals('Test Subject', $emailService->lastEmailData['subject']);
        $this->assertEquals('Test Body', $emailService->lastEmailData['body']);
        $this->assertEquals('', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
    }

    public function testSendThreadEmailFailure() {
        // Arrange
        $thread = new stdClass();
        $thread->my_email = 'test@example.com';
        $thread->my_name = 'Test User';
        $thread->sent = false;

        $emailService = new MockEmailService(false);

        // Act
        $result = sendThreadEmail(
            $thread,
            'recipient@example.com',
            'Test Subject',
            'Test Body',
            'entity1',
            new Threads(),
            $emailService
        );

        // Assert
        $this->assertFalse($result['success']);
        $this->assertFalse($thread->sent);
        $this->assertEquals('Mock email failure', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
    }
}
