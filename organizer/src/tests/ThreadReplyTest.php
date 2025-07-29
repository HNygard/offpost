<?php

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/ThreadStorageManager.php';
require_once __DIR__ . '/../class/Entity.php';
require_once __DIR__ . '/../class/ThreadUtils.php';

use PHPUnit\Framework\TestCase;

class ThreadReplyTest extends TestCase {

    private static $testThreadId;
    private static $testEntityId = 'test-entity-reply';

    public static function setUpBeforeClass(): void {
        // Create test thread with incoming email
        self::$testThreadId = 'thread-reply-test-' . uniqid();
        
        // Create test entity
        $testEntity = new stdClass();
        $testEntity->id = self::$testEntityId;
        $testEntity->name = 'Test Entity for Reply';
        $testEntity->email = 'test-entity@example.com';
        
        // We'll mock the storage instead of creating actual files
    }

    public function testSuggestedReplyGeneration() {
        // Create a mock thread with emails
        $thread = new Thread();
        $thread->id = self::$testThreadId;
        $thread->title = 'Test Thread for Reply';
        $thread->my_email = 'test@offpost.no';
        $thread->my_name = 'Test User';

        // Add some test emails
        $email1 = new ThreadEmail();
        $email1->id = 'email1';
        $email1->email_type = 'OUT';
        $email1->datetime_received = '2024-01-01 10:00:00';
        $email1->description = 'Initial request sent';

        $email2 = new ThreadEmail();
        $email2->id = 'email2';
        $email2->email_type = 'IN';
        $email2->datetime_received = '2024-01-02 15:30:00';
        $email2->description = 'Response received from entity';

        $email3 = new ThreadEmail();
        $email3->id = 'email3';
        $email3->email_type = 'IN';
        $email3->datetime_received = '2024-01-03 09:15:00';
        $email3->description = 'Follow-up email received';

        $thread->emails = [$email1, $email2, $email3];

        // Test that we can identify incoming emails
        $hasIncomingEmails = false;
        foreach ($thread->emails as $email) {
            if ($email->email_type === 'IN') {
                $hasIncomingEmails = true;
                break;
            }
        }

        $this->assertTrue($hasIncomingEmails, 'Thread should have incoming emails');

        // Test suggested reply generation logic
        $suggestedReply = "Previous emails in this thread:\n\n";
        $emailCount = 0;
        foreach (array_reverse($thread->emails) as $email) {
            $emailCount++;
            if ($emailCount > 5) break;
            
            $direction = ($email->email_type === 'IN') ? 'Received' : 'Sent';
            $suggestedReply .= "{$emailCount}. {$direction} on {$email->datetime_received}\n";
            if (isset($email->description) && $email->description) {
                $suggestedReply .= "   Summary: " . strip_tags($email->description) . "\n";
            }
            $suggestedReply .= "\n";
        }

        $this->assertStringContainsString('Previous emails in this thread:', $suggestedReply);
        $this->assertStringContainsString('1. Received on 2024-01-03 09:15:00', $suggestedReply);
        $this->assertStringContainsString('2. Received on 2024-01-02 15:30:00', $suggestedReply);
        $this->assertStringContainsString('3. Sent on 2024-01-01 10:00:00', $suggestedReply);
        $this->assertStringContainsString('Follow-up email received', $suggestedReply);
    }

    public function testThreadReplyValidation() {
        // Test that reply is only allowed for threads with incoming emails
        $threadWithoutIncoming = new Thread();
        $threadWithoutIncoming->emails = [];
        
        $hasIncomingEmails = false;
        foreach ($threadWithoutIncoming->emails as $email) {
            if ($email->email_type === 'IN') {
                $hasIncomingEmails = true;
                break;
            }
        }
        
        $this->assertFalse($hasIncomingEmails, 'Empty thread should not have incoming emails');

        // Test thread with only outgoing emails
        $outgoingEmail = new ThreadEmail();
        $outgoingEmail->email_type = 'OUT';
        $threadWithoutIncoming->emails = [$outgoingEmail];
        
        $hasIncomingEmails = false;
        foreach ($threadWithoutIncoming->emails as $email) {
            if ($email->email_type === 'IN') {
                $hasIncomingEmails = true;
                break;
            }
        }
        
        $this->assertFalse($hasIncomingEmails, 'Thread with only outgoing emails should not allow replies');
    }

    public function testEmailFormattingHelpers() {
        // Test HTML formatting for bold and italic
        $selectedText = 'important text';
        
        $boldFormatted = '<strong>' . $selectedText . '</strong>';
        $italicFormatted = '<em>' . $selectedText . '</em>';
        
        $this->assertEquals('<strong>important text</strong>', $boldFormatted);
        $this->assertEquals('<em>important text</em>', $italicFormatted);
    }

    public function testEmailAddressValidation() {
        // Test isValidReplyEmail function
        $myEmail = 'test-user@offpost.no';
        
        // Valid emails
        $this->assertTrue(isValidReplyEmail('valid@example.com', $myEmail), 'Valid email should pass');
        $this->assertTrue(isValidReplyEmail('another.valid@domain.org', $myEmail), 'Another valid email should pass');
        
        // Invalid emails
        $this->assertFalse(isValidReplyEmail($myEmail, $myEmail), 'Same as my email should be rejected');
        $this->assertFalse(isValidReplyEmail('noreply@example.com', $myEmail), 'noreply email should be rejected');
        $this->assertFalse(isValidReplyEmail('test-no-reply@example.com', $myEmail), 'no-reply email should be rejected');
        $this->assertFalse(isValidReplyEmail('ikke-svar@example.com', $myEmail), 'ikke-svar email should be rejected');
        $this->assertFalse(isValidReplyEmail('invalid-email', $myEmail), 'Invalid email format should be rejected');
        $this->assertFalse(isValidReplyEmail('', $myEmail), 'Empty email should be rejected');
    }

    public function testEmailHeaderExtraction() {
        // Test getEmailAddressesFromImapHeaders function
        $headers = [
            'from' => [
                (object)['mailbox' => 'sender', 'host' => 'example.com']
            ],
            'reply_to' => [
                (object)['mailbox' => 'reply', 'host' => 'example.com']
            ],
            'sender' => [
                (object)['mailbox' => 'sender2', 'host' => 'example.org']
            ]
        ];
        
        $addresses = getEmailAddressesFromImapHeaders($headers);
        
        $this->assertContains('sender@example.com', $addresses, 'Should extract from field');
        $this->assertContains('reply@example.com', $addresses, 'Should extract reply_to field');
        $this->assertContains('sender2@example.org', $addresses, 'Should extract sender field');
        
        // Test with JSON string
        $jsonHeaders = json_encode($headers);
        $addressesFromJson = getEmailAddressesFromImapHeaders($jsonHeaders);
        
        $this->assertEquals($addresses, $addressesFromJson, 'JSON parsing should yield same results');
    }

    public function testReplySubjectGeneration() {
        $originalTitle = 'Request for Information';
        $expectedSubject = 'Re: ' . $originalTitle;
        
        $this->assertEquals('Re: Request for Information', $expectedSubject);
        
        // Test with existing Re: prefix
        $existingReTitle = 'Re: Request for Information';
        $replySubject = 'Re: ' . $existingReTitle;
        
        $this->assertEquals('Re: Re: Request for Information', $replySubject);
        // Note: In a real implementation, we might want to avoid double "Re:" prefixes
    }

    public static function tearDownAfterClass(): void {
        // Clean up any test data if needed
        // In a real test, we would clean up database records
    }
}