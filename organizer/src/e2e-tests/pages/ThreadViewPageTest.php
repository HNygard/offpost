<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');

class ThreadViewPageTest extends E2EPageTestCase {
    public function testPageHappy() {
        // :: Setup
        // Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];

        // :: Act
        // Need to provide an entityId parameter
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert basic page content
        $this->assertStringContainsString('<h1>Thread: ', $response->body);

        // :: Assert that page rendered data (only check for structure, not content)
        $this->assertStringContainsString('<div class="thread-details">', $response->body);
        $this->assertStringContainsString('<strong>Identity:</strong>', $response->body);

        $this->assertStringContainsString('<div class="thread-history">', $response->body);
        $this->assertStringContainsString('<span class="history-action">Created thread</span>', $response->body);

        $this->assertStringContainsString('<div class="emails-list">', $response->body);
        $this->assertStringContainsString('<span class="email-type">', $response->body);
    }

    public function testPageWithoutEntityId() {
        // :: Setup
        // No setup needed

        // :: Act
        // Should fail when no entityId provided
        $response = $this->renderPage('/thread-view', 'dev-user-id', 'GET', '400 Bad Request');

        // :: Assert
        $this->assertStringContainsString('Thread ID and Entity ID are required', $response->body);
    }

    public function testTogglePublicStatus() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Ensure thread is initially private
        Database::execute(
            "UPDATE threads SET public = false WHERE id = ?",
            [$threadId]
        );
        
        // :: Act
        // First, check that the thread is private
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // :: Assert
        $this->assertStringContainsString('Make Public', $response->body);
        $this->assertStringContainsString('(Currently Private)', $response->body);
        
        // :: Act
        // Now toggle to public
        $response = $this->renderPage(
            '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId,
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'toggle_public_to' => '1'
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // :: Assert
        $this->assertStringContainsString('Make Private', $response->body);
        $this->assertStringContainsString('(Currently Public)', $response->body);
        
        // Verify in database
        $isPublic = Database::queryValue(
            "SELECT public FROM threads WHERE id = ?",
            [$threadId]
        );
        $this->assertTrue((bool)$isPublic, "Thread should be public in the database");
    }
    
    public function testChangeStatusToReadyForSending() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Ensure thread is in STAGING status
        Database::execute(
            "UPDATE threads SET sending_status = ? WHERE id = ?",
            [Thread::SENDING_STATUS_STAGING, $threadId]
        );
        
        // :: Act
        // First, check that the thread is in STAGING status
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // :: Assert
        $this->assertStringContainsString('Mark as Ready for Sending', $response->body);
        $this->assertStringContainsString('(Currently in Staging)', $response->body);
        
        // :: Act
        // Now change to READY_FOR_SENDING
        $response = $this->renderPage(
            '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId,
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'change_status_to_ready' => '1'
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // :: Assert
        // The button should no longer be visible
        $this->assertStringNotContainsString('Mark as Ready for Sending', $response->body);
        $this->assertStringNotContainsString('(Currently in Staging)', $response->body);
        $this->assertStringContainsString('Sending status changed from [Staging] to [Ready for sending]', $response->body);
        
        // Verify in database
        $status = Database::queryValue(
            "SELECT sending_status FROM threads WHERE id = ?",
            [$threadId]
        );
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $status, "Thread should be in READY_FOR_SENDING status in the database");
    }
    
    public function testAddAndRemoveUser() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        $testUserId = 'test-user-testAddAndRemoveUser-' . uniqid();
        
        // :: Act
        // First, add a user
        $response = $this->renderPage(
            '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId,
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'user_id' => $testUserId,
                'add_user' => '1'
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // :: Assert
        // User admin table:
        $this->assertStringContainsString('<input type="hidden" name="user_id" value="' . $testUserId . '">', $response->body);
        // Thread log:
        $this->assertStringContainsString('Added user: ' . $testUserId, $response->body);
        
        // Verify in database
        $userExists = Database::queryValue(
            "SELECT COUNT(*) FROM thread_authorizations WHERE thread_id = ? AND user_id = ?",
            [$threadId, $testUserId]
        );
        $this->assertEquals(1, $userExists, "User should exist in thread_authorizations table");
        
        // :: Act
        // Now remove the user
        $response = $this->renderPage(
            '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId,
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'user_id' => $testUserId,
                'remove_user' => '1'
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // :: Assert
        // User admin table:
        $this->assertStringNotContainsString('<input type="hidden" name="user_id" value="' . $testUserId . '">', $response->body);
        // Thread log:
        $this->assertStringContainsString('Removed user: ' . $testUserId, $response->body);
        
        // Verify in database
        $userExists = Database::queryValue(
            "SELECT COUNT(*) FROM thread_authorizations WHERE thread_id = ? AND user_id = ?",
            [$threadId, $testUserId]
        );
        $this->assertEquals(0, $userExists, "User should not exist in thread_authorizations table");
    }
}
