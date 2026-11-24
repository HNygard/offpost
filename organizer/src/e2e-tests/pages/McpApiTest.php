<?php
// organizer/src/e2e-tests/pages/McpApiTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once __DIR__ . '/common/OAuthE2E.php';
require_once __DIR__ . '/common/OAuthTestFlow.php';

class McpApiTest extends TestCase {
    const BASE = 'http://localhost:25081';
    const AUDIENCE = 'http://localhost:25081/mcp';

    private ?string $token = null;

    protected function setUp(): void {
        OAuthE2E::clearRegistrationRateLimit();
        $this->token = null;
    }

    /** A real access token from Offpost's token endpoint for dev-user-id, minted once per test. */
    private function validToken(): string {
        if ($this->token === null) {
            $this->token = OAuthTestFlow::accessTokenFor('dev-user-id');
        }
        return $this->token;
    }

    /** Sends a request and returns status, raw headers and body. */
    private function request(string $method, string $path, ?string $token, ?string $body = null): array {
        $ch = curl_init(self::BASE . $path);
        $headers = [];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [
            'status' => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
        ];
    }

    private function headerValue(string $rawHeaders, string $name): ?string {
        foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }

    private function rpc(string $token, array $message): array {
        return $this->request('POST', '/mcp', $token, json_encode($message));
    }

    public function testProtectedResourceMetadata(): void {
        // :: Act
        $resp = $this->request('GET', '/.well-known/oauth-protected-resource', null);

        // :: Assert
        $this->assertEquals(200, $resp['status'], $resp['body']);
        $this->assertEquals([
            'resource' => 'http://localhost:25081/mcp',
            'authorization_servers' => ['http://localhost:25081'],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
        ], json_decode($resp['body'], true));
    }

    public function testProtectedResourceMetadataPathSuffixedForMcp(): void {
        // :: Act
        $resp = $this->request('GET', '/.well-known/oauth-protected-resource/mcp', null);

        // :: Assert
        $this->assertEquals(200, $resp['status'], $resp['body']);
        $this->assertEquals([
            'resource' => 'http://localhost:25081/mcp',
            'authorization_servers' => ['http://localhost:25081'],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
        ], json_decode($resp['body'], true));
    }

    public function testNoTokenIs401WithMetadataHeader(): void {
        // :: Act
        $resp =$this->request('POST', '/mcp', null, '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        // :: Assert
        $this->assertEquals(401, $resp['status'], $resp['body']);
        $this->assertEquals(
            'Bearer resource_metadata="http://localhost:25081/.well-known/oauth-protected-resource", scope="mcp"',
            $this->headerValue($resp['headers'], 'WWW-Authenticate')
        );
    }

    public function testBadTokenIs401InvalidToken(): void {
        // :: Setup
        $badToken = 'opmcp_' . str_repeat('x', 43);

        // :: Act
        $resp = $this->rpc($badToken, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        // :: Assert
        $this->assertEquals(401, $resp['status'], $resp['body']);
        $this->assertEquals(
            'Bearer resource_metadata="http://localhost:25081/.well-known/oauth-protected-resource", scope="mcp", error="invalid_token"',
            $this->headerValue($resp['headers'], 'WWW-Authenticate')
        );
        $this->assertEquals(['error' => 'Invalid token'], json_decode($resp['body'], true));
    }

    public function testRevokedTokenIs401InvalidToken(): void {
        // :: Setup
        $client = OAuthTestFlow::connect('dev-user-id', true, 'e2e revoke client');
        $token = $client['access_token'];
        $before = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        // :: Act
        $revoke = OAuthTestFlow::request('POST', '/oauth/revoke', http_build_query([
            'token' => $token,
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $after = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);

        // :: Assert
        $this->assertEquals(200, $before['status'], $before['body']);
        $this->assertEquals(200, $revoke['status'], $revoke['body']);
        $this->assertEquals(401, $after['status'], $after['body']);
        $this->assertEquals(
            'Bearer resource_metadata="http://localhost:25081/.well-known/oauth-protected-resource", scope="mcp", error="invalid_token"',
            $this->headerValue($after['headers'], 'WWW-Authenticate')
        );
    }

    public function testGetIs405(): void {
        // :: Act
        $resp = $this->request('GET', '/mcp', $this->validToken());

        // :: Assert
        $this->assertEquals(405, $resp['status'], $resp['body']);
        $this->assertEquals('POST', $this->headerValue($resp['headers'], 'Allow'));
    }

    public function testInitializeAndToolsList(): void {
        // :: Setup
        $token = $this->validToken();

        // :: Act
        $init = $this->rpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'e2e', 'version' => '1'],
            ],
        ]);
        $list = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        // :: Assert
        $this->assertEquals(200, $init['status'], $init['body']);
        $initBody = json_decode($init['body'], true);
        $this->assertEquals(1, $initBody['id'], $init['body']);
        $this->assertArrayHasKey('result', $initBody, $init['body']);

        $this->assertEquals(200, $list['status'], $list['body']);
        $names = array_column(json_decode($list['body'], true)['result']['tools'], 'name');
        $this->assertEquals([
            'list_entities',
            'list_my_threads',
            'get_thread',
            'get_attachment_link',
            'get_attachment_extraction',
            'start_thread',
            'send_reply',
            'archive_thread',
        ], $names, 'tools: ' . json_encode($names, JSON_PRETTY_PRINT));
    }

