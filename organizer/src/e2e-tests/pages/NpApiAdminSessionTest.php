<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

/**
 * The NP API GET endpoints accept an authenticated admin session in addition to
 * the X-Np-Api-Token header (browser convenience for inspecting the API).
 * POST /api/np/thread must stay token-only (session-authed writes = CSRF risk).
 */
class NpApiAdminSessionTest extends E2EPageTestCase {
    public function testThreadsListWithAdminSessionNoToken() {
        $response = $this->renderPage('/api/np/threads', 'dev-user-id');
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('supported_entities', $body);
        $this->assertArrayHasKey('threads', $body);
    }

    public function testThreadsListWithoutSessionOrTokenGives401() {
        $this->renderPage('/api/np/threads', null, 'GET', '401 Unauthorized');
    }

    public function testThreadCreatePostStaysTokenOnlyForSessions() {
        // Admin session alone must NOT be able to create threads. The 401 fires at
        // the auth check before any body parsing, so the body shape is irrelevant.
        $this->renderPage('/api/np/thread', 'dev-user-id', 'POST', '401 Unauthorized', array('irrelevant' => '1'));
    }
}
