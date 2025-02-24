<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class E2EAuthenticationTest extends E2EPageTestCase {

    public function testPageNotLoggedIn() {
        $response = $this->renderPage('/', user: null, expected_status: '302 Found');
        $this->assertStringContainsString('Location: http://localhost:25083/oidc/auth?client_id=organizer&response_type=code&scope=openid+email+profile&redirect_uri=http%3A%2F%2Flocalhost%3A25081%2Fcallback&state=', $response->headers);
    }

    public function testPageLoggedIn() {
        $response = $this->renderPage('/');
        $this->assertStringContainsString(
            '<h1>Offpost - Email Engine Organizer</h1>', $response->body);
    }

}