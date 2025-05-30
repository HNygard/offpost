<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadStatusRepository.php';
require_once __DIR__ . '/../class/ImapFolderStatus.php';
require_once __DIR__ . '/../class/Database.php';

class ThreadStatusRepositoryTest extends PHPUnit\Framework\TestCase {
    private $testThreadId;
    private $testEntityId = 'test-entity-status-repo';
    private $testFolderName = 'INBOX.test-status-repo-folder';
    
    protected function setUp(): void {
        parent::setUp();

        // Clean database tables
        $db = new Database();
        $db->execute("DELETE FROM imap_folder_status");
        
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

        ImapFolderStatus::createOrUpdate('INBOX', updateLastChecked: true);
        ImapFolderStatus::createOrUpdate('INBOX.Sent', updateLastChecked: true);
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
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent, sending_status, archived) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                $this->testEntityId,
                'Test Thread Status Repository',
                'Test User',
                'test-thread-status-repo-'.mt_rand(0, 10000).'@example.com',
                'f',
                'STAGING',
                'f'
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
     * Add a test email to a specific thread
     * 
     * @param string $emailType 'incoming' or 'outgoing'
     * @param string $statusType Status type for the email
     * @param string|null $timestamp Timestamp for the email (optional)
     * @param string|null $threadId Thread ID (optional, defaults to $this->testThreadId)
     * @return string Email ID
     */
    private function addTestEmail(string $emailType, string $statusType, ?string $timestamp = null, ?string $threadId = null): string {
        if ($timestamp === null) {
            $timestamp = date('Y-m-d H:i:s');
        }
        
        if ($threadId === null) {
            $threadId = $this->testThreadId;
        }
        
        return Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, description, content) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [
                $threadId,
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
    
    public function testStatusConstants(): void {
        // :: Setup
        
        // :: Assert
        $this->assertEquals('ERROR_NO_FOLDER_FOUND', ThreadStatusRepository::ERROR_NO_FOLDER_FOUND);
        $this->assertEquals('ERROR_MULTIPLE_FOLDERS', ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS);
        $this->assertEquals('ERROR_NO_SYNC', ThreadStatusRepository::ERROR_NO_SYNC);
        $this->assertEquals('ERROR_OLD_SYNC_REQUESTED_UPDATE', ThreadStatusRepository::ERROR_OLD_SYNC_REQUESTED_UPDATE);
        $this->assertEquals('ERROR_OLD_SYNC', ThreadStatusRepository::ERROR_OLD_SYNC);
        $this->assertEquals('ERROR_INBOX_SYNC', ThreadStatusRepository::ERROR_INBOX_SYNC);
        $this->assertEquals('ERROR_SENT_SYNC', ThreadStatusRepository::ERROR_SENT_SYNC);
        $this->assertEquals('NOT_SENT', ThreadStatusRepository::NOT_SENT);
        $this->assertEquals('EMAIL_SENT_NOTHING_RECEIVED', ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED);
        $this->assertEquals('STATUS_OK', ThreadStatusRepository::STATUS_OK);
    }
    
    public function testGetThreadStatusWithNoImapFolder(): void {
        // :: Setup
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_NO_FOLDER_FOUND, $status, "Status should be ERROR_NO_FOLDER_FOUND when no IMAP folder exists");
    }
    
