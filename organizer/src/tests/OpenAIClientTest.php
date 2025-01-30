<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/OpenAIClient.php';
require_once __DIR__ . '/../class/CurlWrapper.php';

class OpenAIClientTest extends TestCase {
    private $apiKey = 'test-api-key';
    private $client;
    private $mockCurlWrapper;

    protected function setUp(): void {
        $this->mockCurlWrapper = $this->createMock(CurlWrapper::class);
        $this->client = new OpenAIClient($this->apiKey);
        $this->client->setCurlWrapper($this->mockCurlWrapper);
    }

    public function testSendRequestSuccess() {
        $data = [
            'prompt' => 'Test prompt',
            'max_tokens' => 5,
        ];

        $mockResponse = [
            'choices' => [
                ['text' => 'Test response']
            ]
        ];

        $this->mockCurlWrapper->method('execute')->willReturn(json_encode($mockResponse));
        $this->mockCurlWrapper->method('getErrno')->willReturn(0);

        $response = $this->client->sendRequest($data);
        $this->assertEquals($mockResponse, $response);
    }

    public function testSendRequestError() {
        $data = [
            'prompt' => 'Test prompt',
            'max_tokens' => 5,
        ];

        $this->mockCurlWrapper->method('execute')->willReturn(false);
        $this->mockCurlWrapper->method('getErrno')->willReturn(1);
        $this->mockCurlWrapper->method('getError')->willReturn('Test error');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Request Error: Test error');

        $this->client->sendRequest($data);
    }

    public function testSummarizeEmailSuccess() {
        $emailContent = 'This is a test email content.';
        $data = [
            'prompt' => 'Summarize the following email: ' . $emailContent,
            'max_tokens' => 150,
        ];

        $mockResponse = [
            'choices' => [
                ['text' => 'Test summary']
            ]
        ];

        $this->mockCurlWrapper->method('execute')->willReturn(json_encode($mockResponse));
        $this->mockCurlWrapper->method('getErrno')->willReturn(0);

        $summary = $this->client->summarizeEmail($emailContent);
        $this->assertEquals('Test summary', $summary);
    }

    public function testSummarizeEmailFailure() {
        $emailContent = 'This is a test email content.';
        $data = [
            'prompt' => 'Summarize the following email: ' . $emailContent,
            'max_tokens' => 150,
        ];

        $mockResponse = [
            'choices' => []
        ];

        $this->mockCurlWrapper->method('execute')->willReturn(json_encode($mockResponse));
        $this->mockCurlWrapper->method('getErrno')->willReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to summarize email');

        $this->client->summarizeEmail($emailContent);
    }
}
