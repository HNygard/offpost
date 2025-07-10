<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once __DIR__ . '/../../class/ThreadEmailSending.php';
require_once __DIR__ . '/../../class/Thread.php';

class BulkEmailActionsPageTest extends E2EPageTestCase {
    private $testEmails = [];
    private $testThreadIds = [];
    private $testEntityId = '000000000-test-entity-development';
    
    protected function setUp(): void {
        parent::setUp();
        
        // Create test threads first
        for ($i = 0; $i < 3; $i++) {
            $testData = E2ETestSetup::createTestThread($this->testEntityId);
            $this->testThreadIds[] = $testData['thread']->id;
            
            // Create test email sending records in STAGING status
            $email = ThreadEmailSending::create(
                $testData['thread']->id,
                "Test email content for bulk action test $i",
                "Test Email Subject $i",
                "test-recipient-$i@example.com",
                "test-sender-$i@example.com",
                "Test Sender $i",
                ThreadEmailSending::STATUS_STAGING
            );
            
            $this->testEmails[] = $email;
        }
    }
    
    public function testMultiSelectUIElements() {
        // :: Setup
        // No additional setup needed
        
        // :: Act
        $response = $this->renderPage('/email-sending-overview');
        
        // :: Assert
        // Check for the select-all checkbox
        $this->assertStringContainsString(
            '<input type="checkbox" id="select-all-emails"', 
            $response->body,
            "Select all checkbox should be present"
        );
        
        // Check for the bulk actions form
        $this->assertStringContainsString(
            '<form method="post" id="bulk-actions-form">',
            $response->body,
            "Bulk actions form should be present"
        );
        
        // Check for the action dropdown
        $this->assertStringContainsString(
            '<select name="action" id="bulk-action">',
            $response->body,
            "Action dropdown should be present"
        );
        
        // Check for the available action
        $this->assertStringContainsString(
            'value="set_ready_for_sending">Set ready for sending</option>',
            $response->body,
            "Set ready for sending action should be available"
        );
        
        // Check for the selected count container
        $this->assertStringContainsString(
            '<div class="selected-count-container" id="selected-count-container">',
            $response->body,
            "Selected count container should be present"
        );
        
        // Check for individual email checkboxes (only for STAGING emails)
        $this->assertStringContainsString(
            'class="email-checkbox" name="email_ids[]"',
            $response->body,
            "Email checkboxes should be present"
        );
        
        // Verify that checkboxes are only present for STAGING emails
        foreach ($this->testEmails as $email) {
            $this->assertStringContainsString(
                'value="' . $email->id . '"',
                $response->body,
                "Checkbox for email ID {$email->id} should be present since it's STAGING"
            );
        }
    }
    
    public function testBulkSetReadyForSendingUI() {
        // :: Setup
        // Ensure emails are in STAGING status (they should be from setUp)
        foreach ($this->testEmails as $email) {
            $this->assertEquals(
                ThreadEmailSending::STATUS_STAGING,
                $email->status,
                "Email {$email->id} should be in STAGING status"
            );
        }
        
        // Prepare email IDs for the POST request
        $emailIds = [];
        foreach ($this->testEmails as $email) {
            $emailIds[] = $email->id;
        }
        
        // :: Act
        // Submit the bulk action form
        $response = $this->renderPage(
            '/email-sending-overview',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'set_ready_for_sending',
                'email_ids' => $emailIds
            ]
        );
        
        // Follow the redirect
        $response = $this->renderPage('/email-sending-overview');
        
        // :: Assert
        // Verify in database that emails are ready for sending
        foreach ($this->testEmails as $email) {
            $updatedEmail = ThreadEmailSending::getById($email->id);
            $this->assertEquals(
                ThreadEmailSending::STATUS_READY_FOR_SENDING,
                $updatedEmail->status,
                "Email {$email->id} should be marked as ready for sending"
            );
        }
        
