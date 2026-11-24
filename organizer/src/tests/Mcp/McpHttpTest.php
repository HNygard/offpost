<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../class/Mcp/McpHttp.php';

class McpHttpTest extends TestCase {
    public function testBearerTokenFromHttpAuthorization() {
        // :: Setup
        $server = ['HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi'];

        // :: Act
        $token = McpHttp::bearerToken($server, []);

        // :: Assert
        $this->assertEquals('abc.def.ghi', $token);
    }

    public function testBearerTokenFromRedirectHttpAuthorization() {
        // :: Setup
        $server = ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected'];

        // :: Act
        $token = McpHttp::bearerToken($server, []);

        // :: Assert
        $this->assertEquals('redirected', $token);
    }

    public function testBearerTokenFromHeadersCaseInsensitiveName() {
        // :: Setup
        $headers = ['authorization' => 'Bearer from-headers'];

        // :: Act
        $token = McpHttp::bearerToken([], $headers);

        // :: Assert
        $this->assertEquals('from-headers', $token);
    }

    public function testHttpAuthorizationWinsOverRedirectAndHeaders() {
        // :: Setup
        $server = ['HTTP_AUTHORIZATION' => 'Bearer first', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer second'];
        $headers = ['Authorization' => 'Bearer third'];

        // :: Act
        $token = McpHttp::bearerToken($server, $headers);

        // :: Assert
        $this->assertEquals('first', $token);
    }

    public function testBearerSchemeIsCaseInsensitive() {
        // :: Setup
        $server = ['HTTP_AUTHORIZATION' => 'bearer lower'];

        // :: Act
        $token = McpHttp::bearerToken($server, []);

        // :: Assert
        $this->assertEquals('lower', $token);
    }

    public function testBasicSchemeGivesNull() {
        // :: Setup
        $server = ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'];

        // :: Act
        $token = McpHttp::bearerToken($server, []);

        // :: Assert
        $this->assertNull($token);
    }

    public function testNoHeaderGivesNull() {
        // :: Act
        $token = McpHttp::bearerToken([], []);

        // :: Assert
        $this->assertNull($token);
    }

    public function testWwwAuthenticateWithoutError() {
        // :: Act
        $value = McpHttp::wwwAuthenticate('https://offpost.test/.well-known/oauth-protected-resource', false);

        // :: Assert
        $this->assertEquals(
            'Bearer resource_metadata="https://offpost.test/.well-known/oauth-protected-resource"',
            $value
        );
    }

    public function testWwwAuthenticateWithScope() {
        // :: Act
        $value = McpHttp::wwwAuthenticate('https://offpost.test/.well-known/oauth-protected-resource', false, ['mcp']);

        // :: Assert
        $this->assertEquals(
            'Bearer resource_metadata="https://offpost.test/.well-known/oauth-protected-resource", scope="mcp"',
            $value
        );
    }

    public function testWwwAuthenticateWithScopeAndInvalidToken() {
        // :: Act
        $value = McpHttp::wwwAuthenticate('https://offpost.test/.well-known/oauth-protected-resource', true, ['mcp', 'other']);

        // :: Assert
        $this->assertEquals(
            'Bearer resource_metadata="https://offpost.test/.well-known/oauth-protected-resource", scope="mcp other", error="invalid_token"',
            $value
        );
    }

    public function testWwwAuthenticateWithInvalidToken() {
        // :: Act
        $value = McpHttp::wwwAuthenticate('https://offpost.test/.well-known/oauth-protected-resource', true);

        // :: Assert
        $this->assertEquals(
            'Bearer resource_metadata="https://offpost.test/.well-known/oauth-protected-resource", error="invalid_token"',
            $value
        );
    }
}
