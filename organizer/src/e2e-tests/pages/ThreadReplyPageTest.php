<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');
require_once(__DIR__ . '/../../class/ThreadEmailSending.php');
require_once(__DIR__ . '/../../class/ThreadEmail.php');

class ThreadReplyPageTest extends E2EPageTestCase {

    private static $testThreadId;
    private static $testEntityId = 'test-entity-reply-e2e';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$testThreadId = 'thread-reply-e2e-test-' . uniqid();
        self::cleanupTestData();
    }

    public static function tearDownAfterClass(): void {
        self::cleanupTestData();
        parent::tearDownAfterClass();
    }

    private static function cleanupTestData() {
        try {
            Database::execute(
                "DELETE FROM thread_email_sendings WHERE thread_id = ?",
                [self::$testThreadId]
            );
            Database::execute(
                "DELETE FROM thread_emails WHERE thread_id = ?",
                [self::$testThreadId]
            );
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }

    public function testThreadViewShowsReplyFormWhenValidRecipientsExist() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email to the thread to enable reply functionality
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, 'IN', 'info', 'Email received', '2024-01-01 10:00:00', '2024-01-01 10:00:00', 'Test incoming email content')",
            [$threadId]
        );

        // :: Act
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        $this->assertStringContainsString('class="button">Send reply', $response->body, 'Should show reply form when valid recipients exist');
        $this->assertStringContainsString('id="reply-section"', $response->body, 'Should have reply section');
        $this->assertStringContainsString('name="reply_subject"', $response->body, 'Should have reply subject field');
        $this->assertStringContainsString('name="reply_body"', $response->body, 'Should have reply body field');
        $this->assertStringContainsString('name="send_reply"', $response->body, 'Should have send reply button');
        $this->assertStringContainsString('name="save_draft"', $response->body, 'Should have save draft button');
        
        // Check for formatting buttons
        $this->assertStringContainsString('formatText(\'bold\')', $response->body, 'Should have bold formatting button');
        $this->assertStringContainsString('formatText(\'italic\')', $response->body, 'Should have italic formatting button');
        $this->assertStringContainsString('insertSuggestedReply()', $response->body, 'Should have suggested reply button');
        
        // Check for suggested reply content
        $this->assertStringContainsString('Tidligere e-poster:', $response->body, 'Should have suggested reply content');
    }

    public function testThreadViewShowsReplyFormWithOnlyOutgoingEmails() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Ensure only outgoing emails exist (entity email is still available as recipient)
        Database::execute(
            "UPDATE thread_emails SET email_type = 'OUT' WHERE thread_id = ?",
            [$threadId]
        );

        // :: Act
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert - Should now SHOW reply form because entity email is a valid recipient
        $this->assertStringContainsString('class="button">Send reply', $response->body, 'Should show reply form when entity has valid email even without incoming emails');
        $this->assertStringContainsString('id="reply-section"', $response->body, 'Should have reply section');
    }

    public function testReplyFormSubmissionSaveDraft() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, 'IN', 'info', 'Email received', '2024-01-01 10:00:00', '2024-01-01 10:00:00', 'Test incoming email for reply')",
            [$threadId]
        );

        $replySubject = 'Re: Test Reply Subject';
        $replyBody = 'This is a test reply with <strong>bold</strong> text.';

        // :: Act
        $response1 = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => $replySubject,
                'reply_body' => $replyBody,
                'recipient' => 'public-entity@dev.offpost.no',
                'save_draft' => '1'
            ]
        );
        $response = $this->renderPage(path: '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        // Should redirect back to thread view
        $this->assertStringContainsString('Location: /thread-view?threadId=' . urlencode($threadId), $response1->headers);

        // Assert the success message
        $this->assertStringContainsString('Reply draft has been saved for', $response->body, 'Should show draft saved message');

        // Verify draft was saved in database
        $emailSendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertNotEmpty($emailSendings, 'Should have created email sending record');
        
        $draftEmail = null;
        foreach ($emailSendings as $email) {
            if ($email->email_subject === $replySubject) {
                $draftEmail = $email;
                break;
            }
        }
        
        $this->assertNotNull($draftEmail, 'Should find the draft email');
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $draftEmail->status, 'Draft should be in STAGING status');
        $this->assertEquals($replyBody, $draftEmail->email_content, 'Should preserve reply body content');
        $this->assertEquals($threadId, $draftEmail->thread_id, 'Should be associated with correct thread');
    }

    public function testReplyFormSubmissionSendReply() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, 'IN', 'info', 'Email received', '2024-01-01 10:00:00', '2024-01-01 10:00:00', 'Test incoming email for send reply')",
            [$threadId]
        );

        $replySubject = 'Re: Test Send Reply Subject';
        $replyBody = 'This is a test reply to be sent with <em>italic</em> text.';

        // :: Act
        $response1 = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => $replySubject,
                'reply_body' => $replyBody,
                'recipient' => 'public-entity@dev.offpost.no', // Single recipient parameter
                'send_reply' => '1'
            ]
        );
        $response = $this->renderPage(path: '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        // Should redirect back to thread view
        $this->assertStringContainsString('Location: /thread-view?threadId=' . urlencode($threadId), $response1->headers);

        // Assert the success message
        $this->assertStringContainsString('Reply has been prepared for ', $response->body, 'Should show draft saved message');

        // Verify email was marked for sending in database
        $emailSendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertNotEmpty($emailSendings, 'Should have created email sending record');
        
        $readyEmail = null;
        foreach ($emailSendings as $email) {
            if ($email->email_subject === $replySubject) {
                $readyEmail = $email;
                break;
            }
        }
        
        $this->assertNotNull($readyEmail, 'Should find the ready email');
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $readyEmail->status, 'Reply should be in READY_FOR_SENDING status');
        $this->assertEquals($replyBody, $readyEmail->email_content, 'Should preserve reply body content');
        $this->assertEquals($threadId, $readyEmail->thread_id, 'Should be associated with correct thread');
    }

    public function testReplyFormValidation() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];

        // :: Act - Test missing required fields
        $response = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '400 Bad Request',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                // Missing reply_subject and reply_body
                'send_reply' => '1'
            ]
        );

        // :: Assert
        // Should fail with either missing parameters or no recipient selected
        $this->assertTrue(
            strpos($response->body, 'Missing required parameters') !== false ||
            strpos($response->body, 'No recipient selected') !== false,
            'Should fail with parameter validation error'
        );
    }

    public function testReplyFormAcceptsThreadWithoutIncomingEmailsButValidRecipient() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Don't add any incoming emails, but entity email should be valid recipient

        // :: Act
        $response = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '302 Found', // Should now succeed with redirect
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => 'Test Subject',
                'reply_body' => 'Test Body',
                'recipient' => 'public-entity@dev.offpost.no', // Valid entity email
                'send_reply' => '1'
            ]
        );

        // :: Assert - Should succeed and redirect
        $this->assertStringContainsString('Location: /thread-view?threadId=' . urlencode($threadId), $response->headers, 'Should redirect to thread view on success');
    }

    public function testSuccessAndErrorMessages() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, 'IN', 'info', 'Email received', '2024-01-01 10:00:00', '2024-01-01 10:00:00', 'Test email for messages')",
            [$threadId]
        );

        // :: Act - Submit a successful reply
        $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => 'Test Success Subject',
                'reply_body' => 'Test success body',
                'recipient' => 'public-entity@dev.offpost.no', // Use the actual recipient that getThreadReplyRecipients() returns
                'send_reply' => '1'
            ]
        );

        // Follow the redirect to see the success message
        $response = $this->renderPage(path: '/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        $this->assertStringContainsString('Reply has been prepared for public-entity@dev.offpost.no and will be sent shortly', $response->body, 'Should show success message with recipient email');
    }
}
