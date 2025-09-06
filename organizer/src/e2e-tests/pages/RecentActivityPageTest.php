<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');
require_once(__DIR__ . '/../../class/Entity.php');
require_once(__DIR__ . '/../../class/ThreadEmail.php');

class RecentActivityPageTest extends E2EPageTestCase {
    
    private $createdThreadIds = [];
    private $createdEmailIds = [];
    
    protected function tearDown(): void {
        // Clean up test data after each test to prevent interference
        $this->cleanupTestData();
        parent::tearDown();
    }
    
    private function cleanupTestData() {
        // Delete emails and threads created by this test
        foreach ($this->createdEmailIds as $emailId) {
            Database::execute("DELETE FROM thread_emails WHERE id = ?", [$emailId]);
        }
        foreach ($this->createdThreadIds as $threadId) {
            E2ETestSetup::cleanupTestThread($threadId);
        }
        $this->createdThreadIds = [];
        $this->createdEmailIds = [];
    }
    
    private function createTestEmailForThread($threadId, $subjectSuffix = '', $timeOffset = 0) {
        $uniqueId = uniqid();
        $imapHeaders = json_encode([
            'from' => [
                ['name' => 'Test Sender ' . $uniqueId, 'email' => 'test' . $uniqueId . '@example.com']
            ],
            'subject' => 'Test Email Subject' . $subjectSuffix . ' ' . $uniqueId
        ]);
        
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, email_type, description, datetime_received, timestamp_received, status_type, status_text, content, imap_headers) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $threadId,
                'IN',
                'Test email description for recent activity',
                date('Y-m-d H:i:s', strtotime($timeOffset . ' seconds')),
                date('Y-m-d H:i:s', strtotime($timeOffset . ' seconds')),
                'unknown',
                'Pending Classification',
                'Test email content',
                $imapHeaders
            ]
        );
        
        $this->createdEmailIds[] = $emailId;
        return $emailId;
    }

    public function testPageHappy() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $this->createdThreadIds[] = $threadId;
        
        // Create a test incoming email for this thread
        $this->createTestEmailForThread($threadId);

        // :: Act
        $response = $this->renderPage('/recent-activity');

        // :: Assert basic page content
        $this->assertStringContainsString('<h1>Recent Activity</h1>', $response->body);
        $this->assertStringContainsString('Recent Incoming Emails', $response->body);

        // :: Assert that page rendered the recent activity table structure
        $this->assertStringContainsString('<table class="recent-activity-table">', $response->body);
        $this->assertStringContainsString('<th>Thread Info</th>', $response->body);
        $this->assertStringContainsString('<th>Email Info</th>', $response->body);
        $this->assertStringContainsString('<th>Received</th>', $response->body);
        $this->assertStringContainsString('<th>Actions</th>', $response->body);

        // :: Assert that the test email appears in the listing
        $this->assertStringContainsString('Test Email Subject', $response->body);
        $this->assertStringContainsString('Test Sender', $response->body);
        $this->assertStringContainsString('@example.com', $response->body);

        // :: Assert navigation links
        $this->assertStringContainsString('Back to main page', $response->body);
        $this->assertStringContainsString('href="/"', $response->body);

        // :: Assert action links are present
        $this->assertStringContainsString('View Thread', $response->body);
        $this->assertStringContainsString('Classify', $response->body);
    }

    public function testPageWithNoActivity() {
        // :: Setup  
        // Temporarily remove all incoming emails to simulate no activity state
        $backupEmails = Database::query("SELECT * FROM thread_emails WHERE email_type = 'IN'");
        Database::execute("DELETE FROM thread_emails WHERE email_type = 'IN'");
        
        try {
            // :: Act
            $response = $this->renderPage('/recent-activity');

            // :: Assert basic page content
            $this->assertStringContainsString('<h1>Recent Activity</h1>', $response->body);
            $this->assertStringContainsString('Recent Incoming Emails (0)', $response->body);

            // :: Assert message for no activity
            $this->assertStringContainsString('No recent email activity found.', $response->body);

            // :: Assert navigation links are still present
            $this->assertStringContainsString('Back to main page', $response->body);
        } finally {
            // :: Cleanup - Restore all the backed up incoming emails
            foreach ($backupEmails as $email) {
                $columns = array_keys($email);
                $placeholders = str_repeat('?,', count($columns) - 1) . '?';
                $columnList = implode(',', $columns);
                Database::execute(
                    "INSERT INTO thread_emails ($columnList) VALUES ($placeholders)",
                    array_values($email)
                );
            }
        }
    }

    public function testPageWithMultipleEmails() {
        // :: Setup
        // Create multiple test threads with emails
        $testData1 = E2ETestSetup::createTestThread();
        $testData2 = E2ETestSetup::createTestThread();
        $this->createdThreadIds[] = $testData1['thread']->id;
        $this->createdThreadIds[] = $testData2['thread']->id;
        
        $threadId1 = $testData1['thread']->id;
        $threadId2 = $testData2['thread']->id;

        // Create multiple test incoming emails with different times
        $this->createTestEmailForThread($threadId1, ' First', '-3600'); // 1 hour ago
        $this->createTestEmailForThread($threadId2, ' Second', '0'); // now

        // :: Act
        $response = $this->renderPage('/recent-activity');

        // :: Assert both specific emails we created are shown
        $this->assertStringContainsString('Test Email Subject First', $response->body);
        $this->assertStringContainsString('Test Email Subject Second', $response->body);

        // :: Assert the emails are ordered by time (most recent first)
        $firstPos = strpos($response->body, 'Test Email Subject Second');
        $secondPos = strpos($response->body, 'Test Email Subject First');
        $this->assertTrue($firstPos < $secondPos, 'Most recent email should appear first');
    }

    public function testPageRequiresAuthentication() {
        // :: Act
        // Try to access the page without authentication
        $response = $this->renderPage('/recent-activity', user: null, expected_status: '302 Found');

        // :: Assert
        // Should be redirected (authentication required)
        $this->assertStringContainsString('Location: http://localhost:25083/oidc/auth?client_id=organizer&response_type=code&scope=openid+email+profile&redirect_uri=http%3A%2F%2Flocalhost%3A25081%2Fcallback&state=', $response->headers);
    }
}