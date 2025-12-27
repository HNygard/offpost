<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class FilePageTest extends E2EPageTestCase {
    public function testFileEmailBodyHappy() {
        $testData = E2ETestSetup::createTestThread();
        $file = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];

        // Need file ID to download
        $response = $this->renderPage('/file?entityId=' . $file['entity_id'] . '&threadId=' . $file['thread_id'] . '&body=' . $file['email_id']);

        // :: Assert content type header for file download
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $response->headers);

        // Contain "mailHeaders" as part of the output
        $this->assertStringContainsString('HEADERS', $response->body);
        $this->assertStringContainsString('From: sender@example.com', $response->body);
    }
    /*
    public function testFilePdfHappy() {
        // TODO: 
        return;
        // :: Assert content type header for file download
        $this->assertStringContainsString('Content-Type: ', $response->headers);
        
        // :: Assert content disposition header for download
        $this->assertStringContainsString('Content-Disposition:', $response->headers);
    }
        */

    public function testFileNotLoggedIn() {
        // Should fail when not logged in
        $response = $this->renderPage('/file', user: null, expected_status:'302 Found');
    }

    public function testFileWithoutThreadId() {
        // Should fail when no file ID provided
        $response = $this->renderPage('/file', 'dev-user-id', 'GET', '400 Bad Request');

        // Assert error message
        $this->assertStringContainsString('Missing required parameter: threadId', $response->body);
    }

    public function testFileInvalidId() {
        // Should fail with invalid file ID
        $response = $this->renderPage('/file?threadId=00000000-0000-0000-0000-000000000000', 'dev-user-id', 'GET', '404 Not Found');

        // Assert error message
        $this->assertStringContainsString('Thread not found: threadId=00000000-0000-0000-0000-000000000000', $response->body);
    }

    public function testFileWithoutContent() {

        $testData = E2ETestSetup::createTestThread();
        $file = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];

        // Update content to empty
        Database::query("UPDATE thread_emails SET content = ''::bytea WHERE id = ?", [$file['email_id']]);

        // Need file ID to download
        $response = $this->renderPage('/file?entityId=' . $file['entity_id'] . '&threadId=' . $file['thread_id'] . '&body=' . $file['email_id'], 
        'dev-user-id', 'GET', '500 Internal Server Error');

        // Correct error message
        $this->assertStringContainsString("Empty email content provided for extraction", $response->body);
    }

}
