<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/OAuth/OAuthAuthorizationService.php';
require_once __DIR__ . '/../../class/OAuth/OAuthTokenService.php';

class OAuthTokenServiceTest extends TestCase {
    private const NOW = 1700000000;
    private const DAY = 86400;
    private const ISSUER = 'http://localhost:25081';
    private const RESOURCE = 'http://localhost:25081/mcp';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    // RFC 7636 appendix B example pair.
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private int $now = self::NOW;
    private OAuthClientRegistry $registry;
    private array $client;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->now = self::NOW;
        $this->registry = new OAuthClientRegistry(['claude.ai', 'claude.com'], fn(): int => $this->now);
        $this->client = $this->registerClient('Claude');
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function registerClient(string $name): array {
        $registered = $this->registry->register(['client_name' => $name, 'redirect_uris' => [self::REDIRECT]], '198.51.100.1');
        return $this->registry->find($registered['client_id']);
    }

    private function tokens(): OAuthTokenService {
        return new OAuthTokenService(self::RESOURCE, fn(): int => $this->now);
    }

    private function newCode(?array $client = null, string $userId = 'user-1'): string {
        $authorization = new OAuthAuthorizationService(self::ISSUER, self::RESOURCE, $this->registry, fn(): int => $this->now);
        return $authorization->createCode([
            'client' => $client ?? $this->client,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::CHALLENGE,
            'resource' => self::RESOURCE,
        ], $userId);
    }

