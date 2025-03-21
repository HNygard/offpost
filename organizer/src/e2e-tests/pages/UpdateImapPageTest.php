<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');
require_once(__DIR__ . '/../../class/Entity.php');

class UpdateImapPageTest extends E2EPageTestCase {

    private $testData;

    protected function setUp(): void {
        parent::setUp();
        // Create a test thread that will be processed by update-imap
        $this->testData = E2ETestSetup::createTestThread();
    }

    public function testShowsTaskMenu() {
        // :: Act
        $response = $this->renderPage('/update-imap');

        // :: Assert
        $this->assertStringContainsString('<h1>IMAP Tasks</h1>', $response->body);
        $this->assertStringContainsString('<h2>Available Tasks</h2>', $response->body);
        $this->assertStringContainsString('<a href="?task=create-folders">Create Required Folders</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=archive-folders">Archive Folders</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=process-inbox">Process Inbox</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=process-sent">Process Sent Folder</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=process-all">Process All Thread Folders</a>', $response->body);
    }

    public function testCreateFolders() {
        // :: Act
        $response = $this->renderPage('/update-imap?task=create-folders');

        // :: Assert
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Created folders:', $response->body);
        $this->assertStringContainsString('INBOX.Archive', $response->body);
        $this->assertStringContainsString($this->testData['entity_id'], $response->body);
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }

    public function testArchiveFolders() {
        // :: Act
        $response = $this->renderPage('/update-imap?task=archive-folders');

        // :: Assert
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Archived folders for archived threads', $response->body);
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }

    public function testProcessInbox() {
        // :: Act
        $response = $this->renderPage('/update-imap?task=process-inbox');

        // :: Assert
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('---- PROCESSING INBOX ----', $response->body);
        // Either we have unmatched addresses or not
        $this->assertTrue(
            strpos($response->body, 'No unmatched email addresses found') !== false ||
            strpos($response->body, 'Unmatched email addresses:') !== false
        );
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }

    public function testProcessSentFolder() {
        // :: Act
        $response = $this->renderPage('/update-imap?task=process-sent');

        // :: Assert
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Processed sent folder', $response->body);
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }

    public function testProcessSpecificThread() {
        // :: Act
        $response = $this->renderPage('/update-imap?task=process-folder&folder=abc');

        // :: Assert
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        // Either we have new emails or not
        $this->assertTrue(
            strpos($response->body, 'No new emails to save') !== false ||
            strpos($response->body, 'Saved emails:') !== false
        );
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }

    public function testProcessAllThreads() {
        // :: Act
        $response = $this->renderPage('/update-imap?task=process-all');

        // :: Assert
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString($this->testData['entity_id'], $response->body);
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }
}
