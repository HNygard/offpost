<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/OpenAIClient.php';

class OpenAIClientTest extends TestCase {
    private $apiKey = 'test-api-key';
    private $client;

    protected function setUp(): void {
        $this->client = new OpenAIClient($this->apiKey);
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

        $mockCurl = $this->createMock(CurlHandle::class);
        $mockCurl->method('exec')->willReturn(json_encode($mockResponse));
        $mockCurl->method('errno')->willReturn(0);

        $this->client->setCurlHandle($mockCurl);

        $response = $this->client->sendRequest($data);
        $this->assertEquals($mockResponse, $response);
    }

    public function testSendRequestError() {
        $data = [
            'prompt' => 'Test prompt',
            'max_tokens' => 5,
        ];

        $mockCurl = $this->createMock(CurlHandle::class);
        $mockCurl->method('exec')->willReturn(false);
        $mockCurl->method('errno')->willReturn(1);
        $mockCurl->method('error')->willReturn('Test error');

        $this->client->setCurlHandle($mockCurl);

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

        $mockCurl = $this->createMock(CurlHandle::class);
        $mockCurl->method('exec')->willReturn(json_encode($mockResponse));
        $mockCurl->method('errno')->willReturn(0);

        $this->client->setCurlHandle($mockCurl);

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

        $mockCurl = $this->createMock(CurlHandle::class);
        $mockCurl->method('exec')->willReturn(json_encode($mockResponse));
        $mockCurl->method('errno')->willReturn(0);

        $this->client->setCurlHandle($mockCurl);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to summarize email');

        $this->client->summarizeEmail($emailContent);
    }
}
