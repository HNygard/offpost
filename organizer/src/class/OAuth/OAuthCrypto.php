<?php

/**
 * Token generation, hashing and PKCE helpers for the OAuth server.
 * Secrets are only ever stored as OAuthCrypto::hash() values.
 */
class OAuthCrypto {
    /** Random bytes as base64url without padding (32 bytes -> 43 chars). */
    public static function randomToken(int $bytes = 32): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** SHA-256 as lowercase hex (64 chars). */
    public static function hash(string $value): string {
        return hash('sha256', $value);
    }

    /** RFC 7636 section 4.1: 43-128 chars of unreserved characters. */
    public static function isValidCodeVerifier(string $verifier): bool {
        // \z, not $: "$" would accept a trailing newline.
        return preg_match('/^[A-Za-z0-9\-._~]{43,128}\z/', $verifier) === 1;
    }

    /** PKCE S256: BASE64URL(SHA256(verifier)) === challenge, compared in constant time. */
    public static function pkceS256Matches(string $verifier, string $challenge): bool {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($computed, $challenge);
    }
}
