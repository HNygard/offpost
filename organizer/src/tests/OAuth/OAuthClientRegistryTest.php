<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/OAuth/OAuthClientRegistry.php';

class OAuthClientRegistryTest extends TestCase {
    private const NOW = 1700000000;
    private const ALLOWLIST = ['claude.ai', 'claude.com'];

    private int $now = self::NOW;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->now = self::NOW;
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function registry(): OAuthClientRegistry {
        return new OAuthClientRegistry(self::ALLOWLIST, fn(): int => $this->now);
    }

    private function claudeAiMetadata(): array {
        return [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ];
    }

    private function registerExpectingError(array $metadata, string $ip = '198.51.100.1'): OAuthError {
        try {
            $this->registry()->register($metadata, $ip);
        } catch (OAuthError $e) {
            return $e;
        }
        $this->fail('Expected OAuthError for ' . json_encode($metadata));
    }

    public function testRegisterClaudeAiClientSecretPost() {
        // :: Setup
        $registry = $this->registry();

        // :: Act
        $response = $registry->register($this->claudeAiMetadata(), '198.51.100.1');

        // :: Assert
        $this->assertEquals(22, strlen($response['client_id']), 'client_id is 16 random bytes as base64url');
        $this->assertEquals(43, strlen($response['client_secret']), 'client_secret is 32 random bytes as base64url');
        $expected = [
            'client_id' => $response['client_id'],
            'client_secret' => $response['client_secret'],
            'client_id_issued_at' => self::NOW,
            'client_secret_expires_at' => 0,
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ];
        $this->assertEquals($expected, $response);

        $row = Database::queryOne('SELECT * FROM oauth_clients WHERE client_id = ?', [$response['client_id']]);
        $this->assertNotEquals($response['client_secret'], $row['client_secret_hash'], 'Secret is not stored in clear');
        $this->assertEquals(hash('sha256', $response['client_secret']), $row['client_secret_hash']);
        $this->assertEquals('198.51.100.1', $row['registration_ip']);
        $this->assertEquals(['https://claude.ai/api/mcp/auth_callback'], json_decode($row['redirect_uris'], true));
    }

    public function testRegisterLoopbackNone() {
        // :: Setup
        $metadata = [
            'client_name' => 'Claude Code',
            'redirect_uris' => ['http://localhost:57706/callback'],
            'token_endpoint_auth_method' => 'none',
        ];

        // :: Act
        $response = $this->registry()->register($metadata, '198.51.100.1');

        // :: Assert
        $this->assertArrayNotHasKey('client_secret', $response);
        $this->assertEquals('none', $response['token_endpoint_auth_method']);
        $row = Database::queryOne('SELECT client_secret_hash FROM oauth_clients WHERE client_id = ?', [$response['client_id']]);
        $this->assertNull($row['client_secret_hash']);
    }

    public function testRegisterDefaultsToClientSecretPost() {
        // :: Act
        $response = $this->registry()->register(['redirect_uris' => ['https://claude.com/cb']], '198.51.100.1');

        // :: Assert
        $this->assertEquals('client_secret_post', $response['token_endpoint_auth_method']);
        $this->assertArrayHasKey('client_secret', $response);
        $this->assertArrayNotHasKey('client_name', $response);
    }

    public static function acceptedRedirectUris(): array {
        return [
            'uppercase host is lowercased for the allowlist check' => ['https://CLAUDE.AI/cb'],
            'claude.com' => ['https://claude.com/some/path?x=1'],
            'loopback ip' => ['http://127.0.0.1:8080/cb'],
            'loopback ipv6' => ['http://[::1]:8080/cb'],
            'localhost without port' => ['http://localhost/cb'],
        ];
    }

    #[DataProvider('acceptedRedirectUris')]
    public function testAcceptedRedirectUris(string $uri) {
        // :: Act
        $response = $this->registry()->register(['redirect_uris' => [$uri]], '198.51.100.1');

        // :: Assert
        $this->assertEquals([$uri], $response['redirect_uris'], 'Stored as given');
    }

