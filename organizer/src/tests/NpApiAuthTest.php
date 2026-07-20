<?php
// organizer/src/tests/NpApiAuthTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/np/np-api-auth.php';

class NpApiAuthTest extends TestCase {
    private string $tokenFile;

    protected function setUp(): void {
        $this->tokenFile = sys_get_temp_dir() . '/np_api_token_test_' . uniqid();
        file_put_contents($this->tokenFile, "test-secret-token\n");
        putenv('NP_API_TOKEN_FILE=' . $this->tokenFile);
    }

    protected function tearDown(): void {
        @unlink($this->tokenFile);
        putenv('NP_API_TOKEN_FILE');
    }

    public function testCorrectTokenAccepted(): void {
        $this->assertTrue(npApiCheckToken('test-secret-token'));
    }

    public function testWrongTokenRejected(): void {
        $this->assertFalse(npApiCheckToken('wrong-token'));
    }

    public function testNullTokenRejected(): void {
        $this->assertFalse(npApiCheckToken(null));
    }

    public function testEmptyTokenFileRejectsEverything(): void {
        file_put_contents($this->tokenFile, '');
        $this->assertFalse(npApiCheckToken(''));
        $this->assertFalse(npApiCheckToken('anything'));
    }
}
