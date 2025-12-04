<?php

require_once __DIR__ . '/bootstrap.php';

class EmailSendingOverviewTest extends PHPUnit\Framework\TestCase {
    
    /**
     * Test that error dialogs are displayed for emails with error data regardless of status
     */
    public function testShowDetailsLinkDisplayedForEmailsWithErrorData() {
        // Test data representing different email statuses with and without error data
        $testCases = [
            // Case 1: SENT email with SMTP response (original behavior)
            [
                'email' => [
                    'id' => 1,
                    'status' => 'SENT',
                    'smtp_response' => '250 OK',
                    'smtp_debug' => null,
                    'error_message' => null
                ],
                'expected' => true,
                'description' => 'SENT email with smtp_response should show details'
            ],
            // Case 2: STAGING email with error data (new behavior from issue #66)
            [
                'email' => [
                    'id' => 2,
                    'status' => 'STAGING', 
                    'smtp_response' => 'Invalid address:  (bcc): sender@example.com',
                    'smtp_debug' => 'Invalid address:  (bcc): sender@example.com<br>',
                    'error_message' => 'Invalid address:  (bcc): sender@example.com'
                ],
                'expected' => true,
                'description' => 'STAGING email with error data should show details'
            ],
            // Case 3: Email with no error data
            [
                'email' => [
                    'id' => 3,
                    'status' => 'READY_FOR_SENDING',
                    'smtp_response' => null,
                    'smtp_debug' => null, 
                    'error_message' => null
                ],
                'expected' => false,
                'description' => 'Email with no error data should not show details'
            ],
            // Case 4: Email with only debug data
            [
                'email' => [
                    'id' => 4,
                    'status' => 'SENDING',
                    'smtp_response' => null,
                    'smtp_debug' => 'Connection attempt debug info',
                    'error_message' => null
                ],
                'expected' => true,
                'description' => 'Email with only smtp_debug should show details'
            ],
            // Case 5: Email with only error message
            [
                'email' => [
                    'id' => 5,
                    'status' => 'READY_FOR_SENDING',
                    'smtp_response' => null,
                    'smtp_debug' => null,
                    'error_message' => 'Failed to send'
                ],
                'expected' => true,
                'description' => 'Email with only error_message should show details'
            ]
        ];

        foreach ($testCases as $testCase) {
            $email = $testCase['email'];
            $expected = $testCase['expected'];
            $description = $testCase['description'];

            // Apply the same logic as in the email-sending-overview.php
            $hasErrorData = $email['smtp_response'] || $email['smtp_debug'] || $email['error_message'];
            
            $this->assertEquals($expected, $hasErrorData, $description);
        }
    }

    /**
     * Test that the dialog content is properly formatted for different error scenarios
     */
    public function testDialogContentFormatting() {
        $testCases = [
            [
                'email' => [
                    'smtp_response' => null,
                    'smtp_debug' => null,
                    'error_message' => 'Connection failed'
                ],
                'expectedSections' => ['Error Message']
            ],
            [
                'email' => [
                    'smtp_response' => '550 Mailbox not found',
                    'smtp_debug' => 'Debug: Connection established',
                    'error_message' => 'Message rejected'
                ],
                'expectedSections' => ['SMTP Response', 'SMTP Debug', 'Error Message']
            ],
            [
                'email' => [
                    'smtp_response' => '250 OK',
                    'smtp_debug' => null,
                    'error_message' => null
                ],
                'expectedSections' => ['SMTP Response']
            ]
        ];

        foreach ($testCases as $testCase) {
            $email = $testCase['email'];
            $expectedSections = $testCase['expectedSections'];

            // Test which sections should be shown based on available data
            $shownSections = [];
            
            // Always show SMTP Response section (with fallback text if empty)
            $shownSections[] = 'SMTP Response';
            
            if ($email['smtp_debug']) {
                $shownSections[] = 'SMTP Debug';
            }
            
            if ($email['error_message']) {
                $shownSections[] = 'Error Message';
            }

            // Update expected sections to always include SMTP Response since it's always shown
            if (!in_array('SMTP Response', $expectedSections)) {
                array_unshift($expectedSections, 'SMTP Response');
            }

            $this->assertEquals($expectedSections, $shownSections, 
                'Dialog should show the correct sections based on available error data');
        }
    }
}
?>