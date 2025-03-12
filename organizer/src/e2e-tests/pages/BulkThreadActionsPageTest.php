<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once __DIR__ . '/../../class/Thread.php';

class BulkThreadActionsPageTest extends E2EPageTestCase {
    private $testThreads = [];
    private $testEntityId = '000000000-test-entity-development';
    
    protected function setUp(): void {
        parent::setUp();
        
        // Create multiple test threads
        for ($i = 0; $i < 3; $i++) {
            $this->testThreads[] = E2ETestSetup::createTestThread($this->testEntityId);
        }
    }
    
    public function testMultiSelectUIElements() {
        // :: Setup
        // No additional setup needed
        
        // :: Act
        $response = $this->renderPage('/');
        
        // :: Assert
        // Check for the select-all checkbox
        $this->assertStringContainsString(
            '<input type="checkbox" id="select-all-threads"', 
            $response->body,
            "Select all checkbox should be present"
        );
        
        // Check for the bulk actions form
        $this->assertStringContainsString(
            '<form action="/thread-bulk-actions" method="post" id="bulk-actions-form">',
            $response->body,
            "Bulk actions form should be present"
        );
        
        // Check for the action dropdown
        $this->assertStringContainsString(
            '<select name="action" id="bulk-action">',
            $response->body,
            "Action dropdown should be present"
        );
        
        // Check for the available actions
        $this->assertStringContainsString('value="archive">Archive thread</option>', $response->body);
        $this->assertStringContainsString('value="ready_for_sending">Mark thread as ready for sending</option>', $response->body);
        $this->assertStringContainsString('value="make_private">Mark thread as private</option>', $response->body);
        $this->assertStringContainsString('value="make_public">Mark thread as public</option>', $response->body);
        
        // Check for the selected count container
        $this->assertStringContainsString(
            '<div class="selected-count-container" id="selected-count-container">',
            $response->body,
            "Selected count container should be present"
        );
        
        // Check for individual thread checkboxes
        $this->assertStringContainsString(
            'class="thread-checkbox" name="thread_ids[]"',
            $response->body,
            "Thread checkboxes should be present"
        );
    }
    
    public function testBulkArchiveThreadsUI() {
        // :: Setup
        // Ensure threads are not archived
        foreach ($this->testThreads as $testData) {
            Database::execute(
                "UPDATE threads SET archived = false WHERE id = ?",
                [$testData['thread']->id]
            );
        }
        
        // Prepare thread IDs for the POST request
        $threadIds = [];
        foreach ($this->testThreads as $testData) {
            $threadIds[] = $this->testEntityId . ':' . $testData['thread']->id;
        }
        
        // :: Act
        // Submit the bulk action form
        $response = $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/');
        
        // :: Assert
        // Check for success message
        $this->assertStringContainsString(
            'Successfully processed ' . count($threadIds) . ' thread(s)',
            $response->body,
            "Success message should be displayed"
        );
        
        // Verify in database that threads are archived
        foreach ($this->testThreads as $testData) {
            $isArchived = Database::queryValue(
                "SELECT archived FROM threads WHERE id = ?",
                [$testData['thread']->id]
            );
            $this->assertTrue((bool)$isArchived, "Thread {$testData['thread']->id} should be archived");
        }
    }
    
    public function testBulkMarkReadyForSendingUI() {
        // :: Setup
        // Ensure threads are in STAGING status
        foreach ($this->testThreads as $testData) {
            Database::execute(
                "UPDATE threads SET sending_status = ? WHERE id = ?",
                [Thread::SENDING_STATUS_STAGING, $testData['thread']->id]
            );
        }
        
        // Prepare thread IDs for the POST request
        $threadIds = [];
        foreach ($this->testThreads as $testData) {
            $threadIds[] = $this->testEntityId . ':' . $testData['thread']->id;
        }
        
        // :: Act
        // Submit the bulk action form
        $response = $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'ready_for_sending',
                'thread_ids' => $threadIds
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/');
        
        // :: Assert
        // Check for success message
        $this->assertStringContainsString(
            'Successfully processed ' . count($threadIds) . ' thread(s)',
            $response->body,
            "Success message should be displayed"
        );
        
        // Verify in database that threads are ready for sending
        foreach ($this->testThreads as $testData) {
            $status = Database::queryValue(
                "SELECT sending_status FROM threads WHERE id = ?",
                [$testData['thread']->id]
            );
            $this->assertEquals(
                Thread::SENDING_STATUS_READY_FOR_SENDING,
                $status,
                "Thread {$testData['thread']->id} should be marked as ready for sending"
            );
        }
    }
    
    public function testBulkMarkPrivateUI() {
        // :: Setup
        // Make all threads public
        foreach ($this->testThreads as $testData) {
            Database::execute(
                "UPDATE threads SET public = true WHERE id = ?",
                [$testData['thread']->id]
            );
        }
        
        // Prepare thread IDs for the POST request
        $threadIds = [];
        foreach ($this->testThreads as $testData) {
            $threadIds[] = $this->testEntityId . ':' . $testData['thread']->id;
        }
        
        // :: Act
        // Submit the bulk action form
        $response = $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'make_private',
                'thread_ids' => $threadIds
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/');
        
        // :: Assert
        // Check for success message
        $this->assertStringContainsString(
            'Successfully processed ' . count($threadIds) . ' thread(s)',
            $response->body,
            "Success message should be displayed"
        );
        
        // Verify in database that threads are private
        foreach ($this->testThreads as $testData) {
            $isPublic = Database::queryValue(
                "SELECT public FROM threads WHERE id = ?",
                [$testData['thread']->id]
            );
            $this->assertFalse((bool)$isPublic, "Thread {$testData['thread']->id} should be private");
        }
    }
    
    public function testBulkMarkPublicUI() {
        // :: Setup
        // Make all threads private
        foreach ($this->testThreads as $testData) {
            Database::execute(
                "UPDATE threads SET public = false WHERE id = ?",
                [$testData['thread']->id]
            );
        }
        
        // Prepare thread IDs for the POST request
        $threadIds = [];
        foreach ($this->testThreads as $testData) {
            $threadIds[] = $this->testEntityId . ':' . $testData['thread']->id;
        }
        
        // :: Act
        // Submit the bulk action form
        $response = $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'make_public',
                'thread_ids' => $threadIds
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/');
        
        // :: Assert
        // Check for success message
        $this->assertStringContainsString(
            'Successfully processed ' . count($threadIds) . ' thread(s)',
            $response->body,
            "Success message should be displayed"
        );
        
        // Verify in database that threads are public
        foreach ($this->testThreads as $testData) {
            $isPublic = Database::queryValue(
                "SELECT public FROM threads WHERE id = ?",
                [$testData['thread']->id]
            );
            $this->assertTrue((bool)$isPublic, "Thread {$testData['thread']->id} should be public");
        }
    }
    
    public function testInvalidBulkActionHandling() {
        // :: Setup
        // Prepare thread IDs for the POST request
        $threadIds = [];
        foreach ($this->testThreads as $testData) {
            $threadIds[] = $this->testEntityId . ':' . $testData['thread']->id;
        }
        
        // :: Act
        // Submit the bulk action form with an invalid action
        $response = $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'invalid_action',
                'thread_ids' => $threadIds
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/');
        
        // :: Assert
        // Check for error message
        $this->assertStringContainsString(
            'Failed to process ' . count($threadIds) . ' thread(s)',
            $response->body,
            "Error message should be displayed"
        );
    }
}
