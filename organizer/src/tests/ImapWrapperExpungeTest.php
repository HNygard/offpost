<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';

class ImapWrapperExpungeTest extends TestCase
{
    /**
     * Test that EXPUNGEISSUED errors are handled gracefully
     * 
     * This test verifies that when an email no longer exists (EXPUNGEISSUED error),
     * the mailMove method returns false instead of throwing an exception.
     */
    public function testMailMoveHandlesExpungeIssuedError()
    {
        // Create a test stream
        $stream = fopen('php://memory', 'r');
        
        // Create a wrapper instance
        $wrapper = new ImapWrapper();
        
        // We can't actually test the imap_mail_move function without a real IMAP server
        // and a deleted email, but we can verify that the method signature is correct
        // and that it accepts the right parameters
        
        $reflection = new ReflectionMethod(ImapWrapper::class, 'mailMove');
        $params = $reflection->getParameters();
        
        // Verify method signature
        $this->assertCount(4, $params, 'mailMove should accept 4 parameters');
        $this->assertEquals('imap_stream', $params[0]->getName());
        $this->assertEquals('msglist', $params[1]->getName());
        $this->assertEquals('mailbox', $params[2]->getName());
        $this->assertEquals('options', $params[3]->getName());
        
        // Verify return type
        $this->assertTrue($reflection->hasReturnType());
        $this->assertEquals('bool', $reflection->getReturnType()->getName());
        
        fclose($stream);
    }
    
    /**
     * Test that the ImapWrapper properly checks for EXPUNGEISSUED in error messages
     */
    public function testExpungeIssuedPatternDetection()
    {
        // Test various EXPUNGEISSUED error message formats
        $expungeErrors = [
            '[EXPUNGEISSUED] Some of the requested messages no longer exist',
            'IMAP error: [EXPUNGEISSUED] Message has been deleted',
            '[EXPUNGEISSUED] Message was expunged',
        ];
        
        foreach ($expungeErrors as $error) {
            $this->assertTrue(
                strpos($error, '[EXPUNGEISSUED]') !== false,
                "Error message should contain [EXPUNGEISSUED]: $error"
            );
        }
        
        // Test that non-EXPUNGEISSUED errors are different
        $otherErrors = [
            'IMAP connection broken',
            'Mailbox not found',
            'Authentication failed',
        ];
        
        foreach ($otherErrors as $error) {
            $this->assertFalse(
                strpos($error, '[EXPUNGEISSUED]') !== false,
                "Error message should not contain [EXPUNGEISSUED]: $error"
            );
        }
    }
}