    public static function rejectedRedirectUris(): array {
        return [
            'http on allowlisted host' => ['http://claude.ai/x'],
            'foreign host' => ['https://evil.com/cb'],
            'suffix of allowlisted host' => ['https://claude.ai.evil.com/cb'],
            'subdomain of allowlisted host' => ['https://sub.claude.ai/cb'],
            'userinfo' => ['https://claude.ai@evil.com/cb'],
            'empty userinfo' => ['https://@claude.ai/cb'],
            'fragment' => ['https://claude.ai/cb#frag'],
            'empty fragment' => ['https://claude.ai/cb#'],
            'punycode host' => ['https://xn--clude-0ra.ai/cb'],
            'idn host' => ['https://clàude.ai/cb'],
            'javascript scheme' => ['javascript:alert(1)'],
            'localhost suffix' => ['http://localhost.evil.com/cb'],
            'private ip' => ['http://192.168.1.10/cb'],
            'https loopback' => ['https://localhost/cb'],
            'port on allowlisted host' => ['https://claude.ai:8443/cb'],
            'backslash' => ['https://claude.ai\\@evil.com/cb'],
            'backslash in path' => ['https://evil.com\\.claude.ai/cb'],
            'space' => ['https://claude.ai/cb x'],
            'newline' => ["https://claude.ai/cb\n"],
            'tab' => ["https://claude.ai/\tcb"],
            'control char' => ["https://claude.ai/cb\x01"],
            'unparseable' => ['http:///cb'],
            'relative' => ['/cb'],
            'empty' => [''],
            'scheme only' => ['https://'],
        ];
    }

    #[DataProvider('rejectedRedirectUris')]
    public function testRejectedRedirectUris(string $uri) {
        // :: Act
        $error = $this->registerExpectingError(['redirect_uris' => [$uri]]);

        // :: Assert
        $this->assertEquals('invalid_redirect_uri', $error->error, $uri);
        $this->assertEquals(400, $error->httpStatus);
    }

    public function testRejectsNonStringRedirectUri() {
        // :: Act
        $error = $this->registerExpectingError(['redirect_uris' => [['https://claude.ai/cb']]]);

        // :: Assert
        $this->assertEquals('invalid_redirect_uri', $error->error);
    }

    public static function badMetadata(): array {
        $ok = ['https://claude.ai/cb'];
        return [
            'no redirect_uris' => [['client_name' => 'x']],
            'redirect_uris not a list' => [['redirect_uris' => 'https://claude.ai/cb']],
            'empty redirect_uris' => [['redirect_uris' => []]],
            '11 redirect_uris' => [['redirect_uris' => array_fill(0, 11, 'https://claude.ai/cb')]],
            'response_types token' => [['redirect_uris' => $ok, 'response_types' => ['token']]],
            'response_types code and token' => [['redirect_uris' => $ok, 'response_types' => ['code', 'token']]],
            'grant client_credentials' => [['redirect_uris' => $ok, 'grant_types' => ['client_credentials']]],
            'grant without authorization_code' => [['redirect_uris' => $ok, 'grant_types' => ['refresh_token']]],
            'grant authorization_code plus unknown' => [['redirect_uris' => $ok, 'grant_types' => ['authorization_code', 'implicit']]],
            'unknown auth method' => [['redirect_uris' => $ok, 'token_endpoint_auth_method' => 'private_key_jwt']],
            'client_name 101 chars' => [['redirect_uris' => $ok, 'client_name' => str_repeat('a', 101)]],
            'client_name not a string' => [['redirect_uris' => $ok, 'client_name' => ['x']]],
            'scope admin' => [['redirect_uris' => $ok, 'scope' => 'admin']],
            'scope mcp admin' => [['redirect_uris' => $ok, 'scope' => 'mcp admin']],
        ];
    }

    #[DataProvider('badMetadata')]
    public function testRegisterRejectsBadMetadata(array $metadata) {
        // :: Act
        $error = $this->registerExpectingError($metadata);

        // :: Assert
        $this->assertEquals('invalid_client_metadata', $error->error, json_encode($metadata));
        $this->assertEquals(400, $error->httpStatus);
    }

    public function testRegisterAcceptsBoundaryMetadata() {
        // :: Setup
        $metadata = [
            'redirect_uris' => array_fill(0, 10, 'https://claude.ai/cb'),
            'client_name' => str_repeat('å', 100),
            'scope' => 'mcp',
            'unknown_field' => 'ignored',
        ];

        // :: Act
        $response = $this->registry()->register($metadata, '198.51.100.1');

        // :: Assert
        $this->assertEquals(str_repeat('å', 100), $response['client_name']);
        $this->assertArrayNotHasKey('unknown_field', $response);
    }

    public function testRegisterRateLimitPerIp() {
        // :: Setup
        $registry = $this->registry();
        $metadata = ['redirect_uris' => ['https://claude.ai/cb']];
        for ($i = 0; $i < 30; $i++) {
            $registry->register($metadata, '198.51.100.1');
        }

        // :: Act
        try {
            $registry->register($metadata, '198.51.100.1');
            $this->fail('31st registration from one IP must be rate limited');
        } catch (OAuthError $e) {
            $limited = $e;
        }
        $other = $registry->register($metadata, '198.51.100.2');

        // :: Assert
        $this->assertEquals('too_many_requests', $limited->error);
        $this->assertEquals(429, $limited->httpStatus);
        $this->assertArrayHasKey('client_id', $other, 'Another IP is not limited');
    }

