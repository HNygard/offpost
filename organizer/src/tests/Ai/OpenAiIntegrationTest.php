<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Ai/OpenAiIntegration.php';
require_once __DIR__ . '/../../class/Ai/OpenAiRequestLog.php';
require_once __DIR__ . '/../../class/Database.php';

use Offpost\Ai\OpenAiIntegration;
use Offpost\Ai\OpenAiRequestLog;

class OpenAiIntegrationTest extends PHPUnit\Framework\TestCase {
    private $testSource;
    private $integration;
    
    protected function setUp(): void {
        parent::setUp();

        $this->testSource = 'test-integration-' . mt_rand(0, 100000);
        $this->integration = new OpenAiIntegration('test-api-key');
        
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
    
    /**
     * Test that sendRequest logs the request
     */
    public function testSendRequestLogsRequest(): void {
        // :: Setup
        $input = [
            ['role' => 'user', 'content' => 'Test message']
        ];
        $model = 'gpt-4';
        
        // Mock the curl execution to avoid actual API calls
        $this->mockCurlExecution();
        
        // :: Act
        try {
            $this->integration->sendRequest($input, $model, $this->testSource);
        } catch (\Exception $e) {
            // Expected exception due to mocked curl
        }
        
        // :: Assert
        $logs = OpenAiRequestLog::getBySource($this->testSource);
        $this->assertGreaterThanOrEqual(1, count($logs), "Should have at least one log entry");
        $this->assertStringContainsString('gpt-4', $logs[0]['request'], "Request should contain model name");
        $this->assertEquals($this->testSource, $logs[0]['source'], "Source should match");
    }
    
    /**
     * Test that analyzeImage logs the request with the correct source
     */
    public function testAnalyzeImageLogsRequest(): void {
        // :: Setup
        $imageUrl = 'https://example.com/image.jpg';
        $question = 'What is in this image?';
        $model = 'gpt-4-vision';
        $source = 'custom_image_analysis';
        
        // Mock the curl execution to avoid actual API calls
        $this->mockCurlExecution();
        
        // :: Act
        try {
            $this->integration->analyzeImage($imageUrl, $question, $model, $source);
        } catch (\Exception $e) {
            // Expected exception due to mocked curl
        }
        
        // :: Assert
        $logs = OpenAiRequestLog::getBySource($source);
        $this->assertGreaterThanOrEqual(1, count($logs), "Should have at least one log entry");
        $this->assertStringContainsString('gpt-4-vision', $logs[0]['request'], "Request should contain model name");
        $this->assertEquals($source, $logs[0]['source'], "Source should match");
    }
    
    /**
     * Test that token counts are extracted from the response
     */
    public function testTokenCountsAreExtracted(): void {
        // :: Setup
        $input = [
            ['role' => 'user', 'content' => 'Test message']
        ];
        $model = 'gpt-4';
        
        // Mock the curl execution with a response that includes token counts
        $this->mockCurlExecutionWithTokens(15, 25);
        
        // :: Act
        try {
            $this->integration->sendRequest($input, $model, $this->testSource);
        } catch (\Exception $e) {
            // Expected exception due to mocked curl
        }
        
        // :: Assert
        $logs = OpenAiRequestLog::getBySource($this->testSource);
        $this->assertEquals(15, $logs[0]['tokens_input'], "Input tokens should be extracted from response");
        $this->assertEquals(25, $logs[0]['tokens_output'], "Output tokens should be extracted from response");
    }
    
    /**
     * Mock curl execution to avoid actual API calls
     */
    private function mockCurlExecution(): void {
        // Override curl_exec to return a mock response
        runkit_function_redefine('curl_exec', function($ch) {
            return json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Mock response'
                        ]
                    ]
                ]
            ]);
        });
        
        // Override curl_getinfo to return a mock HTTP code
        runkit_function_redefine('curl_getinfo', function($ch, $opt = 0) {
            if ($opt === CURLINFO_HTTP_CODE) {
                return 200;
            }
            return [];
        });
        
        // Override curl_error to return no error
        runkit_function_redefine('curl_error', function($ch) {
            return '';
        });
    }
    
    /**
     * Mock curl execution with a response that includes token counts
     */
    private function mockCurlExecutionWithTokens(int $inputTokens, int $outputTokens): void {
        // Override curl_exec to return a mock response with token counts
        runkit_function_redefine('curl_exec', function($ch) use ($inputTokens, $outputTokens) {
            return json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Mock response'
                        ]
                    ]
                ],
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $inputTokens + $outputTokens
                ]
            ]);
        });
        
        // Override curl_getinfo to return a mock HTTP code
        runkit_function_redefine('curl_getinfo', function($ch, $opt = 0) {
            if ($opt === CURLINFO_HTTP_CODE) {
                return 200;
            }
            return [];
        });
        
        // Override curl_error to return no error
        runkit_function_redefine('curl_error', function($ch) {
            return '';
        });
    }
    
    /**
     * Restore original curl functions after tests
     */
    public static function tearDownAfterClass(): void {
        if (function_exists('runkit_function_remove')) {
            runkit_function_remove('curl_exec');
            runkit_function_remove('curl_getinfo');
            runkit_function_remove('curl_error');
        }
        
        parent::tearDownAfterClass();
    }
}
