<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');
require_once(__DIR__ . '/../../class/Entity.php');

class UpdateImapPageTest extends E2EPageTestCase {

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        
        // Reset GreenMail before running tests
        // Wipes all emails and folders.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://localhost:25181/api/service/reset');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($result === false || !empty($error)) {
            throw new Exception("Failed to reset GreenMail. cURL error: " . $error);
        } elseif ($httpCode !== 200) {
            throw new Exception("Failed to reset GreenMail. HTTP code: " . $httpCode);
        }
    }

    public function testShowsTaskMenu() {
        // :: Setup
        E2ETestSetup::createTestThread();

        // :: Act
        $response = $this->renderPage('/update-imap');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString('<h1>IMAP Tasks</h1>', $response->body);
        $this->assertStringContainsString('<h2>Available Tasks</h2>', $response->body);
        $this->assertStringContainsString('<a href="?task=create-folders">Create Required Folders</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=archive-folders">Archive Folders</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=process-inbox">Process Inbox</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=process-sent">Process Sent Folder</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=process-all">Process All Thread Folders</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=create-folder-status">Create Folder Status Records</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=view-folder-status">View Folder Status</a>', $response->body);
        $this->assertStringContainsString('<a href="?task=view-folder-logs">View Folder Logs</a>', $response->body);
    }

    public function testFolderThatRequireCreation() {
        $this->_testCreateFolders();
        $this->_testProcessInbox();
        $this->_testProcessSentFolder();
        $this->_testProcessAllThreads();
    }

    public function _testCreateFolders() {
        // :: Setup
        // Move all existing threads back in time using database update
        Database::queryValue(
        "UPDATE threads SET created_at = NOW() - INTERVAL '5 hour' returning id"
        );

        // Make our test thread
        $testdata = E2ETestSetup::createTestThread();

        // :: Act part 1 - create folders
        $twoSecondsAgo = new DateTime('now', new DateTimeZone('Europe/Oslo'));
        $twoSecondsAgo->modify('-2 second');
        $response = $this->renderPage('/update-imap?task=create-folders&not-before=' . str_replace(' ', '%20', $twoSecondsAgo->format('Y-m-d H:i:s')));

        // :: Assert part 1 - create folders
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Created folders:', $response->body);
        $this->assertStringContainsString('INBOX.Archive', $response->body);
        $this->assertStringContainsString($testdata['entity_id'], $response->body);

        // :: Setup part 2 - process folder
        preg_match('/Created folders:\n-\s*(\S+)/', $response->body, $matches);

        // :: Act part 2 - process folder
        $response = $this->renderPage('/update-imap?task=process-folder&folder=' . $matches[1]);

        // :: Assert part 2 - process folder
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        // Either we have new emails or not
        $this->assertNotErrorInResponse($response);
        $this->assertTrue(
            strpos($response->body, 'No new emails to save') !== false ||
            strpos($response->body, 'Saved emails:') !== false
        );
    }

    public function testArchiveFolders() {
        // :: Setup
        E2ETestSetup::createTestThread();

        // :: Act
        $response = $this->renderPage('/update-imap?task=archive-folders');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Archived folders for archived threads', $response->body);
    }

    public function _testProcessInbox() {
        // :: Setup
        E2ETestSetup::createTestThread();

        // :: Act
        $response = $this->renderPage('/update-imap?task=process-inbox');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('---- PROCESSING INBOX ----', $response->body);
        // Either we have unmatched addresses or not
        $this->assertTrue(
            strpos($response->body, 'No unmatched email addresses found') !== false ||
            strpos($response->body, 'Unmatched email addresses:') !== false
        );
    }

    public function _testProcessSentFolder() {
        // :: Setup
        E2ETestSetup::createTestThread();

        // :: Act
        $response = $this->renderPage('/update-imap?task=process-sent');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Processed sent folder', $response->body);
    }

    public function _testProcessAllThreads() {
        // :: Setup
        $testdata = E2ETestSetup::createTestThread();

        // :: Act
        $response = $this->renderPage('/update-imap?task=process-all');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString($testdata['entity_id'], $response->body);
    }
    
    public function testCreateFolderStatus() {
        // :: Setup
        $testdata = E2ETestSetup::createTestThread();

        // :: Act
        $response = $this->renderPage('/update-imap?task=create-folder-status');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Created', $response->body);
        $this->assertStringContainsString('folder status records', $response->body);
    }
    
    public function testViewFolderStatus() {
        // :: Setup
        $testdata = E2ETestSetup::createTestThread();
        // Create folder status records first
        $this->renderPage('/update-imap?task=create-folder-status');

        // :: Act
        $response = $this->renderPage('/update-imap?task=view-folder-status');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('IMAP Folder Status Records:', $response->body);
        $this->assertStringContainsString('Folder Name', $response->body);
        $this->assertStringContainsString('Thread', $response->body);
        $this->assertStringContainsString('Last Checked', $response->body);
    }
    
    public function testViewFolderLogs() {
        // :: Setup
        $testdata = E2ETestSetup::createTestThread();
        // Process a folder to generate logs
        $this->renderPage('/update-imap?task=process-all');

        // :: Act
        $response = $this->renderPage('/update-imap?task=view-folder-logs');

        // :: Assert
        $this->assertNotErrorInResponse($response);
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('All folder logs', $response->body);
        $this->assertStringContainsString('Folder Name', $response->body);
        $this->assertStringContainsString('Status', $response->body);
        $this->assertStringContainsString('Message', $response->body);
        $this->assertStringContainsString('Created At', $response->body);
    }

    private function assertNotErrorInResponse($response) {
        $this->assertStringNotContainsString('thrown', $response->body, 'Reponse body contained "thrown": ' . $response->body);
        $this->assertStringNotContainsString('Error updating imap:', $response->body, 'Reponse body contained "Error updating imap:": ' . $response->body);
    }
}
