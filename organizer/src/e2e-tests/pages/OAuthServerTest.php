<?php
// Metadata and dynamic client registration over HTTP against the dev stack.
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';

class OAuthServerTest extends TestCase {
    const BASE = 'http://localhost:25081';

    private function request(string $method, string $path, ?string $body = null, array $headers = []): array {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        return ['status' => $status, 'headers' => strtolower($rawHeaders), 'body' => $body, 'json' => json_decode($body, true)];
    }

    private function register(array $metadata): array {
        return $this->request('POST', '/oauth/register', json_encode($metadata), ['Content-Type: application/json']);
    }

    public function testMetadataDocument() {
        // :: Act
        $response = $this->request('GET', '/.well-known/oauth-authorization-server');

        // :: Assert
        $this->assertEquals(200, $response['status'], $response['body']);
        $authMethods = ['none', 'client_secret_post', 'client_secret_basic'];
        $expected = [
            'issuer' => 'http://localhost:25081',
            'authorization_endpoint' => 'http://localhost:25081/oauth/authorize',
            'token_endpoint' => 'http://localhost:25081/oauth/token',
            'registration_endpoint' => 'http://localhost:25081/oauth/register',
            'revocation_endpoint' => 'http://localhost:25081/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => $authMethods,
            'revocation_endpoint_auth_methods_supported' => $authMethods,
            'scopes_supported' => ['mcp'],
            'authorization_response_iss_parameter_supported' => true,
        ];
        $this->assertEquals($expected, $response['json']);
        $this->assertStringContainsString('content-type: application/json', $response['headers']);
    }

    public function testRegisterClaudeAiClient() {
        // :: Setup
        $metadata = [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ];

        // :: Act
        $response = $this->register($metadata);

        // :: Assert
        $this->assertEquals(201, $response['status'], $response['body']);
        $json = $response['json'];
        $this->assertEquals('Claude', $json['client_name']);
        $this->assertEquals(['https://claude.ai/api/mcp/auth_callback'], $json['redirect_uris']);
        $this->assertEquals('client_secret_post', $json['token_endpoint_auth_method']);
        $this->assertEquals(0, $json['client_secret_expires_at']);
        $this->assertEquals(22, strlen($json['client_id']));
        $this->assertEquals(43, strlen($json['client_secret']));
        $this->assertStringContainsString('cache-control: no-store', $response['headers']);
    }

    public function testRegisterDisallowedRedirectGivesJson400() {
        // :: Act
        $response = $this->register(['redirect_uris' => ['https://evil.com/cb']]);

        // :: Assert
        $this->assertEquals(400, $response['status'], $response['body']);
        $this->assertEquals('invalid_redirect_uri', $response['json']['error']);
        $this->assertStringContainsString('content-type: application/json', $response['headers']);
    }

    public function testRegisterRejectsNonJsonBodyAndWrongMethod() {
        // :: Act
        $notJson = $this->request('POST', '/oauth/register', 'redirect_uris=x', ['Content-Type: application/x-www-form-urlencoded']);
        $get = $this->request('GET', '/oauth/register');

        // :: Assert
        $this->assertEquals(400, $notJson['status'], $notJson['body']);
        $this->assertEquals('invalid_client_metadata', $notJson['json']['error']);
        $this->assertEquals(405, $get['status'], $get['body']);
        $this->assertStringContainsString('allow: post', $get['headers']);
        $this->assertEquals('invalid_request', $get['json']['error']);
    }
}
