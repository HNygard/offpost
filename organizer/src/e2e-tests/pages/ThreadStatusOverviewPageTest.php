<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/../../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../../class/Thread.php';
require_once __DIR__ . '/../../class/Database.php';

class ThreadStatusOverviewPageTest extends E2EPageTestCase {

    private $testThreadId = null;
    private $entityId = null;

    protected function setUp(): void {
        parent::setUp();
        // Create a test thread
        $this->createTestThread();
    }
    
    private function createTestThread() {
        // Create a new thread
        $thread = new Thread();
        $thread->title = 'Test Thread for Status Overview';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test-' . uniqid() . '@example.com';
        $thread->sending_status = Thread::SENDING_STATUS_SENT;
        
        // Use a valid entity ID from entities_test.json
        $this->entityId = '000000000-test-entity-1';
        $thread = createThread($this->entityId, $thread);
        $this->testThreadId = $thread->id;
        
        // Create a test email in the database to ensure the thread has activity
        $emailId = (new Thread())->id; // Use the UUID generated in the Thread constructor
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, status_type, status_text, datetime_received, timestamp_received, content, email_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $emailId,
                $this->testThreadId,
                'unknown',
                'Unclassified',
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'), // timestamp_received
                'Test email content', // content
                'OUT' // email_type
            ]
        );
        
        // Create an IMAP folder status entry for the thread to ensure it has a status
        Database::execute(
            "INSERT INTO imap_folder_status (thread_id, folder_name, last_checked_at) 
            VALUES (?, ?, ?)",
            [
                $this->testThreadId,
                'INBOX.' . $this->testThreadId,
                date('Y-m-d H:i:s')
            ]
        );
    }

    public function testPageLoggedIn() {
        // :: Setup
        $response = $this->renderPage('/thread-status-overview');

        // :: Assert
        // Assert basic page content - the heading
        $this->assertStringContainsString('<h1>Thread Status Overview</h1>', $response->body);
        
        // Assert that the summary box is present
        $this->assertStringContainsString('<div class="summary-box">', $response->body);
        $this->assertStringContainsString('<div class="summary-count">', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Total Threads</div>', $response->body);
        
        // Assert that the table headers are present
        $this->assertStringContainsString('<th class="id-col">ID</th>', $response->body);
        $this->assertStringContainsString('<th class="thread-col">Thread</th>', $response->body);
        $this->assertStringContainsString('<th class="status-col">Status</th>', $response->body);
        $this->assertStringContainsString('<th class="emails-col">Emails (In/Out)</th>', $response->body);
        $this->assertStringContainsString('<th class="activity-col">Last Activity</th>', $response->body);
        $this->assertStringContainsString('<th class="sync-col">Last Sync</th>', $response->body);
        $this->assertStringContainsString('<th class="actions-col">Actions</th>', $response->body);
        
        // Assert that the table has our test thread
        $this->assertStringContainsString(substr($this->testThreadId, 0, 8), $response->body);
        $this->assertStringContainsString('Test Thread for Status Overview', $response->body);
        $this->assertStringContainsString('View Thread', $response->body);
        $this->assertStringContainsString('Show Details', $response->body);
    }

    public function testPageNotLoggedIn() {
        // :: Setup
        // Test that the page redirects to login when not logged in
        $response = $this->renderPage('/thread-status-overview', null, 'GET', '302 Found');
        
        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }
}
