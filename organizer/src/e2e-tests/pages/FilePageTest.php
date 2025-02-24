<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class FilePageTest extends E2EPageTestCase {

    public function testFileEmailBodyHappy() {
        $file = Database::query("SELECT tea.id as att_id, te.thread_id, t.entity_id
        FROM thread_email_attachments tea
        join thread_emails te on tea.email_id = te.id
        join threads t on te.thread_id = t.id
        limit 1")[0];
        var_dump($file);

        // Need file ID to download
        $response = $this->renderPage('/file?entityId=' . $file['entity_id'] . '&threadId=' . $file['thread_id'] . '&body=' . $file['att_id']);

        // :: Assert content type header for file download
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $response->headers);

        // Contain "mailHeaders" as part of the output
        $this->assertStringContainsString('mailHeaders', $response->body);
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
        $response = $this->renderPage('/file?entityId=abc', 'dev-user-id', 'GET', '400 Bad Request');

        // Assert error message
        $this->assertStringContainsString('Missing required parameters: entityId and threadId', $response->body);
    }

    public function testFileWithoutEntityId() {
        // Should fail when no entity ID provided
        $response = $this->renderPage('/file?threadId=00000000-0000-0000-0000-000000000000', 'dev-user-id', 'GET', '400 Bad Request');

        // Assert error message
        $this->assertStringContainsString('Missing required parameters: entityId and threadId', $response->body);
    }

    public function testFileInvalidId() {
        // Should fail with invalid file ID
        $response = $this->renderPage('/file?threadId=00000000-0000-0000-0000-000000000000&entityId=ab<b>c', 'dev-user-id', 'GET', '404 Not Found');

        // Assert error message
        $this->assertStringContainsString('Thread not found: threadId=00000000-0000-0000-0000-000000000000, entityId=ab&lt;b&gt;c', $response->body);
    }

}
