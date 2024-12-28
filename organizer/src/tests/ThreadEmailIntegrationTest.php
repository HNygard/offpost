<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/Imap/ImapWrapper.php');
require_once(__DIR__ . '/../class/Imap/ImapConnection.php');

class ThreadEmailIntegrationTest extends TestCase {
    private $imapConnection;
    private $threadEmailService;
    private $testEntityId = 'test-entity-development';
    private $testEntityEmail = 'public-entity@dev.offpost.no';

    protected function setUp(): void {
        parent::setUp();
        
        // Set up IMAP connection using greenmail test credentials
        // Set up IMAP connection using greenmail test credentials
        $this->imapConnection = new ImapConnection(
            '{localhost:25993/imap/ssl/novalidate-cert}',
            'public-entity',
            'KjMnBvCxZq9Y',
            true  // Enable debug logging
        );
        
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
            $this->removeDirectory(THREADS_DIR);
        }
        
        // Close IMAP connection
        if ($this->imapConnection) {
            $this->imapConnection->closeConnection();
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

    private function waitForEmail($subject, $maxWaitSeconds = 10): bool {
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
                            return true;
                        }
                    }
                }
                
                // Wait a bit before trying again
                sleep(1);
            } catch (Exception $e) {
                $this->fail('IMAP error while waiting for email: ' . $e->getMessage());
            }
        }
        return false;
    }

    public function testSendAndReceiveEmail() {
        // Create a new thread
        $thread = new Thread();
        $thread->title = 'Integration Test Thread';
        $thread->my_name = 'Test User';
        $thread->my_email = 'test@example.com';
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
            new Threads(),
            $this->threadEmailService
        );

        // Assert email was sent successfully
        $this->assertTrue($result['success'], 'Failed to send email: ' . $result['error'] . "\nDebug: " . $result['debug']);
        
        // Wait for and verify email receipt
        $emailReceived = $this->waitForEmail($subject);
        $this->assertTrue($emailReceived, 'Email was not received within the timeout period');
    }
}
