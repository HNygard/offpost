<?php

use PHPUnit\Framework\TestCase;
use OpenAI\OpenAISummarizer;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/../class/OpenAISummarizer.php';

class OpenAISummarizerTest extends TestCase {
    private $apiKey = 'test-api-key';
    private $mockClient;
    private $summarizer;

    protected function setUp(): void {
        $this->mockClient = $this->createMock(Client::class);
        $this->summarizer = new OpenAISummarizer($this->apiKey);
        
        // Use reflection to inject the mock client
        $reflection = new ReflectionClass($this->summarizer);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->summarizer, $this->mockClient);
    }

    public function testSummarizeEmailSuccess() {
        $emailBody = 'This is a test email body.';
        $responseBody = json_encode([
            'choices' => [
                ['text' => 'This is a summary.']
            ]
        ]);
        $response = new Response(200, [], $responseBody);

        $this->mockClient->expects($this->once())
            ->method('post')
            ->with('https://api.openai.com/v1/engines/davinci-codex/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'prompt' => "Summarize the following email:\n\n" . $emailBody,
                    'max_tokens' => 150,
                ],
            ])
            ->willReturn($response);

        $summary = $this->summarizer->summarizeEmail($emailBody);
        $this->assertEquals('This is a summary.', $summary);
    }

    public function testSummarizeEmailFailure() {
        $emailBody = 'This is a test email body.';
        $response = new Response(500, [], 'Internal Server Error');

        $this->mockClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $summary = $this->summarizer->summarizeEmail($emailBody);
        $this->assertEquals('Failed to get summary', $summary);
    }

    public function testSummarizeEmailRequestException() {
        $emailBody = 'This is a test email body.';

        $this->mockClient->expects($this->once())
            ->method('post')
            ->willThrowException(new RequestException('Error Communicating with Server', new \GuzzleHttp\Psr7\Request('POST', 'test')));

        $summary = $this->summarizer->summarizeEmail($emailBody);
        $this->assertEquals('Failed to get summary', $summary);
    }

    public function testProcessResponseWithValidData() {
        $responseBody = json_encode([
            'choices' => [
                ['text' => 'This is a summary.']
            ]
        ]);
        $response = new Response(200, [], $responseBody);

        $reflection = new ReflectionClass($this->summarizer);
        $method = $reflection->getMethod('processResponse');
        $method->setAccessible(true);

        $summary = $method->invoke($this->summarizer, $response);
        $this->assertEquals('This is a summary.', $summary);
    }

    public function testProcessResponseWithInvalidData() {
        $responseBody = json_encode([
            'choices' => []
        ]);
        $response = new Response(200, [], $responseBody);

        $reflection = new ReflectionClass($this->summarizer);
        $method = $reflection->getMethod('processResponse');
        $method->setAccessible(true);

        $summary = $method->invoke($this->summarizer, $response);
        $this->assertEquals('No summary available', $summary);
    }

    public function testProcessResponseWithError() {
        $response = new Response(500, [], 'Internal Server Error');

        $reflection = new ReflectionClass($this->summarizer);
        $method = $reflection->getMethod('processResponse');
        $method->setAccessible(true);

        $summary = $method->invoke($this->summarizer, $response);
        $this->assertEquals('Failed to get summary', $summary);
    }
}
