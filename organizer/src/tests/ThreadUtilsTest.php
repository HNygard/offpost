<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadUtils.php';

class ThreadUtilsTest extends TestCase {
    public function testGetThreadIdWithUTF8() {
        $thread = new stdClass();
        $thread->title = "Café Møller";
        // Test actual behavior - UTF-8 characters are preserved in lowercase
        $this->assertEquals('café_møller', getThreadId($thread));
    }

    public function testGetThreadIdWithSpaces() {
        $thread = new stdClass();
        $thread->title = "Hello World Test";
        $this->assertEquals('hello_world_test', getThreadId($thread));
    }

    public function testGetThreadIdWithForwardSlashes() {
        $thread = new stdClass();
        $thread->title = "path/to/something";
        $this->assertEquals('path-to-something', getThreadId($thread));
    }

    public function testGetLabelTypeInfo() {
        $this->assertEquals('label', getLabelType('any', 'info'));
    }

    public function testGetLabelTypeDisabled() {
        $this->assertEquals('label label_disabled', getLabelType('any', 'disabled'));
    }

    public function testGetLabelTypeDanger() {
        $this->assertEquals('label label_warn', getLabelType('any', 'danger'));
    }

    public function testGetLabelTypeSuccess() {
        $this->assertEquals('label label_ok', getLabelType('any', 'success'));
    }

    public function testGetLabelTypeUnknown() {
        $this->assertEquals('label', getLabelType('any', 'unknown'));
    }

    public function testGetLabelTypeInvalid() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown status_type[any]: invalid');
        getLabelType('any', 'invalid');
    }

    public function testGetEmailSubjectFromImapHeaders() {
        // Test with object
        $headers = (object) ['subject' => 'Test Email Subject'];
        $this->assertEquals('Test Email Subject', getEmailSubjectFromImapHeaders($headers));
        
        // Test with JSON string
        $jsonHeaders = json_encode(['subject' => 'JSON Test Subject']);
        $this->assertEquals('JSON Test Subject', getEmailSubjectFromImapHeaders($jsonHeaders));
        
        // Test with empty headers
        $this->assertEquals('', getEmailSubjectFromImapHeaders(null));
        $this->assertEquals('', getEmailSubjectFromImapHeaders([]));
        
        // Test with missing subject
        $headersNoSubject = (object) ['from' => 'test@example.com'];
        $this->assertEquals('', getEmailSubjectFromImapHeaders($headersNoSubject));
    }

    public function testGetEmailFromAddressFromImapHeaders() {
        // Test with valid from field
        $headers = (object) [
            'from' => [
                (object) ['mailbox' => 'sender', 'host' => 'example.com']
            ]
        ];
        $this->assertEquals('sender@example.com', getEmailFromAddressFromImapHeaders($headers));
        
        // Test with JSON string
        $jsonHeaders = json_encode([
            'from' => [
                ['mailbox' => 'json-sender', 'host' => 'example.org']
            ]
        ]);
        $this->assertEquals('json-sender@example.org', getEmailFromAddressFromImapHeaders($jsonHeaders));
        
        // Test with empty headers
        $this->assertEquals('', getEmailFromAddressFromImapHeaders(null));
        $this->assertEquals('', getEmailFromAddressFromImapHeaders([]));
        
        // Test with missing from field
        $headersNoFrom = (object) ['subject' => 'Test'];
        $this->assertEquals('', getEmailFromAddressFromImapHeaders($headersNoFrom));
        
        // Test with malformed from field
        $headersBadFrom = (object) ['from' => []];
        $this->assertEquals('', getEmailFromAddressFromImapHeaders($headersBadFrom));
    }

    public function testGetEmailToAddressesFromImapHeaders() {
        // Test with valid to field
        $headers = (object) [
            'to' => [
                (object) ['mailbox' => 'recipient1', 'host' => 'example.com'],
                (object) ['mailbox' => 'recipient2', 'host' => 'example.org']
            ]
        ];
        $expected = ['recipient1@example.com', 'recipient2@example.org'];
        $this->assertEquals($expected, getEmailToAddressesFromImapHeaders($headers));
        
        // Test with JSON string
        $jsonHeaders = json_encode([
            'to' => [
                ['mailbox' => 'json-recipient', 'host' => 'example.net']
            ]
        ]);
        $this->assertEquals(['json-recipient@example.net'], getEmailToAddressesFromImapHeaders($jsonHeaders));
        
        // Test with empty headers
        $this->assertEquals([], getEmailToAddressesFromImapHeaders(null));
        $this->assertEquals([], getEmailToAddressesFromImapHeaders([]));
        
        // Test with missing to field
        $headersNoTo = (object) ['subject' => 'Test'];
        $this->assertEquals([], getEmailToAddressesFromImapHeaders($headersNoTo));
        
        // Test with empty to field
        $headersEmptyTo = (object) ['to' => []];
        $this->assertEquals([], getEmailToAddressesFromImapHeaders($headersEmptyTo));
    }

    public function testGetEmailCcAddressesFromImapHeaders() {
        // Test with valid cc field
        $headers = (object) [
            'cc' => [
                (object) ['mailbox' => 'cc1', 'host' => 'example.com'],
                (object) ['mailbox' => 'cc2', 'host' => 'example.org']
            ]
        ];
        $expected = ['cc1@example.com', 'cc2@example.org'];
        $this->assertEquals($expected, getEmailCcAddressesFromImapHeaders($headers));
        
        // Test with JSON string
        $jsonHeaders = json_encode([
            'cc' => [
                ['mailbox' => 'json-cc', 'host' => 'example.net']
            ]
        ]);
        $this->assertEquals(['json-cc@example.net'], getEmailCcAddressesFromImapHeaders($jsonHeaders));
        
        // Test with empty headers
        $this->assertEquals([], getEmailCcAddressesFromImapHeaders(null));
        $this->assertEquals([], getEmailCcAddressesFromImapHeaders([]));
        
        // Test with missing cc field
        $headersNoCc = (object) ['subject' => 'Test'];
        $this->assertEquals([], getEmailCcAddressesFromImapHeaders($headersNoCc));
        
        // Test with empty cc field
        $headersEmptyCc = (object) ['cc' => []];
        $this->assertEquals([], getEmailCcAddressesFromImapHeaders($headersEmptyCc));
    }
}
