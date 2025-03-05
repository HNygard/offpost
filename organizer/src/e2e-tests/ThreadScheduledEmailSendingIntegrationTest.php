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

require_once(__DIR__ . '/../tests/bootstrap.php');
require_once(__DIR__ . '/../class/common.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/ThreadScheduledEmailSender.php');
require_once(__DIR__ . '/../class/ThreadDatabaseOperations.php');
require_once(__DIR__ . '/../class/Entity.php');
require_once(__DIR__ . '/../class/Imap/ImapWrapper.php');
require_once(__DIR__ . '/../class/Imap/ImapConnection.php');
require_once(__DIR__ . '/../class/ThreadEmailSending.php');

/**
 * Integration test for the scheduled email sending functionality
 * 
 * This test creates a thread, sets it to READY_FOR_SENDING status,
 * triggers the scheduled email sending, and verifies the email was received
 * in the public entity mailbox.
 */
class ThreadScheduledEmailSendingIntegrationTest extends TestCase {
    private $imapConnection;
    private $testEntityId = '000000000-test-entity-development';
    private $testEntityEmail = 'public-entity@dev.offpost.no';
    private $dbOps;

    protected function setUp(): void {
        parent::setUp();
        
        // Set up IMAP connection to public entity mailbox
        $this->imapConnection = new ImapConnection(
            '{localhost:25993/imap/ssl/novalidate-cert}',
            'public-entity',
            'KjMnBvCxZq9Y',
            true  // Enable debug logging
        );
        
        // Clean up any previous test emails
        try {
            $this->imapConnection->openConnection();
            $testEmails = imap_search($this->imapConnection->getConnection(), 'SUBJECT "Scheduled Email Test"');
            if ($testEmails) {
                foreach ($testEmails as $email) {
                    imap_delete($this->imapConnection->getConnection(), $email);
                }
                imap_expunge($this->imapConnection->getConnection());
            }
        } catch(Exception $e) {
            // Ignore connection errors during setup
        }
        
        // Initialize database operations
        $this->dbOps = new ThreadDatabaseOperations();
        
        // Ensure test directories exist
        if (!file_exists(THREADS_DIR)) {
            mkdir(THREADS_DIR, 0777, true);
        }
    }

    protected function tearDown(): void {
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
    
    /**
     * Helper method to wait for an email with a specific subject
     * 
     * @param string $subject The subject to search for
     * @param int $maxWaitSeconds Maximum time to wait for the email
     * @return array|null Email data if found, null otherwise
     */
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
                            $body = $this->imapConnection->getBody($msgno);
                            return [
                                'header' => $header,
                                'structure' => $structure,
                                'body' => $body
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
     * Test the scheduled email sending functionality
     * 
     * This test:
     * 1. Creates a thread with READY_FOR_SENDING status
     * 2. Triggers the scheduled email sending
     * 3. Verifies the email was received in the public entity mailbox
     * 
     * @group integration
     */
    public function testScheduledEmailSending() {
        // :: Setup

        // Update all existing threads with status READY_FOR_SENDING to STAGED
        Database::execute("UPDATE threads SET sending_status = ? WHERE sending_status = ?", 
            [Thread::SENDING_STATUS_STAGING, Thread::SENDING_STATUS_READY_FOR_SENDING]);
        
        // Create unique test data
        $uniqueId = uniqid();
        $testName = "Test User " . $uniqueId;
        $testEmail = "test." . $uniqueId . "@example.com";
        $subject = "Scheduled Email Test " . $uniqueId;
        $body = "This is a test email body for scheduled sending at " . date('Y-m-d H:i:s');
        
        // Create a new thread
        $thread = new Thread();
        $thread->title = $subject;
        $thread->my_name = $testName;
        $thread->my_email = $testEmail;
        $thread->labels = [];
        $thread->initial_request = $body;
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->sent = false;
        $thread->archived = false;
        $thread->emails = [];
        $thread->entity_id = $this->testEntityId;

        // Create thread in the system
        $createdThread = createThread($this->testEntityId, $thread);
        $this->assertNotNull($createdThread, 'Failed to create test thread');
        
        // Ensure the thread has READY_FOR_SENDING status
        $createdThread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $this->dbOps->updateThread($createdThread, 'test-user');
        
        // Create a ThreadEmailSending record for this thread
        $entity = Entity::getById($this->testEntityId);
        ThreadEmailSending::create(
            $createdThread->id,
            $body,
            $subject,
            $entity->email,
            $testEmail,
            $testName,
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );
        
        // Verify thread was created with correct status
        $threadFromDb = Thread::loadFromDatabase($createdThread->id);
        $this->assertEquals(Thread::SENDING_STATUS_READY_FOR_SENDING, $threadFromDb->sending_status, 
            'Thread should have READY_FOR_SENDING status');
        
        // Verify ThreadEmailSending was created with correct status
        $emailSendings = ThreadEmailSending::getByThreadId($createdThread->id);
        $this->assertCount(1, $emailSendings, 'There should be one ThreadEmailSending record');
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $emailSendings[0]->status,
            'ThreadEmailSending should have READY_FOR_SENDING status');
        
        // :: Act
        
        // Create the email sender with debugging
        $emailSender = new ThreadScheduledEmailSender();
        
        // Send the next scheduled email
        $result = $emailSender->sendNextScheduledEmail();
        
        // :: Assert
        
        // Verify the email was sent successfully
        $this->assertTrue($result['success'], 'Email sending should succeed: ' . ($result['message'] ?? 'Unknown error'));
        $this->assertEquals('Email sent successfully', $result['message'], 'Email sending message should indicate success');
        $this->assertEquals($createdThread->id, $result['thread_id'], 'Result should contain the correct thread ID');
        
        // Wait for and verify email receipt in the public entity mailbox
        $email = $this->waitForEmail($subject);
        $this->assertNotNull($email, 'Email was not received within the timeout period');
        
        // Verify email headers
        $this->assertEquals($testEmail, $email['header']->from[0]->mailbox . '@' . $email['header']->from[0]->host, 
            'From address should match thread email');
        $this->assertEquals($this->testEntityEmail, $email['header']->to[0]->mailbox . '@' . $email['header']->to[0]->host, 
            'To address should match entity email');
        $this->assertEquals($subject, $email['header']->subject, 'Subject should match thread title');
        
        // Verify email body contains the expected content
        $this->assertStringContainsString($body, $email['body'], 'Email body should contain the thread initial request');
        
        // Verify thread status was updated to SENT
        $updatedThread = Thread::loadFromDatabase($createdThread->id);
        $this->assertEquals(Thread::SENDING_STATUS_SENT, $updatedThread->sending_status, 
            'Thread status should be updated to SENT');
        $this->assertTrue($updatedThread->sent, 'Thread sent flag should be true');
        
        // Verify ThreadEmailSending status was updated to SENT
        $updatedEmailSendings = ThreadEmailSending::getByThreadId($createdThread->id);
        $this->assertCount(1, $updatedEmailSendings, 'There should still be one ThreadEmailSending record');
        $this->assertEquals(ThreadEmailSending::STATUS_SENT, $updatedEmailSendings[0]->status,
            'ThreadEmailSending status should be updated to SENT');
        $this->assertNotNull($updatedEmailSendings[0]->smtp_response, 'SMTP response should be recorded');
        $this->assertNotNull($updatedEmailSendings[0]->smtp_debug, 'SMTP debug output should be recorded');
    }
}
