<?php

use PHPUnit\Framework\TestCase;
require_once(__DIR__ . '/../class/Threads.php');

class MockEmailService implements IEmailService {
    private $shouldSucceed;
    private $lastError = '';
    public $lastEmailData;

    public function __construct($shouldSucceed = true) {
        $this->shouldSucceed = $shouldSucceed;
    }

    public function sendEmail($from, $fromName, $to, $subject, $body, $bcc = null) {
        $this->lastEmailData = [
            'from' => $from,
            'fromName' => $fromName,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'bcc' => $bcc
        ];
        if (!$this->shouldSucceed) {
            $this->lastError = 'Mock email failure';
        }
        return $this->shouldSucceed;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getDebugOutput() {
        return 'Mock debug output';
    }
}

class ThreadsTest extends TestCase {
    public function testSendThreadEmail() {
        // Arrange
        $thread = new stdClass();
        $thread->my_email = 'test@example.com';
        $thread->my_name = 'Test User';
        $thread->sent = false;

        $emailService = new MockEmailService(true);

        // Act
        $result = sendThreadEmail(
            $thread,
            'recipient@example.com',
            'Test Subject',
            'Test Body',
            'entity1',
            new Threads(),
            $emailService
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('test@example.com', $emailService->lastEmailData['from']);
        $this->assertEquals('Test User', $emailService->lastEmailData['fromName']);
        $this->assertEquals('recipient@example.com', $emailService->lastEmailData['to']);
        $this->assertEquals('Test Subject', $emailService->lastEmailData['subject']);
        $this->assertEquals('Test Body', $emailService->lastEmailData['body']);
        $this->assertEquals('', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
    }

    public function testSendThreadEmailFailure() {
        // Arrange
        $thread = new stdClass();
        $thread->my_email = 'test@example.com';
        $thread->my_name = 'Test User';
        $thread->sent = false;

        $emailService = new MockEmailService(false);

        // Act
        $result = sendThreadEmail(
            $thread,
            'recipient@example.com',
            'Test Subject',
            'Test Body',
            'entity1',
            new Threads(),
            $emailService
        );

        // Assert
        $this->assertFalse($result['success']);
        $this->assertFalse($thread->sent);
        $this->assertEquals('Mock email failure', $result['error']);
        $this->assertEquals('Mock debug output', $result['debug']);
    }
}
