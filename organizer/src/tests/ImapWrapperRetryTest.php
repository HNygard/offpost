<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';

class ImapWrapperRetryTest extends TestCase
{
    private $mockStream;
    private $wrapper;

    protected function setUp(): void
    {
        $this->mockStream = fopen('php://memory', 'r');
        $this->wrapper = new ImapWrapper();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->mockStream)) {
            fclose($this->mockStream);
        }
    }

    public function testRetryLogicForConnectionClosedError()
    {
        // We can't easily mock the internal imap functions in a unit test,
        // but we can test that our retry logic constants are properly defined
        $reflection = new ReflectionClass(ImapWrapper::class);
        
        $maxRetriesConstant = $reflection->getConstant('MAX_RETRIES');
        $this->assertEquals(5, $maxRetriesConstant, 'MAX_RETRIES should be 5');
        
        $retryDelayConstant = $reflection->getConstant('RETRY_DELAY_MS');
        $this->assertEquals(100, $retryDelayConstant, 'RETRY_DELAY_MS should be 100');
    }

    public function testIsRetryableErrorMethod()
    {
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('isRetryableError');
        $method->setAccessible(true);
        
        $wrapper = new ImapWrapper();
        
        // Test retryable errors - only specific patterns from the original issue
        $this->assertTrue($method->invoke($wrapper, '[CLOSED] IMAP connection broken'));
        $this->assertTrue($method->invoke($wrapper, '[CLOSED] IMAP connection lost'));
        $this->assertTrue($method->invoke($wrapper, 'IMAP connection broken (server response)'));
        $this->assertTrue($method->invoke($wrapper, 'IMAP error during fetchbody: [CLOSED] IMAP connection broken (server response)'));
        $this->assertTrue($method->invoke($wrapper, 'IMAP error during body: [CLOSED] IMAP connection lost'));
        
        // Test non-retryable errors - everything else should not be retryable
        $this->assertFalse($method->invoke($wrapper, 'connection lost'));
        $this->assertFalse($method->invoke($wrapper, 'connection reset'));
        $this->assertFalse($method->invoke($wrapper, 'timeout occurred'));
        $this->assertFalse($method->invoke($wrapper, 'network error'));
        $this->assertFalse($method->invoke($wrapper, 'server response'));
        $this->assertFalse($method->invoke($wrapper, 'invalid credentials'));
        $this->assertFalse($method->invoke($wrapper, 'authentication failed'));
        $this->assertFalse($method->invoke($wrapper, 'mailbox not found'));
    }

    public function testWaitBeforeRetryMethod()
    {
        $reflection = new ReflectionClass(ImapWrapper::class);
        $method = $reflection->getMethod('waitBeforeRetry');
        $method->setAccessible(true);
        
        $wrapper = new ImapWrapper();
        
        // Test that the method executes without throwing exceptions
        // We can't easily test the actual delay timing in unit tests
        $start = microtime(true);
        $method->invoke($wrapper, 1);
        $end = microtime(true);
        
        // Should have some minimal delay (at least 0.1 seconds for first retry)
        $this->assertGreaterThan(0.1, $end - $start);
        $this->assertLessThan(1.0, $end - $start); // But not too long for test performance
    }

    public function testConstantsAreReasonable()
    {
        $reflection = new ReflectionClass(ImapWrapper::class);
        
        $maxRetries = $reflection->getConstant('MAX_RETRIES');
        $this->assertGreaterThan(0, $maxRetries, 'MAX_RETRIES should be positive');
        $this->assertLessThanOrEqual(10, $maxRetries, 'MAX_RETRIES should not be excessive');
        
        $retryDelay = $reflection->getConstant('RETRY_DELAY_MS');
        $this->assertGreaterThan(0, $retryDelay, 'RETRY_DELAY_MS should be positive');
        $this->assertLessThanOrEqual(1000, $retryDelay, 'RETRY_DELAY_MS should not be excessive');
    }
}