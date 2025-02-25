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

class ThreadEmailSendingIntegrationTest extends TestCase {
    private $imapConnection;
    private $threadEmailService;
    private $testEntityId = 'test-entity-development';
    private $testEntityEmail = 'public-entity@dev.offpost.no';

    protected function setUp(): void {
        parent::setUp();
        
        // Set up IMAP connection using greenmail test credentials
        $this->imapConnection = new ImapConnection(
            '{localhost:25993/imap/ssl/novalidate-cert}',
            'public-entity',
            'KjMnBvCxZq9Y',
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
     */
    public function testStartThread() {
        // Create unique test data
        $uniqueId = uniqid();
        $testName = "Test User " . $uniqueId;
        $testEmail = "test." . $uniqueId . "@example.com";
        
        // Create a new thread
        $thread = new Thread();
        $thread->title = 'Test Thread - ' . $uniqueId;
        $thread->my_name = $testName;
        $thread->my_email = $testEmail;
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->emails = [];

        // Create thread in the system
        $createdThread = createThread($this->testEntityId, 'Test', $thread);
        $this->assertNotNull($createdThread);

        // Generate a unique subject for this test
        $subject = 'Integration Test Email ' . uniqid();
        $body = 'This is a test email body sent at ' . date('Y-m-d H:i:s');

        // Send the email
        $result = sendThreadEmail(
            $createdThread,
            $this->testEntityEmail,
            $subject,
            $body,
            $this->testEntityId,
            'test-user',
            $this->threadEmailService,
            null,
            null
        );

        // Assert email was sent successfully
        $this->assertTrue($result['success'], 'Failed to send email: ' . $result['error'] . "\nDebug: " . $result['debug']);
        
        // Wait for and verify email receipt
        $email = $this->waitForEmail($subject);
        $this->assertNotNull($email, 'Email was not received within the timeout period');

        // Get raw headers from the email we found
        $rawHeaders = imap_fetchheader($this->imapConnection->getConnection(), $email['header']->Msgno);
        
        // Parse raw headers into an associative array
        $headerLines = explode("\n", $rawHeaders);
        $parsedHeaders = [];
        $currentHeader = '';
        
        foreach ($headerLines as $line) {
            if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $matches)) {
                $currentHeader = $matches[1];
                $parsedHeaders[$currentHeader] = trim($matches[2]);
            } elseif (strlen(trim($line)) > 0 && $currentHeader) {
                // Handle multi-line headers
                $parsedHeaders[$currentHeader] .= ' ' . trim($line);
            }
        }
        
        // Define expected headers and their values
        $expectedHeaders = [
            'Return-Path' => '<' . $thread->my_email . '>',
            'To' => $this->testEntityEmail,
            'From' => $thread->my_name . ' <' . $thread->my_email . '>',
            'Subject' => $subject,
            'X-Mailer' => 'Roundcube thread starter',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8'
        ];
        
        // Verify all expected headers exist with correct values
        foreach ($expectedHeaders as $headerName => $expectedValue) {
            $this->assertArrayHasKey($headerName, $parsedHeaders, "Header '$headerName' is missing");
            $this->assertEquals($expectedValue, $parsedHeaders[$headerName], "Header '$headerName' value does not match");
        }
        
        // Verify presence and format of variable headers
        $this->assertArrayHasKey('Message-ID', $parsedHeaders, "Header 'Message-ID' is missing");
        $this->assertMatchesRegularExpression('/<[^>]+@[^>]+>/', $parsedHeaders['Message-ID'], "Invalid Message-ID format");
        
        $this->assertArrayHasKey('Date', $parsedHeaders, "Header 'Date' is missing");
        $this->assertNotFalse(strtotime($parsedHeaders['Date']), "Invalid Date format");
        
        $this->assertArrayHasKey('Received', $parsedHeaders, "Header 'Received' is missing");
        $this->assertMatchesRegularExpression('/from .+ \(HELO [^)]+\); .+/', $parsedHeaders['Received'], "Invalid Received format");
        
        // Verify no unexpected headers exist
        $expectedHeaderNames = array_merge(
            array_keys($expectedHeaders),
            ['Message-ID', 'Date', 'Received']
        );
        $unexpectedHeaders = array_diff(array_keys($parsedHeaders), $expectedHeaderNames);
        $this->assertEmpty($unexpectedHeaders, 'Unexpected headers found: ' . implode(', ', $unexpectedHeaders));
    }
}
