<?php

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../class/ThreadAuthorization.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/Threads.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';

class ThreadAuthorizationIntegrationTest extends PHPUnit\Framework\TestCase {
    private $entityId = '000000000-test-entity-development';
    private $userId = 'test_user';
    private $otherUserId = 'other_user';
    private $storageManager;
    
    protected function setUp(): void {
        parent::setUp();
        $this->storageManager = ThreadStorageManager::getInstance();
        
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

    private function generateUniqueEmail(): string {
        return 'test' . uniqid() . '@example.com';
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
        $thread->my_email = $this->generateUniqueEmail();
        $thread->public = false;
        
        // Save thread
        $savedThread = $this->storageManager->createThread($this->entityId, $thread);
        
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
        $thread->my_email = $this->generateUniqueEmail();
        $thread->public = true;
        
        // Save thread
        $savedThread = $this->storageManager->createThread($this->entityId, $thread);
        
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
        $thread->my_email = $this->generateUniqueEmail();
        
        // Save thread and add users
        $savedThread = $this->storageManager->createThread($this->entityId, $thread);
        $savedThread->addUser($this->userId, true);
        $savedThread->addUser($this->otherUserId);
        
        // Reload threads
        $threads = $this->storageManager->getThreadsForEntity($this->entityId);
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
        $thread->my_email = $this->generateUniqueEmail();
        $thread->public = false;
        
        // Save thread and add owner
        $savedThread = $this->storageManager->createThread($this->entityId, $thread);
        $savedThread->addUser($this->userId, true);
        
        // Initially other user can't access
        $this->assertFalse($savedThread->canUserAccess($this->otherUserId));
        
        // Toggle to public and save
        $savedThread->public = true;
        $this->storageManager->updateThread($savedThread);
        
        // Reload and verify public access
        $reloadedThreads = $this->storageManager->getThreadsForEntity($this->entityId);
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
