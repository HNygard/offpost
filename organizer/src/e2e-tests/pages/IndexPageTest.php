<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

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

}