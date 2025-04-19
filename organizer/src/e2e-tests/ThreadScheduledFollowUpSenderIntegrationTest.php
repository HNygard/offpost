<?php

require_once __DIR__ . '/pages/common/E2ETestSetup.php';
require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';
require_once __DIR__ . '/../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../class/ThreadEmailSending.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/Entity.php';
require_once __DIR__ . '/../class/Database.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration test for ThreadScheduledFollowUpSender
 */
class ThreadScheduledFollowUpSenderIntegrationTest extends TestCase {
    private $threadId;
    private $entityId;
    
    /**
     * Test the entire flow of finding a thread that needs follow-up and sending a follow-up email
     */
    public function testSendNextFollowUpEmailIntegration() {
        // :: Setup
        $followUpSender = new ThreadScheduledFollowUpSender();
        $testData = E2ETestSetup::createTestThread();

        // Set up that no other threads are up to date with IMAP
        Database::execute(
            "UPDATE imap_folder_status SET last_checked_at = NULL WHERE thread_id != ?",
            [$testData['thread']->id]
        );

        // Our thread should have updated status
        ImapFolderStatus::createOrUpdate(
            'INBOX.test-folder-' . mt_rand(0, 100000),
            $testData['thread']->id,
            updateLastChecked: true,
        );
        ImapFolderStatus::createOrUpdate(
            'INBOX',
            updateLastChecked: true,
        );
        ImapFolderStatus::createOrUpdate(
            'INBOX.Sent',
            updateLastChecked: true,
        );

        // Switch the default incoming email to an outgoing (follow up needs up-to-date imap + 1x OUT)
        Database::execute(
            "UPDATE thread_emails SET email_type = 'OUT' WHERE thread_id = ? AND email_type = 'IN'",
            [$testData['thread']->id]
        );
        
        // :: Act
        $result = $followUpSender->sendNextFollowUpEmail();
        
        // :: Assert
        $this->assertTrue($result['success'], 'Follow-up email should be scheduled successfully');
        $this->assertEquals('Follow-up email scheduled for sending', $result['message']);
        $this->assertEquals($testData['thread']->id, $result['thread_id']);
        
        // Verify that an email sending record was created
        $emailSendings = ThreadEmailSending::getByThreadId($testData['thread']->id);
        $this->assertCount(1, $emailSendings, 'One email sending record should be created');
        
        $emailSending = $emailSendings[0];
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $emailSending->status);
        $this->assertEquals('Purring - ' . $testData['thread']->title, $emailSending->email_subject);
        $this->assertStringContainsString($testData['thread']->title, $emailSending->email_content);
        $this->assertEquals($testData['thread']->my_email, $emailSending->email_from);
        $this->assertEquals('public-entity@dev.offpost.no', $emailSending->email_to);
    }

    /**
     * Check if an INBOX folder exists
     * 
     * @param string $folderName The folder name
     * @return bool True if the folder exists
     */
    private function checkInboxFolderExists($folderName) {
        $result = Database::queryOne(
            "SELECT COUNT(*) as count FROM imap_folder_status WHERE folder_name = ?",
            [$folderName]
        );
        
        return $result && $result['count'] > 0;
    }
}
