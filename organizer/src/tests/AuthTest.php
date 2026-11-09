<?php

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase {
    public function testProtectedPageRemembersOriginalUrl(): void {
        // :: Setup
        $originalUrl = '/thread-view?entityId=test-entity&threadId=test-thread';

        // :: Act
        $session = $this->runLogin($originalUrl);

        // :: Assert
        $this->assertEquals(['redirect_after_login' => $originalUrl], $session);
    }

    public function testAuthorizePreservesOriginalUrl(): void {
        // :: Setup
        $originalUrl = '/thread-view?entityId=test-entity&threadId=test-thread';

        // :: Act
        $session = $this->runLogin(
            '/authorize?iss=https%3A%2F%2Fauth.offpost.no%2F',
            ['redirect_after_login' => $originalUrl],
            true
        );

        // :: Assert
        $this->assertEquals(['redirect_after_login' => $originalUrl], $session);
    }

    public function testAuthorizeWithoutSavedDestinationUsesCallbackDefault(): void {
        // :: Setup

        // :: Act
        $session = $this->runLogin(
            '/authorize?iss=https%3A%2F%2Fauth.offpost.no%2F',
            [],
            true
        );

        // :: Assert
        $this->assertEquals([], $session);
    }

    public function testAuthorizeWithAuthenticatedSessionDoesNotRestartLogin(): void {
        // :: Setup
        $userSession = ['user' => ['sub' => 'dev-user-id']];

        // :: Act
        $session = $this->runLogin('/authorize', $userSession, true);

        // :: Assert
        $this->assertEquals($userSession, $session);
    }

    private function runLogin(string $requestUri, array $session = [], bool $authorize = false): array {
        $code = 'define("PHPUNIT_RUNNING", true);'
            . 'session_start();'
            . '$_SESSION = ' . var_export($session, true) . ';'
            . '$_SERVER["REQUEST_URI"] = ' . var_export($requestUri, true) . ';'
            . 'register_shutdown_function(function () { echo json_encode($_SESSION); });'
            . 'require ' . var_export(__DIR__ . ($authorize ? '/../webroot/index.php' : '/../auth.php'), true) . ';'
            . ($authorize ? '' : 'requireAuth();');

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true);
        $this->assertIsArray($result, implode("\n", $output));
        return $result;
    }
}
