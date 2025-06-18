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

    public function testThreadViewShowsReplyFormWhenIncomingEmailsExist() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email to the thread to enable reply functionality
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, ?, 'IN', 'processed', 'Email received', '2024-01-01 10:00:00', ?, 'Test incoming email content')",
            ['email-' . uniqid(), $threadId, time()]
        );

        // :: Act
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        $this->assertStringContainsString('Reply to Thread', $response->body, 'Should show reply form when incoming emails exist');
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
        $this->assertStringContainsString('Previous emails in this thread:', $response->body, 'Should have suggested reply content');
    }

    public function testThreadViewHidesReplyFormWhenNoIncomingEmails() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Don't add any incoming emails - only outgoing should exist

        // :: Act
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        $this->assertStringNotContainsString('Reply to Thread', $response->body, 'Should not show reply form when no incoming emails exist');
        $this->assertStringNotContainsString('id="reply-section"', $response->body, 'Should not have reply section');
    }

    public function testReplyFormSubmissionSaveDraft() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, ?, 'IN', 'processed', 'Email received', '2024-01-01 10:00:00', ?, 'Test incoming email for reply')",
            ['email-reply-' . uniqid(), $threadId, time()]
        );

        $replySubject = 'Re: Test Reply Subject';
        $replyBody = 'This is a test reply with <strong>bold</strong> text.';

        // :: Act
        $response = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => $replySubject,
                'reply_body' => $replyBody,
                'recipients' => ['test-recipient@example.com'], // Add recipients parameter
                'save_draft' => '1'
            ]
        );

        // :: Assert
        // Should redirect back to thread view
        $this->assertStringContainsString('Location: /thread-view?threadId=' . urlencode($threadId), $response->headers);

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
            "INSERT INTO thread_emails (id, thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, ?, 'IN', 'processed', 'Email received', '2024-01-01 10:00:00', ?, 'Test incoming email for send reply')",
            ['email-send-' . uniqid(), $threadId, time()]
        );

        $replySubject = 'Re: Test Send Reply Subject';
        $replyBody = 'This is a test reply to be sent with <em>italic</em> text.';

        // :: Act
        $response = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => $replySubject,
                'reply_body' => $replyBody,
                'recipients' => ['test-recipient@example.com'], // Add recipients parameter
                'send_reply' => '1'
            ]
        );

        // :: Assert
        // Should redirect back to thread view
        $this->assertStringContainsString('Location: /thread-view?threadId=' . urlencode($threadId), $response->headers);

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
        // Should fail with either missing parameters or no recipients selected
        $this->assertTrue(
            strpos($response->body, 'Missing required parameters') !== false ||
            strpos($response->body, 'No recipients selected') !== false,
            'Should fail with parameter validation error'
        );
    }

    public function testReplyFormRejectsThreadWithoutIncomingEmails() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Don't add any incoming emails

        // :: Act
        $response = $this->renderPage(
            '/thread-reply',
            'dev-user-id',
            'POST',
            '400 Bad Request',
            [
                'thread_id' => $threadId,
                'entity_id' => $entityId,
                'reply_subject' => 'Test Subject',
                'reply_body' => 'Test Body',
                'send_reply' => '1'
            ]
        );

        // :: Assert
        $this->assertStringContainsString('No incoming emails found', $response->body);
    }

    public function testSuccessAndErrorMessages() {
        // :: Setup
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];
        
        // Add an incoming email
        Database::execute(
            "INSERT INTO thread_emails (id, thread_id, email_type, status_type, status_text, datetime_received, timestamp_received, content)
             VALUES (?, ?, 'IN', 'processed', 'Email received', '2024-01-01 10:00:00', ?, 'Test email for messages')",
            ['email-msg-' . uniqid(), $threadId, time()]
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
                'recipients' => ['test-recipient@example.com'], // Add recipients parameter
                'send_reply' => '1'
            ]
        );

        // Follow the redirect to see the success message
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);

        // :: Assert
        $this->assertStringContainsString('Reply has been prepared for 1 recipient and will be sent shortly', $response->body, 'Should show success message with recipient count');
    }
}