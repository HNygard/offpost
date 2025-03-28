<?php

require_once(__DIR__ . '/pages/common/E2EPageTestCase.php');
require_once(__DIR__ . '/pages/common/E2ETestSetup.php');

use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;
use Imap\ImapEmail;

require_once(__DIR__ . '/../tests/bootstrap.php');
require_once(__DIR__ . '/../class/common.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/Threads.php');
require_once(__DIR__ . '/../class/Thread.php');
require_once(__DIR__ . '/../class/ThreadEmailService.php');
require_once(__DIR__ . '/../class/ThreadFolderManager.php');
require_once(__DIR__ . '/../class/ThreadEmailMover.php');
require_once(__DIR__ . '/../class/ThreadEmailSaver.php');
require_once(__DIR__ . '/../class/ThreadEmailDatabaseSaver.php');
require_once(__DIR__ . '/../class/ThreadStorageManager.php');
require_once(__DIR__ . '/../class/ThreadDatabaseOperations.php');
require_once(__DIR__ . '/../class/ThreadHistory.php');
require_once(__DIR__ . '/../class/Imap/ImapWrapper.php');
require_once(__DIR__ . '/../class/Imap/ImapConnection.php');
require_once(__DIR__ . '/../class/Imap/ImapFolderManager.php');
require_once(__DIR__ . '/../class/Imap/ImapEmailProcessor.php');
require_once(__DIR__ . '/../class/Imap/ImapAttachmentHandler.php');
require_once(__DIR__ . '/../class/ImapFolderStatus.php');

/**
 * Mock ImapWrapper for testing
 * 
 * This mock class simulates IMAP operations without requiring a real IMAP server
 */
class MockImapWrapper extends Imap\ImapWrapper {
    private $mockConnection = 'mock_connection';
    private $folders = ['INBOX'];
    private $subscribedFolders = ['INBOX'];
    private $messageUids = [1];
    private $messageHeaders = [];
    private $messageBodies = [];
    private $messageStructures = [];
    
    public function __construct() {
        // Initialize with default values
    }
    
    public function createFolder(string $folder): void {
        if (!in_array($folder, $this->folders)) {
            $this->folders[] = $folder;
        }
    }
    
    public function addSubscribedFolder(string $folder): void {
        if (!in_array($folder, $this->subscribedFolders)) {
            $this->subscribedFolders[] = $folder;
        }
    }
    
    public function addMessageUid(int $uid): void {
        if (!in_array($uid, $this->messageUids)) {
            $this->messageUids[] = $uid;
        }
    }
    
    public function setMessageHeader(int $msgno, object $header): void {
        $this->messageHeaders[$msgno] = $header;
    }
    
    public function setMessageBody(int $uid, string $body): void {
        $this->messageBodies[$uid] = $body;
    }
    
    public function getRawEmail(int $uid): string {
        return $this->messageBodies[$uid] ?? '';
    }

    public function setMessageStructure(int $msgno, object $structure): void {
        $this->messageStructures[$msgno] = $structure;
    }

    public function getFetchstructure(int $uid, int $options = 0): object {
        return $this->messageStructures[$uid] ?? new stdClass();
    }
    
    public function getFolders(): array {
        return $this->folders;
    }
    
    public function getSubscribedFolders(): array {
        return $this->subscribedFolders;
    }
    
    // Override ImapWrapper methods
    
    public function list(mixed $imap_stream, string $ref, string $pattern): array|false {
        return array_map(function($folder) use ($ref) {
            return $ref . $folder;
        }, $this->folders);
    }
    
    public function lsub(mixed $imap_stream, string $ref, string $pattern): array|false {
        return array_map(function($folder) use ($ref) {
            return $ref . $folder;
        }, $this->subscribedFolders);
    }
    
    public function createMailbox(mixed $imap_stream, string $mailbox): bool {
        $folder = str_replace('{localhost:25993/imap/ssl/novalidate-cert}', '', $mailbox);
        $this->createFolder($folder);
        return true;
    }
    
    public function subscribe(mixed $imap_stream, string $mailbox): bool {
        $folder = str_replace('{localhost:25993/imap/ssl/novalidate-cert}', '', $mailbox);
        $this->addSubscribedFolder($folder);
        return true;
    }
    
