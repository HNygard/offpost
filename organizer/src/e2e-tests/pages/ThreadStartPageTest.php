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
        $this->assertStringContainsString('name="entity_ids[]"', $response->body);
        $this->assertStringContainsString('name="body"', $response->body);
        
        // :: Assert new form fields exist
        $this->assertStringContainsString('name="request_law_basis"', $response->body);
        $this->assertStringContainsString('value="offentleglova"', $response->body);
        $this->assertStringContainsString('value="other"', $response->body);
        $this->assertStringContainsString('name="request_follow_up_plan"', $response->body);
        $this->assertStringContainsString('value="speedy"', $response->body);
        $this->assertStringContainsString('value="slow"', $response->body);
    }
    
    public function testPageNotLoggedIn() {
        // :: Act - Should redirect to login when not logged in
        $response = $this->renderPage('/thread-start', user: null, expected_status: '302 Found');
        
        // :: Assert - Redirect to OIDC authentication
        $this->assertStringContainsString('Location: http://localhost:25083/oidc/auth', $response->headers);
    }

    public function testPagePost() {
        // :: Setup - Get a valid entity ID from the database
        $entity_id = '000000000-test-entity-development';
        
        // :: Setup - Create post data
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'labels' => 'test e2e',
            'entity_ids[]' => $entity_id,
            'body' => 'This is a test message body created by E2E test ÆØÅ.',
            'public' => '1',
            'request_law_basis' => Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA,
            'request_follow_up_plan' => Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY
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
        $this->assertEquals($post_data['body'], explode("\n\n", $emailSendings[0]->email_content)[0], 'Email content should match');
        $this->assertEquals($post_data['title'], $emailSendings[0]->email_subject, 'Email subject should match');
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $emailSendings[0]->status, 'Status should be STAGING by default');
        
        // Verify the thread has the correct request fields
        $thread = Thread::loadFromDatabase($threadId);
        $this->assertEquals($post_data['request_law_basis'], $thread->request_law_basis, 'Request law basis should match');
        $this->assertEquals($post_data['request_follow_up_plan'], $thread->request_follow_up_plan, 'Request follow-up plan should match');
    }
    
    public function testPagePostWithSendNow() {
        // :: Setup - Get a valid entity ID from the database
        $entity_id = '000000000-test-entity-development';
        
        // :: Setup - Create post data with send_now option
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'labels' => 'test e2e',
            'entity_ids[]' => $entity_id,
            'body' => 'This is a test message body created by E2E test.',
            'public' => '1',
            'send_now' => '1',
            'request_law_basis' => Thread::REQUEST_LAW_BASIS_OTHER,
            'request_follow_up_plan' => Thread::REQUEST_FOLLOW_UP_PLAN_SLOW
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
            
        // Verify the thread has the correct request fields
        $thread = Thread::loadFromDatabase($threadId);
        $this->assertEquals($post_data['request_law_basis'], $thread->request_law_basis, 'Request law basis should match');
        $this->assertEquals($post_data['request_follow_up_plan'], $thread->request_follow_up_plan, 'Request follow-up plan should match');
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
    

    public function testPagePost_multipleEntities() {
        // :: Setup - Get a valid entity ID from the database
        $entity_id1 = '000000000-test-entity-development';
        $entity_id2 = '958935420-oslo-kommune';
        
        // :: Setup - Create post data
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'labels' => 'test e2e',
            'entity_ids' => [$entity_id1 , $entity_id2],
            'body' => 'This is a test message body created by E2E test.',
            'public' => '1',
            'request_law_basis' => Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA,
            'request_follow_up_plan' => Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY
        ];
        
        // :: Act - Test POST request to start thread
        $response = $this->renderPage('/thread-start', 'dev-user-id', 'POST', '302 Found', $post_data);
        
        // :: Assert - Should redirect to thread overview with label
        $this->assertStringContainsString('Location: /?label_filter=group-', $response->headers);
        

        // Extract thread ID from the redirect URL
        preg_match('/label_filter=([a-z0-9\-]+)/', $response->headers, $matches);
        $this->assertNotEmpty($matches[1], 'Thread ID should be in the redirect URL');
        $groupLabel = $matches[1];

        $threads = Database::query("SELECT * FROM threads WHERE ? = any(labels)", [$groupLabel]);
        $this->assertEquals(2, count($threads), 'Two threads should be created');
        
        foreach($threads as $thread) {
            // Verify ThreadEmailSending record was created
            $emailSendings = ThreadEmailSending::getByThreadId($thread['id']);
            $this->assertNotEmpty($emailSendings, 'ThreadEmailSending record should be created');
            $this->assertEquals($post_data['body'], explode("\n\n", $emailSendings[0]->email_content)[0], 'Email content should match');
            $this->assertEquals($post_data['title'], $emailSendings[0]->email_subject, 'Email subject should match');
            $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $emailSendings[0]->status, 'Status should be STAGING by default');
            
            // Verify the thread has the correct request fields
            $threadObj = Thread::loadFromDatabase($thread['id']);
            $this->assertEquals($post_data['request_law_basis'], $threadObj->request_law_basis, 'Request law basis should match');
            $this->assertEquals($post_data['request_follow_up_plan'], $threadObj->request_follow_up_plan, 'Request follow-up plan should match');
        }
    }
    
    public function testPagePostWithSendNow_multipleEntities() {
        // :: Setup - Get a valid entity ID from the database
        $entity_id1 = '000000000-test-entity-development';
        $entity_id2 = '958935420-oslo-kommune';
        
        // :: Setup - Create post data with send_now option
        $post_data = [
            'title' => 'Test Thread ' . uniqid(),
            'labels' => 'test e2e',
            'entity_ids' => [$entity_id1 , $entity_id2],
            'body' => 'This is a test message body created by E2E test.',
            'public' => '1',
            'send_now' => '1',
            'request_law_basis' => Thread::REQUEST_LAW_BASIS_OTHER,
            'request_follow_up_plan' => Thread::REQUEST_FOLLOW_UP_PLAN_SLOW
        ];
        
        // :: Act - Test POST request to start thread with send_now
        $response = $this->renderPage('/thread-start', 'dev-user-id', 'POST', '302 Found', $post_data);
        
        // :: Assert - Should redirect to thread overview with label
        $this->assertStringContainsString('Location: /?label_filter=group-', $response->headers);
        
        // Extract thread ID from the redirect URL
        preg_match('/label_filter=([a-z0-9\-]+)/', $response->headers, $matches);
        $this->assertNotEmpty($matches[1], 'Thread ID should be in the redirect URL');
        $groupLabel = $matches[1];

        $threads = Database::query("SELECT * FROM threads WHERE ? = any(labels)", [$groupLabel]);
        $this->assertEquals(2, count($threads), 'Two threads should be created');
        
        foreach($threads as $thread) {
            // Verify ThreadEmailSending record was created with READY_FOR_SENDING status
            $emailSendings = ThreadEmailSending::getByThreadId($thread['id']);
            $this->assertNotEmpty($emailSendings, 'ThreadEmailSending record should be created');
            $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $emailSendings[0]->status, 
                'Status should be READY_FOR_SENDING when send_now is selected');
                
            // Verify the thread has the correct request fields
            $threadObj = Thread::loadFromDatabase($thread['id']);
            $this->assertEquals($post_data['request_law_basis'], $threadObj->request_law_basis, 'Request law basis should match');
            $this->assertEquals($post_data['request_follow_up_plan'], $threadObj->request_follow_up_plan, 'Request follow-up plan should match');
        }
    }
    
}
