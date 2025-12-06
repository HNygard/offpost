<?php

// IMAP message type constants if not already defined
if (!defined('TYPETEXT')) define('TYPETEXT', 0);
if (!defined('TYPEMULTIPART')) define('TYPEMULTIPART', 1);
if (!defined('TYPEMESSAGE')) define('TYPEMESSAGE', 2);
if (!defined('TYPEAPPLICATION')) define('TYPEAPPLICATION', 3);
if (!defined('TYPEAUDIO')) define('TYPEAUDIO', 4);
if (!defined('TYPEIMAGE')) define('TYPEIMAGE', 5);
if (!defined('TYPEVIDEO')) define('TYPEVIDEO', 6);
if (!defined('TYPEMODEL')) define('TYPEMODEL', 7);
if (!defined('TYPEOTHER')) define('TYPEOTHER', 8);

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

require_once(__DIR__ . '/../tests/bootstrap.php');
require_once(__DIR__ . '/../class/common.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/ThreadFolderManager.php');
require_once(__DIR__ . '/../class/ThreadEmailMover.php');
require_once(__DIR__ . '/../class/ThreadEmailSaver.php');
require_once(__DIR__ . '/../class/Imap/ImapWrapper.php');
require_once(__DIR__ . '/../class/Imap/ImapConnection.php');
require_once(__DIR__ . '/../class/Imap/ImapFolderManager.php');
require_once(__DIR__ . '/../class/Imap/ImapEmailProcessor.php');
require_once(__DIR__ . '/../class/Imap/ImapAttachmentHandler.php');

class ThreadEmailReceiveIntegrationTest extends TestCase {
    private $imapConnection;
    private $threadEmailService;
    private $testEntityId = '000000000-test-entity-development';
    private $testEntityEmail = 'public-entity@dev.offpost.no';

    protected function setUp(): void {
        parent::setUp();
        
        // Set up IMAP connection using greenmail test credentials
        $this->imapConnection = new ImapConnection(
            '{localhost:25993/imap/ssl/novalidate-cert}',
            'greenmail-user',
            'EzUVrHxLVrF2',
            true  // Enable debug logging
        );
        
        // Clean up any previous test emails
        try {
            $this->imapConnection->openConnection();
            $testEmails = imap_search($this->imapConnection->getConnection(), 'SUBJECT "Test Receive Email"');
            if ($testEmails) {
                foreach ($testEmails as $email) {
                    imap_delete($this->imapConnection->getConnection(), $email);
                }
                imap_expunge($this->imapConnection->getConnection());
            }
        }
        catch(Exception $e) {
        }

        
        // Set up SMTP service using greenmail test credentials
        $this->threadEmailService = new PHPMailerService(
            'localhost',                    // SMTP server
            'greenmail-user',              // Username (without domain)
            'EzUVrHxLVrF2',               // Password
            25025,                         // Port (exposed Docker port)
            ''                          // Use TLS encryption
        );
        
        // Ensure test directories exist
        if (!file_exists(THREADS_DIR)) {
            mkdir(THREADS_DIR, 0777, true);
        }
    }

    protected function tearDown(): void {
        // Clean up test data
        if (file_exists(THREADS_DIR)) {
            //$this->removeDirectory(THREADS_DIR);
        }
        
        // Close IMAP connection
        if ($this->imapConnection) {
            try {
                $this->imapConnection->closeConnection();
            } catch (Exception $e) {
                // Ignore close errors
            }
        }
        
        parent::tearDown();
    }
    
