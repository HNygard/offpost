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
        
        // :: Assert Last-Modified header is present
        $this->assertStringContainsString('Last-Modified:', $response->headers);
        
        // :: Assert Cache-Control header is present
        $this->assertStringContainsString('Cache-Control:', $response->headers);

        // Contain "mailHeaders" as part of the output
        $this->assertStringContainsString('HEADERS', $response->body);
        $this->assertStringContainsString('From: sender@example.com', $response->body);
    }
    
    public function testFileEmailBodyIfModifiedSince() {
        $testData = E2ETestSetup::createTestThread();
        $file = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];

        // First request - get the Last-Modified header
        $response = $this->renderPage('/file?entityId=' . $file['entity_id'] . '&threadId=' . $file['thread_id'] . '&body=' . $file['email_id']);
        
        // Extract Last-Modified header from response
        $lastModified = null;
        $lines = explode("\n", $response->headers);
        foreach($lines as $line) {
            if (stripos($line, 'Last-Modified:') !== false) {
                $lastModified = trim(substr($line, strlen('Last-Modified:')));
                break;
            }
        }
        
        $this->assertNotNull($lastModified, 'Last-Modified header should be present');
        
        // Second request - with If-Modified-Since header set to the same time
        $response2 = $this->renderPage(
            '/file?entityId=' . $file['entity_id'] . '&threadId=' . $file['thread_id'] . '&body=' . $file['email_id'],
            'dev-user-id',
            'GET',
            '304 Not Modified',
            null,
            ['If-Modified-Since: ' . $lastModified]
        );
        
        // Body should be empty for 304 response
        $this->assertEmpty($response2->body, '304 response should have empty body');
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

    public function testFileEmailHeadersAreEscaped() {
        // Create a test thread with email containing HTML-like patterns in headers
        $testData = E2ETestSetup::createTestThread();
        $file = [
            'email_id' => $testData['email_id'],
            'thread_id' => $testData['thread']->id,
            'entity_id' => $testData['entity_id']
        ];

        // Update email content to include email address with angle brackets that could be interpreted as HTML
        $email_time = mktime(12, 0, 0, 1, 1, 2021);
        $content = "From: John Doe <john.doe@example.com>\r\n" .
                "To: Jane Smith <jane.smith@example.com>\r\n" .
                "Cc: Bob Johnson <bob@example.com>\r\n" .
                "Subject: Test Email with <brackets>\r\n" .
                "Date: " . date('r', $email_time) . "\r\n" .
                "\r\n" .
                "This is a test email";
        
        Database::query("UPDATE thread_emails SET content = ? WHERE id = ?", [$content, $file['email_id']]);

        // Render the file page
        $response = $this->renderPage('/file?entityId=' . $file['entity_id'] . '&threadId=' . $file['thread_id'] . '&body=' . $file['email_id']);

        // Verify that angle brackets are escaped (not interpreted as HTML tags)
        // The escaped form should be &lt; and &gt;
        $this->assertStringContainsString('&lt;john.doe@example.com&gt;', $response->body, 
            'Email addresses in headers should be HTML-escaped');
        $this->assertStringContainsString('&lt;jane.smith@example.com&gt;', $response->body,
            'Email addresses in headers should be HTML-escaped');
        $this->assertStringContainsString('&lt;bob@example.com&gt;', $response->body,
            'Email addresses in headers should be HTML-escaped');
        $this->assertStringContainsString('&lt;brackets&gt;', $response->body,
            'Angle brackets in subject should be HTML-escaped');
        
        // Verify that the raw angle brackets are NOT present (which would indicate they're being rendered as HTML)
        $this->assertStringNotContainsString('<john.doe@example.com>', $response->body,
            'Raw angle brackets should not be present - they should be escaped');
    }

}
