<?php

/**
 * TEST-ONLY RSA keys used to sign MCP bearer tokens in tests.
 *
 * The keys are generated on first use and written to the gitignored directory
 * tests/fixtures/mcp/generated/ (no key material lives in the repository):
 *  - test-keys.json: both private PEMs, kids and the public JWKS (mode 0600)
 *  - test-jwks.json: the public JWKS only, which the development server trusts
 *
 * Key generation is random, which the project test rules normally forbid. The
 * owner explicitly asked for generated keys; tests must not depend on the key
 * values, only on the sign/verify behaviour.
 *
 * The generated JWKS must be trusted in development / PHPUnit only, never in production.
 */
class McpTestKeys {
    public const KID = 'offpost-mcp-test-1';
    public const OTHER_KID = 'offpost-mcp-test-other';

    private static ?array $keys = null;

    public static function generatedDir(): string {
        return __DIR__ . '/generated';
    }

    public static function keysPath(): string {
        return self::generatedDir() . '/test-keys.json';
    }

    public static function jwksPath(): string {
        return self::generatedDir() . '/test-jwks.json';
    }

    /** PEM of the key whose public half is in the JWKS. */
    public static function privateKey(): string {
        return self::load()['private_key'];
    }

    /** PEM of a second, unrelated key. Tokens signed with it must be rejected. */
    public static function otherPrivateKey(): string {
        return self::load()['other_private_key'];
    }

    /** Decoded JWKS containing the public part of privateKey(). */
    public static function jwks(): array {
        return self::load()['jwks'];
    }

    /** Sign claims as an RS256 JWT with header kid. Defaults to privateKey(). */
    public static function token(array $claims, ?string $privateKey = null): string {
        return \Firebase\JWT\JWT::encode($claims, $privateKey ?? self::privateKey(), 'RS256', self::KID);
    }

    private static function load(): array {
        if (self::$keys !== null) {
            return self::$keys;
        }
        $raw = is_readable(self::keysPath()) ? file_get_contents(self::keysPath()) : false;
        $keys = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($keys) || !isset($keys['private_key'], $keys['other_private_key'], $keys['jwks'])) {
            $keys = self::generate();
        }
        // The JWKS file is what the server reads; keep it in step with the key file.
        if (!is_readable(self::jwksPath())
            || json_decode((string)file_get_contents(self::jwksPath()), true) !== $keys['jwks']) {
            self::writeAtomic(self::jwksPath(), json_encode($keys['jwks'], JSON_PRETTY_PRINT) . "\n", 0644);
        }
        return self::$keys = $keys;
    }

    private static function generate(): array {
        $private = self::newPrivateKeyPem();
        $keys = [
            'kid' => self::KID,
            'private_key' => $private,
            'other_kid' => self::OTHER_KID,
            'other_private_key' => self::newPrivateKeyPem(),
            'jwks' => self::jwksFor($private),
        ];
        self::writeAtomic(self::keysPath(), json_encode($keys, JSON_PRETTY_PRINT) . "\n", 0600);
        return $keys;
    }

    private static function newPrivateKeyPem(): string {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not generate a test RSA key: ' . openssl_error_string());
        }
        return $pem;
    }

    private static function jwksFor(string $privatePem): array {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privatePem));
        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KID,
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]];
    }

    /** Temp file + rename, so a concurrent reader never sees a half-written file. */
    private static function writeAtomic(string $path, string $content, int $mode): void {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create $dir");
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false || !chmod($tmp, $mode) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write $path");
        }
    }

    private static function base64Url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
