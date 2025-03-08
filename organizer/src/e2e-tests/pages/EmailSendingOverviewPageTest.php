<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/../../class/ThreadEmailSending.php';
require_once __DIR__ . '/../../class/Database.php';

class EmailSendingOverviewPageTest extends E2EPageTestCase {

    private $testEmailId = null;

    protected function setUp(): void {
        parent::setUp();
        // Create a test email sending record
        $this->createTestEmailSending();
    }

    protected function tearDown(): void {
        // Clean up the test email sending record
        if ($this->testEmailId) {
            $this->deleteTestEmailSending();
        }
        parent::tearDown();
    }

    private function createTestEmailSending() {
        // Create a test thread email sending record
        $threadId = '00000000-0000-0000-0000-000000000001'; // Use a fixed UUID for testing
        
        // Check if the thread exists, if not create it
        $threadExists = Database::queryValue(
            "SELECT COUNT(*) FROM threads WHERE id = ?",
            [$threadId]
        );
        
        if (!$threadExists) {
            // Create a test thread
            Database::execute(
                "INSERT INTO threads (id, entity_id, title, my_name, my_email, sending_status) 
                VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $threadId,
                    'test-entity',
                    'Test Thread',
                    'Test User',
                    'test@example.com',
                    'SENT'
                ]
            );
        }
        
        // Create a test email sending
        $emailSending = ThreadEmailSending::create(
            $threadId,
            'Test email content',
            'Test email subject',
            'recipient@example.com',
            'sender@example.com',
            'Sender Name',
            ThreadEmailSending::STATUS_SENT
        );
        
        // Update with SMTP response
        ThreadEmailSending::updateStatus(
            $emailSending->id,
            ThreadEmailSending::STATUS_SENT,
            'SMTP Response: 250 OK',
            'SMTP Debug: Connection established',
            null
        );
        
        $this->testEmailId = $emailSending->id;
    }
    
    private function deleteTestEmailSending() {
        // Delete the test email sending
        Database::execute(
            "DELETE FROM thread_email_sendings WHERE id = ?",
            [$this->testEmailId]
        );
    }

    public function testPageLoggedIn() {
        // :: Setup
        $response = $this->renderPage('/email-sending-overview');

        // :: Assert
        // Assert basic page content - the heading
        $this->assertStringContainsString('<h1>Email Sending Overview</h1>', $response->body);
        
        // Assert that the summary box is present
        $this->assertStringContainsString('<div class="summary-box">', $response->body);
        $this->assertStringContainsString('<div class="summary-count">', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Total</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Sent (Last 5 Days)</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Ready for Sending</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Currently Sending</div>', $response->body);
        
        // Assert that the table headers are present
        $this->assertStringContainsString('<th class="id-col">ID</th>', $response->body);
        $this->assertStringContainsString('<th class="thread-col">Thread</th>', $response->body);
        $this->assertStringContainsString('<th class="subject-col">Subject</th>', $response->body);
        $this->assertStringContainsString('<th class="to-col">To</th>', $response->body);
        $this->assertStringContainsString('<th class="from-col">From</th>', $response->body);
        $this->assertStringContainsString('<th class="status-col">Status</th>', $response->body);
        $this->assertStringContainsString('<th class="date-col">Created</th>', $response->body);
        $this->assertStringContainsString('<th class="date-col">Updated</th>', $response->body);
        $this->assertStringContainsString('<th class="actions-col">Actions</th>', $response->body);
        
        // Assert that the table has our test content
        $this->assertStringContainsString('<td class="id-col">' . $this->testEmailId . '</td>', $response->body);
        $this->assertStringContainsString('Test email subject', $response->body);
        $this->assertStringContainsString('recipient@example', $response->body); // Account for truncation
        $this->assertStringContainsString('sender@example', $response->body); // Account for truncation
        $this->assertStringContainsString('SENT', $response->body);
        $this->assertStringContainsString('View Response', $response->body);
    }

    public function testPageNotLoggedIn() {
        // :: Setup
        // Test that the page redirects to login when not logged in
        $response = $this->renderPage('/email-sending-overview', null, 'GET', '302 Found');
        
        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }
}
