<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadAuthorization.php';
require_once __DIR__ . '/../class/Thread.php';

class ThreadAuthorizationTest extends PHPUnit\Framework\TestCase {
    private $testThread;
    private $testUserId = 'test_user';
    private $otherUserId = 'other_user';
    protected function setUp(): void {
        parent::setUp();
        
        // Create test thread
        $this->testThread = new Thread();
        $this->testThread->title = 'Test Thread';
        $this->testThread->my_name = 'Test User';
        $this->testThread->my_email = 'test@example.com';
        
        // Store the thread using ThreadStorageManager
        $storageManager = ThreadStorageManager::getInstance();
        $storageManager->createThread('test_entity', 'Test', $this->testThread);
        
        // Clean any existing test files
        $this->cleanDirectory(THREAD_AUTH_DIR);
    }

    protected function tearDown(): void {
        parent::tearDown();
        
        // Cleanup test files
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

    public function testAddUserToThread() {
        // Test adding normal user
        $auth = $this->testThread->addUser($this->testUserId);
        $this->assertInstanceOf(ThreadAuthorization::class, $auth);
        $this->assertEquals($this->testThread->id, $auth->getThreadId());
        $this->assertEquals($this->testUserId, $auth->getUserId());
        $this->assertFalse($auth->isOwner());

        // Test adding owner
        $ownerAuth = $this->testThread->addUser($this->otherUserId, true);
        $this->assertTrue($ownerAuth->isOwner());
    }

    public function testRemoveUserFromThread() {
        // Add and then remove user
        $this->testThread->addUser($this->testUserId);
        $this->assertTrue($this->testThread->canUserAccess($this->testUserId));
        
        $this->testThread->removeUser($this->testUserId);
        $this->assertFalse($this->testThread->canUserAccess($this->testUserId));
    }

    public function testPublicThreadAccess() {
        // Test private thread
        $this->testThread->public = false;
        $this->assertFalse($this->testThread->canUserAccess($this->testUserId));

        // Add user and verify access
        $this->testThread->addUser($this->testUserId);
        $this->assertTrue($this->testThread->canUserAccess($this->testUserId));

        // Make thread public and verify anyone can access
        $this->testThread->public = true;
        $this->assertTrue($this->testThread->canUserAccess($this->testUserId));
        $this->assertTrue($this->testThread->canUserAccess($this->otherUserId));
    }

    public function testOwnerManagement() {
        // Add owner
        $this->testThread->addUser($this->testUserId, true);
        $this->assertTrue($this->testThread->isUserOwner($this->testUserId));
        
        // Verify non-owner
        $this->testThread->addUser($this->otherUserId);
        $this->assertFalse($this->testThread->isUserOwner($this->otherUserId));
    }

    public function testGetThreadUsers() {
        // Add multiple users
        $this->testThread->addUser($this->testUserId, true);
        $this->testThread->addUser($this->otherUserId);
        
        $users = ThreadAuthorizationManager::getThreadUsers($this->testThread->id);
        $this->assertCount(2, $users);
        
        // Verify user properties
        $foundOwner = false;
        $foundNormalUser = false;
        foreach ($users as $user) {
            if ($user->getUserId() === $this->testUserId) {
                $this->assertTrue($user->isOwner());
                $foundOwner = true;
            }
            if ($user->getUserId() === $this->otherUserId) {
                $this->assertFalse($user->isOwner());
                $foundNormalUser = true;
            }
        }
        $this->assertTrue($foundOwner && $foundNormalUser);
    }

    public function testGetUserThreads() {
        // Create another test thread
        $anotherThread = new Thread();
        $anotherThread->title = 'Another Test Thread';
        
        // Add user to both threads
        $this->testThread->addUser($this->testUserId);
        $anotherThread->addUser($this->testUserId);
        
        // Get user's threads
        $threads = ThreadAuthorizationManager::getUserThreads($this->testUserId);
        $this->assertCount(2, $threads);
        
        // Verify thread IDs
        $threadIds = array_map(function($auth) {
            return $auth->getThreadId();
        }, $threads);
        $this->assertContains($this->testThread->id, $threadIds);
        $this->assertContains($anotherThread->id, $threadIds);
    }

    public function testAuthorizationPersistence() {
        // Add user and verify storage
        $this->testThread->addUser($this->testUserId, true);
        
        // Reload authorizations
        $users = ThreadAuthorizationManager::getThreadUsers($this->testThread->id);
        $this->assertCount(1, $users);
        
        $user = reset($users);
        $this->assertEquals($this->testUserId, $user->getUserId());
        $this->assertTrue($user->isOwner());
    }
}
