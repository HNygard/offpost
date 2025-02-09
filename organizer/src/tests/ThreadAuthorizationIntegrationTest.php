<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadAuthorization.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/Threads.php';
require_once __DIR__ . '/../class/ThreadFileOperations.php';

class ThreadAuthorizationIntegrationTest extends PHPUnit\Framework\TestCase {
    private $entityId = 'test_entity';
    private $userId = 'test_user';
    private $otherUserId = 'other_user';
    private $fileOps;
    protected function setUp(): void {
        parent::setUp();
        $this->fileOps = new ThreadFileOperations();
        
        // Clean any existing test files
        $this->cleanDirectory(THREADS_DIR);
        $this->cleanDirectory(THREAD_AUTH_DIR);
    }

    protected function tearDown(): void {
        parent::tearDown();
        
        // Cleanup test files
        $this->cleanDirectory(THREADS_DIR);
        $this->cleanDirectory(THREAD_AUTH_DIR);
    }

    private function cleanDirectory($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanDirectory($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }

    public function testThreadCreationWithAuthorization() {
        // Create thread
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
        $thread->public = false;
        
        // Save thread
        $savedThread = $this->fileOps->createThread($this->entityId, 'Test Entity', $thread);
        
        // Set creator as owner
        $savedThread->addUser($this->userId, true);
        
        // Verify thread exists and owner has access
        $this->assertTrue($savedThread->canUserAccess($this->userId));
        $this->assertTrue($savedThread->isUserOwner($this->userId));
        
        // Verify other user doesn't have access
        $this->assertFalse($savedThread->canUserAccess($this->otherUserId));
    }

    public function testPublicThreadAccess() {
        // Create public thread
        $thread = new Thread();
        $thread->title = 'Public Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
        $thread->public = true;
        
        // Save thread
        $savedThread = $this->fileOps->createThread($this->entityId, 'Test Entity', $thread);
        
        // Set creator as owner
        $savedThread->addUser($this->userId, true);
        
        // Verify any user can access
        $this->assertTrue($savedThread->canUserAccess($this->userId));
        $this->assertTrue($savedThread->canUserAccess($this->otherUserId));
        
        // But only owner has owner privileges
        $this->assertTrue($savedThread->isUserOwner($this->userId));
        $this->assertFalse($savedThread->isUserOwner($this->otherUserId));
    }

    public function testThreadAuthorizationPersistence() {
        // Create thread
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
        
        // Save thread and add users
        $savedThread = $this->fileOps->createThread($this->entityId, 'Test Entity', $thread);
        $savedThread->addUser($this->userId, true);
        $savedThread->addUser($this->otherUserId);
        
        // Reload threads
        $threads = $this->fileOps->getThreadsForEntity($this->entityId);
        $reloadedThread = null;
        foreach ($threads->threads as $t) {
            if ($t->id === $savedThread->id) {
                $reloadedThread = $t;
                break;
            }
        }
        
        $this->assertNotNull($reloadedThread);
        
        // Verify authorizations persisted
        $this->assertTrue($reloadedThread->canUserAccess($this->userId));
        $this->assertTrue($reloadedThread->canUserAccess($this->otherUserId));
        $this->assertTrue($reloadedThread->isUserOwner($this->userId));
        $this->assertFalse($reloadedThread->isUserOwner($this->otherUserId));
    }

    public function testToggleThreadVisibility() {
        // Create private thread
        $thread = new Thread();
        $thread->title = 'Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
        $thread->public = false;
        
        // Save thread and add owner
        $savedThread = $this->fileOps->createThread($this->entityId, 'Test Entity', $thread);
        $savedThread->addUser($this->userId, true);
        
        // Initially other user can't access
        $this->assertFalse($savedThread->canUserAccess($this->otherUserId));
        
        // Toggle to public
        $savedThread->public = true;
        
        // Create threads container
        $threads = new Threads();
        $threads->entity_id = $this->entityId;
        $threads->title_prefix = 'Test Entity';
        $threads->threads = array($savedThread);
        
        $this->fileOps->saveEntityThreads($this->entityId, $threads);
        
        // Reload and verify public access
        $reloadedThreads = $this->fileOps->getThreadsForEntity($this->entityId);
        $reloadedThread = null;
        foreach ($reloadedThreads->threads as $t) {
            if ($t->id === $savedThread->id) {
                $reloadedThread = $t;
                break;
            }
        }
        
        $this->assertNotNull($reloadedThread);
        $this->assertTrue($reloadedThread->public);
        $this->assertTrue($reloadedThread->canUserAccess($this->otherUserId));
    }
}