    public function testRegisterRateLimitWindowSlidesAfterAnHour() {
        // :: Setup
        $registry = $this->registry();
        $metadata = ['redirect_uris' => ['https://claude.ai/cb']];
        for ($i = 0; $i < 30; $i++) {
            $registry->register($metadata, '198.51.100.1');
        }

        // :: Act
        $this->now = self::NOW + 3601;
        $response = $registry->register($metadata, '198.51.100.1');

        // :: Assert
        $this->assertArrayHasKey('client_id', $response, 'Attempts older than one hour do not count');
    }

    public function testRejectedRegistrationsCountTowardsRateLimit() {
        // :: Setup
        $registry = $this->registry();
        for ($i = 0; $i < 30; $i++) {
            try {
                $registry->register(['redirect_uris' => ['https://evil.com/cb']], '198.51.100.1');
            } catch (OAuthError $e) {
                $this->assertEquals('invalid_redirect_uri', $e->error);
            }
        }

        // :: Act
        $error = $this->registerExpectingError(['redirect_uris' => ['https://claude.ai/cb']]);

        // :: Assert
        $this->assertEquals('too_many_requests', $error->error);
    }

    public function testRegisterRateLimitGlobal() {
        // :: Setup
        $registry = $this->registry();
        $metadata = ['redirect_uris' => ['https://claude.ai/cb']];
        for ($i = 0; $i < 200; $i++) {
            $registry->register($metadata, '203.0.113.' . (1 + intdiv($i, 20)));
        }

        // :: Act
        $error = $this->registerExpectingError($metadata, '203.0.113.250');

        // :: Assert
        $this->assertEquals('too_many_requests', $error->error);
        $this->assertEquals(429, $error->httpStatus);
    }

    public function testRedirectUriMatchesExactOrLoopbackAnyPort() {
        // :: Setup
        $registry = $this->registry();
        $web = ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];
        $loop = ['redirect_uris' => ['http://localhost:57706/callback', 'http://127.0.0.1:1/x?a=1']];

