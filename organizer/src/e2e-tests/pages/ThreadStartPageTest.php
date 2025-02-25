<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class ThreadStartPageTest extends E2EPageTestCase {

    public function testPageLoggedIn() {
        // :: Act
        $response = $this->renderPage('/thread-start');

        // :: Assert basic page content
        $this->assertStringContainsString('<h1>Start', $response->body);
        $this->assertStringContainsString('Email Thread</h1>', $response->body);

        // :: Assert form elements exist
        $this->assertStringContainsString('<form', $response->body);
        $this->assertStringContainsString('name="title"', $response->body);
        $this->assertStringContainsString('name="my_name"', $response->body);
        $this->assertStringContainsString('name="my_email"', $response->body);
        $this->assertStringContainsString('name="entity_id"', $response->body);
        $this->assertStringContainsString('name="entity_email"', $response->body);
        $this->assertStringContainsString('name="body"', $response->body);
    }

    public function testPagePost() {
        // :: Setup - Get a valid entity ID from the database
        $entity = Database::queryOne("SELECT entity_id FROM threads LIMIT 1");
        if (!$entity) {
            $this->markTestSkipped('No entities found in database');
        }
        
        // :: Setup - Create post data
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'my_name' => 'Test User',
            'my_email' => 'test.user.' . uniqid() . '@example.com',
            'labels' => 'test e2e',
            'entity_id' => $entity['entity_id'],
            'entity_title_prefix' => 'Test Entity',
            'entity_email' => 'entity.' . uniqid() . '@example.com',
            'body' => 'This is a test message body created by E2E test.',
            'public' => '1'
        ];
        
        // :: Act - Test POST request to start thread
        $response = $this->renderPage('/thread-start', 'dev-user-id', 'POST', '200 OK', $post_data);
        
        // :: Assert - Should show success message
        $this->assertStringContainsString('Message has been sent', $response->body);
    }
    
    public function testPageNotLoggedIn() {
        // :: Act - Should redirect to login when not logged in
        $response = $this->renderPage('/thread-start', user: null, expected_status: '302 Found');
        
        // :: Assert - Redirect to OIDC authentication
        $this->assertStringContainsString('Location: http://localhost:25083/oidc/auth', $response->headers);
    }
    
    public function testPagePostMissingRequiredFields() {
        // :: Setup - Create incomplete post data (missing entity_id and entity_email)
        $post_data = [
            'title' => 'Test Thread',
            'my_name' => 'Test User',
            'my_email' => 'test.user@example.com',
            'body' => 'This is a test message body.'
        ];
        
        // :: Act - Test POST request with missing fields
        // Note: The actual behavior depends on how the application handles missing fields
        // This test assumes it will return a 400 Bad Request or redisplay the form
        $response = $this->renderPage('/thread-start', 'dev-user-id', 'POST', null, $post_data);
        
        // :: Assert - Should not redirect to thread view (should stay on form page)
        $this->assertStringNotContainsString('Location: /thread-view', $response->headers);
    }
    
    public function testPageWithExistingThread() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // :: Act - Test GET request with thread_id parameter
        // Note: We're just testing if the page loads with these parameters
        $response = $this->renderPage('/thread-start?thread_id=' . $threadId . '&entity_id=' . $entityId . '&body=Hello');
        
        // :: Assert - Page should load successfully
        $this->assertStringContainsString('<h1>Start', $response->body);
        $this->assertStringContainsString('Email Thread</h1>', $response->body);
        
        // Check that the thread_id field is populated
        $this->assertStringContainsString('value="' . $threadId . '"', $response->body);
    }
}
