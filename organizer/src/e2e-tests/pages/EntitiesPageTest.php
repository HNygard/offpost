<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class EntitiesPageTest extends E2EPageTestCase {

    public function testPageLoggedIn() {
        $response = $this->renderPage('/entities');

        // :: Assert basic page content - the heading
        $this->assertStringContainsString('<h1>Entities</h1>', $response->body);
        
        // :: Assert that the table headers are present
        $this->assertStringContainsString('<th>Name</th>', $response->body);
        $this->assertStringContainsString('<th>Norske-postlister.no</th>', $response->body);
        $this->assertStringContainsString('<th>Number of Threads</th>', $response->body);
        $this->assertStringContainsString('<th>Status</th>', $response->body);
        
        // :: Assert that at least one entity is displayed
        $this->assertStringContainsString('<td>', $response->body);
        
        // :: Assert that the status labels are present
        $this->assertStringContainsString('label label_warn', $response->body);
        $this->assertStringContainsString('label label_ok', $response->body);
    }

    public function testPageNotLoggedIn() {
        // Test that the page redirects to login when not logged in
        $response = $this->renderPage('/entities', null, 'GET', '302 Found');
        $this->assertStringContainsString('Location:', $response->headers);
    }
}
