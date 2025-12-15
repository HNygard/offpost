<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ScheduledTaskLogger.php';
require_once __DIR__ . '/../class/Database.php';

class ScheduledTaskLoggerTest extends PHPUnit\Framework\TestCase {
    private $testTaskName;
    
    protected function setUp(): void {
        parent::setUp();

        $this->testTaskName = 'test-task-' . mt_rand(0, 100000);
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean up any existing test records
        Database::execute(
            "DELETE FROM scheduled_task_log WHERE task_name = ?",
            [$this->testTaskName]
        );
    }
    
    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        
        parent::tearDown();
    }
    
    public function testStartAndComplete(): void {
        // :: Setup
        $logger = new ScheduledTaskLogger($this->testTaskName);
        
        // :: Act
        $logger->start();
        $logger->addBytesProcessed(1024);
        $logger->addItemsProcessed(5);
        $logger->complete('Task completed successfully');
        
        // :: Assert
        $logs = ScheduledTaskLogger::getLogsForTask($this->testTaskName);
        $this->assertCount(1, $logs, "Should have one log entry");
        $this->assertEquals('completed', $logs[0]['status'], "Status should be completed");
        $this->assertEquals(1024, $logs[0]['bytes_processed'], "Bytes should match");
        $this->assertEquals(5, $logs[0]['items_processed'], "Items should match");
        $this->assertEquals('Task completed successfully', $logs[0]['message'], "Message should match");
        $this->assertNotNull($logs[0]['completed_at'], "Should have completion timestamp");
    }
    
    public function testStartAndFail(): void {
        // :: Setup
        $logger = new ScheduledTaskLogger($this->testTaskName);
        
        // :: Act
        $logger->start();
        $logger->addBytesProcessed(512);
        $logger->addItemsProcessed(2);
        $logger->fail('Task failed due to error');
        
        // :: Assert
        $logs = ScheduledTaskLogger::getLogsForTask($this->testTaskName);
        $this->assertCount(1, $logs, "Should have one log entry");
        $this->assertEquals('failed', $logs[0]['status'], "Status should be failed");
        $this->assertEquals(512, $logs[0]['bytes_processed'], "Bytes should match");
        $this->assertEquals(2, $logs[0]['items_processed'], "Items should match");
        $this->assertEquals('Task failed due to error', $logs[0]['error_message'], "Error message should match");
        $this->assertNotNull($logs[0]['completed_at'], "Should have completion timestamp");
    }
    
    public function testAddBytesProcessed(): void {
        // :: Setup
        $logger = new ScheduledTaskLogger($this->testTaskName);
        
        // :: Act
        $logger->start();
        $logger->addBytesProcessed(100);
        $logger->addBytesProcessed(200);
        $logger->addBytesProcessed(300);
        $logger->complete();
        
        // :: Assert
        $logs = ScheduledTaskLogger::getLogsForTask($this->testTaskName);
        $this->assertEquals(600, $logs[0]['bytes_processed'], "Should accumulate bytes");
    }
    
    public function testAddItemsProcessed(): void {
        // :: Setup
        $logger = new ScheduledTaskLogger($this->testTaskName);
        
        // :: Act
        $logger->start();
        $logger->addItemsProcessed(1);
        $logger->addItemsProcessed(2);
        $logger->addItemsProcessed(3);
        $logger->complete();
        
        // :: Assert
        $logs = ScheduledTaskLogger::getLogsForTask($this->testTaskName);
        $this->assertEquals(6, $logs[0]['items_processed'], "Should accumulate items");
    }
    
    public function testGetRecentLogs(): void {
        // :: Setup
        $logger1 = new ScheduledTaskLogger($this->testTaskName . '-1');
        $logger1->start();
        $logger1->addBytesProcessed(1000);
        $logger1->complete();
        
        $logger2 = new ScheduledTaskLogger($this->testTaskName . '-2');
        $logger2->start();
        $logger2->addBytesProcessed(2000);
        $logger2->complete();
        
        // :: Act
        $logs = ScheduledTaskLogger::getRecentLogs();
        
        // :: Assert
        $this->assertGreaterThanOrEqual(2, count($logs), "Should have at least two log entries");
    }
    
    public function testGetBandwidthSummary(): void {
        // :: Setup
        $logger1 = new ScheduledTaskLogger($this->testTaskName);
        $logger1->start();
        $logger1->addBytesProcessed(1000);
        $logger1->addItemsProcessed(5);
        $logger1->complete();
        
        $logger2 = new ScheduledTaskLogger($this->testTaskName);
        $logger2->start();
        $logger2->addBytesProcessed(2000);
        $logger2->addItemsProcessed(10);
        $logger2->complete();
        
        // :: Act
        $summary = ScheduledTaskLogger::getBandwidthSummary(7);
        
        // :: Assert
        $found = false;
        foreach ($summary as $item) {
            if ($item['task_name'] === $this->testTaskName) {
                $found = true;
                $this->assertEquals(2, $item['run_count'], "Should have 2 runs");
                $this->assertEquals(3000, $item['total_bytes'], "Total bytes should be 3000");
                // Use delta comparison for float AVG() results
                $this->assertEqualsWithDelta(1500, $item['avg_bytes_per_run'], 0.01, "Average should be 1500");
                $this->assertEquals(2000, $item['max_bytes_per_run'], "Max should be 2000");
                $this->assertEquals(15, $item['total_items'], "Total items should be 15");
            }
        }
        $this->assertTrue($found, "Should find summary for test task");
    }
    
    public function testGetLogsForTask(): void {
        // :: Setup
        $logger1 = new ScheduledTaskLogger($this->testTaskName);
        $logger1->start();
        $logger1->complete('First run');
        
        $logger2 = new ScheduledTaskLogger($this->testTaskName);
        $logger2->start();
        $logger2->complete('Second run');
        
        // Create log for different task
        $otherLogger = new ScheduledTaskLogger($this->testTaskName . '-other');
        $otherLogger->start();
        $otherLogger->complete();
        
        // :: Act
        $logs = ScheduledTaskLogger::getLogsForTask($this->testTaskName);
        
        // :: Assert
        $this->assertCount(2, $logs, "Should have two log entries for this task");
        $this->assertEquals($this->testTaskName, $logs[0]['task_name'], "Task name should match");
        $this->assertEquals($this->testTaskName, $logs[1]['task_name'], "Task name should match");
    }
}
