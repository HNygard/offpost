<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class ThreadExportPageTest extends E2EPageTestCase {

    public function testPageLoggedIn() {
        // :: Setup
        $created = E2ETestSetup::createTestThread();

        try {
            // :: Act
            $response = $this->renderPage('/thread-export');

            // :: Assert
            $this->assertStringContainsString('<h1>Thread Export</h1>', $response->body);
            $this->assertStringContainsString(
                '/api/admin/export/thread?id=' . $created['thread']->id,
                $response->body
            );
        } finally {
            E2ETestSetup::cleanupTestThread($created['thread']->id, $created['entity_id']);
        }
    }

    public function testPageNotLoggedIn() {
        // :: Setup
        // Test that the page redirects to login when not logged in
        $response = $this->renderPage('/thread-export', null, 'GET', '302 Found');

        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }
}
