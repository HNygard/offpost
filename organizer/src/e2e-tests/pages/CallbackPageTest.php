<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class CallbackPageTest extends E2EPageTestCase {

    public function testCallbackWithError() {
        // Test callback with error
        $response = $this->renderPage('/callback?error=access_denied', null, 'GET', '400 Bad Request');

        // :: Assert error message
        $this->assertStringContainsString('No authorization code provided', $response->body);
    }

    public function testCallbackWithoutParams() {
        // Should fail when no params provided
        $response = $this->renderPage('/callback', null, 'GET', '400 Bad Request');

        // :: Assert error message
        $this->assertStringContainsString('No authorization code provided', $response->body);
    }

}
