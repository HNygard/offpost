<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadDatabaseOperations.php';
require_once __DIR__ . '/../class/Threads.php';

class ThreadDatabaseOperationsTest extends PHPUnit\Framework\TestCase {
    private $db;
    private $threadDbOps;
    
    protected function setUp(): void {
        // Initialize database connection
        $this->db = Database::getInstance();
        $this->threadDbOps = new ThreadDatabaseOperations();
        
        // Start transaction for test isolation
        Database::beginTransaction();
        
        // Clean up any existing test data
        Database::execute("DELETE FROM thread_history WHERE thread_id IN (SELECT id FROM threads WHERE entity_id = 'test_entity')");
        Database::execute("DELETE FROM thread_email_attachments WHERE email_id IN (SELECT id FROM thread_emails WHERE thread_id IN (SELECT id FROM threads WHERE entity_id = 'test_entity'))");
        Database::execute("DELETE FROM thread_emails WHERE thread_id IN (SELECT id FROM threads WHERE entity_id = 'test_entity')");
        Database::execute("DELETE FROM threads WHERE entity_id = 'test_entity'");
    }
    
    protected function tearDown(): void {
        // Rollback transaction to clean up test data
        if ($this->db) {
            Database::rollBack();
        }
    }

    public function testCreateAndRetrieveThread() {
        // Create a test thread
        $thread = new Thread();
        $thread->title = "Test Thread";
        $thread->my_name = "Test User";
        $thread->my_email = "test@example.com";
        $thread->sent = false;
        $thread->archived = false;
        $thread->labels = ["test"];
        $thread->sentComment = "Test comment";

        $createdThread = $this->threadDbOps->createThread('test_entity', 'Test', $thread, 'test-user');
        $this->assertNotNull($createdThread->id, "Thread should have an ID after creation");

        // Retrieve and verify
        $threads = $this->threadDbOps->getThreadsForEntity('test_entity');
        $this->assertNotNull($threads, "Should retrieve threads for entity");
        $this->assertEquals(1, count($threads->threads), "Should have one thread");
        
        $retrievedThread = $threads->threads[0];
        $this->assertEquals($thread->title, $retrievedThread->title);
        $this->assertEquals($thread->my_name, $retrievedThread->my_name);
        $this->assertEquals($thread->my_email, $retrievedThread->my_email);
        $this->assertEquals($thread->sent, $retrievedThread->sent);
        $this->assertEquals($thread->archived, $retrievedThread->archived);
        $this->assertEquals($thread->labels, $retrievedThread->labels);
        $this->assertEquals($thread->sentComment, $retrievedThread->sentComment);

        // Verify history was created
        $history = new ThreadHistory();
        $historyEntries = $history->getHistoryForThread($createdThread->id);
        $this->assertCount(1, $historyEntries, "Should have one history entry");
        $this->assertEquals('created', $historyEntries[0]['action']);
        $this->assertEquals('test-user', $historyEntries[0]['user_id']);
    }

    public function testUpdateThreadHistory() {
        // Create initial thread
        $thread = new Thread();
        $thread->title = "Initial Title";
        $thread->my_name = "Test User";
        $thread->my_email = "test@example.com";
        $thread->labels = ["initial"];
        $thread->archived = false;
        
        $createdThread = $this->threadDbOps->createThread('test_entity', 'Test', $thread, 'test-user');
        
        // Update thread title and labels
        $thread->id = $createdThread->id;
        $thread->title = "Updated Title";
        $thread->labels = ["initial", "new-label"];
        $this->threadDbOps->updateThread($thread, 'test-user');
        
        // Archive thread
        $thread->archived = true;
        $this->threadDbOps->updateThread($thread, 'test-user');
        
        // Verify history entries
        $history = new ThreadHistory();
        $historyEntries = $history->getHistoryForThread($thread->id);
        
        $this->assertCount(5, $historyEntries, "Should have five history entries. History: " . json_encode($historyEntries));
        
        // Entries are in reverse chronological order
        $this->assertEquals('created', $historyEntries[0]['action'], "First action should be thread creation");
        $this->assertEquals('edited', $historyEntries[1]['action'], "Second action should be thread edit");
        $this->assertEquals('unsent', $historyEntries[2]['action'], "Third action should be initial unsent state");
        $this->assertEquals('archived', $historyEntries[3]['action'], "Fourth action should be archiving");
        $this->assertEquals('unsent', $historyEntries[4]['action'], "Fifth action should be unsent after archive");
        
        // Verify edit details
        $editDetails = json_decode($historyEntries[1]['details'], true);
        $this->assertArrayHasKey('title', $editDetails);
        $this->assertEquals('Updated Title', $editDetails['title']);
        $this->assertArrayHasKey('labels', $editDetails);
        $this->assertEquals(['initial', 'new-label'], $editDetails['labels']);
    }

