<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/../../class/Ai/OpenAiRequestLog.php';
require_once __DIR__ . '/../../class/Database.php';

use Offpost\Ai\OpenAiRequestLog;

class OpenAiRequestLogRetryPageTest extends E2EPageTestCase {

    private $testLogIds = [];
    private $testSource = null;

    protected function setUp(): void {
        parent::setUp();
        $this->testSource = 'test-e2e-retry-' . uniqid();
        // Create test OpenAI request log entries
        $this->createTestLogs();
    }

    protected function tearDown(): void {
        // Clean up the test log entries
        if (!empty($this->testLogIds)) {
            $this->deleteTestLogs();
        }
        parent::tearDown();
    }

    private function createTestLogs() {
        // Create test log entries with different statuses
        $endpoint = 'https://api.openai.com/v1/responses';
        
        // Log 1: Failed request (for retry testing)
        $request1 = [
            'model' => 'gpt-4',
            'input' => [
                ['role' => 'user', 'content' => 'Test message 1']
            ]
        ];
        $logId1 = OpenAiRequestLog::log($this->testSource, $endpoint, $request1);
        OpenAiRequestLog::updateWithResponse($logId1, 'Error: Rate limit exceeded', 429);
        $this->testLogIds[] = $logId1;
        
        // Log 2: Another failed request
        $request2 = [
            'model' => 'gpt-4',
            'input' => [
                ['role' => 'user', 'content' => 'Test message 2']
            ]
        ];
        $logId2 = OpenAiRequestLog::log($this->testSource, $endpoint, $request2);
        OpenAiRequestLog::updateWithResponse($logId2, 'Error: Service unavailable', 503);
        $this->testLogIds[] = $logId2;
        
        // Log 3: Successful request
        $request3 = [
            'model' => 'gpt-4',
            'input' => [
                ['role' => 'user', 'content' => 'Test message 3']
            ]
        ];
        $logId3 = OpenAiRequestLog::log($this->testSource, $endpoint, $request3);
        $response3 = [
            'choices' => [
                ['message' => ['content' => 'Test response']]
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 20
            ],
            'model' => 'gpt-4'
        ];
        OpenAiRequestLog::updateWithResponse($logId3, $response3, 200, 10, 20, 'gpt-4', 'completed');
        $this->testLogIds[] = $logId3;
    }
    
    private function deleteTestLogs() {
        // Delete all test logs
        foreach ($this->testLogIds as $logId) {
            Database::execute(
                "DELETE FROM openai_request_log WHERE id = ?",
                [$logId]
            );
        }
        
        // Also delete any retry logs created during tests
        Database::execute(
            "DELETE FROM openai_request_log WHERE source LIKE ?",
            [$this->testSource . '%']
        );
    }

    public function testOverviewPageLoggedIn() {
        // :: Setup
        $response = $this->renderPage('/openai-request-log-overview');

        // :: Assert
        // Assert basic page content - the heading
        $this->assertStringContainsString('<h1>OpenAI Request Log Overview</h1>', $response->body);
        
        // Assert that the summary box is present
        $this->assertStringContainsString('<div class="summary-box">', $response->body);
        $this->assertStringContainsString('<div class="summary-count">', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Input Tokens</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Output Tokens</div>', $response->body);
        $this->assertStringContainsString('<div class="summary-label">Total Tokens</div>', $response->body);
        
        // Assert that the retry button is present
        $this->assertStringContainsString('<button id="retry-selected"', $response->body);
        $this->assertStringContainsString('Retry Selected Requests', $response->body);
        
        // Assert that the table headers are present with checkbox column
        $this->assertStringContainsString('<th><input type="checkbox" id="select-all"', $response->body);
        $this->assertStringContainsString('<th>ID</th>', $response->body);
        $this->assertStringContainsString('<th>Source</th>', $response->body);
        $this->assertStringContainsString('<th>Time</th>', $response->body);
        $this->assertStringContainsString('<th>Endpoint</th>', $response->body);
        $this->assertStringContainsString('<th>Status</th>', $response->body);
        $this->assertStringContainsString('<th>Tokens</th>', $response->body);
        $this->assertStringContainsString('<th>Actions</th>', $response->body);
        
        // Assert that individual checkboxes are present
        $this->assertStringContainsString('<input type="checkbox" class="request-checkbox"', $response->body);
    }

    public function testOverviewPageNotLoggedIn() {
        // :: Setup
        // Test that the page redirects to login when not logged in
        $response = $this->renderPage('/openai-request-log-overview', null, 'GET', '302 Found');
        
        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }

    public function testRetryEndpointNotLoggedIn() {
        // :: Setup
        // Test that the retry endpoint returns 302 redirect when not logged in
        
        // :: Act
        $response = $this->renderPage(
            '/openai-request-log-retry', 
            null, 
            'POST', 
            '302 Found',
            ['ids' => [$this->testLogIds[0]]]
        );
        
        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }

    public function testRetryEndpointInvalidMethod() {
        // :: Setup
        // Test that the retry endpoint rejects GET requests
        
        // :: Act
        $response = $this->renderPage(
            '/openai-request-log-retry',
            'dev-user-id',
            'GET',
            '405 Method Not Allowed'
        );
        
        // :: Assert
        // The endpoint should reject non-POST requests
        $this->assertStringContainsString('Method not allowed', $response->body);
    }

    public function testRetryEndpointInvalidIds() {
        // :: Setup
        // Test with empty IDs array
        
        // :: Act
        $response = $this->renderPage(
            '/openai-request-log-retry',
            'dev-user-id',
            'POST',
            '400 Bad Request',
            ['ids' => []]
        );
        
        // :: Assert
        $this->assertStringContainsString('No valid IDs provided', $response->body);
    }

    public function testRetryEndpointNonExistentIds() {
        // :: Setup
        // Test with non-existent IDs
        
        // :: Act
        $response = $this->renderPage(
            '/openai-request-log-retry',
            'dev-user-id',
            'POST',
            '404 Not Found',
            ['ids' => [999999999, 999999998]]
        );
        
        // :: Assert
        $this->assertStringContainsString('No log entries found', $response->body);
    }

    public function testGetByIdMethod() {
        // :: Setup
        $logId = $this->testLogIds[0];
        
        // :: Act
        $log = OpenAiRequestLog::getById($logId);
        
        // :: Assert
        $this->assertNotNull($log, "Should retrieve log by ID");
        $this->assertEquals($logId, $log['id'], "Log ID should match");
        $this->assertEquals($this->testSource, $log['source'], "Source should match");
    }

    public function testGetByIdsMethod() {
        // :: Setup
        $logIds = [$this->testLogIds[0], $this->testLogIds[1]];
        
        // :: Act
        $logs = OpenAiRequestLog::getByIds($logIds);
        
        // :: Assert
        $this->assertCount(2, $logs, "Should retrieve 2 logs");
        $retrievedIds = array_column($logs, 'id');
        $this->assertContains($this->testLogIds[0], $retrievedIds, "Should contain first log ID");
        $this->assertContains($this->testLogIds[1], $retrievedIds, "Should contain second log ID");
    }

    public function testGetByIdsWithEmptyArray() {
        // :: Act
        $logs = OpenAiRequestLog::getByIds([]);
        
        // :: Assert
        $this->assertEmpty($logs, "Should return empty array for empty IDs");
    }
}
