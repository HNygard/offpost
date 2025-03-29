<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ImapFolderLog.php';
require_once __DIR__ . '/../class/Database.php';

class ImapFolderLogTest extends PHPUnit\Framework\TestCase {
    private $testFolderName = 'INBOX.test-folder';
    
    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean up any existing test records
        Database::execute(
            "DELETE FROM imap_folder_log WHERE folder_name = ?",
            [$this->testFolderName]
        );
    }
    
    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        
        parent::tearDown();
    }
    
    public function testLog(): void {
        // :: Setup
        $status = 'success';
        $message = 'Test message';
        
        // :: Act
        $result = ImapFolderLog::log($this->testFolderName, $status, $message);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully create a log entry");
        
        // Verify log exists
        $logs = ImapFolderLog::getForFolder($this->testFolderName);
        $this->assertCount(1, $logs, "Should have one log entry");
        $this->assertEquals($status, $logs[0]['status'], "Status should match");
        $this->assertEquals($message, $logs[0]['message'], "Message should match");
    }
    
    public function testCreateLog(): void {
        // :: Setup
        $status = 'started';
        $message = 'Starting process';
        
        // :: Act
        $logId = ImapFolderLog::createLog($this->testFolderName, $status, $message);
        
        // :: Assert
        $this->assertNotNull($logId, "Should return a log ID");
        $this->assertIsInt($logId, "Log ID should be an integer");
        
        // Verify log exists
        $log = ImapFolderLog::getMostRecentForFolder($this->testFolderName);
        $this->assertNotNull($log, "Should find the created log");
        $this->assertEquals($status, $log['status'], "Status should match");
        $this->assertEquals($message, $log['message'], "Message should match");
        $this->assertEquals($logId, (int)$log['id'], "Log ID should match");
    }
    
    public function testUpdateLog(): void {
        // :: Setup
        $initialStatus = 'started';
        $initialMessage = 'Starting process';
        $logId = ImapFolderLog::createLog($this->testFolderName, $initialStatus, $initialMessage);
        
        $newStatus = 'success';
        $newMessage = 'Process completed successfully';
        
        // :: Act
        $result = ImapFolderLog::updateLog($logId, $newStatus, $newMessage);
        
        // :: Assert
        $this->assertTrue($result, "Should successfully update the log entry");
        
        // Verify log was updated
        $log = ImapFolderLog::getMostRecentForFolder($this->testFolderName);
        $this->assertEquals($newStatus, $log['status'], "Status should be updated");
        $this->assertEquals($newMessage, $log['message'], "Message should be updated");
    }
    
    public function testGetMostRecentForFolder(): void {
        // :: Setup
        Database::execute("DELETE FROM imap_folder_log WHERE folder_name = ?", [$this->testFolderName]);
        
        // Create a log entry
        ImapFolderLog::log($this->testFolderName, 'info', 'Test message');
        
        // :: Act
        $log = ImapFolderLog::getMostRecentForFolder($this->testFolderName);
        
        // :: Assert
        $this->assertNotNull($log, "Should find a log entry");
        $this->assertEquals('info', $log['status'], "Status should match");
        $this->assertEquals('Test message', $log['message'], "Message should match");
    }
    
    public function testGetForFolder(): void {
        // :: Setup
        Database::execute("DELETE FROM imap_folder_log WHERE folder_name = ?", [$this->testFolderName]);
        
        // Create two log entries
        ImapFolderLog::log($this->testFolderName, 'success', 'Test message 1');
        ImapFolderLog::log($this->testFolderName, 'error', 'Test message 2');
        
        // :: Act
        $logs = ImapFolderLog::getForFolder($this->testFolderName);
        
        // :: Assert
        $this->assertCount(2, $logs, "Should have two log entries");
        
        // Check that both log entries exist, regardless of order
        $foundSuccess = false;
        $foundError = false;
        
        foreach ($logs as $log) {
            if ($log['status'] === 'success' && $log['message'] === 'Test message 1') {
                $foundSuccess = true;
            }
            if ($log['status'] === 'error' && $log['message'] === 'Test message 2') {
                $foundError = true;
            }
        }
        
        $this->assertTrue($foundSuccess, "Should find the success log");
        $this->assertTrue($foundError, "Should find the error log");
    }
    
    public function testGetAll(): void {
        // :: Setup
        ImapFolderLog::log($this->testFolderName, 'success', 'Test message 1');
        ImapFolderLog::log('INBOX.another-folder', 'error', 'Test message 2');
        
        // :: Act
        $logs = ImapFolderLog::getAll();
        
        // :: Assert
        $this->assertGreaterThanOrEqual(2, count($logs), "Should have at least two log entries");
    }
    
    public function testGetByStatus(): void {
        // :: Setup
        ImapFolderLog::log($this->testFolderName, 'success', 'Test message 1');
        ImapFolderLog::log($this->testFolderName, 'error', 'Test message 2');
        
        // :: Act
        $logs = ImapFolderLog::getByStatus('error');
        
        // :: Assert
        $this->assertGreaterThanOrEqual(1, count($logs), "Should have at least one error log");
        $this->assertEquals('error', $logs[0]['status'], "Status should be error");
    }
}
