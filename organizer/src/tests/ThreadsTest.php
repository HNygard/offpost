<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/ThreadFileOperations.php');

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

class ThreadsTest extends TestCase {
    private $testDataDir;
    private $threadsDir;

    protected function setUp(): void {
        parent::setUp();
        $this->testDataDir = DATA_DIR;
        $this->threadsDir = THREADS_DIR;
        
        // Create test directories
        if (!file_exists($this->threadsDir)) {
            mkdir($this->threadsDir, 0777, true);
        }
    }

    protected function tearDown(): void {
        // Clean up test directories
        $this->removeDirectory($this->threadsDir);
        parent::tearDown();
    }
    
    private function removeDirectory($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = joinPaths($dir, $file);
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testSaveEntityThreads() {
        // Arrange
        $entityId = 'test-entity';
        $threads = new Threads();
        $threads->entity_id = $entityId;
        $threads->title_prefix = 'Test';
        $threads->threads = [];

        // Act
        saveEntityThreads($entityId, $threads);

        // Assert
        $savedThreads = getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertEquals('Test', $savedThreads->title_prefix);
        $this->assertIsArray($savedThreads->threads);
    }

    public function testCreateThreadForNewEntity() {
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
        $savedThreads = getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertEquals($titlePrefix, $savedThreads->title_prefix);
        $this->assertCount(1, $savedThreads->threads);
        $this->assertEquals($thread, $savedThreads->threads[0]);
        $this->assertEquals($thread, $result);
    }

    public function testCreateThreadForExistingEntity() {
        // Arrange
        $entityId = 'test-entity';
        $titlePrefix = 'Test Prefix';
        
        // Create existing thread
        $existingThread = new Thread();
        $existingThread->title = 'Existing Thread';
        $existingThread->my_name = 'Test User';
        $existingThread->my_email = 'test@example.com';
        $existingThread->labels = [];
        $existingThread->sent = true;
        $existingThread->archived = false;
        $existingThread->emails = [];
        
        createThread($entityId, $titlePrefix, $existingThread);

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
        $savedThreads = getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
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
