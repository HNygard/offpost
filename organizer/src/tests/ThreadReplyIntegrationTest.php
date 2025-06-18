<?php

require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/ThreadHistory.php';
require_once __DIR__ . '/../class/Entity.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration test for thread reply functionality
 * This test verifies the complete flow from reply creation to email sending
 */
class ThreadReplyIntegrationTest extends TestCase {

    private static $testThreadId;
    private static $testEntityId = 'test-entity-reply-integration';
    private static $testUserId = 'test-user-reply-integration';

    /**
     * Generate a UUID v4
     */
    private static function generateUuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public static function setUpBeforeClass(): void {
        self::$testThreadId = self::generateUuid();
        
        // Clean up any existing test data
        self::cleanupTestData();
    }

    public static function tearDownAfterClass(): void {
        self::cleanupTestData();
    }

    private static function cleanupTestData() {
        try {
            // Clean up ThreadEmailSending records
            Database::execute(
                "DELETE FROM thread_email_sendings WHERE thread_id = ?",
                [self::$testThreadId]
            );
            
            // Clean up thread history
            Database::execute(
                "DELETE FROM thread_history WHERE thread_id = ?",
                [self::$testThreadId]
            );
            
            // Clean up thread emails (if they exist in database)
            Database::execute(
                "DELETE FROM thread_emails WHERE thread_id = ?",
                [self::$testThreadId]
            );
            
        } catch (Exception $e) {
            // Ignore cleanup errors in case tables don't exist or are empty
        }
    }

    public function testCreateReplyEmailSending() {
        $threadId = self::$testThreadId;
        $replySubject = 'Re: Test Reply Integration';
        $replyBody = 'This is a test reply with <strong>bold</strong> text.';
        $recipientEmail = 'test-recipient@example.com';
        $senderEmail = 'test-sender@offpost.no';
        $senderName = 'Test Sender';

        // Test creating a reply in STAGING status (draft)
        $draftReply = ThreadEmailSending::create(
            $threadId,
            $replyBody,
            $replySubject,
            $recipientEmail,
            $senderEmail,
            $senderName,
            ThreadEmailSending::STATUS_STAGING
        );

        $this->assertNotNull($draftReply, 'Draft reply should be created successfully');
        $this->assertEquals($threadId, $draftReply->thread_id);
        $this->assertEquals($replySubject, $draftReply->email_subject);
        $this->assertEquals($replyBody, $draftReply->email_content);
        $this->assertEquals($recipientEmail, $draftReply->email_to);
        $this->assertEquals($senderEmail, $draftReply->email_from);
        $this->assertEquals($senderName, $draftReply->email_from_name);
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $draftReply->status);
        $this->assertTrue($draftReply->isStaged());

        // Test creating a reply ready for sending
        $readyReply = ThreadEmailSending::create(
            $threadId,
            $replyBody,
            $replySubject,
            $recipientEmail,
            $senderEmail,
            $senderName,
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );

