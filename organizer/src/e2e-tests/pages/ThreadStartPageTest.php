<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once __DIR__ . '/../../class/ThreadEmailSending.php';

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
        $this->assertStringContainsString('name="body"', $response->body);
    }

    public function testPagePost() {
        // :: Setup - Get a valid entity ID from the database
        $entity_id = '000000000-test-entity-development';
        
        // :: Setup - Create post data
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'my_name' => 'Test User',
            'my_email' => 'test.user.' . uniqid() . '@example.com',
            'labels' => 'test e2e',
            'entity_id' => $entity_id,
            'entity_email' => 'entity.' . uniqid() . '@example.com',
            'body' => 'This is a test message body created by E2E test.',
            'public' => '1'
        ];
        
        // :: Act - Test POST request to start thread
        $response = $this->renderPage('/thread-start', 'dev-user-id', 'POST', '302 Found', $post_data);
        
        // :: Assert - Should redirect to thread view
        $this->assertStringContainsString('Location: /thread-view', $response->headers);
        
        // Extract thread ID from the redirect URL
        preg_match('/threadId=([a-z0-9\-]+)/', $response->headers, $matches);
        $this->assertNotEmpty($matches[1], 'Thread ID should be in the redirect URL');
        $threadId = $matches[1];
        
        // Verify ThreadEmailSending record was created
        $emailSendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertNotEmpty($emailSendings, 'ThreadEmailSending record should be created');
        $this->assertEquals($post_data['body'], $emailSendings[0]->email_content, 'Email content should match');
        $this->assertEquals($post_data['title'], $emailSendings[0]->email_subject, 'Email subject should match');
        $this->assertEquals($post_data['my_email'], $emailSendings[0]->email_from, 'Email from should match');
        $this->assertEquals($post_data['my_name'], $emailSendings[0]->email_from_name, 'Email from name should match');
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $emailSendings[0]->status, 'Status should be STAGING by default');
    }
    
    public function testPageNotLoggedIn() {
        // :: Act - Should redirect to login when not logged in
        $response = $this->renderPage('/thread-start', user: null, expected_status: '302 Found');
        
        // :: Assert - Redirect to OIDC authentication
        $this->assertStringContainsString('Location: http://localhost:25083/oidc/auth', $response->headers);
    }
    
    public function testPagePostWithSendNow() {
        // :: Setup - Get a valid entity ID from the database
        $entity_id = '000000000-test-entity-development';
        
        // :: Setup - Create post data with send_now option
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'my_name' => 'Test User',
            'my_email' => 'test.user.' . uniqid() . '@example.com',
            'labels' => 'test e2e',
            'entity_id' => $entity_id,
            'entity_email' => 'entity.' . uniqid() . '@example.com',
            'body' => 'This is a test message body created by E2E test.',
            'public' => '1',
            'send_now' => '1'
        ];
        
        // :: Act - Test POST request to start thread with send_now
        $response = $this->renderPage('/thread-start', 'dev-user-id', 'POST', '302 Found', $post_data);
        
        // :: Assert - Should redirect to thread view
        $this->assertStringContainsString('Location: /thread-view', $response->headers);
        
        // Extract thread ID from the redirect URL
        preg_match('/threadId=([a-z0-9\-]+)/', $response->headers, $matches);
        $this->assertNotEmpty($matches[1], 'Thread ID should be in the redirect URL');
        $threadId = $matches[1];
        
        // Verify ThreadEmailSending record was created with READY_FOR_SENDING status
        $emailSendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertNotEmpty($emailSendings, 'ThreadEmailSending record should be created');
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $emailSendings[0]->status, 
            'Status should be READY_FOR_SENDING when send_now is selected');
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