    public function testThreadWithEmailAndAttachments() {
        // First create a thread
        $thread = new Thread();
        $thread->title = "Test Thread with Attachments";
        $thread->my_name = "Test User";
        $thread->my_email = "test@example.com";
        
        $createdThread = $this->threadDbOps->createThread('test_entity', 'Test', $thread, 'test-user');
        
        // Add an email to the thread
        $now = new DateTime();
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, description, content) 
            VALUES (?, ?, ?, 'incoming', 'received', 'Test status', 'Test description', ?) RETURNING id",
            [
                $createdThread->id,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                'Test email content'
            ]
        );
        
        // Add an attachment to the email
        Database::execute(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, status_type, status_text, location) 
            VALUES (?, 'test.pdf', 'test.pdf', 'application/pdf', 'processed', 'Test attachment status', '/test/path/test.pdf')",
            [$emailId]
        );
        
        // Retrieve and verify
        $threads = $this->threadDbOps->getThreadsForEntity('test_entity');
        $this->assertNotNull($threads, "Should retrieve threads for entity");
        
        $retrievedThread = $threads->threads[0];
        $this->assertNotEmpty($retrievedThread->emails, "Thread should have emails");
        
        $email = $retrievedThread->emails[0];
        $this->assertEquals('incoming', $email->email_type);
        $this->assertEquals('received', $email->status_type);
        
        $this->assertNotEmpty($email->attachments, "Email should have attachments");
        $attachment = $email->attachments[0];
        $this->assertEquals('test.pdf', $attachment->name);
        $this->assertEquals('application/pdf', $attachment->filetype);
        $this->assertEquals('processed', $attachment->status_type);
        $this->assertEquals('Test attachment status', $attachment->status_text);
    }

    public function testGetThreadsWithAuth0UserId() {
        // Create a test thread
        $thread = new Thread();
        $thread->title = "Test Thread";
        $thread->my_name = "Test User";
        $thread->my_email = "test@example.com";
        $thread->public = false;
        
        $createdThread = $this->threadDbOps->createThread('test_entity', 'Test', $thread, 'test-user');
        
        // Add authorization for Auth0 user
        $auth0UserId = 'auth0|1234abc1234abc1234abc123';
        Database::execute(
            "INSERT INTO thread_authorizations (thread_id, user_id) VALUES (?, ?)",
            [$createdThread->id, $auth0UserId]
        );
        
        // Get threads for Auth0 user
        $threads = $this->threadDbOps->getThreads($auth0UserId);
        
        // Verify
        $this->assertNotEmpty($threads, "Should retrieve threads for Auth0 user");
        $this->assertArrayHasKey("threads-test_entity.json", $threads, "Should have threads for test entity");
        $retrievedThreads = $threads["threads-test_entity.json"]->threads;
        $this->assertCount(1, $retrievedThreads, "Should have one thread");
        $this->assertEquals($createdThread->id, $retrievedThreads[0]->id, "Should retrieve the correct thread");
    }

    public function testUpdateThreadPublicStatus() {
        // Create initial thread (private by default)
        $thread = new Thread();
        $thread->title = "Test Thread";
        $thread->my_name = "Test User";
        $thread->my_email = "test@example.com";
        $thread->public = false;

        $createdThread = $this->threadDbOps->createThread('test_entity', 'Test', $thread, 'test-user');

        // Load current thread state
        $thread = Thread::loadFromDatabase($createdThread->id);

        // Make thread public (keeping other properties unchanged)
        $thread->public = true;
        $this->threadDbOps->updateThread($thread, 'test-user');

        // Make thread private again (keeping other properties unchanged)
        $thread->public = false;
        $this->threadDbOps->updateThread($thread, 'test-user');

        // Verify history entries
        $history = new ThreadHistory();
        $historyEntries = $history->getHistoryForThread($thread->id);

        $this->assertCount(3, $historyEntries, "Should have three history entries");

        // Entries are returned in reverse chronological order
        $this->assertEquals('created', $historyEntries[0]['action'], "First action should be thread creation");
        $this->assertEquals('made_public', $historyEntries[1]['action'], "Second action should be making thread public");
        $this->assertEquals('made_private', $historyEntries[2]['action'], "Third action should be making thread private");
    }

    public function testUpdateThreadSentStatus() {
        // Create initial thread (not sent by default)
        $thread = new Thread();
        $thread->title = "Test Thread";
        $thread->my_name = "Test User";
        $thread->my_email = "test@example.com";
        $thread->sent = false;

        $createdThread = $this->threadDbOps->createThread('test_entity', 'Test', $thread, 'test-user');

        // Load current thread state
        $thread = Thread::loadFromDatabase($createdThread->id);

        // Mark thread as sent
        $thread->sent = true;
        $this->threadDbOps->updateThread($thread, 'test-user');

        // Mark thread as not sent
        $thread->sent = false;
        $this->threadDbOps->updateThread($thread, 'test-user');

        // Verify history entries
        $history = new ThreadHistory();
        $historyEntries = $history->getHistoryForThread($thread->id);

        $this->assertCount(3, $historyEntries, "Should have three history entries");

        // Entries are returned in reverse chronological order
        $this->assertEquals('created', $historyEntries[0]['action'], "First action should be thread creation");
        $this->assertEquals('sent', $historyEntries[1]['action'], "Second action should be marking as sent");
        $this->assertEquals('unsent', $historyEntries[2]['action'], "Third action should be marking as not sent");
    }

    public function testGetThreadsReturnsAllEntities() {
        // Create threads for multiple entities
        $thread1 = new Thread();
        $thread1->title = "Test Thread 1";
        $thread1->my_name = "Test User 1";
        $thread1->my_email = "test1@example.com";
        
        $thread2 = new Thread();
        $thread2->title = "Test Thread 2";
        $thread2->my_name = "Test User 2";
        $thread2->my_email = "test2@example.com";
        
        $this->threadDbOps->createThread('test_entity_1', 'Test1', $thread1, 'test-user');
        $this->threadDbOps->createThread('test_entity_2', 'Test2', $thread2, 'test-user');
        
        // Get all threads
        $allThreads = $this->threadDbOps->getThreads();
        
        // Clean up additional test entities in correct order
        Database::execute("DELETE FROM thread_history WHERE thread_id IN (SELECT id FROM threads WHERE entity_id IN ('test_entity_1', 'test_entity_2'))");
        Database::execute("DELETE FROM threads WHERE entity_id IN ('test_entity_1', 'test_entity_2')");
        
        // Verify
        $this->assertNotEmpty($allThreads, "Should retrieve threads");
        $this->assertIsArray($allThreads, "Should return an array of threads");
    }
}