    private function post(string $code, array $override = []): array {
        return array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ], $override);
    }

    private function issueToken(?array $client = null, string $userId = 'user-1'): string {
        $client ??= $this->client;
        return $this->tokens()->redeemCode($client, $this->post($this->newCode($client, $userId)))['access_token'];
    }

    private function redeemExpectingError(array $client, array $post): OAuthError {
        try {
            $this->tokens()->redeemCode($client, $post);
        } catch (OAuthError $e) {
            return $e;
        }
        $this->fail('Expected OAuthError for ' . json_encode($post));
    }

    private function tokenCount(): int {
        return (int) Database::queryValue('SELECT count(*) FROM oauth_access_tokens');
    }

    public function testRedeemIssuesOpaqueToken() {
        // :: Setup
        $code = $this->newCode();

        // :: Act
        $response = $this->tokens()->redeemCode($this->client, $this->post($code));

        // :: Assert
        $token = $response['access_token'];
        $this->assertEquals(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 15552000, 'scope' => 'mcp'], $response);
        $this->assertMatchesRegularExpression('/^opmcp_[A-Za-z0-9_-]{43}$/', $token);
        $rows = Database::query('SELECT token_hash, user_id, client_id, resource FROM oauth_access_tokens');
        $this->assertEquals([[
            'token_hash' => hash('sha256', $token),
            'user_id' => 'user-1',
            'client_id' => $this->client['client_id'],
            'resource' => self::RESOURCE,
        ]], $rows, 'only the hash of the token is stored');
        $this->assertEquals('user-1', $this->tokens()->userIdForAccessToken($token));
    }

    public function testRedeemExplicitMatchingResourceIsAccepted() {
        // :: Setup
        $code = $this->newCode();

        // :: Act
        $response = $this->tokens()->redeemCode($this->client, $this->post($code, ['resource' => self::RESOURCE]));

        // :: Assert
        $this->assertEquals('Bearer', $response['token_type']);
    }

    public function testCodeExpiresAfter60s() {
        // :: Setup
        $fresh = $this->newCode();
        $expired = $this->newCode();

        // :: Act
        $this->now = self::NOW + 59;
        $response = $this->tokens()->redeemCode($this->client, $this->post($fresh));
        $this->now = self::NOW + 60;
        $error = $this->redeemExpectingError($this->client, $this->post($expired));

        // :: Assert
        $this->assertEquals('Bearer', $response['token_type'], 'code redeemed 59 s after creation');
        $this->assertEquals('invalid_grant', $error->error, 'code redeemed 60 s after creation');
        $this->assertEquals(400, $error->httpStatus);
    }

    public function testCodeReuseFailsAndRevokesIssuedToken() {
        // :: Setup
        $code = $this->newCode();
        $token = $this->tokens()->redeemCode($this->client, $this->post($code))['access_token'];
        $this->assertEquals('user-1', $this->tokens()->userIdForAccessToken($token), 'token works before the reuse');

        // :: Act
        $error = $this->redeemExpectingError($this->client, $this->post($code));

        // :: Assert
        $this->assertEquals('invalid_grant', $error->error);
        $this->assertNull($this->tokens()->userIdForAccessToken($token), 'token issued from the reused code');
        $this->assertEquals(0, $this->tokenCount());
    }

    public function testCodeReuseKeepsTokensFromOtherCodes() {
        // :: Setup
        $otherToken = $this->issueToken();
        $code = $this->newCode();
        $this->tokens()->redeemCode($this->client, $this->post($code));

        // :: Act
        $this->redeemExpectingError($this->client, $this->post($code));

        // :: Assert
        $this->assertEquals('user-1', $this->tokens()->userIdForAccessToken($otherToken));
    }

    /**
     * Two real connections are not available, so the losing side of a race is simulated:
     * another process already burned the code and issued a token between our validation and
     * our UPDATE. The UPDATE ... WHERE used_at IS NULL guard then returns no row, and the
     * loser must fail and revoke what the winner issued.
     */
    public function testConcurrentRedeemOnlyOneSucceeds() {
        // :: Setup
        $code = $this->newCode();
        $winnerToken = 'opmcp_winner';
        Database::execute('UPDATE oauth_authorization_codes SET used_at = to_timestamp(?) WHERE code_hash = ?', [self::NOW, hash('sha256', $code)]);
        Database::execute(
            'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, resource, code_id)
             SELECT ?, client_id, user_id, resource, id FROM oauth_authorization_codes WHERE code_hash = ?',
            [hash('sha256', $winnerToken), hash('sha256', $code)]
        );

        // :: Act
        $error = $this->redeemExpectingError($this->client, $this->post($code));

        // :: Assert
        $this->assertEquals('invalid_grant', $error->error);
        $this->assertEquals(0, $this->tokenCount(), 'the loser must not get a token and the winner token is revoked');
    }

    public function testCodeForOtherClientFails() {
        // :: Setup
        $other = $this->registerClient('Other');
        $code = $this->newCode();

        // :: Act
        $error = $this->redeemExpectingError($other, $this->post($code));
        $retryByOwner = $this->redeemExpectingError($this->client, $this->post($code));

        // :: Assert
        $this->assertEquals('invalid_grant', $error->error, 'client B with the right verifier');
        $this->assertEquals('invalid_grant', $retryByOwner->error, 'the code is burned after the mismatch');
        $this->assertEquals(0, $this->tokenCount());
    }

    public function testRedirectUriMismatchFails() {
        // :: Setup
        $code = $this->newCode();

        // :: Act
        $error = $this->redeemExpectingError($this->client, $this->post($code, ['redirect_uri' => self::REDIRECT . '/']));

        // :: Assert
        $this->assertEquals('invalid_grant', $error->error);
        $this->assertEquals(0, $this->tokenCount());
    }

    public function testWrongVerifierFails() {
        // :: Setup
        $code = $this->newCode();
        $wrongVerifier = str_repeat('a', 43);

        // :: Act
        $error = $this->redeemExpectingError($this->client, $this->post($code, ['code_verifier' => $wrongVerifier]));
        $retry = $this->redeemExpectingError($this->client, $this->post($code));

        // :: Assert
        $this->assertEquals('invalid_grant', $error->error);
        $this->assertEquals('invalid_grant', $retry->error, 'the code is burned, even with the right verifier');
        $this->assertEquals(0, $this->tokenCount());
    }

    public function testResourceMismatchFails() {
        // :: Setup
        $code = $this->newCode();

        // :: Act
        $error = $this->redeemExpectingError($this->client, $this->post($code, ['resource' => 'http://localhost:25081/other']));

        // :: Assert
        $this->assertEquals('invalid_target', $error->error);
        $this->assertEquals('invalid_grant', $this->redeemExpectingError($this->client, $this->post($code))->error, 'the code stays burned');
        $this->assertEquals(0, $this->tokenCount());
    }

    public function testUnsupportedGrantTypeAndMissingParameters() {
        // :: Setup
        $code = $this->newCode();

        // :: Act
        $password = $this->redeemExpectingError($this->client, ['grant_type' => 'password', 'username' => 'a', 'password' => 'b']);
        $refresh = $this->redeemExpectingError($this->client, ['grant_type' => 'refresh_token', 'refresh_token' => 'x']);
        $noVerifier = $this->redeemExpectingError($this->client, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT]);
        $arrayCode = $this->redeemExpectingError($this->client, $this->post('x', ['code' => ['x']]));

        // :: Assert
        $this->assertEquals('unsupported_grant_type', $password->error);
        $this->assertEquals('unsupported_grant_type', $refresh->error);
        $this->assertEquals('invalid_request', $noVerifier->error);
        $this->assertEquals('invalid_request', $arrayCode->error);
        $this->assertEquals('Bearer', $this->tokens()->redeemCode($this->client, $this->post($code))['token_type'], 'rejected requests before validation do not burn the code');
    }

    public function testUserIdForAccessTokenSlidingWindow() {
        // :: Setup
        $atDay179 = $this->issueToken(null, 'user-1');
        $atDay181 = $this->issueToken(null, 'user-3');
        $usedAtDay100 = $this->issueToken(null, 'user-2');
        $usedAtDay100Too = $this->issueToken(null, 'user-4');

        // :: Act
        $this->now = self::NOW + 100 * self::DAY;
        $usedAtDay100Result = $this->tokens()->userIdForAccessToken($usedAtDay100);
        $this->tokens()->userIdForAccessToken($usedAtDay100Too);
        $this->now = self::NOW + 179 * self::DAY;
        $day179 = $this->tokens()->userIdForAccessToken($atDay179);
        $this->now = self::NOW + 181 * self::DAY;
        $day181 = $this->tokens()->userIdForAccessToken($atDay181);
        $this->now = self::NOW + 279 * self::DAY;
        $usedDay279 = $this->tokens()->userIdForAccessToken($usedAtDay100);
        $this->now = self::NOW + 281 * self::DAY;
        $usedDay281 = $this->tokens()->userIdForAccessToken($usedAtDay100Too);

        // :: Assert
        $this->assertEquals('user-2', $usedAtDay100Result);
        $this->assertEquals('user-1', $day179, 'idle for 179 days');
        $this->assertNull($day181, 'idle for 181 days');
        $this->assertEquals('user-2', $usedDay279, 'use at day 100 extended the window to day 280');
        $this->assertNull($usedDay281, 'idle for 181 days after the use at day 100');
    }

    public function testLastUsedTouchedAtMostHourly() {
        // :: Setup
        $token = $this->issueToken();
        $lastUsed = fn(): int => (int) Database::queryValue('SELECT extract(epoch FROM last_used_at) FROM oauth_access_tokens');

        // :: Act
        $this->now = self::NOW + 3600;
        $this->tokens()->userIdForAccessToken($token);
        $afterExactlyOneHour = $lastUsed();
        $this->now = self::NOW + 3601;
        $this->tokens()->userIdForAccessToken($token);
        $afterOverOneHour = $lastUsed();
        $this->now = self::NOW + 3601 + 3000;
        $this->tokens()->userIdForAccessToken($token);
        $afterFurther3000s = $lastUsed();

        // :: Assert
        $this->assertEquals(self::NOW, $afterExactlyOneHour);
        $this->assertEquals(self::NOW + 3601, $afterOverOneHour);
        $this->assertEquals(self::NOW + 3601, $afterFurther3000s);
    }

    public function testWrongResourceTokenRejected() {
        // :: Setup
        $token = $this->issueToken();
        $otherResource = new OAuthTokenService('http://localhost:25081/other', fn(): int => $this->now);
        Database::execute('UPDATE oauth_access_tokens SET resource = ?', ['http://localhost:25081/other']);

        // :: Act
        $atMcp = $this->tokens()->userIdForAccessToken($token);
        $atOther = $otherResource->userIdForAccessToken($token);

        // :: Assert
        $this->assertNull($atMcp, 'token stored for another resource');
        $this->assertEquals('user-1', $atOther, 'same token at the resource it was issued for');
    }

    public function testGarbageTokenRejected() {
        // :: Setup
        $token = $this->issueToken();
        $withoutPrefix = substr($token, strlen('opmcp_'));
        Database::execute('UPDATE oauth_access_tokens SET token_hash = ?', [hash('sha256', $withoutPrefix)]);

        $lookup = fn(string $t): ?string => $this->tokens()->userIdForAccessToken($t);

        // :: Act
        $results = [
            'empty' => $lookup(''),
            'prefix only' => $lookup('opmcp_'),
            'unknown' => $lookup('opmcp_unknown'),
            'hash stored without prefix' => $lookup($withoutPrefix),
            'scheme included' => $lookup('Bearer ' . $token),
        ];

        // :: Assert
        $this->assertEquals(array_fill_keys(array_keys($results), null), $results, json_encode($results, JSON_PRETTY_PRINT));
    }

    public function testRevokeByClientOnlyOwnTokens() {
        // :: Setup
        $other = $this->registerClient('Other');
        $ownToken = $this->issueToken();
        $otherToken = $this->issueToken($other, 'user-2');

        // :: Act
        $this->tokens()->revoke($other, $ownToken);
        $this->tokens()->revoke($this->client, 'opmcp_unknown');
        $stillValid = $this->tokens()->userIdForAccessToken($ownToken);
        $this->tokens()->revoke($this->client, $ownToken);

        // :: Assert
        $this->assertEquals('user-1', $stillValid, 'revoked by another client');
        $this->assertNull($this->tokens()->userIdForAccessToken($ownToken), 'revoked by its own client');
        $this->assertEquals('user-2', $this->tokens()->userIdForAccessToken($otherToken), 'the other client token is untouched');
    }

    public function testRevokeForUserOnlyOwnTokens() {
        // :: Setup
        $this->issueToken();
        $this->now = self::NOW + 10;
        $this->issueToken(null, 'user-2');
        $rows = fn(string $user): array => $this->tokens()->listForUser($user);
        $user1Id = (int) $rows('user-1')[0]['id'];
        $user2Id = (int) $rows('user-2')[0]['id'];

        // :: Act
        $foreign = $this->tokens()->revokeForUser('user-1', $user2Id);
        $own = $this->tokens()->revokeForUser('user-1', $user1Id);
        $again = $this->tokens()->revokeForUser('user-1', $user1Id);

        // :: Assert
        $this->assertFalse($foreign, 'token of another user');
        $this->assertTrue($own);
        $this->assertFalse($again, 'already revoked');
        $this->assertEquals(1, count($rows('user-2')), json_encode($rows('user-2'), JSON_PRETTY_PRINT));
        $this->assertEquals([], $rows('user-1'));
    }

    public function testListForUserNewestFirstWithClientName() {
        // :: Setup
        $other = $this->registerClient('Other');
        $this->issueToken();
        $this->now = self::NOW + 10;
        $this->issueToken($other);
        $this->issueToken($this->client, 'user-2');

        // :: Act
        $list = $this->tokens()->listForUser('user-1');

        // :: Assert
        $this->assertEquals(['Other', 'Claude'], array_column($list, 'client_name'), json_encode($list, JSON_PRETTY_PRINT));
        $this->assertEquals(['id', 'client_name', 'created_at', 'last_used_at'], array_keys($list[0]));
    }

    public function testIdleWindowBoundary() {
        // :: Setup
        $atLimit = $this->issueToken(null, 'user-1');
        $justInside = $this->issueToken(null, 'user-2');

        // :: Act
        $this->now = self::NOW + 180 * self::DAY - 1;
        $inside = $this->tokens()->userIdForAccessToken($justInside);
        $this->now = self::NOW + 180 * self::DAY;
        $limit = $this->tokens()->userIdForAccessToken($atLimit);

        // :: Assert
        $this->assertEquals('user-2', $inside, 'idle for 180 days minus 1 s');
        $this->assertNull($limit, 'idle for exactly 180 days');
    }

    public function testCleanupDeletesExpiredAndStale() {
        // :: Setup
        $clientB = $this->registerClient('Idle');
        $this->registerClient('Never used');
        $this->issueToken();
        $this->issueToken($clientB, 'user-2');
        $cleanupTime = self::NOW + 40 * self::DAY;
        // Claude: token used recently (kept). Idle: token idle for exactly 180 days (deleted);
        // its code was used at NOW, 40 days ago, so it is also stale.
        Database::execute('UPDATE oauth_access_tokens SET last_used_at = to_timestamp(?) WHERE user_id = ?', [$cleanupTime - self::DAY, 'user-1']);
        Database::execute('UPDATE oauth_access_tokens SET last_used_at = to_timestamp(?) WHERE user_id = ?', [$cleanupTime - 180 * self::DAY, 'user-2']);
        $this->now = $cleanupTime;
        $this->registerClient('Fresh');
        $this->newCode(null);

        // :: Act
        $counts = $this->tokens()->cleanup();

        // :: Assert
        $this->assertEquals([
            'tokens' => 1,
            'unused_clients' => 1,
            'stale_clients' => 1,
            'codes' => 1,
            'registration_attempts' => 3,
        ], $counts, 'the old used code of Claude is deleted, the codes of Idle go with the client');
        $clients = Database::query('SELECT client_name FROM oauth_clients ORDER BY client_name');
        $this->assertEquals([['client_name' => 'Claude'], ['client_name' => 'Fresh']], $clients, json_encode($clients, JSON_PRETTY_PRINT));
        $this->assertEquals(1, $this->tokenCount());
        $this->assertEquals(1, (int) Database::queryValue('SELECT count(*) FROM oauth_registration_attempts'), 'only the attempt of Fresh is younger than a day');
        $this->assertEquals(1, (int) Database::queryValue('SELECT count(*) FROM oauth_authorization_codes'), 'only the unexpired code');
    }

    public function testCleanupKeepsClientWithRevokedTokenFor30Days() {
        // :: Setup
        $this->now = self::NOW + 9 * self::DAY;
        $token = $this->issueToken();
        $this->tokens()->revoke($this->client, $token);

        // :: Act
        $this->now = self::NOW + 12 * self::DAY;
        $day12 = $this->tokens()->cleanup();
        $keptAtDay12 = $this->registry->find($this->client['client_id']) !== null;
        $this->now = self::NOW + 40 * self::DAY;
        $day40 = $this->tokens()->cleanup();
        $keptAtDay40 = $this->registry->find($this->client['client_id']) !== null;

        // :: Assert
        $this->assertTrue($keptAtDay12, json_encode($day12));
        $this->assertEquals(0, $day12['unused_clients'] + $day12['stale_clients']);
        $this->assertFalse($keptAtDay40, json_encode($day40));
        $this->assertEquals(1, $day40['stale_clients']);
    }
}
