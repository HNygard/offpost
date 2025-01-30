<?php

use PHPUnit\Framework\TestCase;
use OpenAI\ThreadEmailSummarizer;
use OpenAI\OpenAISummarizer;

require_once __DIR__ . '/../class/ThreadEmailSummarizer.php';
require_once __DIR__ . '/../class/OpenAISummarizer.php';

class ThreadEmailSummarizerTest extends TestCase {
    private $apiKey = 'test-api-key';
    private $mockSummarizer;
    private $emailSummarizer;

    protected function setUp(): void {
        $this->mockSummarizer = $this->createMock(OpenAISummarizer::class);
        $this->emailSummarizer = new ThreadEmailSummarizer($this->apiKey, $this->mockSummarizer);
    }

    public function testProcessEmails() {
        $email1 = (object) ['body' => 'This is the first test email body.'];
        $email2 = (object) ['body' => 'This is the second test email body.'];
        $emails = [$email1, $email2];

        $this->mockSummarizer->expects($this->exactly(2))
            ->method('summarizeEmail')
            ->withConsecutive(
                [$this->equalTo($email1->body)],
                [$this->equalTo($email2->body)]
            )
            ->willReturnOnConsecutiveCalls(
                'Summary of first email.',
                'Summary of second email.'
            );

        $this->emailSummarizer->processEmails($emails);

        $this->assertEquals('Summary of first email.', $email1->summary);
        $this->assertEquals('Summary of second email.', $email2->summary);
    }

    public function testUpdateThreadEmail() {
        $email = (object) ['body' => 'This is a test email body.'];
        $summary = 'This is a summary.';

        $reflection = new ReflectionClass($this->emailSummarizer);
        $method = $reflection->getMethod('updateThreadEmail');
        $method->setAccessible(true);

        $method->invoke($this->emailSummarizer, $email, $summary);

        $this->assertEquals($summary, $email->summary);
    }
}