        // :: Act / Assert
        $this->assertTrue($registry->redirectUriMatches($web, 'https://claude.ai/api/mcp/auth_callback'));
        $this->assertFalse($registry->redirectUriMatches($web, 'https://claude.ai/api/mcp/auth_callback/'), 'Trailing slash');
        $this->assertFalse($registry->redirectUriMatches($web, 'https://CLAUDE.AI/api/mcp/auth_callback'), 'Case');
        $this->assertFalse($registry->redirectUriMatches($web, 'https://claude.ai/api/mcp/auth_callback#f'), 'Fragment');
        $this->assertFalse($registry->redirectUriMatches($web, 'https://claude.ai:8443/api/mcp/auth_callback'), 'Port on https is not ignored');
        $this->assertFalse($registry->redirectUriMatches($web, 'https://claude.ai@evil.com/api/mcp/auth_callback'), 'Userinfo');
        $this->assertFalse($registry->redirectUriMatches($web, 'https://claude.ai/other'));
        $this->assertTrue($registry->redirectUriMatches($loop, 'http://localhost:57706/callback'));
        $this->assertTrue($registry->redirectUriMatches($loop, 'http://localhost:1234/callback'), 'Loopback port ignored');
        $this->assertTrue($registry->redirectUriMatches($loop, 'http://localhost/callback'), 'Loopback without port');
        $this->assertTrue($registry->redirectUriMatches($loop, 'http://127.0.0.1:9999/x?a=1'));
        $this->assertFalse($registry->redirectUriMatches($loop, 'http://127.0.0.1:9999/x?a=2'), 'Query differs');
        $this->assertFalse($registry->redirectUriMatches($loop, 'http://localhost:1234/other'), 'Path differs');
        $this->assertFalse($registry->redirectUriMatches($loop, 'http://[::1]:1234/callback'), 'Different loopback host');
        $this->assertFalse($registry->redirectUriMatches($loop, 'http://localhost:1234/callback#f'), 'Fragment');
        $this->assertFalse($registry->redirectUriMatches($loop, 'http://user@localhost:1234/callback'), 'Userinfo');
        $this->assertFalse($registry->redirectUriMatches($loop, 'https://localhost:1234/callback'), 'Scheme');
    }

    public function testFind() {
        // :: Setup
        $registered = $this->registry()->register($this->claudeAiMetadata(), '198.51.100.1');

        // :: Act
        $client = $this->registry()->find($registered['client_id']);
        $missing = $this->registry()->find('does-not-exist');

        // :: Assert
        $this->assertEquals($registered['client_id'], $client['client_id']);
        $this->assertEquals(['https://claude.ai/api/mcp/auth_callback'], $client['redirect_uris']);
        $this->assertEquals('Claude', $client['client_name']);
        $this->assertNull($missing);
    }

    private function assertInvalidClient(callable $call, string $context): void {
        try {
            $call();
        } catch (OAuthError $e) {
            $this->assertEquals('invalid_client', $e->error, $context);
            $this->assertEquals(401, $e->httpStatus, $context);
            return;
        }
        $this->fail("Expected invalid_client: $context");
    }

    private function registerWithMethod(string $method): array {
        return $this->registry()->register([
            'redirect_uris' => ['https://claude.ai/cb'],
            'token_endpoint_auth_method' => $method,
        ], '198.51.100.1');
    }

    public function testAuthenticateEachMethod() {
        // :: Setup
        $registry = $this->registry();
        $post = $this->registerWithMethod('client_secret_post');
        $basic = $this->registerWithMethod('client_secret_basic');
        $none = $this->registerWithMethod('none');
        // Basic credentials are form-urlencoded before base64 (RFC 6749 section 2.3.1).
        $header = 'Basic ' . base64_encode(urlencode($basic['client_id']) . ':' . urlencode($basic['client_secret']));

        // :: Act
        $viaPost = $registry->authenticate(['client_id' => $post['client_id'], 'client_secret' => $post['client_secret']], []);
        $viaPhpAuth = $registry->authenticate([], ['PHP_AUTH_USER' => $basic['client_id'], 'PHP_AUTH_PW' => $basic['client_secret']]);
        $viaHeader = $registry->authenticate([], ['HTTP_AUTHORIZATION' => $header]);
        $viaNone = $registry->authenticate(['client_id' => $none['client_id']], []);

        // :: Assert
        $this->assertEquals($post['client_id'], $viaPost['client_id']);
        $this->assertEquals($basic['client_id'], $viaPhpAuth['client_id']);
        $this->assertEquals($basic['client_id'], $viaHeader['client_id']);
        $this->assertEquals($none['client_id'], $viaNone['client_id']);
    }

    public function testAuthenticateBasicDecodesPercentEncodedCredentials() {
        // :: Setup
        $registry = $this->registry();
        $basic = $this->registerWithMethod('client_secret_basic');
        $header = 'Basic ' . base64_encode(rawurlencode($basic['client_id']) . ':' . rawurlencode($basic['client_secret']));

        // :: Act
        $client = $registry->authenticate([], ['HTTP_AUTHORIZATION' => $header]);

        // :: Assert
        $this->assertEquals($basic['client_id'], $client['client_id']);
    }

    public function testAuthenticateRejectsWrongSecretAndMethodMismatch() {
        // :: Setup
        $registry = $this->registry();
        $post = $this->registerWithMethod('client_secret_post');
        $basic = $this->registerWithMethod('client_secret_basic');
        $none = $this->registerWithMethod('none');
        $basicHeader = fn(string $id, string $secret) => ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode("$id:$secret")];

        // :: Act / Assert
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => $post['client_id'], 'client_secret' => 'wrong'], []), 'wrong post secret');
        $this->assertInvalidClient(fn() => $registry->authenticate([], $basicHeader($basic['client_id'], 'wrong')), 'wrong basic secret');
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => 'unknown', 'client_secret' => 'x'], []), 'unknown client');
        $this->assertInvalidClient(fn() => $registry->authenticate([], []), 'no credentials');
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => $post['client_id']], []), 'secret client using none');
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => $basic['client_id']], []), 'basic client using none');
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => $none['client_id'], 'client_secret' => 'x'], []), 'none client sending a secret');
        $this->assertInvalidClient(fn() => $registry->authenticate([], $basicHeader($none['client_id'], 'x')), 'none client sending basic');
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => $basic['client_id'], 'client_secret' => $basic['client_secret']], []), 'basic client using post');
        $this->assertInvalidClient(fn() => $registry->authenticate([], $basicHeader($post['client_id'], $post['client_secret'])), 'post client using basic');
        $this->assertInvalidClient(fn() => $registry->authenticate([], ['HTTP_AUTHORIZATION' => 'Basic !!!notbase64']), 'garbage header');
        $this->assertInvalidClient(fn() => $registry->authenticate([], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('nocolon')]), 'header without colon');
        $this->assertInvalidClient(fn() => $registry->authenticate(['client_id' => ['x'], 'client_secret' => 'y'], []), 'array client_id');
    }
}
