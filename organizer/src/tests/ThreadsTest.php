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
    private $fileOps;

    protected function setUp(): void {
        parent::setUp();
        $this->testDataDir = DATA_DIR;
        $this->threadsDir = THREADS_DIR;
        
        // Clean database tables
        $db = new Database();
        $db->execute("DELETE FROM thread_email_history");
        $db->execute("DELETE FROM thread_history");
        $db->execute("DELETE FROM thread_authorizations");
        $db->execute("DELETE FROM thread_email_attachments");
        $db->execute("DELETE FROM thread_emails");
        $db->execute("DELETE FROM threads");
        
        // Create test directories
        if (!file_exists($this->threadsDir)) {
            mkdir($this->threadsDir, 0777, true);
        }
        $this->fileOps = new ThreadFileOperations();
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
        $this->fileOps->saveEntityThreads($entityId, $threads);

        // Assert
        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
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
        $result = $this->fileOps->createThread($entityId, $titlePrefix, $thread);

        // Assert
        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
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
        
        $this->fileOps->createThread($entityId, $titlePrefix, $existingThread);

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
        $result = $this->fileOps->createThread($entityId, $titlePrefix, $newThread);

        // Assert
        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
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
        $thread->id = '550e8400-e29b-41d4-a716-446655440000';
        $thread->my_email = 'test@example.com';
        $thread->my_name = 'Test User';
        $thread->sent = false;

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent) VALUES (?, ?, ?, ?, ?, ?)",
            [$thread->id, 'test-entity', 'Test Thread', $thread->my_name, $thread->my_email, 'f']
        );

        $emailService = new MockEmailService(true);
        
        // Create mock history with test user
        $mockHistory = new class extends ThreadHistory {
            public function logAction($threadId, $action, $userId, $details = null) {
                // Always use test user id for consistency
                return parent::logAction($threadId, $action, 'test-user', $details);
            }
        };

        // Act
        $result = sendThreadEmail(
            $thread,
            'recipient@example.com',
            'Test Subject',
            'Test Body',
            'entity1',
            new Threads(),
            $emailService,
            null,
            new $mockHistory()
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
        $thread->id = '550e8400-e29b-41d4-a716-446655440001';
        $thread->my_email = 'test@example.com';
        $thread->my_name = 'Test User';
        $thread->sent = false;

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent) VALUES (?, ?, ?, ?, ?, ?)",
            [$thread->id, 'test-entity', 'Test Thread', $thread->my_name, $thread->my_email, 'f']
        );

        $emailService = new MockEmailService(false);
        
        // Create mock history with test user
        $mockHistory = new class extends ThreadHistory {
            public function logAction($threadId, $action, $userId, $details = null) {
                // Always use test user id for consistency
                return parent::logAction($threadId, $action, 'test-user', $details);
            }
        };

        // Act
        $result = sendThreadEmail(
            $thread,
            'recipient@example.com',
            'Test Subject',
            'Test Body',
            'entity1',
            new Threads(),
            $emailService,
            null,
            new $mockHistory()
        );

        // Assert
        $this->assertFalse($result['success']);
        $this->assertFalse($thread->sent);
        $this->assertEquals('Mock email failure', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
    }
}
