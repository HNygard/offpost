<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/AdminNotificationService.php';

class AdminNotificationServiceTest extends PHPUnit\Framework\TestCase {
    
    public function testNotifyAdminOfError() {
        $mockEmailService = new MockEmailService(true);
        $notificationService = new AdminNotificationService($mockEmailService);
        
        $result = $notificationService->notifyAdminOfError(
            'scheduled-email-sending',
            'Test error message',
            ['thread_id' => 'test-123', 'error_code' => 500]
        );
        
        $this->assertTrue($result);
        $this->assertEquals(1, count($mockEmailService->getSentEmails()));
        
        $email = $mockEmailService->lastEmailData;
        $this->assertEquals('system@offpost.no', $email['from']);
        $this->assertEquals('Offpost System', $email['fromName']);
        $this->assertEquals('admin@dev.offpost.no', $email['to']);
        $this->assertEquals('Offpost Error: scheduled-email-sending', $email['subject']);
        
        // Check that the body contains expected information
        $this->assertStringContainsString('scheduled-email-sending', $email['body']);
        $this->assertStringContainsString('Test error message', $email['body']);
        $this->assertStringContainsString('thread_id: test-123', $email['body']);
        $this->assertStringContainsString('error_code: 500', $email['body']);
    }
    
    public function testNotifyAdminOfErrorWithoutDetails() {
        $mockEmailService = new MockEmailService(true);
        $notificationService = new AdminNotificationService($mockEmailService);
        
        $result = $notificationService->notifyAdminOfError(
            'scheduled-imap-handling',
            'Simple error message'
        );
        
        $this->assertTrue($result);
        $this->assertEquals(1, count($mockEmailService->getSentEmails()));
        
        $email = $mockEmailService->lastEmailData;
        $this->assertStringContainsString('scheduled-imap-handling', $email['body']);
        $this->assertStringContainsString('Simple error message', $email['body']);
    }
    
    public function testNotifyAdminOfErrorHandlesFailure() {
        $mockEmailService = new MockEmailService(false);
        $notificationService = new AdminNotificationService($mockEmailService);
        
        $result = $notificationService->notifyAdminOfError(
            'test-source',
            'Test error'
        );
        
        $this->assertFalse($result);
        $this->assertEquals('Mock email failure', $notificationService->getLastError());
    }
}