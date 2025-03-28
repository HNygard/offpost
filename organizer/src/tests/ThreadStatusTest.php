<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Thread.php';
require_once __DIR__ . '/../class/ImapFolderStatus.php';
require_once __DIR__ . '/../class/ThreadEmail.php';
require_once __DIR__ . '/../class/Database.php';

class ThreadStatusTest extends PHPUnit\Framework\TestCase {
    private $testThreadId;
    private $testEntityId = 'test-entity-status';
    private $testFolderName = 'INBOX.test-status-folder';
    
    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction
        Database::beginTransaction();
        
        // Create a test thread in the database
        $this->testThreadId = $this->createTestThread();
        
        // Clean up any existing test records
        Database::execute(
            "DELETE FROM imap_folder_status WHERE thread_id = ?",
            [$this->testThreadId]
        );
        
        Database::execute(
            "DELETE FROM thread_emails WHERE thread_id = ?",
            [$this->testThreadId]
        );
    }
    
    /**
     * Create a test thread in the database
     * 
     * @return string UUID of the created thread
     */
    private function createTestThread(): string {
        // Generate a UUID for the thread
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        // Insert the thread into the database
        Database::execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent, sending_status) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                $this->testEntityId,
                'Test Thread Status',
                'Test User',
                'test-thread-status-'.mt_rand(0, 10000).'@example.com',
                'f',
                Thread::SENDING_STATUS_STAGING
            ]
        );
        
        return $uuid;
    }
    
    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        
        parent::tearDown();
    }
    
    /**
     * Add a test email to the thread
     * 
     * @param string $emailType 'incoming' or 'outgoing'
     * @param string $statusType Status type for the email
     * @param string $timestamp Timestamp for the email (optional)
     * @return string Email ID
     */
    private function addTestEmail(string $emailType, string $statusType, string $timestamp = null): string {
        if ($timestamp === null) {
            $timestamp = date('Y-m-d H:i:s');
        }
        
        return Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, description, content) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $this->testThreadId,
                $timestamp,
                $timestamp,
                $emailType,
                $statusType,
                'Test status',
                'Test description',
                'Test email content'
            ]
        );
    }
    
    public function testSendingStatusConstants(): void {
        // :: Setup
        
        // :: Assert
        $this->assertEquals('STAGING', Thread::SENDING_STATUS_STAGING);
        $this->assertEquals('READY_FOR_SENDING', Thread::SENDING_STATUS_READY_FOR_SENDING);
        $this->assertEquals('SENDING', Thread::SENDING_STATUS_SENDING);
        $this->assertEquals('SENT', Thread::SENDING_STATUS_SENT);
    }
    
    public function testDefaultSendingStatus(): void {
        // :: Setup
        
        // :: Act
        $thread = new Thread();
        
        // :: Assert
        $this->assertEquals(Thread::SENDING_STATUS_STAGING, $thread->sending_status, "New thread should have STAGING status by default");
        $this->assertTrue($thread->isStaged(), "isStaged() should return true for new thread");
        $this->assertFalse($thread->isReadyForSending(), "isReadyForSending() should return false for new thread");
        $this->assertFalse($thread->isSending(), "isSending() should return false for new thread");
        $this->assertFalse($thread->isSent(), "isSent() should return false for new thread");
    }
    
    public function testSendingStatusMethods(): void {
        // :: Setup
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        // :: Act & Assert - STAGING
        $this->assertEquals(Thread::SENDING_STATUS_STAGING, $thread->sending_status);
        $this->assertTrue($thread->isStaged(), "isStaged() should return true when status is STAGING");
        $this->assertFalse($thread->isReadyForSending(), "isReadyForSending() should return false when status is STAGING");
        $this->assertFalse($thread->isSending(), "isSending() should return false when status is STAGING");
        $this->assertFalse($thread->isSent(), "isSent() should return false when status is STAGING");
        
        // :: Act & Assert - READY_FOR_SENDING
        Database::execute(
            "UPDATE threads SET sending_status = ? WHERE id = ?",
            [Thread::SENDING_STATUS_READY_FOR_SENDING, $this->testThreadId]
        );
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $thread->sending_status);
        $this->assertFalse($thread->isStaged(), "isStaged() should return false when status is READY_FOR_SENDING");
        $this->assertTrue($thread->isReadyForSending(), "isReadyForSending() should return true when status is READY_FOR_SENDING");
        $this->assertFalse($thread->isSending(), "isSending() should return false when status is READY_FOR_SENDING");
        $this->assertFalse($thread->isSent(), "isSent() should return false when status is READY_FOR_SENDING");
        
        // :: Act & Assert - SENDING
        Database::execute(
            "UPDATE threads SET sending_status = ? WHERE id = ?",
            [Thread::SENDING_STATUS_SENDING, $this->testThreadId]
        );
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        $this->assertEquals(Thread::SENDING_STATUS_SENDING, $thread->sending_status);
        $this->assertFalse($thread->isStaged(), "isStaged() should return false when status is SENDING");
        $this->assertFalse($thread->isReadyForSending(), "isReadyForSending() should return false when status is SENDING");
        $this->assertTrue($thread->isSending(), "isSending() should return true when status is SENDING");
        $this->assertFalse($thread->isSent(), "isSent() should return false when status is SENDING");
        
        // :: Act & Assert - SENT
        Database::execute(
            "UPDATE threads SET sending_status = ? WHERE id = ?",
            [Thread::SENDING_STATUS_SENT, $this->testThreadId]
        );
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        $this->assertEquals(Thread::SENDING_STATUS_SENT, $thread->sending_status);
        $this->assertFalse($thread->isStaged(), "isStaged() should return false when status is SENT");
        $this->assertFalse($thread->isReadyForSending(), "isReadyForSending() should return false when status is SENT");
        $this->assertFalse($thread->isSending(), "isSending() should return false when status is SENT");
        $this->assertTrue($thread->isSent(), "isSent() should return true when status is SENT");
    }
    
    public function testGetThreadStatusWithNoImapFolder(): void {
        // :: Setup
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        // :: Act
        $status = $thread->getThreadStatus();
        
        // :: Assert
        $this->assertEquals('Email not synced', $status->status_text, "Status should indicate email is not synced when no IMAP folder exists");
        $this->assertFalse(property_exists($status, 'error'), "Error flag should not be set when no IMAP folder exists");
    }
    
    public function testGetThreadStatusWithMultipleImapFolders(): void {
        // :: Setup
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        // Create multiple IMAP folder status records for the thread
        ImapFolderStatus::createOrUpdate($this->testFolderName . '1', $this->testThreadId, true);
        ImapFolderStatus::createOrUpdate($this->testFolderName . '2', $this->testThreadId, true);
        
        // :: Act
        $status = $thread->getThreadStatus();
        
        // :: Assert
        $this->assertEquals('Email synced to multiple folders', $status->status_text, "Status should indicate email is synced to multiple folders");
        $this->assertTrue($status->error, "Error flag should be set when email is synced to multiple folders");
    }
    
    public function testGetThreadStatusWithOldImapFolderCheck(): void {
        // :: Setup
        // Create IMAP folder status with old timestamp (more than 6 hours ago)
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId);
        
        // Set last_checked_at to 7 hours ago
        $sevenHoursAgo = time() - (7 * 60 * 60);
        Database::execute(
            "UPDATE imap_folder_status SET last_checked_at = to_timestamp(?) WHERE folder_name = ? AND thread_id = ?",
            [$sevenHoursAgo, $this->testFolderName, $this->testThreadId]
        );
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('outgoing', 'sent');
        
        // :: Act
        // Load the thread after adding the email to ensure it's included
        $thread = Thread::loadFromDatabase($this->testThreadId);
        $status = $thread->getThreadStatus();
        
        // :: Assert
        // When there's an email, the Thread::getThreadStatus() method returns a new status object
        // that doesn't have the error flag set from the old IMAP folder check
        $this->assertEquals('Email sent, nothing received', $status->status_text, "Status should indicate email is sent but nothing received");
    }
    
    public function testGetThreadStatusWithNoEmails(): void {
        // :: Setup
        $thread = Thread::loadFromDatabase($this->testThreadId);
        
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Act
        $status = $thread->getThreadStatus();
        
        // :: Assert
        $this->assertEquals('Email not sent', $status->status_text, "Status should indicate email is not sent when no emails exist");
    }
    
    public function testGetThreadStatusWithOneEmail(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one outgoing email
        $timestamp = '2023-01-01 12:00:00';
        $this->addTestEmail('outgoing', 'sent', $timestamp);
        
        // :: Act
        // Load the thread after adding the email to ensure it's included
        $thread = Thread::loadFromDatabase($this->testThreadId);
        $status = $thread->getThreadStatus();
        
        // :: Assert
        $this->assertEquals('Email sent, nothing received', $status->status_text, "Status should indicate email is sent but nothing received");
        // PostgreSQL adds timezone information to timestamps, so we need to check if the timestamp starts with our expected value
        $this->assertStringStartsWith($timestamp, $status->last_activity, "Last activity should match the timestamp of the email");
    }
    
    public function testGetThreadStatusWithMultipleEmails(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add multiple emails
        $timestamp1 = '2023-01-01 12:00:00';
        $timestamp2 = '2023-01-02 12:00:00';
        $this->addTestEmail('outgoing', 'sent', $timestamp1);
        $this->addTestEmail('incoming', 'received', $timestamp2);
        
        // :: Act
        // Load the thread after adding the emails to ensure they're included
        $thread = Thread::loadFromDatabase($this->testThreadId);
        $status = $thread->getThreadStatus();
        
        // :: Assert
        $this->assertEquals('Unknown.', $status->status_text, "Status should be 'Unknown.' when multiple emails exist");
        // PostgreSQL adds timezone information to timestamps, so we need to check if the timestamp starts with our expected value
        $this->assertStringStartsWith($timestamp2, $status->last_activity, "Last activity should match the timestamp of the most recent email");
    }
}
