<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Ai/OpenAiIntegration.php';
require_once __DIR__ . '/../../class/Ai/OpenAiRequestLog.php';
require_once __DIR__ . '/../../class/Database.php';

use Offpost\Ai\OpenAiIntegration;
use Offpost\Ai\OpenAiRequestLog;

class OpenAiIntegrationMock extends OpenAiIntegration {
    var $next_response = array();

    public function setNextResponse($response) {
        $this->next_response = $response;
    }

    protected function internalSendRequest($apiEndpoint, $requestData) {
        return $this->next_response;
    }
}

class OpenAiIntegrationTest extends PHPUnit\Framework\TestCase {
    private $testSource;
    private $integration;
    
    protected function setUp(): void {
        parent::setUp();

        $this->testSource = 'test-integration-' . mt_rand(0, 100000);
        $this->integration = new OpenAiIntegrationMock('test-api-key');
        
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
        $this->integration->setNextResponse([
            'response' => json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Mock response'
                        ]
                    ]
                ]
            ]),
            'httpCode' => 200,
            'error' => ''
        ]);

        // :: Act
        $this->integration->sendRequest($input, $model, $this->testSource);
        
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
        $this->integration->setNextResponse([
            'response' => json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Mock image analysis response'
                        ]
                    ]
                ]
            ]),
            'httpCode' => 200,
            'error' => ''
        ]);
        
        // :: Act
        $this->integration->analyzeImage($imageUrl, $question, $model, $source);
        
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
        $this->integration->setNextResponse([
            'response' => json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Mock response'
                        ]
                    ]
                ],
                'usage' => [
                    'input_tokens' => 15,
                    'output_tokens' => 25,
                    'total_tokens' => 40
                ]
            ]),
            'httpCode' => 200,
            'error' => ''
        ]);
        
        // :: Act
        $this->integration->sendRequest($input, $model, $this->testSource);
        
        // :: Assert
        $logs = OpenAiRequestLog::getBySource($this->testSource);
        $this->assertEquals(15, $logs[0]['tokens_input'], "Input tokens should be extracted from response");
        $this->assertEquals(25, $logs[0]['tokens_output'], "Output tokens should be extracted from response");
    } 
}
