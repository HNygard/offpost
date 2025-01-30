<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/ThreadEmailSummarizer.php';
require_once __DIR__ . '/../class/OpenAIClient.php';

class ThreadEmailSummarizerTest extends TestCase {
    private $apiKey = 'test-api-key';
    private $summarizer;
    private $mockOpenAIClient;

    protected function setUp(): void {
        $this->mockOpenAIClient = $this->createMock(OpenAIClient::class);
        $this->summarizer = new ThreadEmailSummarizer($this->apiKey);
        $this->summarizer->openAIClient = $this->mockOpenAIClient;
    }

    public function testSummarize() {
        $emailContent = 'This is a test email content.';
        $expectedSummary = 'Test summary';

        $this->mockOpenAIClient->expects($this->once())
            ->method('summarizeEmail')
            ->with($emailContent)
            ->willReturn($expectedSummary);

        $summary = $this->summarizer->summarize($emailContent);
        $this->assertEquals($expectedSummary, $summary);
    }

    public function testProcessEmail() {
        $email = (object)[
            'body' => 'This is a test email content.'
        ];
        $expectedSummary = 'Test summary';

        $this->mockOpenAIClient->expects($this->once())
            ->method('summarizeEmail')
            ->with($email->body)
            ->willReturn($expectedSummary);

        $processedEmail = $this->summarizer->processEmail($email);
        $this->assertEquals($expectedSummary, $processedEmail->summary);
    }
}
