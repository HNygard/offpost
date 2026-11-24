<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../class/OAuth/OAuthCrypto.php';

class OAuthCryptoTest extends TestCase {
    public function testPkceRfc7636AppendixB() {
        // :: Setup
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        // :: Act
        $matches = OAuthCrypto::pkceS256Matches($verifier, $challenge);
        $wrongMatches = OAuthCrypto::pkceS256Matches($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cN');

        // :: Assert
        $this->assertTrue($matches, 'RFC 7636 appendix B example must match');
        $this->assertFalse($wrongMatches, 'A different challenge must not match');
    }

    public function testInvalidVerifierLengthsAndChars() {
        // :: Setup
        $valid43 = str_repeat('a', 43);
        $valid128 = str_repeat('a', 128);

        // :: Act / Assert
        $this->assertTrue(OAuthCrypto::isValidCodeVerifier($valid43), '43 chars is the minimum');
        $this->assertTrue(OAuthCrypto::isValidCodeVerifier($valid128), '128 chars is the maximum');
        $this->assertTrue(OAuthCrypto::isValidCodeVerifier(str_repeat('-._~Az09', 8)), 'All unreserved chars are allowed');
        $this->assertFalse(OAuthCrypto::isValidCodeVerifier(str_repeat('a', 42)), 'Too short');
        $this->assertFalse(OAuthCrypto::isValidCodeVerifier(str_repeat('a', 129)), 'Too long');
        $this->assertFalse(OAuthCrypto::isValidCodeVerifier(str_repeat('a', 42) . '+'), 'Plus is not an unreserved char');
        $this->assertFalse(OAuthCrypto::isValidCodeVerifier(str_repeat('a', 43) . "\n"), 'Trailing newline must not slip past the regex');
    }

    public function testRandomTokenIsBase64UrlWithoutPadding() {
        // :: Act
        $token = OAuthCrypto::randomToken(32);
        $other = OAuthCrypto::randomToken(32);

        // :: Assert
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertNotEquals($token, $other, 'Two tokens must differ');
    }

    public function testHashIsSha256Hex() {
        // :: Act
        $hash = OAuthCrypto::hash('abc');

        // :: Assert
        $this->assertEquals('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $hash);
    }
}