    public function testNotificationGives202WithEmptyBody(): void {
        // :: Act
        $resp = $this->rpc($this->validToken(), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        // :: Assert
        $this->assertEquals(202, $resp['status'], $resp['body']);
        $this->assertEquals('', $resp['body']);
    }

    public function testListMyThreadsThroughHttp(): void {
        // :: Act
        $resp = $this->rpc($this->validToken(), [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'list_my_threads', 'arguments' => new stdClass()],
        ]);

        // :: Assert
        $this->assertEquals(200, $resp['status'], $resp['body']);
        $result = json_decode($resp['body'], true)['result'];
        $this->assertEquals('text', $result['content'][0]['type'], $resp['body']);
        $payload = json_decode($result['content'][0]['text'], true);
        $this->assertIsArray($payload, $resp['body']);
        $this->assertArrayHasKey('threads', $payload, $resp['body']);
    }

    /** Calls a tool as dev-user-id and returns the decoded JSON of its first text item. */
    private function callToolJson(string $name, array $arguments): array {
        $resp = $this->rpc($this->validToken(), [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
        $this->assertEquals(200, $resp['status'], $resp['body']);
        $result = json_decode($resp['body'], true)['result'];
        $this->assertFalse($result['isError'], $resp['body']);
        return json_decode($result['content'][0]['text'], true);
    }

    /** Plain GET of an absolute URL, without any Authorization header. */
    private function download(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }

    private function attachmentBytes(string $attachmentId): string {
        return hex2bin(Database::queryValue("SELECT encode(content, 'hex') FROM thread_email_attachments WHERE id = ?", [$attachmentId]));
    }

    public function testAttachmentLinkDownloadsExactBytesWithoutAuth(): void {
        // :: Setup
        $setup = E2ETestSetup::createTestThread();
        try {
            $expected = $this->attachmentBytes($setup['attachment_id']);

            // :: Act
            $link = $this->callToolJson('get_attachment_link',
                ['thread_id' => $setup['thread']->id, 'attachment_id' => $setup['attachment_id']]);
            $resp = $this->download($link['download_url']);

            // :: Assert
            $this->assertEquals(300, $link['expires_in']);
            $this->assertStringStartsWith(self::AUDIENCE . '/attachment/', $link['download_url']);
            $this->assertEquals(200, $resp['status'], $resp['body']);
            $this->assertEquals($expected, $resp['body'], 'downloaded bytes');
            $this->assertEquals('application/pdf', $this->headerValue($resp['headers'], 'Content-Type'));
            $this->assertEquals('attachment; filename="test.pdf"', $this->headerValue($resp['headers'], 'Content-Disposition'));
            $this->assertEquals((string)strlen($expected), $this->headerValue($resp['headers'], 'Content-Length'));
            $this->assertEquals('no-store', $this->headerValue($resp['headers'], 'Cache-Control'));
            $this->assertEquals('no-referrer', $this->headerValue($resp['headers'], 'Referrer-Policy'));
            $this->assertEquals('nosniff', $this->headerValue($resp['headers'], 'X-Content-Type-Options'));
        } finally {
            E2ETestSetup::cleanupTestThread($setup['thread']->id);
        }
    }

    public function testAttachmentLinkInlineIncludesContentAndUrl(): void {
        // :: Setup
        $setup = E2ETestSetup::createTestThread();
        try {
            $expected = $this->attachmentBytes($setup['attachment_id']);

            // :: Act
            $resp = $this->rpc($this->validToken(), [
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'tools/call',
                'params' => ['name' => 'get_attachment_link', 'arguments' => [
                    'thread_id' => $setup['thread']->id, 'attachment_id' => $setup['attachment_id'], 'inline' => true,
                ]],
            ]);

            // :: Assert
            $content = json_decode($resp['body'], true)['result']['content'];
            $this->assertEquals(['text', 'resource'], array_column($content, 'type'), $resp['body']);
            $this->assertStringStartsWith(self::AUDIENCE . '/attachment/', json_decode($content[0]['text'], true)['download_url']);
            $this->assertEquals(base64_encode($expected), $content[1]['resource']['blob']);
        } finally {
            E2ETestSetup::cleanupTestThread($setup['thread']->id);
        }
    }

    public function testTamperedAttachmentLinkIs403(): void {
        // :: Setup
        $setup = E2ETestSetup::createTestThread();
        try {
            $link = $this->callToolJson('get_attachment_link',
                ['thread_id' => $setup['thread']->id, 'attachment_id' => $setup['attachment_id']]);
            [, $signature] = explode('.', substr(strrchr($link['download_url'], '/'), 1));
            $forgedPayload = rtrim(strtr(base64_encode(json_encode([
                'sub' => 'someone-else', 'tid' => $setup['thread']->id, 'aid' => $setup['attachment_id'], 'exp' => 4102444800,
            ])), '+/', '-_'), '=');

            // :: Act
            $resp = $this->download(self::AUDIENCE . '/attachment/' . $forgedPayload . '.' . $signature);
            $garbage = $this->download(self::AUDIENCE . '/attachment/garbage');

            // :: Assert
            $this->assertEquals(403, $resp['status'], $resp['body']);
            $this->assertEquals('Link invalid or expired', $resp['body']);
            $this->assertEquals(403, $garbage['status'], $garbage['body']);
        } finally {
            E2ETestSetup::cleanupTestThread($setup['thread']->id);
        }
    }

    public function testAttachmentLinkIsRecheckedWhenAccessIsLostBeforeDownload(): void {
        // :: Setup
        // Links are signed with a server-side secret the host cannot read, so a valid link for a
        // thread the user cannot access is made by removing the access after the link was issued.
        $setup = E2ETestSetup::createTestThread();
        try {
            $link = $this->callToolJson('get_attachment_link',
                ['thread_id' => $setup['thread']->id, 'attachment_id' => $setup['attachment_id']]);
            Database::execute("DELETE FROM thread_authorizations WHERE thread_id = ?", [$setup['thread']->id]);
            Database::execute("UPDATE threads SET public = false WHERE id = ?", [$setup['thread']->id]);

            // :: Act
            $resp = $this->download($link['download_url']);

            // :: Assert
            $this->assertEquals(404, $resp['status'], $resp['body']);
            $this->assertEquals('Not found, or no access', $resp['body']);
        } finally {
            E2ETestSetup::cleanupTestThread($setup['thread']->id);
        }
    }

    public function testAttachmentLinkPostIs405(): void {
        // :: Act
        $resp = $this->request('POST', '/mcp/attachment/anything', null, '{}');

        // :: Assert
        $this->assertEquals(405, $resp['status'], $resp['body']);
    }
}
