<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../fixtures/mcp/McpTestKeys.php';
require_once __DIR__ . '/../../class/Mcp/McpTokenVerifier.php';
require_once __DIR__ . '/../../class/Mcp/McpJwksLoader.php';

class McpTokenVerifierTest extends TestCase {
    private const ISSUER = 'https://issuer.test/';
    private const AUDIENCE = 'https://offpost.test/mcp';

    private function claims(array $override = []): array {
        return array_merge([
            'sub' => 'user-1',
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => 4102444800,
            'iat' => 1700000000,
        ], $override);
    }

    private function verifier(): McpTokenVerifier {
        return new McpTokenVerifier(self::ISSUER, self::AUDIENCE, McpTestKeys::jwks());
    }

    public function testValidTokenReturnsSub() {
        // :: Setup
        $token = McpTestKeys::token($this->claims());

        // :: Act
        $sub = $this->verifier()->verify($token);

        // :: Assert
        $this->assertEquals('user-1', $sub);
    }

    public function testAudienceArrayContainingAudienceIsAccepted() {
        // :: Setup
        $token = McpTestKeys::token($this->claims([
            'aud' => [self::AUDIENCE, 'https://issuer.test/userinfo'],
        ]));

        // :: Act
        $sub = $this->verifier()->verify($token);

        // :: Assert
        $this->assertEquals('user-1', $sub);
    }

    public function testExpiredTokenRejected() {
        // :: Setup
        $token = McpTestKeys::token($this->claims(['exp' => 1700000001]));

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testMissingExpRejected() {
        // :: Setup
        $claims = $this->claims();
        unset($claims['exp']);
        $token = McpTestKeys::token($claims);

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testWrongAudienceRejected() {
        // :: Setup
        $token = McpTestKeys::token($this->claims(['aud' => 'https://other.test/mcp']));

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testWrongIssuerRejected() {
        // :: Setup
        $token = McpTestKeys::token($this->claims(['iss' => 'https://evil.test/']));

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testMissingSubRejected() {
        // :: Setup
        $claims = $this->claims();
        unset($claims['sub']);
        $token = McpTestKeys::token($claims);

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testTokenSignedByOtherKeyRejected() {
        // :: Setup
        $token = McpTestKeys::token($this->claims(), McpTestKeys::otherPrivateKey());

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testHs256TokenRejected() {
        // :: Setup
        $details = openssl_pkey_get_details(openssl_pkey_get_private(McpTestKeys::privateKey()));
        $token = \Firebase\JWT\JWT::encode($this->claims(), $details['key'], 'HS256', McpTestKeys::KID);

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testGarbageRejected() {
        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify('not.a.jwt');
    }

    public function testIatWithinLeewayIsAccepted() {
        // :: Setup
        // time() is used on purpose: this test is about clock leeway itself, and the
        // verifier has no injectable clock. It is the only allowed exception to the no-time rule.
        $token = McpTestKeys::token($this->claims(['iat' => time() + 30]));

        // :: Act
        $sub = $this->verifier()->verify($token);

        // :: Assert
        $this->assertEquals('user-1', $sub);
    }

    public function testIatBeyondLeewayIsRejected() {
        // :: Setup
        // time() on purpose, see testIatWithinLeewayIsAccepted.
        $token = McpTestKeys::token($this->claims(['iat' => time() + 120]));

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testLeewayIsRestoredAfterVerify() {
        // :: Setup
        $before = \Firebase\JWT\JWT::$leeway;
        $token = McpTestKeys::token($this->claims());

        // :: Act
        $this->verifier()->verify($token);

        // :: Assert
        $this->assertEquals($before, \Firebase\JWT\JWT::$leeway, 'JWT::$leeway must not leak');
    }

    public function testClientCredentialsGrantTypeRejected() {
        // :: Setup
        $token = McpTestKeys::token($this->claims(['gty' => 'client-credentials']));

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testClientsSubjectRejected() {
        // :: Setup
        $token = McpTestKeys::token($this->claims(['sub' => 'abc123@clients']));

        // :: Act
        $this->expectException(McpAuthException::class);
        $this->verifier()->verify($token);
    }

    public function testJwksLoaderSkipsFailingSource() {
        // :: Setup
        $dir = sys_get_temp_dir() . '/offpost-mcp-jwks-test-mixed-' . getmypid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/good.json', json_encode(['keys' => [['kid' => 'a', 'kty' => 'RSA']]]));

        // :: Act
        $previousLog = ini_set('error_log', '/dev/null'); // the skipped source is logged
        $jwks = McpJwksLoader::load([$dir . '/missing.json', $dir . '/good.json']);
        ini_set('error_log', $previousLog);

        // :: Assert
        $this->assertEquals(['a'], array_column($jwks['keys'], 'kid'), json_encode($jwks, JSON_PRETTY_PRINT));

        unlink($dir . '/good.json');
        rmdir($dir);
    }

    public function testJwksLoaderThrowsWhenNoSourceGivesKeys() {
        // :: Setup
        $dir = sys_get_temp_dir() . '/offpost-mcp-jwks-test-bad-' . getmypid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/bad.json', 'not json');

        // :: Act
        $previousLog = ini_set('error_log', '/dev/null'); // the skipped sources are logged
        try {
            McpJwksLoader::load([$dir . '/missing.json', $dir . '/bad.json']);
            $error = null;
        } catch (McpAuthException $e) {
            $error = $e;
        }
        ini_set('error_log', $previousLog);

        // :: Assert
        $this->assertNotNull($error, 'all sources bad must throw');

        unlink($dir . '/bad.json');
        rmdir($dir);
    }

    public function testJwksLoaderMergesFileSources() {
        // :: Setup
        $dir = sys_get_temp_dir() . '/offpost-mcp-jwks-test-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/a.json', json_encode(['keys' => [['kid' => 'a', 'kty' => 'RSA']]]));
        file_put_contents($dir . '/b.json', json_encode(['keys' => [['kid' => 'b', 'kty' => 'RSA']]]));

        // :: Act
        $jwks = McpJwksLoader::load([$dir . '/a.json', $dir . '/b.json']);

        // :: Assert
        $this->assertCount(2, $jwks['keys'], json_encode($jwks, JSON_PRETTY_PRINT));
        $this->assertEquals(['a', 'b'], array_column($jwks['keys'], 'kid'));

        unlink($dir . '/a.json');
        unlink($dir . '/b.json');
        rmdir($dir);
    }

    public function testCacheDirIsCreatedPrivate() {
        // :: Act
        $dir = McpJwksLoader::ensureCacheDir();

        // :: Assert
        $this->assertNotNull($dir, 'cache dir should be usable');
        $this->assertEquals('0700', substr(sprintf('%o', fileperms($dir)), -4), 'cache dir mode');
    }

    public function testCacheInWorldWritableDirIsNotUsed() {
        // :: Setup
        $dir = sys_get_temp_dir() . '/offpost-mcp-jwks-test-ww-' . getmypid();
        mkdir($dir, 0777);
        chmod($dir, 0777);
        $file = $dir . '/x.json';
        file_put_contents($file, '{"keys":[]}');

        // :: Act
        $usable = McpJwksLoader::isTrustedCacheFile($dir, $file);

        // :: Assert
        $this->assertFalse($usable, 'cache file in world-writable dir must be ignored');

        unlink($file);
        rmdir($dir);
    }
}
