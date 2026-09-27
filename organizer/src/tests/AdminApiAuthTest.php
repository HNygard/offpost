<?php
// organizer/src/tests/AdminApiAuthTest.php
use PHPUnit\Framework\TestCase;

// Defines PHPUNIT_RUNNING, which username-password.php (required transitively
// via npApiIsAdminSession()) checks to avoid loading the production-only
// /username-password-override.php.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../api/admin/admin-api-auth.php';

class AdminApiAuthTest extends TestCase {
    private string $tokenFile;

    protected function setUp(): void {
        $this->tokenFile = sys_get_temp_dir() . '/admin_api_token_test_' . uniqid();
        file_put_contents($this->tokenFile, "admin-secret-token\n");
        putenv('ADMIN_API_TOKEN_FILE=' . $this->tokenFile);

        // npApiIsAdminSession() calls session_start() (via auth.php) the first
        // time it runs if no session is active yet - which replaces $_SESSION
        // with the (empty, for a fresh CLI session) stored session data,
        // wiping out anything a test set beforehand. Starting the session here,
        // before any test sets $_SESSION['user'], avoids that.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void {
        @unlink($this->tokenFile);
        putenv('ADMIN_API_TOKEN_FILE');
        putenv('NP_API_TOKEN_FILE');
        unset($_SESSION['user']);
    }

    // --- adminApiCheckToken() ---

    public function testCorrectTokenAccepted(): void {
        $this->assertTrue(adminApiCheckToken('admin-secret-token'));
    }

    public function testWrongTokenRejected(): void {
        $this->assertFalse(adminApiCheckToken('wrong-token'));
    }

    public function testNullTokenRejected(): void {
        $this->assertFalse(adminApiCheckToken(null));
    }

    public function testEmptyProvidedTokenRejected(): void {
        $this->assertFalse(adminApiCheckToken(''));
    }

    // --- The expected token being empty must reject everything (missing file,
    // empty file, whitespace-only file), not just the "correct" comparison. ---

    public function testMissingTokenFileRejectsEverything(): void {
        putenv('ADMIN_API_TOKEN_FILE=' . $this->tokenFile . '-does-not-exist');
        $this->assertFalse(adminApiCheckToken('anything'));
        $this->assertFalse(adminApiCheckToken(''));
    }

    public function testEmptyTokenFileRejectsEverything(): void {
        file_put_contents($this->tokenFile, '');
        $this->assertFalse(adminApiCheckToken('anything'));
        $this->assertFalse(adminApiCheckToken(''));
    }

    public function testWhitespaceOnlyTokenFileRejectsEverything(): void {
        file_put_contents($this->tokenFile, "   \n\t\n");
        $this->assertFalse(adminApiCheckToken('anything'));
        $this->assertFalse(adminApiCheckToken(''));
        // The file trims to '' - a provided value that happens to also be
        // whitespace-only-then-trimmed must not accidentally match.
        $this->assertFalse(adminApiCheckToken('   '));
    }

    // --- The NP token must never authenticate here: the two tokens are read
    // from independent files, so a value valid for one is never valid for the
    // other unless the secrets happen to be identical (they must not be). ---

    public function testNpTokenValueNotAcceptedWhenFilesDiffer(): void {
        $npTokenFile = sys_get_temp_dir() . '/np_api_token_test_' . uniqid();
        file_put_contents($npTokenFile, "np-secret-token\n");
        putenv('NP_API_TOKEN_FILE=' . $npTokenFile);

        try {
            // Sanity check: the NP token is valid for the NP checker...
            $this->assertTrue(npApiCheckToken('np-secret-token'));
            // ...but must be rejected by the admin checker, whose expected
            // token ("admin-secret-token", set in setUp()) is a different value.
            $this->assertFalse(adminApiCheckToken('np-secret-token'));
        } finally {
            @unlink($npTokenFile);
        }
    }

    // --- adminApiRequireTokenOrAdminSession(): only the non-exiting (allow)
    // branches can be unit-tested directly, since the deny branch calls exit().
    // The deny branch is covered by e2e-tests/pages/AdminExportApiTest.php,
    // which runs in a separate process where exit() only ends that request. ---

    public function testRequireTokenOrAdminSessionReturnsTokenWhenTokenValid(): void {
        $_SERVER['HTTP_X_ADMIN_API_TOKEN'] = 'admin-secret-token';
        try {
            $this->assertEquals('token', adminApiRequireTokenOrAdminSession());
        } finally {
            unset($_SERVER['HTTP_X_ADMIN_API_TOKEN']);
        }
    }

    public function testRequireTokenOrAdminSessionReturnsSessionForAdminSession(): void {
        unset($_SERVER['HTTP_X_ADMIN_API_TOKEN']);
        $_SESSION['user'] = ['sub' => 'dev-user-id'];
        $this->assertEquals('session:dev-user-id', adminApiRequireTokenOrAdminSession());
    }

    // --- npApiIsAdminSession() is the exact function the session branch above
    // reuses unmodified. This is the closest deterministic proof available in
    // this repo that a non-admin session is denied: the dev auth service
    // (auth/server.js) hardcodes its OIDC interaction result to the one dev
    // admin user, so no HTTP-level flow in this environment can mint a
    // genuinely non-admin authenticated session to exercise via e2e. ---

    public function testNonAdminSessionIsNotAnAdminSession(): void {
        $_SESSION['user'] = ['sub' => 'some-non-admin-user'];
        $this->assertFalse(npApiIsAdminSession());
    }

    public function testUnauthenticatedSessionIsNotAnAdminSession(): void {
        unset($_SESSION['user']);
        $this->assertFalse(npApiIsAdminSession());
    }

    public function testDevAdminSessionIsAnAdminSession(): void {
        $_SESSION['user'] = ['sub' => 'dev-user-id'];
        $this->assertTrue(npApiIsAdminSession());
    }

    // --- adminApiRequireToken(): only the non-exiting (allow) branch can be
    // unit-tested directly, since the deny branch calls exit(). The deny
    // branch - including that an admin session with no token is rejected,
    // unlike adminApiRequireTokenOrAdminSession() above - is covered by
    // e2e-tests/pages/AdminAnalysisApiTest.php. ---

    public function testRequireTokenReturnsTokenWhenTokenValid(): void {
        $_SERVER['HTTP_X_ADMIN_API_TOKEN'] = 'admin-secret-token';
        try {
            $this->assertEquals('token', adminApiRequireToken());
        } finally {
            unset($_SERVER['HTTP_X_ADMIN_API_TOKEN']);
        }
    }
}
