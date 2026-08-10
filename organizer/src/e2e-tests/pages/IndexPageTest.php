<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class IndexPageTest extends E2EPageTestCase {

    public function testPageLoggedIn() {
        $response = $this->renderPage('/');

        // :: Assert basic page content - the heading
        $this->assertStringContainsString(
            '<h1>Offpost - Email Engine Organizer</h1>', $response->body);
        

        // :: Assert that page rendered data (only check for structure, not content)
        $this->assertStringContainsString('<tr id="thread-', $response->body);
        $this->assertStringContainsString('<a href="/thread-view?entityId=', $response->body);
    }

    public function testThreadOnDevelopmentOnlyEntityRenders() {
        // :: Setup
        // Entities in entities_test.json exist only in development. A thread on one of
        // them must render: previously Entity::getById() threw for these, and because
        // index.php resolves every listed thread's entity, a single such row took the
        // whole front page down with a 500.
        E2ETestSetup::createTestThread('000000000-test-entity-1');

        // :: Act
        $response = $this->renderPage('/');

        // :: Assert
        $this->assertStringContainsString(
            'TODO - Test Entity (Development)',
            $response->body,
            'Front page should resolve and show the development-only entity name'
        );
    }

    public function testLastEmailColumnHeader() {
        $response = $this->renderPage('/');

        // :: Assert that the "Last email" column header is present
        $this->assertStringContainsString('Last email', $response->body);
        
        // :: Assert that the sort indicator is present
        $this->assertStringContainsString('id="sort-indicator"', $response->body);
        
        // :: Assert that the header is clickable
        $this->assertStringContainsString('id="last-email-header"', $response->body);
    }

    public function testThreadsHaveLastEmailTimestamp() {
        $response = $this->renderPage('/');

        // :: Assert that thread rows have data-last-email-timestamp attribute
        $this->assertMatchesRegularExpression(
            '/<tr[^>]+data-last-email-timestamp="[0-9]*"/',
            $response->body
        );
    }

}