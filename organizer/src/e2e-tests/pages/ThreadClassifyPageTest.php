<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class ThreadClassifyPageTest extends E2EPageTestCase {
    public function testPageLoggedIn() {
        // :: Setup - Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadEmail = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];
        
        // :: Act - Need thread ID and email ID to classify
        $response = $this->renderPage('/thread-classify?entityId=' . $threadEmail['entity_id'] . '&threadId=' . $threadEmail['thread_id'] . '&emailId=' . $threadEmail['email_id']);

        // :: Assert basic page content
        $this->assertStringContainsString('<h1>Classify Email</h1>', $response->body);

        // :: Assert form elements exist
        $this->assertStringContainsString('<form', $response->body);
        $this->assertStringContainsString('name="submit"', $response->body);
    }

    public function testPagePost() {
        // :: Setup - Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadEmail = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];
        
        // Get all emails for this thread to build the form data
        $emails = [['email_id' => $testData['email_id']]];
        
        // Build the POST data with actual email IDs
        $post_data = ['submit' => 'Save'];
        
        foreach ($emails as $index => $email) {
            $emailId = $email['email_id'];
            $emailIdForForm = str_replace(' ', '_', str_replace('.', '_', $emailId));
            
            // Add form fields for each email
            $post_data[$emailIdForForm . '-status_type'] = 'info';
            $post_data[$emailIdForForm . '-status_text'] = 'Test classification';
            $post_data[$emailIdForForm . '-answer'] = 'Test answer';
            
            // Get attachments for this email
            $attachments = Database::query("SELECT tea.id as att_id, tea.location
            FROM thread_email_attachments tea
            WHERE tea.email_id = ?", [$emailId]);
            
            // Add form fields for each attachment
            foreach ($attachments as $attachment) {
                $attId = str_replace(' ', '_', str_replace('.', '_', $attachment['location']));
                $post_data[$emailIdForForm . '-att-' . $attId . '-status_type'] = 'success';
                $post_data[$emailIdForForm . '-att-' . $attId . '-status_text'] = 'Document OK';
            }
        }
        
        // :: Act - Test POST request to classify email
        $response = $this->renderPage('/thread-classify?entityId=' . $threadEmail['entity_id'] . '&threadId=' . $threadEmail['thread_id'] . '&emailId=' . $threadEmail['email_id'], 
            method:'POST',
            expected_status: '302 Found',
            post_data: $post_data
        );
        
        // :: Assert - Should redirect back to thread view
        $this->assertStringContainsString('Location: /thread-view', $response->headers);
    }

    public function testPageWithoutParams() {
        // :: Act - Should fail when no parameters provided
        $response = $this->renderPage('/thread-classify', 'dev-user-id', 'GET', '400 Bad Request');
        
        // :: Assert - Error message
        $this->assertStringContainsString('Missing required parameters', $response->body);
    }

    public function testPageWithoutEmailId() {
        // :: Setup - Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $thread = [
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];
        
        // :: Act - Should fail when no emailId provided
        $response = $this->renderPage('/thread-classify?entityId=' . $thread['entity_id'] . '&threadId=' . $thread['thread_id'], 'dev-user-id', 'GET', '400 Bad Request');
        
        // :: Assert - Error message
        $this->assertStringContainsString('Missing required parameters', $response->body);
    }

    public function testPageInvalidThreadId() {
        // :: Setup - Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadEmail = [
            'email_id' => $testData['email_id'],
            'entity_id' => $testData['entity_id']
        ];
        
        // :: Act - Should fail with invalid threadId
        $response = $this->renderPage('/thread-classify?entityId=' . $threadEmail['entity_id'] . '&threadId=not-a-uuid&emailId=' . $threadEmail['email_id'], 'dev-user-id', 'GET', '400 Bad Request');
        
        // :: Assert - Error message
        $this->assertStringContainsString('Invalid threadId parameter', $response->body);
    }

    public function testPageThreadNotFound() {
        // :: Setup - Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $emailId = $testData['email_id'];
        
        // :: Act - Should fail with non-existent threadId
        $response = $this->renderPage('/thread-classify?entityId=nonexistent&threadId=00000000-0000-0000-0000-000000000000&emailId=' . $emailId, 'dev-user-id', 'GET', '404 Not Found');
        
        // :: Assert - Error message
        $this->assertStringContainsString('Thread not found', $response->body);
    }

    public function testPageNotLoggedIn() {
        // :: Setup - Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadEmail = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];
        
        // :: Act - Should redirect to login when not logged in
        $response = $this->renderPage('/thread-classify?entityId=' . $threadEmail['entity_id'] . '&threadId=' . $threadEmail['thread_id'] . '&emailId=' . $threadEmail['email_id'], user: null, expected_status: '302 Found');
        
        // :: Assert - Redirect to OIDC authentication
        $this->assertStringContainsString('Location: http://localhost:25083/oidc/auth', $response->headers);
    }
}
