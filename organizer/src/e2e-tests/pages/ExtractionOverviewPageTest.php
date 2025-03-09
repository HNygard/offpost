<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/../../class/ThreadEmailExtraction.php';
require_once __DIR__ . '/../../class/ThreadEmailExtractionService.php';
require_once __DIR__ . '/../../class/Database.php';

class ExtractionOverviewPageTest extends E2EPageTestCase {

    private $testExtractionId = null;
    private $threadId = null;
    private $emailId = null;
    private $entityId = null;

    protected function setUp(): void {
        parent::setUp();
        // Create a test extraction record
        $this->createTestExtraction();
    }
    
    private function createTestExtraction() {
        // Create a new thread
        $thread = new Thread();
        $thread->title = 'Test Thread for Extraction';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test-' . uniqid() . '@example.com';
        $thread->sending_status = Thread::SENDING_STATUS_SENT;
        
        // Use a valid entity ID from entities_test.json
        $this->entityId = '000000000-test-entity-1';
        $thread = createThread($this->entityId, $thread);
        $this->threadId = $thread->id;
        
        // Create a test email in the database - use the UUID generation method from Thread class
        $thread = new Thread();
        $this->emailId = $thread->id; // Use the UUID generated in the Thread constructor
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, status_type, status_text, datetime_received, timestamp_received, content) 
            VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $this->emailId,
                $this->threadId,
                'unknown',
                'Unclassified',
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'), // timestamp_received
                'Test email content' // content
            ]
        );
        
        // Create a test extraction
        $extractionService = new ThreadEmailExtractionService();
        $extraction = $extractionService->createExtraction(
            $this->emailId,
            'Test prompt text',
            'test-service',
            null,
            'test-prompt-id'
        );
        
        // Update with extracted text
        $extractionService->updateExtractionResults(
            $extraction->extraction_id,
            'Test extracted text',
            null
        );
        
        $this->testExtractionId = $extraction->extraction_id;
    }

    
    public function testPageLoggedIn() {
        // :: Setup
        $response = $this->renderPage('/extraction-overview');

        // :: Assert
        // Assert basic page content - the heading
        $this->assertStringContainsString('<h1>Email Extraction Overview</h1>', $response->body);
        
        // Assert that the summary box is present
        $this->assertStringContainsString('<div class="summary-box">', $response->body);
        $this->assertStringContainsString('<div class="summary-count">', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Total Extractions (Last 30 Days)</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Total Unclassified</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Unclassified Emails</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Unclassified Attachments</div>', $response->body);
        
        // Assert that the table headers are present
        $this->assertStringContainsString('<th class="id-col">ID</th>', $response->body);
        $this->assertStringContainsString('<th class="thread-col">Thread / Email</th>', $response->body);
        $this->assertStringContainsString('<th class="type-col">Type</th>', $response->body);
        $this->assertStringContainsString('<th class="prompt-col">Prompt</th>', $response->body);
        $this->assertStringContainsString('<th class="status-col">Status</th>', $response->body);
        $this->assertStringContainsString('<th class="date-col">Created</th>', $response->body);
        $this->assertStringContainsString('<th class="actions-col">Actions</th>', $response->body);
        
        // Assert that the table has our test content
        $this->assertStringContainsString('<td class="id-col">' . $this->testExtractionId . '</td>', $response->body);
        $this->assertStringContainsString('Test Thread', $response->body);
        $this->assertStringContainsString('Test prompt text', $response->body);
        $this->assertStringContainsString('test-service', $response->body);
        $this->assertStringContainsString('Show Extraction', $response->body);
    }

    public function testPageNotLoggedIn() {
        // :: Setup
        // Test that the page redirects to login when not logged in
        $response = $this->renderPage('/extraction-overview', null, 'GET', '302 Found');
        
        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }
}
