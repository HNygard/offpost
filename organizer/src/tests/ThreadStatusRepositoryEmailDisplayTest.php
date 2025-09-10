<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../class/ThreadUtils.php';

class ThreadStatusRepositoryEmailDisplayTest extends TestCase {
    
    public function testGetEmailSubjectFromImapHeadersWithEncodedSubject() {
        // Test encoded ISO-8859-1 subject like the one mentioned in the issue
        $encodedSubject = '=?iso-8859-1?Q?Innsyn_i_h=E5ndskrevet_opptellingsdata?=';
        $headers = (object) ['subject' => $encodedSubject];
        
        $result = getEmailSubjectFromImapHeaders($headers);
        
        // The subject should be properly decoded
        $this->assertNotEquals($encodedSubject, $result, 'Subject should be decoded, not raw encoded string');
        $this->assertStringContainsString('Innsyn', $result, 'Should contain readable Norwegian text');
        $this->assertStringContainsString('håndskrevet', $result, 'Should contain properly decoded Norwegian characters');
    }
    
    public function testGetEmailFromAddressFromImapHeadersWithProperStructure() {
        // Test with IMAP header structure that includes personal name
        $headers = (object) [
            'from' => [
                (object) [
                    'mailbox' => 'test.sender',
                    'host' => 'example.com',
                    'personal' => 'Test Sender Name'
                ]
            ]
        ];
        
        $result = getEmailFromAddressFromImapHeaders($headers);
        
        // Should return formatted address with name
        $this->assertStringContainsString('Test Sender Name', $result, 'Should include personal name');
        $this->assertStringContainsString('test.sender@example.com', $result, 'Should include email address');
    }
    
    public function testGetEmailFromAddressFromImapHeadersWithoutPersonalName() {
        // Test with IMAP header structure without personal name
        $headers = (object) [
            'from' => [
                (object) [
                    'mailbox' => 'noreply',
                    'host' => 'government.no'
                ]
            ]
        ];
        
        $result = getEmailFromAddressFromImapHeaders($headers);
        
        // Should return just email address
        $this->assertEquals('noreply@government.no', $result, 'Should return just email when no personal name');
    }
    
    public function testCurrentImplementationProblemsSimulation() {
        // Simulate how the current ThreadStatusRepository code would handle IMAP headers
        $imapHeaders = [
            'from' => [
                [
                    'mailbox' => 'sender',
                    'host' => 'example.com',
                    'personal' => 'Sender Name'
                ]
            ],
            'subject' => '=?iso-8859-1?Q?Innsyn_i_h=E5ndskrevet_opptellingsdata?='
        ];
        
        // Current broken logic (similar to lines 266-274 in ThreadStatusRepository)
        $from_email = 'unknown';
        $from_name = 'Unknown Sender';
        $subject = 'No subject';
        
        if (is_array($imapHeaders)) {
            if (isset($imapHeaders['from']) && is_array($imapHeaders['from']) && !empty($imapHeaders['from'])) {
                // This is how the current code tries to access it - but structure is different
                $from_email = $imapHeaders['from'][0]['email'] ?? 'unknown';
                $from_name = $imapHeaders['from'][0]['name'] ?? $from_email;
            }
            if (isset($imapHeaders['subject'])) {
                $subject = $imapHeaders['subject'];  // Not decoded!
            }
        }
        
        // Assert current broken behavior
        $this->assertEquals('unknown', $from_email, 'Current code fails to extract email');
        $this->assertEquals('unknown', $from_name, 'Current code fails to extract name');
        $this->assertEquals('=?iso-8859-1?Q?Innsyn_i_h=E5ndskrevet_opptellingsdata?=', $subject, 'Current code does not decode subject');
        
        // Now test with proper utility functions
        $headers_object = (object) $imapHeaders;
        $proper_subject = getEmailSubjectFromImapHeaders($headers_object);
        $proper_from = getEmailFromAddressFromImapHeaders($headers_object);
        
        // Assert proper behavior
        $this->assertNotEquals('=?iso-8859-1?Q?Innsyn_i_h=E5ndskrevet_opptellingsdata?=', $proper_subject, 'Utility function should decode subject');
        $this->assertStringContainsString('håndskrevet', $proper_subject, 'Should contain decoded Norwegian text');
        $this->assertStringContainsString('Sender Name', $proper_from, 'Should extract sender name properly');
        $this->assertStringContainsString('sender@example.com', $proper_from, 'Should extract email properly');
    }
    
    public function testFixedThreadStatusRepositoryLogic() {
        // Test the improved logic that should be used in ThreadStatusRepository
        $imapHeadersJson = json_encode([
            'from' => [
                [
                    'mailbox' => 'test.sender',
                    'host' => 'government.no',
                    'personal' => 'Test Government Sender'
                ]
            ],
            'subject' => '=?iso-8859-1?Q?Innsyn_i_h=E5ndskrevet_opptellingsdata?='
        ]);
        
        // Simulate the fixed logic
        $from_email = 'unknown';
        $from_name = 'Unknown Sender';
        $subject = 'No subject';
        
        if (!empty($imapHeadersJson)) {
            // Use utility functions to properly parse and decode headers
            $fromAddress = getEmailFromAddressFromImapHeaders($imapHeadersJson);
            if (!empty($fromAddress)) {
                // Parse the formatted address "Name <email>" or just "email"
                if (preg_match('/^(.+?)\s*<(.+?)>$/', $fromAddress, $matches)) {
                    $from_name = trim($matches[1]);
                    $from_email = trim($matches[2]);
                } else {
                    $from_email = $fromAddress;
                    $from_name = $fromAddress;
                }
            }
            
            $subjectDecoded = getEmailSubjectFromImapHeaders($imapHeadersJson);
            if (!empty($subjectDecoded)) {
                $subject = $subjectDecoded;
            }
        }
        
        // Assert the fixed behavior
        $this->assertEquals('Test Government Sender', $from_name, 'Should extract sender name properly');
        $this->assertEquals('test.sender@government.no', $from_email, 'Should extract email properly');
        $this->assertStringContainsString('Innsyn i håndskrevet', $subject, 'Should decode subject properly');
        $this->assertNotEquals('=?iso-8859-1?Q?Innsyn_i_h=E5ndskrevet_opptellingsdata?=', $subject, 'Should not be encoded anymore');
    }
}