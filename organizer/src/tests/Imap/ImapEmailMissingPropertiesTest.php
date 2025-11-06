<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapEmail;
use Imap\ImapConnection;
use Imap\ImapWrapper;

require_once __DIR__ . '/../../class/Imap/ImapEmail.php';
require_once __DIR__ . '/../../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../../class/Imap/ImapWrapper.php';

/**
 * Test ImapEmail handling of missing properties in IMAP headers
 * 
 * This addresses the bug where undefined properties like $subject and $date
 * were causing fatal errors in email processing from spam/trash folders.
 */
class ImapEmailMissingPropertiesTest extends TestCase {

    private function createMockConnection(): ImapConnection {
        // Create a mock ImapWrapper that doesn't call actual IMAP functions
        $mockWrapper = $this->createMock(ImapWrapper::class);
        $mockWrapper->method('utf8')->willReturnCallback(function($text) {
            return $text ?? '';
        });
        
        return new ImapConnection(
            '{mock.server:993/imap/ssl}',
            'test@example.com',
            'password',
            false,
            $mockWrapper
        );
    }

    public function testFromImapWithMissingSubjectProperty() {
        // :: Setup - Simulate headers without subject property (like in first error)
        $connection = $this->createMockConnection();
        $headersWithoutSubject = (object)[
            'date' => 'Wed, 25 Sep 2024 10:00:00 +0000',
            'fromaddress' => 'test@example.com',
            'senderaddress' => 'test@example.com',
            'reply_toaddress' => 'test@example.com',
            'to' => [],
            'from' => [],
            'reply_to' => [],
            'sender' => []
        ];

        // :: Act
        $email = ImapEmail::fromImap($connection, 123, $headersWithoutSubject, 'Test body');

        // :: Assert
        $this->assertInstanceOf(ImapEmail::class, $email);
        $this->assertEquals('', $email->subject, 'Missing subject should default to empty string');
        $this->assertEquals('Wed, 25 Sep 2024 10:00:00 +0000', $email->date);
        $this->assertEquals(strtotime('Wed, 25 Sep 2024 10:00:00 +0000'), $email->timestamp);
    }

    public function testFromImapWithMissingDateProperty() {
        // :: Setup - Simulate headers without date property (like in second error)
        $connection = $this->createMockConnection();
        $headersWithoutDate = (object)[
            'subject' => 'Test Subject',
            'fromaddress' => 'test@example.com',
            'senderaddress' => 'test@example.com',
            'reply_toaddress' => 'test@example.com',
            'to' => [],
            'from' => [],
            'reply_to' => [],
            'sender' => []
        ];

        // :: Act
        $email = ImapEmail::fromImap($connection, 124, $headersWithoutDate, 'Test body');

        // :: Assert
        $this->assertInstanceOf(ImapEmail::class, $email);
        $this->assertEquals('Test Subject', $email->subject);
        $this->assertNotEmpty($email->date, 'Missing date should default to current date');
        $this->assertIsInt($email->timestamp, 'Timestamp should be an integer');
        $this->assertGreaterThan(0, $email->timestamp, 'Timestamp should be positive');
    }

    public function testFromImapWithBothPropertiesMissing() {
        // :: Setup - Simulate headers without both subject and date properties
        $connection = $this->createMockConnection();
        $headersWithoutBoth = (object)[
            'fromaddress' => 'test@example.com',
            'senderaddress' => 'test@example.com',
            'reply_toaddress' => 'test@example.com',
            'to' => [],
            'from' => [],
            'reply_to' => [],
            'sender' => []
        ];

        // :: Act
        $email = ImapEmail::fromImap($connection, 125, $headersWithoutBoth, 'Test body');

        // :: Assert
        $this->assertInstanceOf(ImapEmail::class, $email);
        $this->assertEquals('', $email->subject, 'Missing subject should default to empty string');
        $this->assertNotEmpty($email->date, 'Missing date should default to current date');
        $this->assertIsInt($email->timestamp, 'Timestamp should be an integer');
        $this->assertGreaterThan(0, $email->timestamp, 'Timestamp should be positive');
    }

    public function testFromImapWithValidPropertiesStillWorks() {
        // :: Setup - Regression test to ensure normal functionality is preserved
        $connection = $this->createMockConnection();
        $validHeaders = (object)[
            'subject' => 'Valid Subject',
            'date' => 'Wed, 25 Sep 2024 12:00:00 +0000',
            'fromaddress' => 'test@example.com',
            'senderaddress' => 'test@example.com',
            'reply_toaddress' => 'test@example.com',
            'to' => [],
            'from' => [],
            'reply_to' => [],
            'sender' => []
        ];

        // :: Act
        $email = ImapEmail::fromImap($connection, 126, $validHeaders, 'Test body');

        // :: Assert
        $this->assertInstanceOf(ImapEmail::class, $email);
        $this->assertEquals('Valid Subject', $email->subject);
        $this->assertEquals('Wed, 25 Sep 2024 12:00:00 +0000', $email->date);
        $this->assertEquals(strtotime('Wed, 25 Sep 2024 12:00:00 +0000'), $email->timestamp);
    }

    public function testFromImapWithEmptySubjectProperty() {
        // :: Setup - Test edge case where subject exists but is empty
        $connection = $this->createMockConnection();
        $headersWithEmptySubject = (object)[
            'subject' => '',
            'date' => 'Wed, 25 Sep 2024 10:00:00 +0000',
            'fromaddress' => 'test@example.com',
            'senderaddress' => 'test@example.com',
            'reply_toaddress' => 'test@example.com',
            'to' => [],
            'from' => [],
            'reply_to' => [],
            'sender' => []
        ];

        // :: Act
        $email = ImapEmail::fromImap($connection, 127, $headersWithEmptySubject, 'Test body');

        // :: Assert
        $this->assertInstanceOf(ImapEmail::class, $email);
        $this->assertEquals('', $email->subject, 'Empty subject should remain empty');
        $this->assertEquals('Wed, 25 Sep 2024 10:00:00 +0000', $email->date);
    }

    public function testFromImapWithInvalidDateProperty() {
        // :: Setup - Test edge case where date exists but is invalid
        $connection = $this->createMockConnection();
        $headersWithInvalidDate = (object)[
            'subject' => 'Test Subject',
            'date' => 'invalid-date-string',
            'fromaddress' => 'test@example.com',
            'senderaddress' => 'test@example.com',
            'reply_toaddress' => 'test@example.com',
            'to' => [],
            'from' => [],
            'reply_to' => [],
            'sender' => []
        ];

        // :: Act
        $email = ImapEmail::fromImap($connection, 128, $headersWithInvalidDate, 'Test body');

        // :: Assert
        $this->assertInstanceOf(ImapEmail::class, $email);
        $this->assertEquals('Test Subject', $email->subject);
        $this->assertEquals('invalid-date-string', $email->date);
        // strtotime() returns false for invalid dates, but our code will still set it
        // This is acceptable since we're preserving the original date string
    }
}