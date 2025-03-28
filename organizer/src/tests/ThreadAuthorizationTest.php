<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadAuthorization.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/ThreadHistory.php';

class ThreadAuthorizationTest extends PHPUnit\Framework\TestCase {
    private $testThread;
    private $testUserId = 'test_user';
    private $otherUserId = 'other_user';
    private $storageManager;

    protected function setUp(): void {
        parent::setUp();
        
        // Clean database tables
        $db = new Database();
        $db->execute("DELETE FROM thread_email_history");
        $db->execute("DELETE FROM thread_history");
        $db->execute("DELETE FROM thread_authorizations");
        $db->execute("DELETE FROM thread_email_attachments");
        $db->execute("DELETE FROM thread_email_extractions");
        $db->execute("DELETE FROM thread_emails");
        $db->execute("DELETE FROM thread_email_sendings");
        $db->execute("DELETE FROM imap_folder_status");
        $db->execute("DELETE FROM threads");
        
        // Clean any existing test files
        $this->cleanDirectory(THREAD_AUTH_DIR);
        
        $this->storageManager = ThreadStorageManager::getInstance();
        
        // Create test thread with unique email
        $this->testThread = new Thread();
        $this->testThread->title = 'Test Thread';
        $this->testThread->my_name = 'Test User';
        $this->testThread->my_email = 'test' . uniqid() . '@example.com';
        
        // Store the thread using ThreadStorageManager
        $this->storageManager->createThread('000000000-test-entity-development', $this->testThread, $this->testUserId);
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
        $anotherThread->my_name = 'Test User';
        $anotherThread->my_email = 'test' . uniqid() . '@example.com';
        
        // Store the second thread
        $this->storageManager->createThread('000000000-test-entity-development', $anotherThread);
        
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

    public function testUserAuthorizationHistory() {
        // Add user and verify history
        $this->testThread->addUser($this->testUserId, true);
        
        // Get history entries
        $history = new ThreadHistory();
        $historyEntries = $history->getHistoryForThread($this->testThread->id);
        
        // Should have two entries (thread creation and user addition)
        $this->assertCount(2, $historyEntries, "Should have two history entries");
        // Entries are returned in reverse chronological order (newest first)
        $this->assertEquals('user_added', $historyEntries[0]['action'], "First action should be user addition");
        $this->assertEquals('created', $historyEntries[1]['action'], "Second action should be thread creation");
        
        $details = json_decode($historyEntries[0]['details'], true);
        $this->assertEquals($this->testUserId, $details['user_id']);
        $this->assertTrue($details['is_owner']);

        // Remove user and verify history
        $this->testThread->removeUser($this->testUserId);
        
        // Get updated history entries
        $historyEntries = $history->getHistoryForThread($this->testThread->id);
        
        // Should now have three entries (creation, add, and remove)
        $this->assertCount(3, $historyEntries, "Should have three history entries");
        
        // Entries are returned in reverse chronological order (newest first)
        $this->assertEquals('user_removed', $historyEntries[0]['action'], "First action should be user removal");
        $this->assertEquals('user_added', $historyEntries[1]['action'], "Second action should be user addition");
        $this->assertEquals('created', $historyEntries[2]['action'], "Third action should be thread creation");
        
        $removeDetails = json_decode($historyEntries[0]['details'], true);
        $this->assertEquals($this->testUserId, $removeDetails['user_id']);
    }
}
