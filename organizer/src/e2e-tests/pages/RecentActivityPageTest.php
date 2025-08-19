<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');
require_once(__DIR__ . '/../../class/Entity.php');
require_once(__DIR__ . '/../../class/ThreadEmail.php');

class RecentActivityPageTest extends E2EPageTestCase {
    public function testPageHappy() {
        // :: Setup
        // Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];

        // Create a test incoming email for this thread to show in recent activity
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, from_name, from_email, subject, description, datetime_received, status_type, status_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $threadId,
                'IN',
                'Test Sender',
                'test@example.com',
                'Test Email Subject',
                'Test email description for recent activity',
                date('Y-m-d H:i:s'),
                'email_status_pending',
                'Pending Classification'
            ]
        );

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
        $this->assertStringContainsString('test@example.com', $response->body);

        // :: Assert navigation links
        $this->assertStringContainsString('Back to main page', $response->body);
        $this->assertStringContainsString('href="/"', $response->body);

        // :: Assert action links are present
        $this->assertStringContainsString('View Thread', $response->body);
        $this->assertStringContainsString('Classify', $response->body);
    }

    public function testPageWithNoActivity() {
        // :: Setup
        // No recent emails setup - just test with empty results

        // :: Act
        $response = $this->renderPage('/recent-activity');

        // :: Assert basic page content
        $this->assertStringContainsString('<h1>Recent Activity</h1>', $response->body);
        $this->assertStringContainsString('Recent Incoming Emails (0)', $response->body);

        // :: Assert message for no activity
        $this->assertStringContainsString('No recent email activity found.', $response->body);

        // :: Assert navigation links are still present
        $this->assertStringContainsString('Back to main page', $response->body);
    }

    public function testPageWithMultipleEmails() {
        // :: Setup
        // Create multiple test threads with emails
        $testData1 = E2ETestSetup::createTestThread();
        $testData2 = E2ETestSetup::createTestThread();
        
        $threadId1 = $testData1['thread']->id;
        $threadId2 = $testData2['thread']->id;

        // Create multiple test incoming emails
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, from_name, from_email, subject, description, datetime_received, status_type, status_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $threadId1,
                'IN',
                'First Sender',
                'first@example.com',
                'First Email Subject',
                'First email description',
                date('Y-m-d H:i:s', strtotime('-1 hour')),
                'email_status_pending',
                'Pending Classification'
            ]
        );

        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, from_name, from_email, subject, description, datetime_received, status_type, status_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $threadId2,
                'IN',
                'Second Sender',
                'second@example.com',
                'Second Email Subject',
                'Second email description',
                date('Y-m-d H:i:s'),
                'email_status_classified',
                'Classified'
            ]
        );

        // :: Act
        $response = $this->renderPage('/recent-activity');

        // :: Assert multiple emails are shown
        $this->assertStringContainsString('Recent Incoming Emails (2)', $response->body);
        $this->assertStringContainsString('First Email Subject', $response->body);
        $this->assertStringContainsString('Second Email Subject', $response->body);
        $this->assertStringContainsString('First Sender', $response->body);
        $this->assertStringContainsString('Second Sender', $response->body);

        // :: Assert the emails are ordered by time (most recent first)
        $firstPos = strpos($response->body, 'Second Email Subject');
        $secondPos = strpos($response->body, 'First Email Subject');
        $this->assertTrue($firstPos < $secondPos, 'Most recent email should appear first');
    }

    public function testPageRequiresAuthentication() {
        // :: Act
        // Try to access the page without authentication
        $response = $this->renderPage('/recent-activity', null, 'GET', '302 Found');

        // :: Assert
        // Should be redirected (authentication required)
        $this->assertStringContainsString('Location: /auth.php', $response->headers);
    }
}