    public function testGetThreadStatusWithMultipleImapFolders(): void {
        // :: Setup
        // Create multiple IMAP folder status records for the thread
        ImapFolderStatus::createOrUpdate($this->testFolderName . '1', $this->testThreadId, true);
        ImapFolderStatus::createOrUpdate($this->testFolderName . '2', $this->testThreadId, true);
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_MULTIPLE_FOLDERS, $status, "Status should be ERROR_MULTIPLE_FOLDERS when email is synced to multiple folders");
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
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_OLD_SYNC, $status, "Status should be ERROR_OLD_SYNC when IMAP folder was last checked more than 6 hours ago");
    }
    
    public function testGetThreadStatusWithRequestedUpdateTime(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Set requested_update_time to current time
        Database::execute(
            "UPDATE imap_folder_status SET requested_update_time = NOW() WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_OLD_SYNC_REQUESTED_UPDATE, $status, 
            "Status should be ERROR_OLD_SYNC_REQUESTED_UPDATE when requested_update_time is not null");
    }
    
    public function testGetThreadStatusWithNoEmails(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::NOT_SENT, $status, "Status should be NOT_SENT when no emails exist");
    }
    
    public function testGetThreadStatusWithOneEmail(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one outgoing email
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED, $status, "Status should be EMAIL_SENT_NOTHING_RECEIVED when one email exists");
    }
    
    public function testGetThreadStatusWithMultipleEmails(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add multiple emails
        $this->addTestEmail('OUT', 'sent');
        $this->addTestEmail('IN', 'received');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::STATUS_OK, $status, "Status should be STATUS_OK when multiple emails exist");
    }
    
    public function testGetAllThreadStatusesEfficient(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one email
        $this->addTestEmail('OUT', 'sent');
        
        // Create a second thread
        $secondThreadId = $this->createTestThread();
        $secondFolderName = $this->testFolderName . '-second';
        ImapFolderStatus::createOrUpdate($secondFolderName, $secondThreadId, true);
        
        // :: Act
        $statuses = ThreadStatusRepository::getAllThreadStatusesEfficient();
        
        // :: Assert
        $this->assertIsArray($statuses, "getAllThreadStatusesEfficient should return an array");
        $this->assertArrayHasKey($this->testThreadId, $statuses, "Array should contain the test thread ID as a key");
        $this->assertArrayHasKey($secondThreadId, $statuses, "Array should contain the second thread ID as a key");
        $this->assertTrue((time() - $statuses[$this->testThreadId]->email_last_sent) < 20);
        $this->assertTrue((time() - $statuses[$this->testThreadId]->email_server_last_checked_at) < 20);
        $this->assertTrue((time() - $statuses[$secondThreadId]->email_server_last_checked_at) < 20);
        $expected_status = new ThreadStatus();
        $expected_status->thread_id = $this->testThreadId;
        $expected_status->entity_id = 'test-entity-status-repo';
        $expected_status->email_count_in = 0;
        $expected_status->email_count_out = 1;
        $expected_status->status = ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED;
        $expected_status->email_server_last_checked_at = $statuses[$this->testThreadId]->email_server_last_checked_at;
        $expected_status->email_last_activity = $statuses[$this->testThreadId]->email_last_activity;
        $expected_status->email_last_sent = $statuses[$this->testThreadId]->email_last_activity;
        $expected_status->email_last_received = null;
        $this->assertEquals($expected_status, $statuses[$this->testThreadId], "Status for test thread should be EMAIL_SENT_NOTHING_RECEIVED");
        $expected_status2 = new ThreadStatus();
        $expected_status2->thread_id = $secondThreadId;
        $expected_status2->entity_id = 'test-entity-status-repo';
        $expected_status2->email_count_in = 0;
        $expected_status2->email_count_out = 0;
        $expected_status2->status = ThreadStatusRepository::NOT_SENT;
        $expected_status2->email_server_last_checked_at = $statuses[$this->testThreadId]->email_server_last_checked_at;
        #$expected_status2->email_last_activity = $statuses[$this->testThreadId]->email_last_activity;
        #$expected_status2->email_last_sent = $statuses[$this->testThreadId]->email_last_activity;
        $this->assertEquals($expected_status2, $statuses[$secondThreadId], "Status for second thread should be NOT_SENT");
    }
    
    public function testGetThreadsByStatus(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one email
        $this->addTestEmail('OUT', 'sent');
        
        // Create a second thread with the same status
        $secondThreadId = $this->createTestThread();
        $secondFolderName = $this->testFolderName . '-second';
        ImapFolderStatus::createOrUpdate($secondFolderName, $secondThreadId, true);
        $this->addTestEmail('OUT', 'sent', null, $secondThreadId);
        
        // Create a third thread with a different status
        $thirdThreadId = $this->createTestThread();
        $thirdFolderName = $this->testFolderName . '-third';
        ImapFolderStatus::createOrUpdate($thirdFolderName, $thirdThreadId, true);
        
        // :: Act
        $emailSentThreads = array_keys(ThreadStatusRepository::getThreadsByStatus(ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED));
        $notSentThreads = array_keys(ThreadStatusRepository::getThreadsByStatus(ThreadStatusRepository::NOT_SENT));
        
        // :: Assert
        $this->assertIsArray($emailSentThreads, "getThreadsByStatus should return an array");
        $this->assertIsArray($notSentThreads, "getThreadsByStatus should return an array");
        
        $this->assertContains($this->testThreadId, $emailSentThreads, "EMAIL_SENT_NOTHING_RECEIVED threads should contain the test thread ID");
        $this->assertContains($secondThreadId, $emailSentThreads, "EMAIL_SENT_NOTHING_RECEIVED threads should contain the second thread ID");
        $this->assertNotContains($thirdThreadId, $emailSentThreads, "EMAIL_SENT_NOTHING_RECEIVED threads should not contain the third thread ID");
        
        $this->assertContains($thirdThreadId, $notSentThreads, "NOT_SENT threads should contain the third thread ID");
        $this->assertNotContains($this->testThreadId, $notSentThreads, "NOT_SENT threads should not contain the test thread ID");
        $this->assertNotContains($secondThreadId, $notSentThreads, "NOT_SENT threads should not contain the second thread ID");
    }
    
    public function testGetAllThreadStatusesEfficientWithThreadIdsFilter(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one email
        $this->addTestEmail('OUT', 'sent');
        
        // Create a second thread
        $secondThreadId = $this->createTestThread();
        $secondFolderName = $this->testFolderName . '-second';
        ImapFolderStatus::createOrUpdate($secondFolderName, $secondThreadId, true);
        
        // Create a third thread
        $thirdThreadId = $this->createTestThread();
        $thirdFolderName = $this->testFolderName . '-third';
        ImapFolderStatus::createOrUpdate($thirdFolderName, $thirdThreadId, true);
        
        // :: Act
        // Filter by first and third thread IDs
        $filteredStatuses = ThreadStatusRepository::getAllThreadStatusesEfficient([$this->testThreadId, $thirdThreadId]);
        
        // :: Assert
        $this->assertIsArray($filteredStatuses, "getAllThreadStatusesEfficient with thread IDs filter should return an array");
        $this->assertCount(2, $filteredStatuses, "Filtered statuses should contain exactly 2 threads");
        $this->assertArrayHasKey($this->testThreadId, $filteredStatuses, "Filtered statuses should contain the test thread ID");
        $this->assertArrayHasKey($thirdThreadId, $filteredStatuses, "Filtered statuses should contain the third thread ID");
        $this->assertArrayNotHasKey($secondThreadId, $filteredStatuses, "Filtered statuses should not contain the second thread ID");
        $this->assertTrue((time() - $filteredStatuses[$this->testThreadId]->email_last_activity) < 20);
        $this->assertTrue((time() - $filteredStatuses[$this->testThreadId]->email_server_last_checked_at) < 20);
        $this->assertTrue((time() - $filteredStatuses[$thirdThreadId]->email_server_last_checked_at) < 20);
        $expected_status = new ThreadStatus();
        $expected_status->thread_id = $this->testThreadId;
        $expected_status->entity_id = 'test-entity-status-repo';
        $expected_status->email_count_in = 0;
        $expected_status->email_count_out = 1;
        $expected_status->status = ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED;
        $expected_status->email_server_last_checked_at = $filteredStatuses[$this->testThreadId]->email_server_last_checked_at;
        $expected_status->email_last_activity = $filteredStatuses[$this->testThreadId]->email_last_activity;
        $expected_status->email_last_sent = $filteredStatuses[$this->testThreadId]->email_last_activity;
        $expected_status->email_last_received = null;
        $this->assertEquals($expected_status, $filteredStatuses[$this->testThreadId], "Status for test thread should be EMAIL_SENT_NOTHING_RECEIVED");
        $expected_status2 = new ThreadStatus();
        $expected_status2->thread_id = $thirdThreadId;
        $expected_status2->entity_id = 'test-entity-status-repo';
        $expected_status2->email_count_in = 0;
        $expected_status2->email_count_out = 0;
        $expected_status2->status = ThreadStatusRepository::NOT_SENT;
        $expected_status2->email_server_last_checked_at = $filteredStatuses[$this->testThreadId]->email_server_last_checked_at;
        $expected_status2->email_last_activity = null;
        $expected_status2->email_last_sent = null;
        $expected_status2->email_last_received = null;
        $this->assertEquals($expected_status2, $filteredStatuses[$thirdThreadId], "Status for third thread should be NOT_SENT");
    }
    
    public function testGetAllThreadStatusesEfficientWithStatusFilter(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one email to first thread
        $this->addTestEmail('OUT', 'sent');
        
        // Create a second thread with the same status
        $secondThreadId = $this->createTestThread();
        $secondFolderName = $this->testFolderName . '-second';
        ImapFolderStatus::createOrUpdate($secondFolderName, $secondThreadId, true);
        $this->addTestEmail('OUT', 'sent', null, $secondThreadId);
        
        // Create a third thread with a different status
        $thirdThreadId = $this->createTestThread();
        $thirdFolderName = $this->testFolderName . '-third';
        ImapFolderStatus::createOrUpdate($thirdFolderName, $thirdThreadId, true);
        
        // :: Act
        // Filter by EMAIL_SENT_NOTHING_RECEIVED status
        $emailSentStatuses = ThreadStatusRepository::getAllThreadStatusesEfficient(null, ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED);
        
        // Filter by NOT_SENT status
        $notSentStatuses = ThreadStatusRepository::getAllThreadStatusesEfficient(null, ThreadStatusRepository::NOT_SENT);
        
        // :: Assert
        $this->assertIsArray($emailSentStatuses, "getAllThreadStatusesEfficient with status filter should return an array");
        $this->assertIsArray($notSentStatuses, "getAllThreadStatusesEfficient with status filter should return an array");
        
        // Check EMAIL_SENT_NOTHING_RECEIVED filter
        $this->assertCount(2, $emailSentStatuses, "EMAIL_SENT_NOTHING_RECEIVED statuses should contain exactly 2 threads");
        $this->assertArrayHasKey($this->testThreadId, $emailSentStatuses, "EMAIL_SENT_NOTHING_RECEIVED statuses should contain the test thread ID");
        $this->assertArrayHasKey($secondThreadId, $emailSentStatuses, "EMAIL_SENT_NOTHING_RECEIVED statuses should contain the second thread ID");
        $this->assertArrayNotHasKey($thirdThreadId, $emailSentStatuses, "EMAIL_SENT_NOTHING_RECEIVED statuses should not contain the third thread ID");
        
        // Check NOT_SENT filter
        $this->assertCount(1, $notSentStatuses, "NOT_SENT statuses should contain exactly 1 thread");
        $this->assertArrayHasKey($thirdThreadId, $notSentStatuses, "NOT_SENT statuses should contain the third thread ID");
        $this->assertArrayNotHasKey($this->testThreadId, $notSentStatuses, "NOT_SENT statuses should not contain the test thread ID");
        $this->assertArrayNotHasKey($secondThreadId, $notSentStatuses, "NOT_SENT statuses should not contain the second thread ID");
    }
    
    public function testGetAllThreadStatusesEfficientWithBothFilters(): void {
        // :: Setup
        // Create IMAP folder status with recent timestamp
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add one email to first thread
        $this->addTestEmail('OUT', 'sent');
        
        // Create a second thread with the same status
        $secondThreadId = $this->createTestThread();
        $secondFolderName = $this->testFolderName . '-second';
        ImapFolderStatus::createOrUpdate($secondFolderName, $secondThreadId, true);
        $this->addTestEmail('OUT', 'sent', null, $secondThreadId);
        
        // Create a third thread with a different status
        $thirdThreadId = $this->createTestThread();
        $thirdFolderName = $this->testFolderName . '-third';
        ImapFolderStatus::createOrUpdate($thirdFolderName, $thirdThreadId, true);
        
        // :: Act
        // Filter by thread IDs and EMAIL_SENT_NOTHING_RECEIVED status
        $filteredStatuses = ThreadStatusRepository::getAllThreadStatusesEfficient(
            [$this->testThreadId, $secondThreadId, $thirdThreadId],
            ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED
        );
        
        // :: Assert
        $this->assertIsArray($filteredStatuses, "getAllThreadStatusesEfficient with both filters should return an array");
        $this->assertCount(2, $filteredStatuses, "Filtered statuses should contain exactly 2 threads");
        $this->assertArrayHasKey($this->testThreadId, $filteredStatuses, "Filtered statuses should contain the test thread ID");
        $this->assertArrayHasKey($secondThreadId, $filteredStatuses, "Filtered statuses should contain the second thread ID");
        $this->assertArrayNotHasKey($thirdThreadId, $filteredStatuses, "Filtered statuses should not contain the third thread ID");
    }
    
    public function testGetThreadStatusWithNullLastCheckedAt(): void {
        // :: Setup
        // Create IMAP folder status with NULL last_checked_at
        // By default, createOrUpdate sets last_checked_at to NULL when $updateLastChecked is false
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, false);
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('OUT', 'sent');
        
        // Verify that last_checked_at is NULL
        $lastCheckedAt = Database::queryValue(
            "SELECT last_checked_at FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertNull($lastCheckedAt, "last_checked_at should be NULL for this test");
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_NO_SYNC, $status, "Status should be ERROR_NO_SYNC when last_checked_at is NULL");
    }
    
    public function testGetThreadStatusWithMissingInboxFolder(): void {
        // :: Setup
        // Create IMAP folder status for the thread folder but not for INBOX
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);

        // Delete INBOX folder status to simulate missing folder
        Database::execute(
            "DELETE FROM imap_folder_status WHERE folder_name = ?",
            ['INBOX']
        );
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_INBOX_SYNC, $status, "Status should be ERROR_INBOX_SYNC when INBOX folder is missing");
    }
    
    public function testGetThreadStatusWithMissingSentFolder(): void {
        // :: Setup
        // Create IMAP folder status for the thread folder and INBOX but not for INBOX.Sent
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Delete INBOX.Sent folder status to simulate missing folder
        Database::execute(
            "DELETE FROM imap_folder_status WHERE folder_name = ?",
            ['INBOX.Sent']
        );
        
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_SENT_SYNC, $status, "Status should be ERROR_SENT_SYNC when INBOX.Sent folder is missing");
    }
    
    public function testGetThreadStatusWithOldInboxFolderCheck(): void {
        // :: Setup
        // Create IMAP folder status for all required folders
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Set INBOX last_checked_at to 15 minutes ago (older than 10 minutes threshold)
        $fifteenMinutesAgo = time() - (15 * 60);
        Database::execute(
            "UPDATE imap_folder_status SET last_checked_at = to_timestamp(?) WHERE folder_name = ?",
            [$fifteenMinutesAgo, 'INBOX']
        );
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_INBOX_SYNC, $status, "Status should be ERROR_INBOX_SYNC when INBOX folder was last checked more than 10 minutes ago");
    }
    
    public function testGetThreadStatusWithOldSentFolderCheck(): void {
        // :: Setup
        // Create IMAP folder status for all required folders
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Set INBOX.Sent last_checked_at to 15 minutes ago (older than 10 minutes threshold)
        $fifteenMinutesAgo = time() - (15 * 60);
        Database::execute(
            "UPDATE imap_folder_status SET last_checked_at = to_timestamp(?) WHERE folder_name = ?",
            [$fifteenMinutesAgo, 'INBOX.Sent']
        );
        
        // Add an email to ensure we get past the "Email not sent" check
        $this->addTestEmail('OUT', 'sent');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::ERROR_SENT_SYNC, $status, "Status should be ERROR_SENT_SYNC when INBOX.Sent folder was last checked more than 10 minutes ago");
    }
    
    public function testGetThreadStatusWithAllFoldersInSync(): void {
        // :: Setup
        // Create IMAP folder status for all required folders with recent timestamps
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // Add multiple emails to get STATUS_OK
        $this->addTestEmail('OUT', 'sent');
        $this->addTestEmail('IN', 'received');
        
        // :: Act
        $status = ThreadStatusRepository::getThreadStatus($this->testThreadId);
        
        // :: Assert
        $this->assertEquals(ThreadStatusRepository::STATUS_OK, $status, "Status should be STATUS_OK when all folders are in sync and multiple emails exist");
    }
}
