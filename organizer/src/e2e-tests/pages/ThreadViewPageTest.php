<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');

class ThreadViewPageTest extends E2EPageTestCase {
    public function testPageHappy() {
        // Use the test data we created
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];

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
        // Should fail when no entityId provided
        $response = $this->renderPage('/thread-view', 'dev-user-id', 'GET', '400 Bad Request');

        // Assert error
        $this->assertStringContainsString('Thread ID and Entity ID are required', $response->body);
    }

}
