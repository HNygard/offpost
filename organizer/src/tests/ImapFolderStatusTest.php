<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ImapFolderStatus.php';
require_once __DIR__ . '/../class/Database.php';

class ImapFolderStatusTest extends PHPUnit\Framework\TestCase {
    private $testFolderName;
    private $testThreadId;
    private $testEntityId = '000000000-test-entity-development';
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->testFolderName = 'INBOX.test-folder-' . mt_rand(0, 100000);
        
        // Start database transaction
        Database::beginTransaction();
        
        // Create a test thread in the database
        $this->testThreadId = $this->createTestThread();
        
        // Clean up any existing test records
        Database::execute(
            "DELETE FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
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
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                $this->testEntityId,
                'Test Thread',
                'Test User',
                'test-imap-folder-status-'.mt_rand(0, 10000) .' @example.com',
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
    
    public function testCreateOrUpdate(): void {
        // :: Setup
        
        // :: Act
        $result = ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully create a new record");
        
        // Verify record exists
        $count = Database::queryValue(
            "SELECT COUNT(*) FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertEquals(1, $count, "Record should exist in database");
        
        // Verify last_checked_at is NULL
        $lastChecked = Database::queryValue(
            "SELECT last_checked_at FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertNull($lastChecked, "last_checked_at should be NULL for new record without updateLastChecked flag");
        
        // Verify requested_update_time is NULL
        $requestedUpdateTime = Database::queryValue(
            "SELECT requested_update_time FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertNull($requestedUpdateTime, "requested_update_time should be NULL for new record without requestUpdate flag");
    }
    
    public function testCreateOrUpdateWithLastChecked(): void {
        // :: Setup
        
        // :: Act
        $result = ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully create a new record with last_checked_at");
        
        // Verify last_checked_at is not NULL
        $lastChecked = Database::queryValue(
            "SELECT last_checked_at FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertNotNull($lastChecked, "last_checked_at should not be NULL when updateLastChecked is true");
    }
    
    public function testUpdateExistingRecord(): void {
        // :: Setup
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId);
        
        // :: Act
        $result = ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully update an existing record");
        
        // Verify last_checked_at is not NULL
        $lastChecked = Database::queryValue(
            "SELECT last_checked_at FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertNotNull($lastChecked, "last_checked_at should be updated when updateLastChecked is true");
    }
    
    public function testGetLastChecked(): void {
        // :: Setup
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Act
        $lastChecked = ImapFolderStatus::getLastChecked($this->testFolderName, $this->testThreadId);
        
        // :: Assert
        $this->assertNotNull($lastChecked, "Should return a timestamp for last_checked_at");
    }
    
    public function testGetAll(): void {
        // :: Setup
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Act
        $records = ImapFolderStatus::getAll();
        
        // :: Assert
        $this->assertIsArray($records, "Should return an array of records");
        $this->assertGreaterThanOrEqual(1, count($records), "Should return at least one record");
        
        // Check if our test record is in the results
        $found = false;
        foreach ($records as $record) {
            if ($record['folder_name'] === $this->testFolderName && $record['thread_id'] === $this->testThreadId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Test record should be included in getAll() results");
    }
    
    public function testCreateOrUpdateWithRequestUpdate(): void {
        // :: Setup
        
        // :: Act
        $result = ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, false, true);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully create a new record with requested_update_time");
        
        // Verify requested_update_time is not NULL
        $requestedUpdateTime = Database::queryValue(
            "SELECT requested_update_time FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$this->testFolderName, $this->testThreadId]
        );
        $this->assertNotNull($requestedUpdateTime, "requested_update_time should not be NULL when requestUpdate is true");
    }
    
    public function testRequestUpdate(): void {
        // :: Setup
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId);
        
        // :: Act
        $result = ImapFolderStatus::requestUpdate($this->testFolderName);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully update requested_update_time");
        
        // Verify requested_update_time is not NULL
        $requestedUpdateTime = Database::queryValue(
            "SELECT requested_update_time FROM imap_folder_status WHERE folder_name = ?",
            [$this->testFolderName]
        );
        $this->assertNotNull($requestedUpdateTime, "requested_update_time should be updated when requestUpdate is called");
    }
    
    public function testClearRequestedUpdate(): void {
        // :: Setup
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, false, true);
        
        // :: Act
        $result = ImapFolderStatus::clearRequestedUpdate($this->testFolderName);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully clear requested_update_time");
        
        // Verify requested_update_time is NULL
        $requestedUpdateTime = Database::queryValue(
            "SELECT requested_update_time FROM imap_folder_status WHERE folder_name = ?",
            [$this->testFolderName]
        );
        $this->assertNull($requestedUpdateTime, "requested_update_time should be NULL after clearRequestedUpdate is called");
    }
    
    public function testGetForThread(): void {
        // :: Setup
        ImapFolderStatus::createOrUpdate($this->testFolderName, $this->testThreadId, true);
        
        // :: Act
        $records = ImapFolderStatus::getForThread($this->testThreadId);
        
        // :: Assert
        $this->assertIsArray($records, "Should return an array of records");
        $this->assertGreaterThanOrEqual(1, count($records), "Should return at least one record");
        
        // Check if our test record is in the results
        $found = false;
        foreach ($records as $record) {
            if ($record['folder_name'] === $this->testFolderName) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Test record should be included in getForThread() results");
    }
}
