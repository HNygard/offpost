<?php
// organizer/src/e2e-tests/pages/AdminExportApiTest.php
// Modeled on NpApiAttachmentTest.php (raw curl + header auth) and
// NpApiAdminSessionTest.php (admin session via test-authenticate).
//
// NOTE on scope: a genuinely non-admin *authenticated* session cannot be
// exercised here. The dev auth service (auth/server.js) hardcodes its OIDC
// interaction result to the single dev admin user (DEV_USER, sub
// 'dev-user-id') regardless of any request parameter, so there is no HTTP-level
// flow in this dev stack that mints a non-admin session to test against. The
// closest available proof that a non-admin session is denied is the unit test
// of the shared npApiIsAdminSession() helper in
// organizer/src/tests/AdminApiAuthTest.php (testNonAdminSessionIsNotAnAdminSession).
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class AdminExportApiTest extends TestCase {
    const BASE = 'http://localhost:25081';

    private static ?string $adminSessionCookie = null;

    private function get(string $path, array $headers = [], ?string $cookie = null): array {
        $ch = curl_init(self::BASE . $path);
        if ($cookie !== null) {
            $headers[] = 'Cookie: ' . $cookie;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($body, true), 'body' => $body];
    }

    private function adminToken(): string {
        return trim(file_get_contents(__DIR__ . '/../../../../secrets/admin_api_token'));
    }

    private function npToken(): string {
        return trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
    }

    /**
     * Dev-mode auto-login always authenticates as the one dev admin user (see
     * the NOTE at the top of this file) - same mechanism as
     * E2EPageTestCase::authenticate(), reimplemented here because this class
     * also needs raw header-based (cookie-less) requests, which
     * E2EPageTestCase::renderPage() does not support.
     */
    private function adminSessionCookie(): string {
        if (self::$adminSessionCookie === null) {
            $ch = curl_init(self::BASE . '/?test-authenticate');
            $cookieJar = tempnam(sys_get_temp_dir(), 'cookie');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEJAR => $cookieJar,
            ]);
            $res = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($res, 0, $headerSize);
            curl_close($ch);

            $cookie = null;
            foreach (explode("\n", $header) as $line) {
                if (str_starts_with($line, 'Set-Cookie: PHPSESSID=')) {
                    $cookie = explode(': ', explode(';', $line)[0])[1];
                }
            }
            if ($cookie === null) {
                throw new Exception('No session cookie found from test-authenticate. Full headers: ' . $header);
            }
            self::$adminSessionCookie = $cookie;
        }
        return self::$adminSessionCookie;
    }

    // --- 401: no auth at all ---

    public function testThreadsListWithoutAuthGives401(): void {
        $resp = $this->get('/api/admin/export/threads');
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testThreadGetWithoutAuthGives401(): void {
        $resp = $this->get('/api/admin/export/thread?id=00000000-0000-4000-8000-000000000000');
        $this->assertEquals(401, $resp['status']);
    }

    // --- 401: the NP token must never authenticate here, whichever header it
    // is sent in ---

    public function testThreadsListWithNpTokenInAdminHeaderGives401(): void {
        $resp = $this->get('/api/admin/export/threads', ['X-Admin-Api-Token: ' . $this->npToken()]);
        $this->assertEquals(401, $resp['status']);
    }

    public function testThreadGetWithNpTokenInAdminHeaderGives401(): void {
        $resp = $this->get(
            '/api/admin/export/thread?id=00000000-0000-4000-8000-000000000000',
            ['X-Admin-Api-Token: ' . $this->npToken()]
        );
        $this->assertEquals(401, $resp['status']);
    }

    public function testThreadsListWithNpTokenInNpHeaderGives401(): void {
        // Sent under its own header name, which this endpoint does not read at all.
        $resp = $this->get('/api/admin/export/threads', ['X-Np-Api-Token: ' . $this->npToken()]);
        $this->assertEquals(401, $resp['status']);
    }

    public function testThreadGetWithNpTokenInNpHeaderGives401(): void {
        $resp = $this->get(
            '/api/admin/export/thread?id=00000000-0000-4000-8000-000000000000',
            ['X-Np-Api-Token: ' . $this->npToken()]
        );
        $this->assertEquals(401, $resp['status']);
    }

    // --- 200: admin token ---

    public function testThreadsListWithAdminTokenGives200(): void {
        $resp = $this->get('/api/admin/export/threads', ['X-Admin-Api-Token: ' . $this->adminToken()]);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('export_version', $resp['json']);
        $this->assertArrayHasKey('threads', $resp['json']);
        $this->assertIsArray($resp['json']['threads']);
    }

    // --- 200: admin session (no token) ---

    public function testThreadsListWithAdminSessionGives200(): void {
        $resp = $this->get('/api/admin/export/threads', [], $this->adminSessionCookie());
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('threads', $resp['json']);
    }

    // --- 400 / 404 on the single-thread endpoint ---

    public function testThreadGetMalformedIdGives400(): void {
        $resp = $this->get(
            '/api/admin/export/thread?id=not-a-uuid',
            ['X-Admin-Api-Token: ' . $this->adminToken()]
        );
        $this->assertEquals(400, $resp['status']);
    }

    public function testThreadGetUnknownUuidGives404(): void {
        $resp = $this->get(
            '/api/admin/export/thread?id=00000000-0000-4000-8000-000000000000',
            ['X-Admin-Api-Token: ' . $this->adminToken()]
        );
        $this->assertEquals(404, $resp['status']);
    }

    // --- 200 + shape, for a thread created via E2ETestSetup ---

    public function testThreadGetReturnsShapeForRealThread(): void {
        $created = E2ETestSetup::createTestThread();
        try {
            $resp = $this->get(
                '/api/admin/export/thread?id=' . $created['thread']->id,
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );

            $this->assertEquals(200, $resp['status'], $resp['body']);
            $json = $resp['json'];
            $this->assertEquals(1, $json['export_version']);
            $this->assertArrayHasKey('exported_at', $json);
            $this->assertArrayHasKey('fingerprint', $json);
            $this->assertEquals($created['thread']->id, $json['thread']['id']);
            $this->assertEquals($created['entity_id'], $json['thread']['entity_id']);
            $this->assertCount(1, $json['emails'], json_encode($json['emails'], JSON_PRETTY_PRINT));
            $this->assertEquals('IN', $json['emails'][0]['email_type']);
            $this->assertCount(1, $json['emails'][0]['attachments']);
            $this->assertEquals('test.pdf', $json['emails'][0]['attachments'][0]['name']);
            $this->assertArrayHasKey('eml_base64', $json['emails'][0]);
            $this->assertArrayHasKey('sendings', $json);
            $this->assertArrayHasKey('history', $json);

            // Also reachable with an admin session instead of the token.
            $sessionResp = $this->get(
                '/api/admin/export/thread?id=' . $created['thread']->id,
                [],
                $this->adminSessionCookie()
            );
            $this->assertEquals(200, $sessionResp['status']);
        } finally {
            E2ETestSetup::cleanupTestThread($created['thread']->id, $created['entity_id']);
        }
    }
}
