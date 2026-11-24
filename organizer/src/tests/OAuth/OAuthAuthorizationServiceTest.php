<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/OAuth/OAuthAuthorizationService.php';

class OAuthAuthorizationServiceTest extends TestCase {
    private const NOW = 1700000000;
    private const ISSUER = 'http://localhost:25081';
    private const RESOURCE = 'http://localhost:25081/mcp';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private OAuthClientRegistry $registry;
    private array $client;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->registry = new OAuthClientRegistry(['claude.ai', 'claude.com'], fn(): int => self::NOW);
        $registered = $this->registry->register([
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ], '198.51.100.1');
        $this->client = $this->registry->find($registered['client_id']);
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function service(): OAuthAuthorizationService {
        return new OAuthAuthorizationService(self::ISSUER, self::RESOURCE, $this->registry, fn(): int => self::NOW);
    }

    private function validQuery(array $override = []): array {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $this->client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ], $override);
    }

    private function validateExpectingError(array $query): OAuthError {
        try {
            $this->service()->validate($query);
        } catch (OAuthError $e) {
            return $e;
        }
        $this->fail('Expected OAuthError for ' . json_encode($query));
    }

    public function testValidRequestReturnsNormalizedParameters() {
        // :: Act
        $validated = $this->service()->validate($this->validQuery());

        // :: Assert
        $this->assertEquals([
            'client' => $this->client,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => self::CHALLENGE,
            'resource' => self::RESOURCE,
            'scope' => 'mcp',
        ], $validated);
    }

    public function testExplicitResourceAndScopeAndMissingStateAreAccepted() {
        // :: Act
        $validated = $this->service()->validate($this->validQuery([
            'resource' => self::RESOURCE,
            'scope' => 'mcp',
            'state' => null,
        ]));

        // :: Assert
        $this->assertEquals(self::RESOURCE, $validated['resource']);
        $this->assertNull($validated['state']);
    }

    public function testUnknownClientIsNotRedirectable() {
        // :: Act
        $error = $this->validateExpectingError($this->validQuery(['client_id' => 'no-such-client']));

        // :: Assert
        $this->assertEquals('invalid_request', $error->error);
        $this->assertNotInstanceOf(OAuthRedirectError::class, $error);
    }

    public function testMalformedClientIdIsNotRedirectable() {
        // :: Act
        $invalidUtf8 = $this->validateExpectingError($this->validQuery(['client_id' => "abc\xff"]));
        $tooLong = $this->validateExpectingError($this->validQuery(['client_id' => str_repeat('a', 65)]));

        // :: Assert
        $this->assertEquals(['invalid_request', 'Unknown client_id'], [$invalidUtf8->error, $invalidUtf8->getMessage()]);
        $this->assertEquals(['invalid_request', 'Unknown client_id'], [$tooLong->error, $tooLong->getMessage()]);
        $this->assertNotInstanceOf(OAuthRedirectError::class, $invalidUtf8);
        $this->assertNotInstanceOf(OAuthRedirectError::class, $tooLong);
    }

    public function testUnregisteredRedirectUriIsNotRedirectable() {
        // :: Act
        $error = $this->validateExpectingError($this->validQuery(['redirect_uri' => 'https://claude.ai/other']));

        // :: Assert
        $this->assertEquals('invalid_request', $error->error);
        $this->assertNotInstanceOf(OAuthRedirectError::class, $error);
    }

    public function testMissingRedirectUriIsNotRedirectable() {
        // :: Act
        $error = $this->validateExpectingError($this->validQuery(['redirect_uri' => null]));

        // :: Assert
        $this->assertNotInstanceOf(OAuthRedirectError::class, $error);
    }

    public function testArrayClientIdIsNotRedirectable() {
        // :: Act
        $error = $this->validateExpectingError($this->validQuery(['client_id' => ['a']]));

        // :: Assert
        $this->assertNotInstanceOf(OAuthRedirectError::class, $error);
    }

    #[DataProvider("redirectableErrors")]
    public function testOtherErrorsAreRedirectableWithStateAndRedirectUri(array $override, string $expectedError) {
        // :: Act
        $error = $this->validateExpectingError($this->validQuery($override));

        // :: Assert
        $this->assertInstanceOf(OAuthRedirectError::class, $error);
        $this->assertEquals($expectedError, $error->error, $error->getMessage());
        $this->assertEquals(self::REDIRECT, $error->redirectUri);
        $this->assertEquals('xyz', $error->state);
    }

    public static function redirectableErrors(): array {
        return [
            'response_type token' => [['response_type' => 'token'], 'unsupported_response_type'],
            'missing challenge' => [['code_challenge' => null], 'invalid_request'],
            'too short challenge' => [['code_challenge' => 'abc'], 'invalid_request'],
            'plain method' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'missing method' => [['code_challenge_method' => null], 'invalid_request'],
            'wrong resource' => [['resource' => 'https://evil.example/mcp'], 'invalid_target'],
            'scope admin' => [['scope' => 'admin'], 'invalid_scope'],
            'scope mcp plus admin' => [['scope' => 'mcp admin'], 'invalid_scope'],
        ];
    }

    public function testRedirectUrlAppendsWithQuestionMarkAndAlwaysIncludesIss() {
        // :: Act
        $url = $this->service()->redirectUrl('https://claude.ai/cb', ['code' => 'abc', 'state' => 'a b']);

        // :: Assert
        $this->assertEquals('https://claude.ai/cb?code=abc&state=a%20b&iss=http%3A%2F%2Flocalhost%3A25081', $url);
    }

    public function testRedirectUrlAppendsWithAmpersandWhenQueryExists() {
        // :: Act
        $url = $this->service()->redirectUrl('http://localhost/cb?x=1', ['error' => 'access_denied']);

        // :: Assert
        $this->assertEquals('http://localhost/cb?x=1&error=access_denied&iss=http%3A%2F%2Flocalhost%3A25081', $url);
    }

    public function testCreateCodeStoresHashWith60SecondExpiry() {
        // :: Setup
        $validated = $this->service()->validate($this->validQuery());

        // :: Act
        $code = $this->service()->createCode($validated, 'user-1');

        // :: Assert
        $this->assertEquals(43, strlen($code));
        $row = Database::queryOne(
            'SELECT *, extract(epoch from created_at)::bigint AS created, extract(epoch from expires_at)::bigint AS expires
             FROM oauth_authorization_codes WHERE code_hash = ?',
            [OAuthCrypto::hash($code)]
        );
        $this->assertEquals([
            'client_id' => $this->client['client_id'],
            'user_id' => 'user-1',
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::CHALLENGE,
            'resource' => self::RESOURCE,
            'created' => self::NOW,
            'expires' => self::NOW + 60,
            'used_at' => null,
        ], array_intersect_key($row, array_flip(['client_id', 'user_id', 'redirect_uri', 'code_challenge', 'resource', 'created', 'expires', 'used_at'])));
        $this->assertEquals(0, Database::queryValue('SELECT count(*) FROM oauth_authorization_codes WHERE code_hash = ?', [$code]), 'plain code must not be stored');
    }
}
