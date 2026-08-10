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
            $this->assertTrue((bool)$isArchived, "Thread {$testData['thread']->id} should be archived in database");
        }
        
        // Verify that the threads are shown as archived in the UI
        foreach ($this->testThreads as $testData) {
            // Load the thread view page to verify it shows as archived
            $threadViewResponse = $this->renderPage(
                '/thread-view?entityId=' . urlencode($this->testEntityId) . '&threadId=' . urlencode($testData['thread']->id)
            );
            
            // Check for archived indicator in the UI
            $this->assertStringContainsString(
                '<span class="label label_ok"><a href="/?label_filter=archived">Archived</a></span>',
                $threadViewResponse->body,
                "Thread {$testData['thread']->id} should be shown as archived in the UI as label"
            );
            $this->assertStringContainsString(
                '<span class="history-action">Archived thread</span>',
                $threadViewResponse->body,
                "Thread {$testData['thread']->id} should be shown as archived in the UI thread history"
            );
        }
        
        // Verify that the StorageManager returns the thread as archived
        $storageManager = ThreadStorageManager::getInstance();
        $threads = $storageManager->getThreads('dev-user-id');
        
        foreach ($this->testThreads as $testData) {
            $found = false;
            foreach ($threads as $file => $entityThreads) {
                if ($entityThreads->entity_id === $this->testEntityId) {
                    foreach ($entityThreads->threads as $thread) {
                        if ($thread->id === $testData['thread']->id) {
                            $this->assertTrue($thread->archived, "Thread {$thread->id} should be archived in StorageManager");
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
            $this->assertTrue($found, "Thread {$testData['thread']->id} should be found in StorageManager results");
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

        // Each failed thread should say why it failed
        $this->assertStringContainsString(
            'Unknown bulk action',
            $response->body,
            "Error message should name the reason the action could not be applied"
        );
    }

    public function testMalformedThreadReferenceReportsReason() {
        // :: Setup
        $threadIds = ['this-reference-has-no-separator'];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'Invalid thread reference (expected entityId:threadId)',
            $response->body,
            "Malformed thread reference should be reported with its own reason"
        );
        $this->assertStringContainsString(
            'this-reference-has-no-separator',
            $response->body,
            "Error message should echo the submitted reference so the admin can identify it"
        );
    }

    public function testNonExistentThreadReportsThatNoThreadExists() {
        // :: Setup
        // A syntactically valid UUID that is not in the database
        $missingThreadId = '00000000-0000-4000-8000-000000000001';
        $threadIds = [$this->testEntityId . ':' . $missingThreadId];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'No thread exists with this ID',
            $response->body,
            "A thread ID with no matching row should be reported as non-existent"
        );
    }

    public function testNonUuidThreadIdReportsThatNoThreadExists() {
        // :: Setup
        // threads.id is a uuid column, so a non-uuid value must not crash the lookup
        $threadIds = [$this->testEntityId . ':not-a-uuid-at-all'];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'No thread exists with this ID',
            $response->body,
            "A non-uuid thread ID should be reported as non-existent rather than crashing the page"
        );
    }

    public function testWrongEntityIdReportsEntityMismatch() {
        // :: Setup
        // Reference an existing thread through an entity it does not belong to
        $thread = $this->testThreads[0]['thread'];
        $threadIds = ['999999999-wrong-entity-development:' . $thread->id];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'Thread belongs to entity ' . $this->testEntityId . ', not 999999999-wrong-entity-development',
            $response->body,
            "An entity/thread mismatch should name both the actual and the submitted entity"
        );
    }

    public function testReadyForSendingOnNonStagingThreadReportsCurrentStatus() {
        // :: Setup
        $thread = $this->testThreads[0]['thread'];
        Database::execute(
            "UPDATE threads SET sending_status = ? WHERE id = ?",
            [Thread::SENDING_STATUS_SENT, $thread->id]
        );
        $threadIds = [$this->testEntityId . ':' . $thread->id];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'ready_for_sending',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'Cannot mark as ready for sending: status is SENT, expected STAGING',
            $response->body,
            "A wrong sending status should be reported with the status the thread actually has"
        );
    }

    /**
     * Extract the contents of the error alert box, so assertions cannot accidentally
     * be satisfied by text that appears elsewhere on the thread listing page.
     */
    private function getErrorAlert($body) {
        $this->assertMatchesRegularExpression(
            '#<div class="alert alert-error">#',
            $body,
            "Page should contain an error alert box"
        );
        preg_match('#<div class="alert alert-error">(.*?)</div>#s', $body, $matches);
        return html_entity_decode($matches[1]);
    }

    public function testFailedThreadIsIdentifiedByTitleInsideErrorAlert() {
        // :: Setup
        $thread = $this->testThreads[0]['thread'];
        Database::execute(
            "UPDATE threads SET sending_status = ? WHERE id = ?",
            [Thread::SENDING_STATUS_SENT, $thread->id]
        );
        $threadIds = [$this->testEntityId . ':' . $thread->id];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'ready_for_sending',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $alert = $this->getErrorAlert($response->body);
        $this->assertStringContainsString(
            $thread->title,
            $alert,
            "The error alert should name the thread title, not just its ID. Alert was: " . $alert
        );
        $this->assertStringContainsString(
            $thread->id,
            $alert,
            "The error alert should name the thread ID. Alert was: " . $alert
        );
    }

    public function testErrorAlertRendersEachFailureOnItsOwnLine() {
        // :: Setup
        // Two unresolvable references, so the message has a heading plus two failure lines
        $threadIds = [
            $this->testEntityId . ':00000000-0000-4000-8000-000000000003',
            $this->testEntityId . ':00000000-0000-4000-8000-000000000004',
        ];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $alert = $this->getErrorAlert($response->body);
        $this->assertEquals(
            2,
            substr_count($alert, '<br />'),
            "Heading and both failure lines should be separated by line breaks. Alert was: " . $alert
        );
    }

    public function testMixedBatchReportsBothSuccessesAndPerThreadFailures() {
        // :: Setup
        // One thread that can be archived, one reference that cannot be resolved
        $thread = $this->testThreads[0]['thread'];
        $missingThreadId = '00000000-0000-4000-8000-000000000002';
        $threadIds = [
            $this->testEntityId . ':' . $thread->id,
            $this->testEntityId . ':' . $missingThreadId,
        ];

        // :: Act
        $this->renderPage(
            '/thread-bulk-actions',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'archive',
                'thread_ids' => $threadIds
            ]
        );
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'Successfully processed 1 thread(s)',
            $response->body,
            "The thread that could be archived should still be reported as processed"
        );
        $this->assertStringContainsString(
            'Failed to process 1 thread(s)',
            $response->body,
            "The unresolvable reference should be reported as a failure"
        );
        $this->assertStringContainsString(
            'No thread exists with this ID',
            $response->body,
            "The failure should carry its specific reason"
        );

        $isArchived = Database::queryValue(
            "SELECT archived FROM threads WHERE id = ?",
            [$thread->id]
        );
        $this->assertTrue((bool)$isArchived, "The resolvable thread should have been archived despite the other failure");
    }
}