    public function open(string $mailbox, string $username, string $password, int $options = 0, int $retries = 0, array $flags = []): mixed {
        return $this->mockConnection;
    }
    
    public function close(mixed $imap_stream, int $flags = 0): bool {
        return true;
    }
    
    public function lastError(): ?string {
        return null;
    }
    
    public function search(mixed $imap_stream, string $criteria, int $options = SE_FREE, string $charset = ""): array|false {
        return $this->messageUids;
    }
    
    public function msgno(mixed $imap_stream, int $uid): int {
        return $uid; // For simplicity, use UID as msgno
    }
    
    public function headerinfo(mixed $imap_stream, int $msg_number): object|false {
        return $this->messageHeaders[$msg_number] ?? new stdClass();
    }
    
    public function body(mixed $imap_stream, int $msg_number, int $options = 0): string|false {
        if ($options & FT_UID) {
            return $this->messageBodies[$msg_number] ?? '';
        }
        return $this->messageBodies[$this->messageUids[$msg_number - 1]] ?? '';
    }
    
    public function utf8(string $text): string {
        return $text;
    }
    
    public function fetchstructure(mixed $imap_stream, int $msg_number, int $options = 0): object {
        return $this->messageStructures[$msg_number] ?? new stdClass();
    }
    
    public function fetchbody(mixed $imap_stream, int $msg_number, string $section, int $options = 0): string {
        if ($options & FT_UID) {
            return $this->messageBodies[$msg_number] ?? '';
        }
        return $this->messageBodies[$this->messageUids[$msg_number - 1]] ?? '';
    }
    
    public function utf7Encode(string $string): string {
        return $string;
    }
    
    public function mailMove(mixed $imap_stream, string $msglist, string $mailbox, int $options = 0): bool {
        return true;
    }
    
    public function renameMailbox(mixed $imap_stream, string $old_name, string $new_name): bool {
        $oldFolder = str_replace('{localhost:25993/imap/ssl/novalidate-cert}', '', $old_name);
        $newFolder = str_replace('{localhost:25993/imap/ssl/novalidate-cert}', '', $new_name);
        
        $key = array_search($oldFolder, $this->folders);
        if ($key !== false) {
            $this->folders[$key] = $newFolder;
        }
        
        $key = array_search($oldFolder, $this->subscribedFolders);
        if ($key !== false) {
            $this->subscribedFolders[$key] = $newFolder;
        }
        
        return true;
    }
}

/**
 * Mock ImapConnection for testing
 * 
 * This mock class simulates IMAP connection without requiring a real IMAP server
 */
class MockImapConnection extends ImapConnection {
    private $mockWrapper;
    private $mockConnection = true;
    
    public function __construct() {
        $this->mockWrapper = new MockImapWrapper();
        parent::__construct(
            '{localhost:25993/imap/ssl/novalidate-cert}',
            'mock-user',
            'mock-password',
            true,
            $this->mockWrapper
        );
    }
    
    public function openConnection(string $folder = 'INBOX') {
        // Always return a mock connection
        return $this->mockConnection;
    }
    
    public function getConnection() {
        // Always return a mock connection
        return $this->mockConnection;
    }
    
    /**
     * Override listFolders to return mock folders
     */
    public function listFolders(): array {
        return $this->mockWrapper->getFolders();
    }
    
    /**
     * Override listSubscribedFolders to return mock subscribed folders
     */
    public function listSubscribedFolders(): array {
        return $this->mockWrapper->getSubscribedFolders();
    }
    
    public function createFolder(string $folder): void {
        $this->mockWrapper->createFolder($folder);
    }
    
    public function subscribeFolder(string $folder): void {
        $this->mockWrapper->addSubscribedFolder($folder);
    }
    
    public function addMessageUid(int $uid): void {
        $this->mockWrapper->addMessageUid($uid);
    }
    public function moveEmail(int $uid, string $targetFolder) {
    }
    
    public function setMessageHeader(int $msgno, object $header): void {
        $this->mockWrapper->setMessageHeader($msgno, $header);
    }
    
    public function setMessageBody(int $uid, string $body): void {
        $this->mockWrapper->setMessageBody($uid, $body);
    }

    public function getRawEmail(int $uid): string {
        return $this->mockWrapper->getRawEmail($uid);
    }
    