        $this->assertNotNull($readyReply, 'Ready reply should be created successfully');
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $readyReply->status);
        $this->assertTrue($readyReply->isReadyForSending());

        // Verify we can retrieve the emails by thread ID
        $threadEmails = ThreadEmailSending::getByThreadId($threadId);
        $this->assertGreaterThanOrEqual(2, count($threadEmails), 'Should have at least 2 email sending records');

        // Test updating status from staging to ready
        $updateResult = ThreadEmailSending::updateStatus(
            $draftReply->id,
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );
        $this->assertTrue($updateResult, 'Status update should succeed');

        // Verify the status was updated
        $updatedReply = ThreadEmailSending::getById($draftReply->id);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $updatedReply->status);
    }

    public function testThreadHistoryLogging() {
        $threadId = self::$testThreadId;
        $userId = self::$testUserId;
        
        // Create a reply to get an email sending ID
        $emailSending = ThreadEmailSending::create(
            $threadId,
            'Test history logging',
            'Test Subject',
            'test@example.com',
            'sender@offpost.no',
            'Test Sender',
            ThreadEmailSending::STATUS_STAGING
        );

        $this->assertNotNull($emailSending);

        // Log the reply action
        $history = new ThreadHistory();
        $action = 'Reply draft saved';
        $details = [
            'email_sending_id' => $emailSending->id,
            'subject' => $emailSending->email_subject
        ];

        $historyId = $history->logAction($threadId, $userId, $action, $details);
        $this->assertNotNull($historyId, 'History logging should succeed');

        // Retrieve and verify the history entry
        $historyEntries = $history->getHistoryForThread($threadId);
        $this->assertNotEmpty($historyEntries, 'Should have history entries');

        $latestEntry = $historyEntries[0]; // Assuming newest first
        $this->assertEquals($threadId, $latestEntry->thread_id);
        $this->assertEquals($userId, $latestEntry->user_id);
        $this->assertEquals($action, $latestEntry->action);
    }

    public function testEmailSendingQueue() {
        $threadId = self::$testThreadId;

        // Create multiple emails in different statuses
        $stagingEmail = ThreadEmailSending::create(
            $threadId,
            'Staging email',
            'Subject 1',
            'test1@example.com',
            'sender@offpost.no',
            'Test Sender',
            ThreadEmailSending::STATUS_STAGING
        );

        $readyEmail = ThreadEmailSending::create(
            $threadId,
            'Ready email',
            'Subject 2',
            'test2@example.com',
            'sender@offpost.no',
            'Test Sender',
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );

        $sendingEmail = ThreadEmailSending::create(
            $threadId,
            'Sending email',
            'Subject 3',
            'test3@example.com',
            'sender@offpost.no',
            'Test Sender',
            ThreadEmailSending::STATUS_SENDING
        );

        // Test finding the next email for sending
        $nextForSending = ThreadEmailSending::findNextForSending();
        $this->assertNotNull($nextForSending, 'Should find an email ready for sending');
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $nextForSending->status);
        $this->assertEquals($readyEmail->id, $nextForSending->id);

        // Update the ready email to sending status
        ThreadEmailSending::updateStatus($readyEmail->id, ThreadEmailSending::STATUS_SENDING);

        // Should not find any more emails ready for sending
        $nextForSending2 = ThreadEmailSending::findNextForSending();
        $this->assertNull($nextForSending2, 'Should not find any more emails ready for sending');

        // Mark one as sent
        ThreadEmailSending::updateStatus(
            $sendingEmail->id,
            ThreadEmailSending::STATUS_SENT,
            'SMTP response test',
            'SMTP debug test'
        );

        $sentEmail = ThreadEmailSending::getById($sendingEmail->id);
        $this->assertEquals(ThreadEmailSending::STATUS_SENT, $sentEmail->status);
        $this->assertEquals('SMTP response test', $sentEmail->smtp_response);
        $this->assertEquals('SMTP debug test', $sentEmail->smtp_debug);
    }

    public function testEmailContentValidation() {
        $threadId = self::$testThreadId;

        // Test that HTML formatting is preserved
        $htmlContent = 'This has <strong>bold</strong> and <em>italic</em> text.';
        $emailSending = ThreadEmailSending::create(
            $threadId,
            $htmlContent,
            'HTML Test Subject',
            'test@example.com',
            'sender@offpost.no',
            'Test Sender'
        );

        $this->assertEquals($htmlContent, $emailSending->email_content);
        $this->assertStringContainsString('<strong>', $emailSending->email_content);
        $this->assertStringContainsString('<em>', $emailSending->email_content);

        // Test empty content handling
        $emptyContentEmail = ThreadEmailSending::create(
            $threadId,
            '',
            'Empty Content Test',
            'test@example.com',
            'sender@offpost.no',
            'Test Sender'
        );

        $this->assertNotNull($emptyContentEmail, 'Should allow empty content');
        $this->assertEquals('', $emptyContentEmail->email_content);
    }
}