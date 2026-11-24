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

    public function testCallbackWithMismatchingState() {
        // :: Setup
        // No login was started in this session, so no state is stored.

        // :: Act
        $response = $this->renderPage('/callback?code=some-code&state=not-the-stored-state', null, 'GET', '400 Bad Request');

        // :: Assert
        $this->assertEquals('Invalid login state', trim($response->body), 'Callback must be rejected before any token exchange');
    }

}
