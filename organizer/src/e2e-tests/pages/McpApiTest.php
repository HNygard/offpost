<?php
// organizer/src/e2e-tests/pages/McpApiTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../tests/fixtures/mcp/McpTestKeys.php';

class McpApiTest extends TestCase {
    const BASE = 'http://localhost:25081';
    const ISSUER = 'http://localhost:25083/oidc';
    const AUDIENCE = 'http://localhost:25081/mcp';
    const DEV_USER_ID = 'dev-user-id';

    private function validToken(): string {
        return McpTestKeys::token([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => self::DEV_USER_ID,
            'iat' => 1700000000,
            'exp' => 4102444800,
        ]);
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
            'authorization_servers' => ['http://localhost:25083/oidc'],
            'bearer_methods_supported' => ['header'],
        ], json_decode($resp['body'], true));
    }

    public function testNoTokenIs401WithMetadataHeader(): void {
        // :: Act
        $resp =$this->request('POST', '/mcp', null, '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        // :: Assert
        $this->assertEquals(401, $resp['status'], $resp['body']);
        $this->assertEquals(
            'Bearer resource_metadata="http://localhost:25081/.well-known/oauth-protected-resource"',
            $this->headerValue($resp['headers'], 'WWW-Authenticate')
        );
    }

    public function testBadTokenIs401InvalidToken(): void {
        // :: Setup
        $badToken = McpTestKeys::token([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => self::DEV_USER_ID,
            'exp' => 4102444800,
        ], McpTestKeys::OTHER_PRIVATE_KEY);

        // :: Act
        $resp = $this->rpc($badToken, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        // :: Assert
        $this->assertEquals(401, $resp['status'], $resp['body']);
        $this->assertEquals(
            'Bearer resource_metadata="http://localhost:25081/.well-known/oauth-protected-resource", error="invalid_token"',
            $this->headerValue($resp['headers'], 'WWW-Authenticate')
        );
        $this->assertEquals(['error' => 'Invalid token'], json_decode($resp['body'], true));
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
            'get_attachment_raw',
            'get_attachment_extraction',
            'start_thread',
            'send_reply',
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
}