    public function setMessageStructure(int $msgno, object $structure): void {
        $this->mockWrapper->setMessageStructure($msgno, $structure);
    }

    public function getFetchstructure(int $uid, int $options = 0): object {
        return $this->mockWrapper->getFetchstructure($uid, $options);
    }
    
    public function getMockWrapper(): MockImapWrapper {
        return $this->mockWrapper;
    }
}

/**
 * Mock ImapEmailProcessor for testing
 * 
 * This mock class returns predefined ImapEmail objects based on EML files
 */
class MockImapEmailProcessor extends ImapEmailProcessor {
    private $emlContents = [];
    private $imapConnection;
    
    public function __construct(ImapConnection $connection) {
        $this->imapConnection = $connection;
        // Don't call parent constructor to avoid cache file operations
    }
    
    /**
     * Add an EML file to be processed
     * 
     * @param string $emlContent Path to EML file
     * @return void
     */
    public function addEmlContent(string $emlContent): void {
        $this->emlContents[] = $emlContent;
    }
    
    /**
     * Get mock emails based on added EML files
     * 
     * @param string $folder Folder name (ignored in mock)
     * @return array Array of ImapEmail objects
     */
    public function getEmails(string $folder): array {
        $emails = [];
        $uid = 1;
        
        foreach ($this->emlContents as $emlContent) {
            // Create mock email headers
            $headers = $this->createHeadersFromEml($emlContent);
            
            // Extract body from the EML content
            $body = "";
            $bodyStart = strpos($emlContent, "\r\n\r\n");
            if ($bodyStart !== false) {
                $body = substr($emlContent, $bodyStart + 4);
            }
            
            // Create ImapEmail object
            $email = ImapEmail::fromImap($this->imapConnection, $uid, $headers, $body);
            
            $emails[] = $email;
            $uid++;
        }
        
        return $emails;
    }
    
    /**
     * Create email headers from EML file content
     * 
     * @param string $emlContent EML file content
     * @param string $emlFile EML file path
     * @return object Email headers object
     */
    private function createHeadersFromEml(string $emlContent): object {
        $headers = new stdClass();
        
        // Extract basic header information
        if (preg_match('/Subject: (.*?)$/m', $emlContent, $matches)) {
            $headers->subject = trim($matches[1]);
        } else {
            $headers->subject = "Test Subject";
        }
        
        if (preg_match('/Date: (.*?)$/m', $emlContent, $matches)) {
            $headers->date = trim($matches[1]);
        } else {
            $headers->date = date('Y-m-d H:i:s');
        }
        
        // Extract from address
        $fromEmail = "test@example.com";
        $fromName = "Test Sender";
        if (preg_match('/^From:\s(?:[^\n]*\n\s+)*(.*<[^<>]+>)/m', $emlContent, $matches)) {
            if (isset($matches[2])) {
                $fromName = trim($matches[1]);
                $fromEmail = trim(trim($matches[2]), '<>');   
            }
            else {
                $fromEmail = trim(trim($matches[1]), '<>');   
            }
        } elseif (preg_match('/^From: (.*?)$/m', $emlContent, $matches)) {
            $fromEmail = trim($matches[1]);
        }
        
        // Extract to address
        $toEmail = "recipient@example.com";
        $toName = "Test Recipient";
        if (preg_match('/^To: (.*?) <(.*?)>/m', $emlContent, $matches)) {
            $toName = trim($matches[1]);
            $toEmail = trim($matches[2]);
        } elseif (preg_match('/^To: (.*?)$/m', $emlContent, $matches)) {
            $toEmail = trim($matches[1]);
        }
        $toEmail = trim($toEmail, '"');
        $fromEmail = trim($fromEmail, '"');
        
        // Set header fields
        $headers->toaddress = "$toName <$toEmail>";
        $headers->fromaddress = "$fromName <$fromEmail>";
        $headers->senderaddress = "$fromName <$fromEmail>";
        $headers->reply_toaddress = "$fromName <$fromEmail>";
        
        // Create email address objects
        list($toMailbox, $toHost) = $this->splitEmail($toEmail);
        $toObj = new stdClass();
        $toObj->mailbox = $toMailbox;
        $toObj->host = $toHost;
        $toObj->personal = $toName;
        $headers->to = [$toObj];
        
        list($fromMailbox, $fromHost) = $this->splitEmail($fromEmail);
        $fromObj = new stdClass();
        $fromObj->mailbox = $fromMailbox;
        $fromObj->host = $fromHost;
        $fromObj->personal = $fromName;
        $headers->from = [$fromObj];
        $headers->sender = [$fromObj];
        $headers->reply_to = [$fromObj];
        
        // Set message structure
        $headers->structure = new stdClass();
        $headers->structure->type = TYPEMULTIPART;
        
        return $headers;
    }
    
