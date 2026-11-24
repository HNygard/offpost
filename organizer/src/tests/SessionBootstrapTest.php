<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../session-bootstrap.php';

class SessionBootstrapTest extends TestCase {
    public function testProductionCookieIsSecureHttpOnlyLax() {
        // :: Setup

        // :: Act
        $params = offpostSessionCookieParams('production');

        // :: Assert
        $this->assertEquals([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => true,
        ], $params);
    }

    public function testDevelopmentAndUnknownEnvironmentAreNotSecure() {
        // :: Setup

        // :: Act
        $development = offpostSessionCookieParams('development');
        $unknown = offpostSessionCookieParams(null);

        // :: Assert
        $this->assertEquals([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => false,
        ], $development, 'Plain http dev server needs a non-secure cookie');
        $this->assertFalse($unknown['secure'], 'Unknown environment must not set Secure');
    }
}
