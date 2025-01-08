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

require_once(__DIR__ . '/bootstrap.php');
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
    private $testEntityId = 'test-entity-development';
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
     * This test checks what happens when we receive emails in our system from a public entity.
     */
    public function testReceiveEmail() {
        // Create test thread
        $uniqueId = uniqid();
        $thread = new Thread();
        $thread->title = 'Test Thread - ' . $uniqueId;
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->emails = [];

        // Create thread in the system
        $createdThread = createThread($this->testEntityId, 'Test', $thread);
        $this->assertNotNull($createdThread);

        // Initialize components needed for email processing
        $folderManager = new ImapFolderManager($this->imapConnection);
        $emailProcessor = new ImapEmailProcessor($this->imapConnection);
        $attachmentHandler = new ImapAttachmentHandler($this->imapConnection);
        
        $threadFolderManager = new ThreadFolderManager($this->imapConnection, $folderManager);
        $threadEmailMover = new ThreadEmailMover($this->imapConnection, $folderManager, $emailProcessor);
        $threadEmailSaver = new ThreadEmailSaver($this->imapConnection, $emailProcessor, $attachmentHandler);

        // Create test email data
        $subject = 'Test Receive Email ' . $uniqueId;
        $plainBody = 'This is a test email for receiving process';
        $attachmentName = 'test.pdf';
        $attachmentContent = '%PDF-1.4 Test PDF content';
        
        // Generate unique message ID and thread index
        $messageId = '<' . uniqid() . '@test.local>';
        $threadIndex = 'AQHZ' . bin2hex(random_bytes(6));
        
        // Create email content
        $email = "";
        $boundary = "----=_Part_" . $uniqueId;
        $altBoundary = "----=_Part_" . $uniqueId . "_alt";
        
        // Headers
        $email .= "Return-Path: <sender@example.com>\r\n";
        $email .= "From: sender@example.com\r\n";
        $email .= "To: " . $thread->my_email . "\r\n";
        $email .= "Subject: " . $subject . "\r\n";
        $email .= "Message-ID: " . $messageId . "\r\n";
        $email .= "Thread-Topic: " . $subject . "\r\n";
        $email .= "Thread-Index: " . $threadIndex . "\r\n";
        $email .= "Date: " . date('r') . "\r\n";
        $email .= "MIME-Version: 1.0\r\n";
        $email .= "Content-Type: multipart/mixed;\r\n boundary=\"" . $boundary . "\"\r\n";
        $email .= "\r\n";
        
        // Plain text version
        $email .= "--" . $boundary . "\r\n";
        $email .= "Content-Type: text/plain; charset=utf-8\r\n";
        $email .= "Content-Transfer-Encoding: base64\r\n";
        $email .= "\r\n";
        $email .= chunk_split(string: base64_encode($plainBody)) . "\r\n";
        
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
        $entityThreads->title_prefix = 'Test';
        $entityThreads->threads = [$createdThread];
        
        // Create required folders
        $requiredFolders = ['INBOX.Archive'];
        $requiredFolders[] = $threadFolderManager->getThreadEmailFolder($entityThreads, $createdThread);
        try {
            $threadFolderManager->createRequiredFolders([$entityThreads]);
        } catch (Exception $e) {
            // Ignore folder creation errors if they already exist
            if (strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }

        // Build email to folder mapping and process INBOX
        $emailToFolder = $threadEmailMover->buildEmailToFolderMapping([$entityThreads]);
        $threadEmailMover->processMailbox('INBOX', $emailToFolder);

        // Get thread folder path
        $threadFolder = $threadFolderManager->getThreadEmailFolder($entityThreads, $createdThread);
        
        // Save thread emails
        $folderJson = THREADS_DIR . '/' . $entityThreads->entity_id . '/' . $createdThread->id;
        $threadEmailSaver->saveThreadEmails($folderJson, $createdThread, $threadFolder);

        // Verify email was saved
        $this->assertTrue(file_exists($folderJson), 'Thread folder should exist');
        $emailFiles = glob($folderJson . '/*.eml');
        $this->assertNotEmpty($emailFiles, 'Email file should exist in thread folder');

        // Debug email files
        foreach ($emailFiles as $emailFile) {
            echo "\nEmail file: " . basename($emailFile) . "\n";
            $savedEmail = file_get_contents($emailFile);
            echo "Email content:\n" . $savedEmail . "\n";
        }
        
        // Find the email file that contains our test subject
        $testEmailFile = null;
        foreach ($emailFiles as $emailFile) {
            $content = file_get_contents($emailFile);
            if (strpos($content, "Subject: Test Receive Email " . $uniqueId) !== false) {
                $testEmailFile = $emailFile;
                $savedEmail = $content;
                break;
            }
        }
        
        $this->assertNotNull($testEmailFile, 'Test email file should exist');
        
        // Verify required headers
        $this->assertStringContainsString('Return-Path:', $savedEmail, 'Email should have Return-Path header');
        $this->assertStringContainsString('From: sender@example.com', $savedEmail, 'Email should have From header');
        $this->assertStringContainsString('To: test@example.com', $savedEmail, 'Email should have To header');
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
        
        // Verify attachment was saved with correct MD5 hash pattern
        /*
        $pattern = $folderJson . '/*att *-' . md5($attachmentName) . '.pdf';
        $attachmentFiles = glob($pattern);
        $this->assertNotEmpty($attachmentFiles, 'Attachment file should exist in thread folder: ' . $pattern);
        $savedAttachment = file_get_contents($attachmentFiles[0]);
        $this->assertEquals($attachmentContent, $savedAttachment, 'Attachment content should match');
        */
        
    }

}