    private function removeDirectory($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = joinPaths($dir, $file);
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function waitForEmail($subject, $maxWaitSeconds = 10): ?array {
        $startTime = time();
        while (time() - $startTime < $maxWaitSeconds) {
            try {
                $this->imapConnection->openConnection();
                
                // Get all messages
                $emails = $this->imapConnection->search('ALL', SE_UID);
                
                if ($emails) {
                    foreach ($emails as $uid) {
                        $msgno = $this->imapConnection->getMsgno($uid);
                        $header = $this->imapConnection->getHeaderInfo($msgno);
                        if ($header && $header->subject === $subject) {
                            // Get message structure for content type info
                            $structure = $this->imapConnection->getFetchstructure($msgno);
                            return [
                                'header' => $header,
                                'structure' => $structure
                            ];
                        }
                    }
                }
                
                // Wait a bit before trying again
                sleep(1);
            } catch (Exception $e) {
                $this->fail('IMAP error while waiting for email: ' . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * @group integration
     * This test checks what happens when we receive emails in our system from a public entity.
     */
    public function testReceiveEmail() {
        // :: Setup

        // Create test thread
        $uniqueId = uniqid();
        $thread = new Thread();
        $thread->title = 'Test Thread - ' . $uniqueId;
        $thread->my_name = 'Test User';
        $thread->my_email = 'test' . $uniqueId . '@example.com';
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->emails = [];

        // Create thread in the system
        $createdThread = createThread($this->testEntityId,  $thread);
        $this->assertNotNull($createdThread);

        // Initialize components needed for email processing
        $folderManager = new ImapFolderManager($this->imapConnection);
        $emailProcessor = new ImapEmailProcessor($this->imapConnection, THREADS_DIR . '/test-cache-threads.json');
        $attachmentHandler = new ImapAttachmentHandler($this->imapConnection);
        
        $threadFolderManager = new ThreadFolderManager($this->imapConnection, $folderManager);
        $threadEmailMover = new ThreadEmailMover($this->imapConnection, $folderManager, $emailProcessor);
        $threadEmailSaver = new ThreadEmailSaver($this->imapConnection, $emailProcessor, $attachmentHandler);

        // Create test email data
        $subject = 'Test Receive Email ' . $uniqueId;
        $plainBody = 'This is a test email for receiving process';
        $attachmentName = 'test.pdf';
        $attachmentContent = "%PDF-1.4
%âãÏÓ

1 0 obj
  << /Type /Catalog
     /Pages 2 0 R
  >>
endobj

2 0 obj
  << /Type /Pages
     /Kids [3 0 R]
     /Count 1
  >>
endobj

3 0 obj
  << /Type /Page
     /Parent 2 0 R
     /MediaBox [0 0 612 792]
     /Contents 4 0 R
     /Resources << >>
  >>
endobj

4 0 obj
  << /Length 22 >>
stream
(Hello, PDF!) Tj
endstream
endobj

xref
0 5
0000000000 65535 f 
0000000010 00000 n 
0000000060 00000 n 
0000000110 00000 n 
0000000160 00000 n 

trailer
  << /Root 1 0 R
     /Size 5
  >>
startxref
210
%%EOF";
        
        // Generate unique message ID and thread index
        $messageId = '<' . uniqid() . '@test.local>';
        $threadIndex = 'AQHZ' . bin2hex(random_bytes(6));
        
        // Create email content with proper MIME structure
        $email = "";
        $boundary = "=_Part_" . $uniqueId;
        
        // Headers
        $email_time = mktime(12, 0, 0, 1, 1, 2021);
        $email .= "Return-Path: <sender@example.com>\r\n";
        $email .= "From: sender@example.com\r\n";
        $email .= "To: " . $thread->my_email . "\r\n";
        $email .= "Subject: " . $subject . "\r\n";
        $email .= "Message-ID: " . $messageId . "\r\n";
        $email .= "Thread-Topic: " . $subject . "\r\n";
        $email .= "Thread-Index: " . $threadIndex . "\r\n";
        $email .= "Date: " . date('r', $email_time) . "\r\n";
        $email .= "MIME-Version: 1.0\r\n";
        $email .= "Content-Type: multipart/mixed;\r\n boundary=\"" . $boundary . "\"\r\n";
        $email .= "\r\n";
        
        // Part 1: Plain text
        $email .= "--" . $boundary . "\r\n";
        $email .= "Content-Type: text/plain; charset=utf-8\r\n";
        $email .= "Content-Transfer-Encoding: base64\r\n";
        $email .= "\r\n";
        $email .= chunk_split(base64_encode($plainBody));
        
        // Part 2: PDF attachment
        $email .= "--" . $boundary . "\r\n";
        $email .= "Content-Type: application/pdf; name=\"" . $attachmentName . "\"\r\n";
        $email .= "Content-Transfer-Encoding: base64\r\n";
        $email .= "Content-Disposition: attachment; filename=\"" . $attachmentName . "\";\r\n";
        $email .= "\r\n";
        $email .= chunk_split(base64_encode($attachmentContent));
        
        // End of message
        $email .= "--" . $boundary . "--\r\n";

        // Use PHP's imap_append to add test email to INBOX
        $this->imapConnection->openConnection();
        imap_append(
            $this->imapConnection->getConnection(),
            '{localhost:25993/imap/ssl/novalidate-cert}INBOX',
            $email
        );

        // Initialize folder manager
        $threadFolderManager->initialize();
        
        // Create entity threads structure
        $entityThreads = new Threads();
        $entityThreads->entity_id = $this->testEntityId;
        $entityThreads->threads = [$createdThread];
        
        // Create required folders
        $requiredFolders = ['INBOX.Archive'];
        $requiredFolders[] = $threadFolderManager->getThreadEmailFolder($entityThreads->entity_id, $createdThread);
        try {
            $threadFolderManager->createRequiredFolders([$entityThreads]);
        } catch (Exception $e) {
            // Ignore folder creation errors if they already exist
            if (strpos($e->getMessage(), 'already exists') === false) {
                throw new Exception('Error creating folders.', 0, $e);
            }
        }

        // :: Act

        // Build email to folder mapping and process INBOX
        $emailToFolder = $threadEmailMover->buildEmailToFolderMapping([$entityThreads]);
        $threadEmailMover->processMailbox('INBOX', $emailToFolder);

        // Get thread folder path
        $threadFolder = $threadFolderManager->getThreadEmailFolder($entityThreads->entity_id, $createdThread);
        
        // Save thread emails
        $threadDir = THREADS_DIR . '/' . $entityThreads->entity_id . '/' . $createdThread->id;
        $threadEmailSaver->saveThreadEmails($threadDir, $createdThread, $threadFolder);
        $threadEmailSaver->finishThreadProcessing($threadDir, $createdThread);

        // :: Assert
        
        // Verify thread folder structure and files
        $this->assertTrue(file_exists($threadDir), 'Thread folder should exist');
        $this->assertTrue(is_dir($threadDir), 'Thread directory should exist');
        
        // List all files in thread directory
        $threadFiles = array_diff(scandir($threadDir), array('.', '..'));
        $testEmailFile = date('Y-m-d_His', $email_time) . ' - IN.eml';
        // Expected files including attachment
        $expectedFiles = [
            $testEmailFile,
            date('Y-m-d_His', $email_time) . ' - IN.json',
            date('Y-m-d_His', $email_time) . ' - IN - att 1-'. md5($attachmentName) . '.pdf'
        ];
        sort($threadFiles);
        sort($expectedFiles);
        $this->assertEquals($expectedFiles, $threadFiles, 'Thread directory should contain the right files including attachment.');

        // Verify attachment content
        $attachmentPath = $threadDir . '/' . date('Y-m-d_His', $email_time) . ' - IN - att 1-'. md5($attachmentName) . '.pdf';
        $this->assertTrue(file_exists($attachmentPath), 'Attachment file should exist');
        $this->assertEquals($attachmentContent, file_get_contents($attachmentPath), 'Attachment content should match');
        
        // Read the saved email file to verify its contents
        $savedEmail = file_get_contents($threadDir . '/' . $testEmailFile);
        
        // Verify required headers
        $this->assertStringContainsString('Return-Path:', $savedEmail, 'Email should have Return-Path header');
        $this->assertStringContainsString('From: sender@example.com', $savedEmail, 'Email should have From header');
        $this->assertStringContainsString('To: test' . $uniqueId . '@example.com', $savedEmail, 'Email should have To header');
        $this->assertStringContainsString('Subject: ' . $subject, $savedEmail, 'Email should have Subject header');
        $this->assertStringContainsString('Message-ID:', $savedEmail, 'Email should have Message-ID header');
        $this->assertStringContainsString('MIME-Version: 1.0', $savedEmail, 'Email should have MIME-Version header');
        $this->assertStringContainsString('Thread-Topic:', $savedEmail, 'Email should have Thread-Topic header');
        $this->assertStringContainsString('Thread-Index:', $savedEmail, 'Email should have Thread-Index header');
        $this->assertStringContainsString('Date:', $savedEmail, 'Email should have Date header');
        
        // Verify content structure
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $savedEmail, 'Email should have text/plain part');
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $savedEmail, 'Content should be base64 encoded');
        
        // Verify email body content
        $this->assertStringContainsString(base64_encode($plainBody), $savedEmail, 'Email should contain the base64 encoded test body');

        // Verify the thread still exists in database
        $entityThreads = getThreadsForEntity($this->testEntityId);
        $updatedThread = null;
        foreach ($entityThreads->threads as $thread) {
            if ($thread->id === $createdThread->id) {
                $updatedThread = $thread;
                break;
            }
        }
        
        $this->assertNotNull($updatedThread, 'Thread should exist in entity threads');
        
        // Get thread ID for comparison
        $threadId = $updatedThread->id;
        
        // Since emails are saved to filesystem but not yet synced to database,
        // read the email data directly from the saved files
        $emailJsonFile = $threadDir . '/' . date('Y-m-d_His', $email_time) . ' - IN.json';
        $this->assertTrue(file_exists($emailJsonFile), 'Email JSON file should exist');
        $emailData = json_decode(file_get_contents($emailJsonFile), true);
        $datetime_first_seen = $emailData['datetime_first_seen'];
        
        // Verify email metadata in the saved JSON file
        $this->assertEquals($email_time, $emailData['timestamp_received'], 'Email timestamp should match');
        $this->assertEquals("2021-01-01 12:00:00", $emailData['datetime_received'], 'Email datetime should match');
        $this->assertEquals("2021-01-01_120000 - IN", $emailData['id'], 'Email ID should match');
        $this->assertEquals("IN", $emailData['email_type'], 'Email type should be IN');
        $this->assertEquals("unknown", $emailData['status_type'], 'Status type should be unknown');
        $this->assertEquals("Uklassifisert", $emailData['status_text'], 'Status text should be Uklassifisert');
        $this->assertFalse($emailData['ignore'], 'Email should not be ignored');
        
        // Verify attachment metadata
        $this->assertCount(1, $emailData['attachments'], 'Should have 1 attachment');
        $this->assertEquals('test.pdf', $emailData['attachments'][0]['name'], 'Attachment name should match');
        $this->assertEquals('test.pdf', $emailData['attachments'][0]['filename'], 'Attachment filename should match');
        $this->assertEquals('pdf', $emailData['attachments'][0]['filetype'], 'Attachment filetype should match');
        $this->assertEquals('2021-01-01_120000 - IN - att 1-754dc77d28e62763c4916970d595a10f.pdf', $emailData['attachments'][0]['location'], 'Attachment location should match');
        $this->assertEquals('unknown', $emailData['attachments'][0]['status_type'], 'Attachment status type should be unknown');
        $this->assertEquals('uklassifisert-dok', $emailData['attachments'][0]['status_text'], 'Attachment status text should match'); 
    }

}
