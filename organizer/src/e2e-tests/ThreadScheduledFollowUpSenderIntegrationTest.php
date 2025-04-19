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
            'INBOX.test-folder',
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
     * Create a test thread with status EMAIL_SENT_NOTHING_RECEIVED
     * 
     * @return string The thread ID
     */
    private function createTestThread() {
        // Generate a UUID for the thread
        $threadId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        // Insert a test thread directly into the database
        Database::execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sending_status, sent) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $threadId,
                $this->entityId,
                'Test Thread for Follow-up',
                'Test User',
                'test-user@example.com',
                Thread::SENDING_STATUS_SENT,
                true
            ]
        );
        
        // Insert a test email for this thread (outgoing email)
        Database::execute(
            "INSERT INTO thread_emails (thread_id, email_type, timestamp_received) 
             VALUES (?, ?, ?)",
            [
                $threadId,
                'OUT',
                '2025-04-19 10:21:00'
            ]
        );
        
        // Create an IMAP folder status record for this thread
        Database::execute(
            "INSERT INTO imap_folder_status (thread_id, folder_name, last_checked_at) 
             VALUES (?, ?, NOW())",
            [
                $threadId,
                'INBOX.test-folder'
            ]
        );
        
        // Create IMAP folder status records for INBOX and INBOX.Sent
        if (!$this->checkInboxFolderExists('INBOX')) {
            Database::execute(
                "INSERT INTO imap_folder_status (folder_name, last_checked_at) 
                 VALUES (?, NOW())",
                ['INBOX']
            );
        }
        
        if (!$this->checkInboxFolderExists('INBOX.Sent')) {
            Database::execute(
                "INSERT INTO imap_folder_status (folder_name, last_checked_at) 
                 VALUES (?, NOW())",
                ['INBOX.Sent']
            );
        }
        
        return $threadId;
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