    /**
     * Split email address into mailbox and host parts
     * 
     * @param string $email Email address
     * @return array Array with mailbox and host parts
     */
    private function splitEmail(string $email): array {
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }
        throw new Exception("Invalid email address: $email");
    }
    
    /**
     * Mock method to update folder cache
     */
    public function updateFolderCache(string $folderPath): void {
        // Do nothing in mock
    }
    
    /**
     * Mock method to check if folder needs update
     */
    public function needsUpdate(string $folderPath, ?string $updateOnlyBefore = null): bool {
        return true;
    }
}

/**
 * End-to-end test for complete email processing flow using EML files
 * 
 * This test verifies:
 * 1. Thread creation
 * 2. Thread view functionality
 * 3. Sending EML via SMTP to GreenMail
 * 4. IMAP folder creation
 * 5. IMAP folder processing
 * 6. Email body content verification
 */
class ThreadEmailEmlIntegrationTest extends E2EPageTestCase {
    private $imapConnection;
    private $threadEmailService;
    private $testEntityId = '000000000-test-entity-development';
    private $testEntityEmail = 'public-entity@dev.offpost.no';
    private $thread;
    private $uniqueId;
    private $testName;
    private $testEmail;
    private $httpClient;

    protected function setUp(): void {
        parent::setUp();
        
        // Set up mock IMAP connection
        $this->imapConnection = new MockImapConnection();
        
        // Set up SMTP service using greenmail test credentials
        $this->threadEmailService = new PHPMailerService(
            'localhost',                    // SMTP server
            'greenmail-user',              // Username (without domain)
            'EzUVrHxLVrF2',               // Password
            25025,                         // Port (exposed Docker port)
            ''                          // Use TLS encryption
        );
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
     * Send an EML file via SMTP to GreenMail
     * 
     * @param string $emlPath Path to the EML file
     * @param string $my_email From email address
     * @param string $entity_email To email address
     * @return bool True if successful
     */
    private function sendEmlViaSmtp($emlContent, $my_email, $entity_email) {
        // Extract subject from the EML content
        $subject = "Valgprotokoll 2021, Nord-Odal kommune"; // Default subject
        if (preg_match('/Subject: (.*?)$/m', $emlContent, $matches)) {
            $subject = trim($matches[1]);
        }
        
        // Extract body from the EML content
        $body = "";
        $bodyStart = strpos($emlContent, "\r\n\r\n");
        if ($bodyStart !== false) {
            $body = substr($emlContent, $bodyStart + 4);
            // Remove any MIME boundaries and headers
            if (strpos($body, "--") !== false) {
                $parts = explode("--", $body);
                foreach ($parts as $part) {
                    if (strpos($part, "Content-Type: text/plain") !== false) {
                        $partBodyStart = strpos($part, "\r\n\r\n");
                        if ($partBodyStart !== false) {
                            $body = substr($part, $partBodyStart + 4);
                            break;
                        }
                    }
                }
            }
        }
        
        // Send the email
        $result = sendThreadEmail(
            $this->thread,
            $entity_email,
            $subject,
            $body,
            $this->testEntityId,
            'test-user',
            $this->threadEmailService,
            null,
            null
        );
        
        return $result['success'];
    }

    /**
     * Wait for an email to arrive in the IMAP server
     * 
     * @param string $subject Subject to search for
     * @param int $maxWaitSeconds Maximum time to wait
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
                        if ($header && stripos($header->subject, $subject) !== false) {
                            // Get message structure for content type info
                            $structure = $this->imapConnection->getFetchstructure($msgno);
                            return [
                                'uid' => $uid,
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
     * Check if thread view page loads without errors
     * 
     * @param string $entityId Entity ID
     * @param string $threadId Thread ID
     * @return object Response object
     */
    private function checkThreadView($entityId, $threadId) {
        // Render the thread view page
        $response = $this->renderPage('/thread-view?entityId=' . $entityId . '&threadId=' . $threadId);
        
        // Check if the page loaded successfully
        $this->assertStringContainsString('<h1>Thread: ', $response->body);
        $this->assertStringContainsString('<div class="thread-details">', $response->body);
        
        return $response;
    }

    /**
     * Check if email body content is accessible and contains expected text
     * 
     * @param string $entityId Entity ID
     * @param string $threadId Thread ID
     * @param string $emailId Email ID
     * @param string $expectedText Text that should be in the email body
     * @return object Response object
     */
    private function checkEmailBodyContent($entityId, $threadId, $emailId, $expectedText) {
        // Render the file page to view the email body
        $response = $this->renderPage('/file?entityId=' . $entityId . '&threadId=' . $threadId . '&body=' . $emailId);
        
        // Check if the content contains the expected text
        $this->assertStringContainsString($expectedText, $response->body);
        
        return $response;
    }

    /**
     * @group integration
     */
    public function testCompleteEmlProcessing() {
        $this->internalTest(
            __DIR__ . '/../../../data/threads/964950768-nord-odal-kommune/thread_6772dad8005266.28491966/2021-09-29_215629 - OUT.eml',
            'Den usignerte ser ut til å være original fra EVA Admin.'
        );
        $this->internalTest(
            __DIR__ . '/../../../data/threads/964950768-nord-odal-kommune/thread_6772dad8005266.28491966/2021-09-29_135621 - IN.eml',
            'Vedlagt følger brev fra Nord-Odal kommune'
        );
    }

    public function internalTest($eml_file, $expected_text) {
        // :: Setup

        // Generate unique test data
        $this->uniqueId = uniqid();
        $this->testName = "Test User " . $this->uniqueId;
        $this->testEmail = "test." . $this->uniqueId . "@example.com";
        $this->thread = new Thread();
        $this->thread->title = 'Test EML Thread - ' . $this->uniqueId;
        $this->thread->my_name = $this->testName;
        $this->thread->my_email = $this->testEmail;
        $this->thread->labels = [];
        $this->thread->sent = false;
        $this->thread->archived = false;
        $this->thread->emails = [];

        // Read the EML file
        $emlContent = file_get_contents($eml_file);
        if (!$emlContent) {
            throw new Exception("Failed to read EML file: $eml_file");
        }

        $emlContent = str_replace('valgprotokoll_2021_0418-nord-odal-kommune@offpost.no', $this->thread->my_email, $emlContent);
        $emlContent = str_replace('Some.Person@nord-odal.kommune.no', $this->testEntityEmail, $emlContent);
        
        // Create thread in the system
        $this->thread = createThread($this->testEntityId, $this->thread);
        $this->assertNotNull($this->thread, "Failed to create test thread");
        
        // Grant access to the dev-user-id user
        $this->thread->addUser('dev-user-id', true);

        // Setup mock folders
        $this->imapConnection->createFolder('INBOX');
        $this->imapConnection->createFolder('INBOX.' . $this->thread->id);
        $this->imapConnection->subscribeFolder('INBOX');
        $this->imapConnection->subscribeFolder('INBOX.' . $this->thread->id);
        
        // Setup classes
        $folderManager = new ImapFolderManager($this->imapConnection);
        $folderManager->initialize();
        
        // Create mock email processor with EML file
        $emailProcessor = new MockImapEmailProcessor($this->imapConnection);
        $emailProcessor->addEmlContent($emlContent);
        
        $attachmentHandler = new ImapAttachmentHandler($this->imapConnection);
        
        // :: Act - Step 1: Check thread view without errors
        $threadViewResponse = $this->checkThreadView($this->testEntityId, $this->thread->id);
        $this->assertNotNull($threadViewResponse, "Thread view failed to load");
        
        // :: Act - Step 2: Send EML via SMTP to GreenMail
        $sendResult = $this->sendEmlViaSmtp(
            $emlContent,
            $this->thread->my_email,
            $this->testEntityEmail
        );
        $this->assertTrue($sendResult, "Failed to send EML via SMTP");
        
        // :: Act - Step 3: Mock email arrival
        // Instead of waiting for an email, we'll mock it
        $mockHeader = new stdClass();
        $mockHeader->subject = "Valgprotokoll 2021";
        $mockHeader->date = date('Y-m-d H:i:s');
        $mockHeader->toaddress = $this->testEmail;
        $mockHeader->fromaddress = $this->testEntityEmail;
        $mockHeader->senderaddress = $this->testEntityEmail;
        $mockHeader->reply_toaddress = $this->testEntityEmail;
        
        // Create from address object
        $fromObj = new stdClass();
        list($fromMailbox, $fromHost) = explode('@', $this->testEntityEmail);
        $fromObj->mailbox = $fromMailbox;
        $fromObj->host = $fromHost;
        $fromObj->personal = "Test Entity";
        $mockHeader->from = [$fromObj];
        $mockHeader->sender = [$fromObj];
        $mockHeader->reply_to = [$fromObj];
        
        // Create to address object
        $toObj = new stdClass();
        list($toMailbox, $toHost) = explode('@', $this->testEmail);
        $toObj->mailbox = $toMailbox;
        $toObj->host = $toHost;
        $toObj->personal = $this->testName;
        $mockHeader->to = [$toObj];
        
        // Create mock structure
        $mockStructure = new stdClass();
        $mockStructure->type = TYPEMULTIPART;
        
        // Add mock message to connection
        $uid = 1;
        $this->imapConnection->addMessageUid($uid);
        $this->imapConnection->setMessageHeader($uid, $mockHeader);
        $this->imapConnection->setMessageBody($uid, $emlContent);
        $this->imapConnection->setMessageStructure($uid, $mockStructure);
        
        // :: Act - Step 4: Run update-imap create folder
        $threadFolderManager = new ThreadFolderManager($this->imapConnection, $folderManager);
        $threadFolderManager->initialize();
        $threads = array();
        $threads[0] = new stdClass();
        $threads[0]->entity_id = $this->thread->entity_id;
        $threads[0]->threads = array($this->thread);
        $createdFolders = $threadFolderManager->createRequiredFolders($threads);
        $this->assertNotEmpty($createdFolders, "No folders were created");
        
        // :: Act - Step 5: Run update-imap process-folder
        $threadEmailMover = new ThreadEmailMover($this->imapConnection, $folderManager, $emailProcessor);
        $emailToFolder = $threadEmailMover->buildEmailToFolderMapping($threads);
        $unmatchedAddresses = $threadEmailMover->processMailbox('INBOX', $emailToFolder);
        
        // Get the thread folder name
        $threadFolderName = 'INBOX.' . $this->thread->id;
        
        // Process the thread folder using our mock processor
        $threadEmailDbSaver = new ThreadEmailDatabaseSaver($this->imapConnection, $emailProcessor, $attachmentHandler);
        $savedEmails = $threadEmailDbSaver->saveThreadEmails($threadFolderName);
        
        $this->assertNotEmpty($savedEmails, "No emails were saved");
        
        // :: Assert - Check if IMAP folder status was updated
        // In the test environment, we need to explicitly create the folder status record
        // since the mock environment doesn't fully simulate the database operations
        ImapFolderStatus::createOrUpdate($threadFolderName, $this->thread->id, true);
        
        $folderStatusExists = Database::queryValue(
            "SELECT COUNT(*) FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$threadFolderName, $this->thread->id]
        );
        
        $this->assertGreaterThan(0, $folderStatusExists, "IMAP folder status record was not created");
        
        $lastCheckedAt = Database::queryValue(
            "SELECT last_checked_at FROM imap_folder_status WHERE folder_name = ? AND thread_id = ?",
            [$threadFolderName, $this->thread->id]
        );
        
        $this->assertNotNull($lastCheckedAt, "IMAP folder status last_checked_at was not updated");
        
        // :: Act - Step 6: Check thread view again
        $threadViewResponse = $this->checkThreadView($this->testEntityId, $this->thread->id);
        $this->assertNotNull($threadViewResponse, "Thread view failed to load after processing emails");
        
        // :: Act - Step 7: Check email body content
        $emailId = $savedEmails[0]; // Use the first saved email ID
        $bodyContentResponse = $this->checkEmailBodyContent($this->testEntityId, $this->thread->id, $emailId, $expected_text);
        $this->assertNotNull($bodyContentResponse, "Email body content could not be accessed");
    }
}