        // Verify that emails no longer have checkboxes (since they're not STAGING anymore)
        foreach ($this->testEmails as $email) {
            // The checkbox should not be present for non-STAGING emails
            $checkboxPattern = 'name="email_ids[]" value="' . $email->id . '"';
            $this->assertStringNotContainsString(
                $checkboxPattern,
                $response->body,
                "Checkbox for email ID {$email->id} should not be present since it's no longer STAGING"
            );
        }
    }
    
    public function testBulkActionOnlyAffectsStagingEmails() {
        // :: Setup
        // Create an additional email that's already READY_FOR_SENDING
        $readyEmail = ThreadEmailSending::create(
            $this->testThreadIds[0],
            "Already ready email content",
            "Already Ready Email Subject",
            "ready-test@example.com",
            "sender@example.com",
            "Test Sender",
            ThreadEmailSending::STATUS_SENT
        );
        
        // Prepare email IDs including both STAGING and READY_FOR_SENDING
        $emailIds = [];
        foreach ($this->testEmails as $email) {
            $emailIds[] = $email->id;
        }
        $emailIds[] = $readyEmail->id; // This should be ignored
        
        // :: Act
        // Submit the bulk action form
        $response = $this->renderPage(
            '/email-sending-overview',
            'dev-user-id',
            'POST',
            '302 Found',
            [
                'action' => 'set_ready_for_sending',
                'email_ids' => $emailIds
            ]
        );

        // Assert response redirect is correct
        $this->assertStringContainsString('Location: /email-sending-overview?success_bulk_action_ready_for_sending=3&error_bulk_action_ready_for_sending=1', $response->headers);
        
        // Verify the already ready email is still ready (unchanged)
        $unchangedEmail = ThreadEmailSending::getById($readyEmail->id);
        $this->assertEquals(
            ThreadEmailSending::STATUS_SENT,
            $unchangedEmail->status,
            "Already ready email should remain unchanged"
        );
    }
    
    public function testInvalidEmailIdHandling() {
        // :: Setup
        $emailIds = ['999999', 'invalid-id', $this->testEmails[0]->id];
        
        // :: Act
        // Submit the bulk action form with invalid IDs
        $response = $this->renderPage(
            '/email-sending-overview',
            'dev-user-id',
            'POST',
            '500 Internal Server Error',
            [
                'action' => 'set_ready_for_sending',
                'email_ids' => $emailIds
            ]
        );
        $this->assertStringContainsString('Invalid email.', $response->body);
    }
    
    public function testEmptySelectionHandling() {
        // :: Setup
        // No email IDs provided
        
        // :: Act
        // Submit the bulk action form with no email IDs
        $response = $this->renderPage(
            '/email-sending-overview',
            'dev-user-id',
            'POST',
            '500 Internal Server Error',
            [
                'action' => 'set_ready_for_sending'
                // No email_ids parameter
            ]
        );
        $this->assertStringContainsString('No email IDs provided.', $response->body);
        
        // Follow the redirect
        $response = $this->renderPage('/email-sending-overview');
        
        // :: Assert
        // Should not show any success or error messages for empty selection
        $this->assertStringNotContainsString(
            'Successfully set',
            $response->body,
            "Should not show success message for empty selection"
        );
        
        $this->assertStringNotContainsString(
            'Failed to process',
            $response->body,
            "Should not show error message for empty selection"
        );
    }
    
    public function testInvalidActionHandling() {
        // :: Setup
        $emailIds = [];
        foreach ($this->testEmails as $email) {
            $emailIds[] = $email->id;
        }
        
        // :: Act
        // Submit the bulk action form with an invalid action
        $response = $this->renderPage(
            '/email-sending-overview',
            'dev-user-id',
            'POST',
            '500 Internal Server Error',
            [
                'action' => 'invalid_action',
                'email_ids' => $emailIds
            ]
        );
        $this->assertStringContainsString('Invalid action.', $response->body);
        
        // Follow the redirect
        $response = $this->renderPage('/email-sending-overview');
        
        // :: Assert
        // Should not show any success or error messages for invalid action
        $this->assertStringNotContainsString(
            'Successfully set',
            $response->body,
            "Should not show success message for invalid action"
        );
        
        $this->assertStringNotContainsString(
            'Failed to process',
            $response->body,
            "Should not show error message for invalid action"
        );
        
        // Verify emails are still in STAGING status
        foreach ($this->testEmails as $email) {
            $unchangedEmail = ThreadEmailSending::getById($email->id);
            $this->assertEquals(
                ThreadEmailSending::STATUS_STAGING,
                $unchangedEmail->status,
                "Email {$email->id} should still be in STAGING status after invalid action"
            );
        }
    }
}
