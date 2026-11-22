<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Mcp/McpAttachmentLink.php';
require_once __DIR__ . '/../../class/Mcp/McpLinkSecret.php';

class McpAttachmentLinkTest extends TestCase {
    const SECRET = 'unit-test-secret-0123456789abcdef-0123456789';
    const NOW = 1700000000;
    const TID = '11111111-1111-4111-8111-111111111111';
    const AID = '22222222-2222-4222-8222-222222222222';

    private function link(string $secret = self::SECRET): McpAttachmentLink {
        return new McpAttachmentLink($secret);
    }

    private function assertRejected(callable $call): void {
        try {
            $call();
        } catch (McpAuthException $e) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Expected McpAuthException');
    }

    public function testValidTokenVerifiesToClaims(): void {
        // :: Setup
        $token = $this->link()->create('user-a', self::TID, self::AID, self::NOW);

        // :: Act
        $claims = $this->link()->verify($token, self::NOW + 10);

        // :: Assert
        $this->assertEquals(['sub' => 'user-a', 'tid' => self::TID, 'aid' => self::AID], $claims);
    }

    public function testTokenIsValidUntilExpiryAndRejectedAfter(): void {
        // :: Setup
        $token = $this->link()->create('user-a', self::TID, self::AID, self::NOW);

        // :: Act
        $atExpiry = $this->link()->verify($token, self::NOW + 300);

        // :: Assert
        $this->assertEquals('user-a', $atExpiry['sub']);
        $this->assertRejected(fn() => $this->link()->verify($token, self::NOW + 301));
    }

    public function testTamperedPayloadIsRejected(): void {
        // :: Setup
        $token = $this->link()->create('user-a', self::TID, self::AID, self::NOW);
        [, $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(
            ['sub' => 'user-b', 'tid' => self::TID, 'aid' => self::AID, 'exp' => self::NOW + 300]
        )), '+/', '-_'), '=');

        // :: Act / Assert
        $this->assertRejected(fn() => $this->link()->verify($forged . '.' . $signature, self::NOW));
    }

    public function testTamperedSignatureIsRejected(): void {
        // :: Setup
        $token = $this->link()->create('user-a', self::TID, self::AID, self::NOW);
        $tampered = substr($token, 0, -2) . (substr($token, -2) === 'AA' ? 'BB' : 'AA');

        // :: Act / Assert
        $this->assertRejected(fn() => $this->link()->verify($tampered, self::NOW));
    }

    public function testWrongSecretIsRejected(): void {
        // :: Setup
        $token = $this->link()->create('user-a', self::TID, self::AID, self::NOW);

        // :: Act / Assert
        $this->assertRejected(fn() => $this->link('another-secret-0123456789abcdef-0123456789')->verify($token, self::NOW));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('garbageProvider')]
    public function testGarbageIsRejected(string $garbage): void {
        // :: Act / Assert
        $this->assertRejected(fn() => $this->link()->verify($garbage, self::NOW));
    }

    public static function garbageProvider(): array {
        return [
            'empty' => [''],
            'no dot' => ['abcdef'],
            'three parts' => ['a.b.c'],
            'bad characters' => ['!!!.???'],
            'empty parts' => ['.'],
        ];
    }

    public function testSecretFromFileIsTrimmed(): void {
        // :: Setup
        $dir = sys_get_temp_dir() . '/mcp-secret-test-trim';
        @mkdir($dir);
        file_put_contents($dir . '/secret', self::SECRET . "\n");

        try {
            // :: Act
            $secret = McpLinkSecret::load($dir . '/secret', false);

            // :: Assert
            $this->assertEquals(self::SECRET, $secret);
        } finally {
            @unlink($dir . '/secret');
            @rmdir($dir);
        }
    }

    public function testShortSecretFileThrows(): void {
        // :: Setup
        $dir = sys_get_temp_dir() . '/mcp-secret-test-short';
        @mkdir($dir);
        file_put_contents($dir . '/secret', 'too-short');

        // :: Act / Assert
        try {
            McpLinkSecret::load($dir . '/secret', true);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertEquals('MCP link secret must be at least 32 characters', $e->getMessage());
        } finally {
            unlink($dir . '/secret');
            rmdir($dir);
        }
    }

    public function testMissingSecretFileThrowsInProduction(): void {
        // :: Act / Assert
        $this->expectException(RuntimeException::class);
        McpLinkSecret::load('/nonexistent/mcp_link_secret', false);
    }

    public function testMissingSecretFileIsGeneratedOnceInDevelopment(): void {
        // :: Setup
        $dir = sys_get_temp_dir() . '/mcp-secret-test-gen';
        @mkdir($dir);

        try {
            // :: Act
            $first = McpLinkSecret::load('/nonexistent/mcp_link_secret', true, $dir);
            $second = McpLinkSecret::load('/nonexistent/mcp_link_secret', true, $dir);

            // :: Assert
            $this->assertEquals($first, $second, 'the generated secret is reused');
            $this->assertEquals(64, strlen($first));
        } finally {
            array_map('unlink', glob($dir . '/*'));
            @rmdir($dir);
        }
    }

    public function testDirectoryAtSecretPathIsReportedAsNotRegularFile(): void {
        // :: Setup
        $dir = sys_get_temp_dir() . '/mcp-secret-test-dir';
        @mkdir($dir);

        try {
            // :: Act / Assert
            McpLinkSecret::load($dir, false);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertEquals("MCP link secret is not a regular file: $dir", $e->getMessage());
        } finally {
            @rmdir($dir);
        }
    }
}
