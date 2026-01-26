<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Ai/OpenAiRequestLog.php';
require_once __DIR__ . '/../../class/Database.php';

use Offpost\Ai\OpenAiRequestLog;

class OpenAiRequestLogTest extends PHPUnit\Framework\TestCase {
    private $testSource;
    
    protected function setUp(): void {
        parent::setUp();

        $this->testSource = 'test-source-' . mt_rand(0, 100000);
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean up any existing test records
        Database::execute(
            "DELETE FROM openai_request_log WHERE source = ?",
            [$this->testSource]
        );
    }
    
    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        
        parent::tearDown();
    }
    
    public function testLog(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        $request = ['model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Test']]];
        
        // :: Act
        $logId = OpenAiRequestLog::log($this->testSource, $endpoint, $request);
        
        // :: Assert
        $this->assertGreaterThan(0, $logId, "Should return a valid log ID");
        
        // Verify log exists in database
        $logs = OpenAiRequestLog::getBySource($this->testSource);
        $this->assertCount(1, $logs, "Should have one log entry");
        $this->assertEquals($endpoint, $logs[0]['endpoint'], "Endpoint should match");
        $this->assertStringContainsString('gpt-4', $logs[0]['request'], "Request should contain model name");
    }
    
    public function testUpdateWithResponse(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        $request = ['model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Test']]];
        $logId = OpenAiRequestLog::log($this->testSource, $endpoint, $request);
        
        $response = ['choices' => [['message' => ['content' => 'Test response']]]];
        $responseCode = 200;
        $tokensInput = 10;
        $tokensOutput = 20;
        
        // :: Act
        $result = OpenAiRequestLog::updateWithResponse(
            $logId,
            $response,
            $responseCode,
            $tokensInput,
            $tokensOutput
        );
        
        // :: Assert
        $this->assertTrue($result, "Should successfully update the log entry");
        
        // Verify log was updated
        $logs = OpenAiRequestLog::getBySource($this->testSource);
        $this->assertEquals($responseCode, $logs[0]['response_code'], "Response code should match");
        $this->assertStringContainsString('Test response', $logs[0]['response'], "Response should contain expected content");
        $this->assertEquals($tokensInput, $logs[0]['tokens_input'], "Input tokens should match");
        $this->assertEquals($tokensOutput, $logs[0]['tokens_output'], "Output tokens should match");
    }
    
    public function testGetBySource(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        
        // Create two log entries
        OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 1]);
        OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 2]);
        
        // :: Act
        $logs = OpenAiRequestLog::getBySource($this->testSource);
        
        // :: Assert
        $this->assertCount(2, $logs, "Should have two log entries");
    }
    
    public function testGetAll(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        
        // Create a log entry
        OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 1]);
        
        // :: Act
        $logs = OpenAiRequestLog::getAll();
        
        // :: Assert
        $this->assertGreaterThanOrEqual(1, count($logs), "Should have at least one log entry");
    }
    
    public function testGetByDateRange(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        
        // Create a log entry
        OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 1]);
        
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // :: Act
        $logs = OpenAiRequestLog::getByDateRange($today, $tomorrow);
        
        // :: Assert
        $this->assertGreaterThanOrEqual(1, count($logs), "Should have at least one log entry in date range");
    }
    
    public function testGetTokenUsage(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        
        // Create a log entry and update with token usage
        $logId1 = OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 1]);
        OpenAiRequestLog::updateWithResponse($logId1, ['result' => 'ok'], 200, 10, 20);
        
        $logId2 = OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 2]);
        OpenAiRequestLog::updateWithResponse($logId2, ['result' => 'ok'], 200, 15, 25);
        
        // :: Act
        $usage = OpenAiRequestLog::getTokenUsage($this->testSource);
        
        // :: Assert
        $this->assertEquals(25, $usage['input_tokens'], "Input tokens should be summed correctly");
        $this->assertEquals(45, $usage['output_tokens'], "Output tokens should be summed correctly");
    }
    
    public function testInvalidLogIdHandling(): void {
        // :: Setup
        $response = ['choices' => [['message' => ['content' => 'Test response']]]];
        
        // :: Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid log ID: 0");
        OpenAiRequestLog::updateWithResponse(0, $response, 200);
    }
    
    public function testGetById(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        $request = ['model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Test']]];
        $logId = OpenAiRequestLog::log($this->testSource, $endpoint, $request);
        
        // :: Act
        $log = OpenAiRequestLog::getById($logId);
        
        // :: Assert
        $this->assertNotNull($log, "Should return a log entry");
        $this->assertEquals($logId, $log['id'], "Log ID should match");
        $this->assertEquals($endpoint, $log['endpoint'], "Endpoint should match");
        $this->assertEquals($this->testSource, $log['source'], "Source should match");
    }
    
    public function testGetByIdNotFound(): void {
        // :: Act
        $log = OpenAiRequestLog::getById(999999999);
        
        // :: Assert
        $this->assertNull($log, "Should return null for non-existent ID");
    }
    
    public function testGetByIds(): void {
        // :: Setup
        $endpoint = 'https://api.openai.com/v1/test';
        $logId1 = OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 1]);
        $logId2 = OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 2]);
        $logId3 = OpenAiRequestLog::log($this->testSource, $endpoint, ['test' => 3]);
        
        // :: Act
        $logs = OpenAiRequestLog::getByIds([$logId1, $logId2, $logId3]);
        
        // :: Assert
        $this->assertCount(3, $logs, "Should return three log entries");
        $logIds = array_column($logs, 'id');
        $this->assertContains($logId1, $logIds, "Should contain first log ID");
        $this->assertContains($logId2, $logIds, "Should contain second log ID");
        $this->assertContains($logId3, $logIds, "Should contain third log ID");
    }
    
    public function testGetByIdsEmpty(): void {
        // :: Act
        $logs = OpenAiRequestLog::getByIds([]);
        
        // :: Assert
        $this->assertEmpty($logs, "Should return empty array for empty IDs");
    }
    
    public function testGetByIdsNonExistent(): void {
        // :: Act
        $logs = OpenAiRequestLog::getByIds([999999999, 999999998]);
        
        // :: Assert
        $this->assertEmpty($logs, "Should return empty array for non-existent IDs");
    }
}
