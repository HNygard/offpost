<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../class/Database.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once __DIR__ . '/common/OAuthE2E.php';
require_once __DIR__ . '/common/OAuthTestFlow.php';

class ConnectedAppsPageTest extends TestCase {
    const OTHER_USER = 'other-user';

    protected function setUp(): void {
        OAuthE2E::clearRegistrationRateLimit();
        Database::execute('DELETE FROM oauth_access_tokens WHERE user_id IN (?, ?)', ['dev-user-id', self::OTHER_USER]);
    }

    private function get(string $cookie): array {
        return OAuthTestFlow::request('GET', '/connected-apps', null, ['Cookie: ' . $cookie]);
    }

    private function postRevoke(string $cookie, array $fields): array {
        return OAuthTestFlow::request('POST', '/connected-apps/revoke', http_build_query($fields), [
            'Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /** @return array{0: string, 1: string} token_id and csrf of the revoke form on the row for $escapedClientName */
    private function revokeFormFor(string $body, string $escapedClientName): array {
        $pattern = '/<td>' . preg_quote($escapedClientName, '/') . '<\/td>.*?name="token_id" value="(\d+)".*?name="csrf" value="([0-9a-f]+)"/s';
        $this->assertEquals(1, preg_match($pattern, $body, $m), "No row for $escapedClientName in: $body");
        return [$m[1], $m[2]];
    }

    private function mcpPing(string $token): array {
        return OAuthTestFlow::request('POST', '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
    }

    public function testEmptyStateAndSecurityHeaders(): void {
        // :: Setup
        $cookie = OAuthTestFlow::loginCookie();

        // :: Act
        $page = $this->get($cookie);

        // :: Assert
        $this->assertEquals(200, $page['status'], $page['body']);
        $this->assertStringContainsString('<p>No connected apps.</p>', $page['body']);
        $this->assertStringContainsString('x-frame-options: deny', $page['headers']);
        $this->assertStringContainsString("content-security-policy: frame-ancestors 'none'", $page['headers']);
        $this->assertStringContainsString('cache-control: no-store', $page['headers']);
    }

    public function testListsTokenWithEscapedClientName(): void {
        // :: Setup
        OAuthTestFlow::connect('dev-user-id', false, '<b>x</b>');
        $cookie = OAuthTestFlow::loginCookie();

        // :: Act
        $page = $this->get($cookie);

        // :: Assert
        $this->assertEquals(200, $page['status'], $page['body']);
        $this->assertStringContainsString('<td>&lt;b&gt;x&lt;/b&gt;</td>', $page['body']);
        $this->assertStringNotContainsString('<b>x</b>', $page['body']);
        $this->assertStringNotContainsString('No connected apps.', $page['body']);
    }

    public function testRevokeRemovesTokenAndMcpReturns401(): void {
        // :: Setup
        $connection = OAuthTestFlow::connect('dev-user-id', false, 'e2e revoke me');
        $cookie = OAuthTestFlow::loginCookie();
        [$tokenId, $csrf] = $this->revokeFormFor($this->get($cookie)['body'], 'e2e revoke me');
        $before = $this->mcpPing($connection['access_token']);

        // :: Act
        $revoke = $this->postRevoke($cookie, ['token_id' => $tokenId, 'csrf' => $csrf]);
        $after = $this->mcpPing($connection['access_token']);
        $page = $this->get($cookie);

        // :: Assert
        $this->assertEquals(200, $before['status'], $before['body']);
        $this->assertEquals(303, $revoke['status'], $revoke['raw_headers'] . $revoke['body']);
        $this->assertEquals(1, preg_match('/^location: \/connected-apps\r?$/mi', $revoke['raw_headers']), $revoke['raw_headers']);
        $this->assertEquals(401, $after['status'], $after['body']);
        $this->assertStringNotContainsString('e2e revoke me', $page['body']);
    }

    public function testRevokeWithoutCsrfIs400AndKeepsToken(): void {
        // :: Setup
        $connection = OAuthTestFlow::connect('dev-user-id', false, 'e2e no csrf');
        $cookie = OAuthTestFlow::loginCookie();
        [$tokenId] = $this->revokeFormFor($this->get($cookie)['body'], 'e2e no csrf');

        // :: Act
        $revoke = $this->postRevoke($cookie, ['token_id' => $tokenId]);
        $wrongCsrf = $this->postRevoke($cookie, ['token_id' => $tokenId, 'csrf' => 'not-the-session-token']);
        $after = $this->mcpPing($connection['access_token']);

        // :: Assert
        $this->assertEquals(400, $revoke['status'], $revoke['body']);
        $this->assertEquals(400, $wrongCsrf['status'], $wrongCsrf['body']);
        $this->assertEquals(200, $after['status'], 'Token must still work: ' . $after['body']);
    }

    public function testCannotRevokeAnotherUsersToken(): void {
        // :: Setup
        // ?test-authenticate only knows the dev user, so the other user's token is inserted directly.
        $connection = OAuthTestFlow::connect('dev-user-id', false, 'e2e other user client');
        $otherHash = str_repeat('a', 64);
        Database::execute(
            'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, resource) VALUES (?, ?, ?, ?)',
            [$otherHash, $connection['client_id'], self::OTHER_USER, 'http://localhost:25081/mcp']
        );
        $otherTokenId = Database::queryValue('SELECT id FROM oauth_access_tokens WHERE token_hash = ?', [$otherHash]);
        $cookie = OAuthTestFlow::loginCookie();
        [, $csrf] = $this->revokeFormFor($this->get($cookie)['body'], 'e2e other user client');

        // :: Act
        $revoke = $this->postRevoke($cookie, ['token_id' => (string) $otherTokenId, 'csrf' => $csrf]);

        // :: Assert
        $remaining = Database::queryValue('SELECT COUNT(*) FROM oauth_access_tokens WHERE id = ?', [$otherTokenId]);
        $this->assertEquals(303, $revoke['status'], $revoke['raw_headers'] . $revoke['body']);
        $this->assertEquals(1, $remaining, "Other user's token must survive the dev user's revoke POST");
    }
}
