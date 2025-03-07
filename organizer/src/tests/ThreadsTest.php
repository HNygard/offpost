<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/ThreadFileOperations.php');


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
        $db->execute("BEGIN");
        $db->execute("DELETE FROM thread_email_history");
        $db->execute("DELETE FROM thread_history");
        $db->execute("DELETE FROM thread_authorizations");
        $db->execute("DELETE FROM thread_email_attachments");
        $db->execute("DELETE FROM thread_emails");
        $db->execute("DELETE FROM thread_email_sendings");
        $db->execute("DELETE FROM threads");
        $db->execute("COMMIT");
        
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
        $threads->threads = [];

        // Act
        $this->fileOps->saveEntityThreads($entityId, $threads);

        // Assert
        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertIsArray($savedThreads->threads);
    }

    public function testCreateThreadForNewEntity() {
        // Arrange
        $entityId = 'test-entity';
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->emails = [];

        // Act
        $result = $this->fileOps->createThread($entityId, $thread);

        // Assert
        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertCount(1, $savedThreads->threads);
        $this->assertEquals($thread, $savedThreads->threads[0]);
        $this->assertEquals($thread, $result);
    }

    public function testCreateThreadForExistingEntity() {
        // Arrange
        $entityId = 'test-entity';
        
        // Create existing thread
        $existingThread = new Thread();
        $existingThread->title = 'Existing Thread';
        $existingThread->my_name = 'Test User';
        $existingThread->my_email = 'test@example.com';
        $existingThread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $existingThread->labels = [];
        $existingThread->sent = true;
        $existingThread->archived = false;
        $existingThread->emails = [];
        
        $this->fileOps->createThread($entityId, $existingThread);

        // Create new thread to add
        $newThread = new Thread();
        $newThread->title = 'New Thread';
        $newThread->my_name = 'Test User';
        $newThread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $newThread->labels = [];
        $newThread->sent = false;
        $newThread->archived = false;
        $newThread->emails = [];

        // Act
        $result = $this->fileOps->createThread($entityId, $newThread);

        // Assert
        $savedThreads = $this->fileOps->getThreadsForEntity($entityId);
        $this->assertNotNull($savedThreads);
        $this->assertEquals($entityId, $savedThreads->entity_id);
        $this->assertCount(2, $savedThreads->threads);
        $this->assertEquals($existingThread, $savedThreads->threads[0]);
        $this->assertEquals($newThread, $savedThreads->threads[1]);
        $this->assertEquals($newThread, $result);
    }

    public function testSendThreadEmail() {
        // Arrange
        $thread = new Thread();
        $thread->id = '550e8400-e29b-41d4-a716-446655440000';
        $thread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $thread->my_name = 'Test User';
        $thread->sent = false;
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->entity_id = '000000000-test-entity-development';

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent, sending_status) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$thread->id, '000000000-test-entity-development', 'Test Thread', $thread->my_name, $thread->my_email, 'f', $thread->sending_status]
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
            '000000000-test-entity-development',
            'user-id',
            $emailService,
            null,
            new $mockHistory()
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals($thread->my_email, $emailService->lastEmailData['from']);
        $this->assertEquals('Test User', $emailService->lastEmailData['fromName']);
        $this->assertEquals('recipient@example.com', $emailService->lastEmailData['to']);
        $this->assertEquals('Test Subject', $emailService->lastEmailData['subject']);
        $this->assertEquals('Test Body', $emailService->lastEmailData['body']);
        $this->assertEquals('', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
        
        // Check that sending_status was updated to SENT
        $updatedThread = Thread::loadFromDatabase($thread->id);
        $this->assertEquals(Thread::SENDING_STATUS_SENT, $updatedThread->sending_status);
    }

    public function testInitialRequestStorage() {
        // Arrange
        $thread = new Thread();
        $thread->id = '550e8400-e29b-41d4-a716-446655440002';
        $thread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $thread->my_name = 'Test User';
        $thread->title = 'Test Thread with Initial Request';
        $thread->initial_request = 'This is the initial request text';
        $thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $thread->entity_id = '000000000-test-entity-development';

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sending_status, initial_request) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$thread->id, '000000000-test-entity-development', $thread->title, $thread->my_name, $thread->my_email, $thread->sending_status, $thread->initial_request]
        );

        // Act
        $loadedThread = Thread::loadFromDatabase($thread->id);

        // Assert
        $this->assertNotNull($loadedThread);
        $this->assertEquals($thread->id, $loadedThread->id);
        $this->assertEquals($thread->initial_request, $loadedThread->initial_request);
        $this->assertEquals($thread->sending_status, $loadedThread->sending_status);
    }

    public function testSendingStatusTransitions() {
        // Arrange
        $thread = new Thread();
        $thread->id = '550e8400-e29b-41d4-a716-446655440003';
        $thread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $thread->my_name = 'Test User';
        $thread->title = 'Test Thread with Status Transitions';
        $thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $thread->entity_id = '000000000-test-entity-development';

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sending_status) VALUES (?, ?, ?, ?, ?, ?)",
            [$thread->id, '000000000-test-entity-development', $thread->title, $thread->my_name, $thread->my_email, $thread->sending_status]
        );

        // Act & Assert - Transition to READY_FOR_SENDING
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $dbOps = new ThreadDatabaseOperations();
        $dbOps->updateThread($thread, 'test-user');
        
        $loadedThread = Thread::loadFromDatabase($thread->id);
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $loadedThread->sending_status);
        
        // Act & Assert - Transition to SENDING
        $loadedThread->sending_status = Thread::SENDING_STATUS_SENDING;
        $dbOps->updateThread($loadedThread, 'test-user');
        
        $reloadedThread = Thread::loadFromDatabase($thread->id);
        $this->assertEquals(Thread::SENDING_STATUS_SENDING, $reloadedThread->sending_status);
        
        // Act & Assert - Transition to SENT
        $reloadedThread->sending_status = Thread::SENDING_STATUS_SENT;
        $dbOps->updateThread($reloadedThread, 'test-user');
        
        $finalThread = Thread::loadFromDatabase($thread->id);
        $this->assertEquals(Thread::SENDING_STATUS_SENT, $finalThread->sending_status);
    }

    public function testCreateThreadWithSendNowOption() {
        // Skip this test if we're running in a database environment
        $this->markTestSkipped('This test requires file-based entity storage which is not available in the test environment');
        
        // Arrange
        $entityId = '000000000-test-entity-development'; // Use a valid entity ID

        // Create thread with send_now = true
        $threadWithSendNow = new Thread();
        $threadWithSendNow->title = 'Thread with Send Now';
        $threadWithSendNow->my_name = 'Test User';
        $threadWithSendNow->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $threadWithSendNow->labels = [];
        $threadWithSendNow->initial_request = 'This is a request to be sent immediately';
        $threadWithSendNow->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING; // Simulating send_now=true
        $threadWithSendNow->archived = false;
        $threadWithSendNow->emails = [];
        
        // Create thread with send_now = false
        $threadWithoutSendNow = new Thread();
        $threadWithoutSendNow->title = 'Thread without Send Now';
        $threadWithoutSendNow->my_name = 'Test User';
        $threadWithoutSendNow->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $threadWithoutSendNow->labels = [];
        $threadWithoutSendNow->initial_request = 'This is a request to be staged';
        $threadWithoutSendNow->sending_status = Thread::SENDING_STATUS_STAGING; // Simulating send_now=false
        $threadWithoutSendNow->archived = false;
        $threadWithoutSendNow->emails = [];
        
        // Act
        $dbOps = new ThreadDatabaseOperations();
        $resultWithSendNow = $dbOps->createThread($entityId, $threadWithSendNow, 'test-user');
        $resultWithoutSendNow = $dbOps->createThread($entityId, $threadWithoutSendNow, 'test-user');
        
        // Assert
        $loadedThreadWithSendNow = Thread::loadFromDatabase($resultWithSendNow->id);
        $loadedThreadWithoutSendNow = Thread::loadFromDatabase($resultWithoutSendNow->id);
        
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $loadedThreadWithSendNow->sending_status);
        $this->assertEquals(Thread::SENDING_STATUS_STAGING, $loadedThreadWithoutSendNow->sending_status);
        
        $this->assertEquals('This is a request to be sent immediately', $loadedThreadWithSendNow->initial_request);
        $this->assertEquals('This is a request to be staged', $loadedThreadWithoutSendNow->initial_request);
    }

    public function testSendThreadEmailFailure() {
        // Arrange
        $thread = new Thread();
        $thread->id = '550e8400-e29b-41d4-a716-446655440001';
        $thread->my_email = "test" . mt_rand(0, 100) . time() ."@example.com";
        $thread->my_name = 'Test User';
        $thread->sent = false;
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->entity_id = '000000000-test-entity-development';

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent, sending_status) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$thread->id, '000000000-test-entity-development', 'Test Thread', $thread->my_name, $thread->my_email, 'f', $thread->sending_status]
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
            '000000000-test-entity-development',
            'user-id',
            $emailService,
            null,
            new $mockHistory()
        );

        // Assert
        $this->assertFalse($result['success']);
        $this->assertFalse($thread->sent);
        $this->assertEquals('Mock email failure', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
        
        // Check that sending_status was reverted to READY_FOR_SENDING
        $updatedThread = Thread::loadFromDatabase($thread->id);
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $updatedThread->sending_status);
    }
}
