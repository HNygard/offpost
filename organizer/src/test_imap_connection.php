<?php

require_once __DIR__ . '/class/Imap/ImapConnection.php';
require_once __DIR__ . '/class/Imap/ImapWrapper.php';

use Imap\ImapConnection;
use Imap\ImapWrapper;

echo "Testing ImapConnection with retry-enabled ImapWrapper...\n";

// Create a mock wrapper to test the integration
class MockImapWrapper extends ImapWrapper {
    private $callCount = 0;
    private $shouldFail = false;
    
    public function setShouldFail($fail) {
        $this->shouldFail = $fail;
        $this->callCount = 0;
    }
    
    // Override the open method for testing
    public function open(string $mailbox, string $username, string $password, int $options = 0, int $retries = 0, array $flags = []): mixed {
        if ($this->shouldFail && $this->callCount < 3) {
            $this->callCount++;
            throw new \Exception('[CLOSED] IMAP connection broken (server response)');
        }
        
        // Return a mock resource
        return fopen('php://memory', 'r');
    }
    
    public function close(mixed $imap_stream, int $flags = 0): bool {
        if (is_resource($imap_stream)) {
            fclose($imap_stream);
        }
        return true;
    }
}

// Test basic connection
$mockWrapper = new MockImapWrapper();
$connection = new ImapConnection(
    '{test.server:993/imap/ssl}',
    'test@example.com',
    'password',
    false,
    $mockWrapper
);

try {
    echo "Testing normal connection... ";
    $mockWrapper->setShouldFail(false);
    $result = $connection->openConnection();
    echo "SUCCESS - Connection established\n";
    $connection->closeConnection();
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

// Test that non-IMAP methods still work
try {
    echo "Testing ImapWrapper constants... ";
    $reflection = new ReflectionClass(ImapWrapper::class);
    $maxRetries = $reflection->getConstant('MAX_RETRIES');
    if ($maxRetries === 5) {
        echo "SUCCESS - MAX_RETRIES = $maxRetries\n";
    } else {
        echo "FAIL - Expected 5, got $maxRetries\n";
    }
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

echo "\nImapConnection integration test completed!\n";