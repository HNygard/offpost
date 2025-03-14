<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once(__DIR__ . '/../../class/Database.php');
require_once(__DIR__ . '/../../class/Thread.php');
require_once(__DIR__ . '/../../class/Entity.php');

class UpdateImapPageTest extends E2EPageTestCase {

    public function testPageProcessesThreads() {
        // :: Setup
        // Create a test thread that will be processed by update-imap
        $testData = E2ETestSetup::createTestThread();
        $threadId = $testData['thread']->id;
        $entityId = $testData['entity_id'];

        // :: Act
        $response = $this->renderPage('/update-imap');

        // :: Assert
        // Check that the page loads with expected IMAP settings information
        $this->assertStringContainsString(':: IMAP setting', $response->body);
        $this->assertStringContainsString('Server ..... :', $response->body);
        $this->assertStringContainsString('Username ... :', $response->body);

        // Check for expected processing steps in the output
        $this->assertStringContainsString('---- CREATING FOLDERS ----', $response->body);
        $this->assertStringContainsString('---- ARCHIVING FOLDERS ----', $response->body);
        $this->assertStringContainsString('---- PROCESSING INBOX ----', $response->body);
        $this->assertStringContainsString('---- PROCESSING SENT FOLDER ----', $response->body);
        $this->assertStringContainsString('---- SAVING THREAD EMAILS ----', $response->body);
        
        // Check if the page correctly displays links for unmatched addresses (if any)
        // This is a soft assertion since there might not be any unmatched addresses
        if (strpos($response->body, 'Start thread with') !== false) {
            $this->assertStringContainsString('<a href="start-thread.php?my_email=', $response->body);
        }

        // Verify no errors occurred during processing
        $this->assertStringNotContainsString('Error updating imap:', $response->body);
    }
}
