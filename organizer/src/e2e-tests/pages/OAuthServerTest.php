<?php
// Metadata and dynamic client registration over HTTP against the dev stack.
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../class/OAuth/OAuthCrypto.php';
require_once __DIR__ . '/common/OAuthE2E.php';

class OAuthServerTest extends TestCase {
    const BASE = 'http://localhost:25081';

    protected function setUp(): void {
        OAuthE2E::clearRegistrationRateLimit();
    }

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
        return ['status' => $status, 'headers' => strtolower($rawHeaders), 'raw_headers' => $rawHeaders, 'body' => $body, 'json' => json_decode($body, true)];
    }

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /** PHPSESSID cookie of a logged-in dev user, obtained like E2EPageTestCase does. */
    private function loginCookie(): string {
        $ch = curl_init(self::BASE . '?test-authenticate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => tempnam(sys_get_temp_dir(), 'cookie'),
        ]);
        $response = curl_exec($ch);
        $rawHeaders = substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        curl_close($ch);
        $this->assertEquals(1, preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/mi', $rawHeaders, $m), $rawHeaders);
        return $m[1];
    }

    private function registerClient(string $name): string {
        $response = $this->register(['client_name' => $name, 'redirect_uris' => [self::REDIRECT]]);
        $this->assertEquals(201, $response['status'], $response['body']);
        return $response['json']['client_id'];
    }

    private function authorizeQuery(string $clientId, array $override = []): string {
        return '/oauth/authorize?' . http_build_query(array_merge([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 'st-1',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ], $override));
    }

    /** GET the consent page and return [response, pending_id, csrf]. */
    private function openConsentPage(string $cookie, string $clientId): array {
        $page = $this->request('GET', $this->authorizeQuery($clientId), null, ['Cookie: ' . $cookie]);
        $this->assertEquals(200, $page['status'], $page['body']);
        $this->assertEquals(1, preg_match('/name="pending_id" value="([0-9a-f]+)"/', $page['body'], $p), $page['body']);
        $this->assertEquals(1, preg_match('/name="csrf" value="([0-9a-f]+)"/', $page['body'], $c), $page['body']);
        return [$page, $p[1], $c[1]];
    }

    private function postDecision(string $cookie, array $fields): array {
        return $this->request('POST', '/oauth/authorize', http_build_query($fields), [
            'Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    private function codeCount(string $clientId): int {
        return (int) Database::queryValue('SELECT count(*) FROM oauth_authorization_codes WHERE client_id = ?', [$clientId]);
    }

    private function location(array $response): string {
        $this->assertEquals(1, preg_match('/^location: (.*)$/mi', $response['raw_headers'], $m), $response['raw_headers']);
        return trim($m[1]);
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

    public function testConsentPageShowsEscapedClientNameAndSecurityHeaders() {
        // :: Setup
        $cookie = $this->loginCookie();
        $clientId = $this->registerClient('<script>alert("x")</script>');

        // :: Act
        [$page] = $this->openConsentPage($cookie, $clientId);

        // :: Assert
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; wants to access your Offpost account', $page['body']);
        $this->assertStringNotContainsString('<script>alert', $page['body']);
        $this->assertStringContainsString('redirects to: claude.ai', strtolower($page['body']));
        $this->assertStringContainsString('x-frame-options: deny', $page['headers']);
        $this->assertStringContainsString("content-security-policy: frame-ancestors 'none'", $page['headers']);
        $this->assertStringContainsString('referrer-policy: no-referrer', $page['headers']);
        $this->assertStringContainsString('cache-control: no-store', $page['headers']);
    }

    public function testDenyRedirectsWithAccessDeniedStateAndIss() {
        // :: Setup
        $cookie = $this->loginCookie();
        $clientId = $this->registerClient('Deny client');
        [, $pendingId, $csrf] = $this->openConsentPage($cookie, $clientId);

        // :: Act
        $response = $this->postDecision($cookie, ['pending_id' => $pendingId, 'csrf' => $csrf, 'decision' => 'deny']);

        // :: Assert
        $this->assertEquals(302, $response['status'], $response['body']);
        $this->assertEquals(self::REDIRECT . '?error=access_denied&state=st-1&iss=http%3A%2F%2Flocalhost%3A25081', $this->location($response));
        $this->assertEquals(0, $this->codeCount($clientId));
    }

    public function testApproveRedirectsWithCodeStateAndIss() {
        // :: Setup
        $cookie = $this->loginCookie();
        $clientId = $this->registerClient('Approve client');
        [, $pendingId, $csrf] = $this->openConsentPage($cookie, $clientId);

        // :: Act
        $response = $this->postDecision($cookie, ['pending_id' => $pendingId, 'csrf' => $csrf, 'decision' => 'approve']);

        // :: Assert
        $this->assertEquals(302, $response['status'], $response['body']);
        $location = $this->location($response);
        $this->assertEquals(1, preg_match('#^' . preg_quote(self::REDIRECT, '#') . '\?code=([A-Za-z0-9_-]{43})&state=st-1&iss=http%3A%2F%2Flocalhost%3A25081$#', $location, $m), $location);
        $this->assertEquals(1, Database::queryValue(
            'SELECT count(*) FROM oauth_authorization_codes WHERE client_id = ? AND code_hash = ?',
            [$clientId, OAuthCrypto::hash($m[1])]
        ));
    }

    public function testWrongCsrfGives400AndNoCode() {
        // :: Setup
        $cookie = $this->loginCookie();
        $clientId = $this->registerClient('Csrf client');
        [, $pendingId] = $this->openConsentPage($cookie, $clientId);

        // :: Act
        $response = $this->postDecision($cookie, ['pending_id' => $pendingId, 'csrf' => 'wrong', 'decision' => 'approve']);

        // :: Assert
        $this->assertEquals(400, $response['status'], $response['body']);
        $this->assertStringNotContainsString('location:', $response['headers']);
        $this->assertEquals(0, $this->codeCount($clientId));
    }

    public function testReusedPendingIdGives400AndOnlyOneCode() {
        // :: Setup
        $cookie = $this->loginCookie();
        $clientId = $this->registerClient('Replay client');
        [, $pendingId, $csrf] = $this->openConsentPage($cookie, $clientId);
        $fields = ['pending_id' => $pendingId, 'csrf' => $csrf, 'decision' => 'approve'];
        $first = $this->postDecision($cookie, $fields);

        // :: Act
        $second = $this->postDecision($cookie, $fields);

        // :: Assert
        $this->assertEquals(302, $first['status'], $first['body']);
        $this->assertEquals(400, $second['status'], $second['body']);
        $this->assertStringNotContainsString('location:', $second['headers']);
        $this->assertEquals(1, $this->codeCount($clientId));
    }

    public function testPendingIdFromAnotherSessionGives400AndNoCode() {
        // :: Setup
        $cookieA = $this->loginCookie();
        $cookieB = $this->loginCookie();
        $clientId = $this->registerClient('Cross session client');
        [, $pendingIdA] = $this->openConsentPage($cookieA, $clientId);
        [, , $csrfB] = $this->openConsentPage($cookieB, $clientId);

        // :: Act
        $response = $this->postDecision($cookieB, ['pending_id' => $pendingIdA, 'csrf' => $csrfB, 'decision' => 'approve']);

        // :: Assert
        $this->assertEquals(400, $response['status'], $response['body']);
        $this->assertStringNotContainsString('location:', $response['headers']);
        $this->assertEquals(0, $this->codeCount($clientId));
    }

    public function testUnknownClientGives400PageWithoutLocation() {
        // :: Act
        $response = $this->request('GET', $this->authorizeQuery('no-such-client'));

        // :: Assert
        $this->assertEquals(400, $response['status'], $response['body']);
        $this->assertStringNotContainsString('location:', $response['headers']);
        $this->assertStringContainsString('content-type: text/html', $response['headers']);
    }

    public function testInvalidRequestWithValidClientRedirectsWithErrorBeforeLogin() {
        // :: Setup
        $clientId = $this->registerClient('Invalid request client');

        // :: Act
        $response = $this->request('GET', $this->authorizeQuery($clientId, ['response_type' => 'token']));

        // :: Assert
        $this->assertEquals(302, $response['status'], $response['body']);
        $this->assertStringStartsWith(self::REDIRECT . '?error=unsupported_response_type&', $this->location($response));
    }

    /** Registers a client and returns the registration response (including client_secret unless method is none). */
    private function registerWithMethod(string $name, string $method): array {
        $response = $this->register([
            'client_name' => $name,
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => $method,
        ]);
        $this->assertEquals(201, $response['status'], $response['body']);
        return $response['json'];
    }

    /** Runs login + consent for the client and returns the authorization code. */
    private function approveAndGetCode(string $clientId): string {
        $cookie = $this->loginCookie();
        [, $pendingId, $csrf] = $this->openConsentPage($cookie, $clientId);
        $response = $this->postDecision($cookie, ['pending_id' => $pendingId, 'csrf' => $csrf, 'decision' => 'approve']);
        $this->assertEquals(302, $response['status'], $response['body']);
        $this->assertEquals(1, preg_match('/[?&]code=([A-Za-z0-9_-]{43})/', $this->location($response), $m), $response['raw_headers']);
        return $m[1];
    }

    private function postForm(string $path, array $fields, array $headers = []): array {
        return $this->request('POST', $path, http_build_query($fields), array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers));
    }

    private function tokenFields(string $code, array $override = []): array {
        return array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ], $override);
    }

    public function testCodeToTokenFlowAndRevoke() {
        // :: Setup
        $client = $this->registerWithMethod('Token client', 'client_secret_post');
        $code = $this->approveAndGetCode($client['client_id']);
        $auth = ['client_id' => $client['client_id'], 'client_secret' => $client['client_secret']];

        // :: Act
        $token = $this->postForm('/oauth/token', $this->tokenFields($code) + $auth);
        $reuse = $this->postForm('/oauth/token', $this->tokenFields($code) + $auth);

        // :: Assert
        $this->assertEquals(200, $token['status'], $token['body']);
        $this->assertEquals(['access_token', 'token_type', 'expires_in', 'scope'], array_keys($token['json']));
        $this->assertMatchesRegularExpression('/^opmcp_[A-Za-z0-9_-]{43}$/', $token['json']['access_token']);
        $this->assertEquals(['Bearer', 15552000, 'mcp'], [$token['json']['token_type'], $token['json']['expires_in'], $token['json']['scope']]);
        $this->assertStringContainsString('cache-control: no-store', $token['headers']);
        $this->assertStringContainsString('pragma: no-cache', $token['headers']);
        $this->assertEquals(400, $reuse['status'], $reuse['body']);
        $this->assertEquals('invalid_grant', $reuse['json']['error']);
        $this->assertEquals(0, Database::queryValue('SELECT count(*) FROM oauth_access_tokens WHERE client_id = ?', [$client['client_id']]), 'reuse revoked the token');
    }

    public function testTokenEndpointWithBasicAuthAndRevoke() {
        // :: Setup
        $client = $this->registerWithMethod('Basic client', 'client_secret_basic');
        $code = $this->approveAndGetCode($client['client_id']);
        $basic = 'Authorization: Basic ' . base64_encode(urlencode($client['client_id']) . ':' . urlencode($client['client_secret']));

        // :: Act
        $token = $this->postForm('/oauth/token', $this->tokenFields($code), [$basic]);
        $revoke = $this->postForm('/oauth/revoke', ['token' => $token['json']['access_token'] ?? '', 'token_type_hint' => 'access_token'], [$basic]);
        $revokeAgain = $this->postForm('/oauth/revoke', ['token' => $token['json']['access_token'] ?? ''], [$basic]);

        // :: Assert
        $this->assertEquals(200, $token['status'], $token['body']);
        $this->assertEquals(200, $revoke['status'], $revoke['body']);
        $this->assertEquals('', $revoke['body']);
        $this->assertEquals(200, $revokeAgain['status'], 'unknown tokens are not an error (RFC 7009)');
        $this->assertEquals(0, Database::queryValue('SELECT count(*) FROM oauth_access_tokens WHERE client_id = ?', [$client['client_id']]));
    }

    public function testTokenWithoutClientAuthGives401InvalidClient() {
        // :: Setup
        $client = $this->registerWithMethod('Secret client', 'client_secret_post');
        $code = $this->approveAndGetCode($client['client_id']);

        // :: Act
        $response = $this->postForm('/oauth/token', $this->tokenFields($code, ['client_id' => $client['client_id']]));

        // :: Assert
        $this->assertEquals(401, $response['status'], $response['body']);
        $this->assertEquals('invalid_client', $response['json']['error']);
        $this->assertStringContainsString('cache-control: no-store', $response['headers']);
    }

    public function testInvalidClientWithBasicAuthCarriesWwwAuthenticate() {
        // :: Setup
        $client = $this->registerWithMethod('Basic client 2', 'client_secret_basic');
        $basic = 'Authorization: Basic ' . base64_encode($client['client_id'] . ':wrong');

        // :: Act
        $response = $this->postForm('/oauth/token', ['grant_type' => 'authorization_code'], [$basic]);

        // :: Assert
        $this->assertEquals(401, $response['status'], $response['body']);
        $this->assertStringContainsString('www-authenticate: basic realm="offpost"', $response['headers']);
    }

    public function testUnsupportedGrantTypeAndGetMethod() {
        // :: Setup
        $client = $this->registerWithMethod('Grant client', 'client_secret_post');
        $auth = ['client_id' => $client['client_id'], 'client_secret' => $client['client_secret']];

        // :: Act
        $password = $this->postForm('/oauth/token', ['grant_type' => 'password', 'username' => 'a', 'password' => 'b'] + $auth);
        $get = $this->request('GET', '/oauth/token');

        // :: Assert
        $this->assertEquals(400, $password['status'], $password['body']);
        $this->assertEquals('unsupported_grant_type', $password['json']['error']);
        $this->assertEquals(405, $get['status'], $get['body']);
        $this->assertStringContainsString('content-type: application/json', $get['headers']);
    }

    public function testRevokeWithoutClientAuthGives401() {
        // :: Setup / Act
        $response = $this->postForm('/oauth/revoke', ['token' => 'opmcp_x']);

        // :: Assert
        $this->assertEquals(401, $response['status'], $response['body']);
        $this->assertEquals('invalid_client', $response['json']['error']);
    }
}
