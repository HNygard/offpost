<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class IndexPageTest extends E2EPageTestCase {

    public function testPageLoggedIn() {
        $response = $this->renderPage('/');
        $this->assertStringContainsString(
            '<h1>Offpost - Email Engine Organizer</h1>', $response->body);
    }

}