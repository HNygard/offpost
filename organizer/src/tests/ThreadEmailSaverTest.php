<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapEmailProcessor;
use Imap\ImapAttachmentHandler;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadEmailSaver.php');

class ThreadEmailSaverTest extends TestCase {
    private $mockConnection;
    private $mockEmailProcessor;
    private $mockAttachmentHandler;
    private $threadEmailSaver;
    private $tempDir;

    protected function setUp(): void {
        parent::setUp();
        
        // Start database transaction
        Database::beginTransaction();
        
        // Clean database tables
        $db = new Database();
        $db->execute("DELETE FROM thread_email_history");
        $db->execute("DELETE FROM thread_history");
        $db->execute("DELETE FROM thread_authorizations");
        $db->execute("DELETE FROM thread_email_attachments");
        $db->execute("DELETE FROM thread_email_sendings");
        $db->execute("DELETE FROM thread_email_extractions");
        $db->execute("DELETE FROM threads");
        
        // Create temp directory for test files
        $this->tempDir = sys_get_temp_dir() . '/thread_email_saver_test_' . uniqid();
        mkdir($this->tempDir);
        
        // Create mocks
        $this->mockConnection = $this->createMock(ImapConnection::class);
        $this->mockEmailProcessor = $this->createMock(ImapEmailProcessor::class);
        $this->mockAttachmentHandler = $this->createMock(ImapAttachmentHandler::class);
        
        // Initialize ThreadEmailSaver with mocks
        $this->threadEmailSaver = new ThreadEmailSaver(
            $this->mockConnection,
            $this->mockEmailProcessor,
            $this->mockAttachmentHandler
        );
    }

    protected function tearDown(): void {
        // Roll back database transaction
        Database::rollBack();
        
        // Clean up temp directory
        if (file_exists($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function removeDirectory($dir) {
        if (!file_exists($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testSaveThreadEmails() {
        // Create test data
        $folderJson = $this->tempDir . '/test_thread';
        $thread = (object)[
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'my_email' => 'test@example.com',
            'labels' => []
        ];

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent) VALUES (?, ?, ?, ?, ?, ?)",
            [$thread->id, 'test-entity', 'Test Thread', 'Test User', $thread->my_email, 'f']
        );
        $folder = 'INBOX.Test';

        // Create test email
        $testEmail = (object)[
            'uid' => 1,
            'timestamp' => time(),
            'mailHeaders' => (object)[
                'subject' => 'Test Email',
                'from' => 'sender@example.com'
            ]
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('processEmails')
            ->with($folder)
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailDirection')
            ->with($testEmail->mailHeaders, $thread->my_email)
            ->willReturn('incoming');

        $this->mockEmailProcessor->expects($this->once())
            ->method('generateEmailFilename')
            ->with($testEmail->mailHeaders, $thread->my_email)
            ->willReturn('test_email_1');

        $this->mockConnection->expects($this->once())
            ->method('getRawEmail')
            ->with($testEmail->uid)
            ->willReturn('Raw email content');

        $this->mockAttachmentHandler->expects($this->once())
            ->method('processAttachments')
            ->with($testEmail->uid)
            ->willReturn([]);

        // Call method
        $savedEmails = $this->threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);

        // Verify results
        $this->assertCount(1, $savedEmails);
        $this->assertEquals('test_email_1', $savedEmails[0]);
        
        // Verify thread was updated
        $this->assertCount(1, $thread->emails);
        $this->assertEquals('test_email_1', $thread->emails[0]->id);
        $this->assertEquals('incoming', $thread->emails[0]->email_type);
        $this->assertEquals('unknown', $thread->emails[0]->status_type);
        $this->assertContains('uklassifisert-epost', $thread->labels);
    }

    public function testSaveThreadEmailsWithAttachments() {
        // Create test data
        $folderJson = $this->tempDir . '/test_thread';
        $thread = (object)[
            'id' => '550e8400-e29b-41d4-a716-446655440001',
            'my_email' => 'test@example.com',
            'labels' => []
        ];

        // Create thread in database
        $db = new Database();
        $db->execute(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email, sent) VALUES (?, ?, ?, ?, ?, ?)",
            [$thread->id, 'test-entity', 'Test Thread', 'Test User', $thread->my_email, 'f']
        );
        $folder = 'INBOX.Test';

        // Create test email with attachment
        $testEmail = (object)[
            'uid' => 1,
            'timestamp' => time(),
            'mailHeaders' => (object)[
                'subject' => 'Test Email with Attachment',
                'from' => 'sender@example.com'
            ]
        ];

        $testAttachment = (object)[
            'name' => 'test.pdf',
            'filetype' => 'pdf'
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('processEmails')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailDirection')
            ->willReturn('incoming');

        $this->mockEmailProcessor->expects($this->once())
            ->method('generateEmailFilename')
            ->willReturn('test_email_1');

        $this->mockConnection->expects($this->once())
            ->method('getRawEmail')
            ->with($testEmail->uid)
            ->willReturn('Raw email content');

        $this->mockAttachmentHandler->expects($this->once())
            ->method('processAttachments')
            ->with($testEmail->uid)
            ->willReturn([$testAttachment]);

        $this->mockAttachmentHandler->expects($this->once())
            ->method('saveAttachment')
            ->with(
                $testEmail->uid,
                2,
                $this->callback(function($att) {
                    return $att->name === 'test.pdf' && 
                           $att->filetype === 'pdf' &&
                           strpos($att->location, 'test_email_1 - att 1-') !== false;
                }),
                $this->stringContains('test_email_1 - att 1-')
            );

        // Call method
        $savedEmails = $this->threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);

        // Verify results
        $this->assertCount(1, $savedEmails);
        $this->assertEquals('test_email_1', $savedEmails[0]);
        
        // Verify thread was updated with attachment info
        $this->assertCount(1, $thread->emails);
        $this->assertCount(1, $thread->emails[0]->attachments);
        $this->assertEquals('unknown', $thread->emails[0]->attachments[0]->status_type);
        $this->assertEquals('uklassifisert-dok', $thread->emails[0]->attachments[0]->status_text);
    }

    public function testFinishThreadProcessing() {
        // Create test data
        $folderJson = $this->tempDir . '/test_thread';
        mkdir($folderJson);
        
        $thread = (object)[
            'archived' => true
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('updateFolderCache')
            ->with($folderJson);

        // Call method
        $this->threadEmailSaver->finishThreadProcessing($folderJson, $thread);

        // Verify archiving_finished.json was created
        $this->assertFileExists($folderJson . '/archiving_finished.json');
        $archiveData = json_decode(file_get_contents($folderJson . '/archiving_finished.json'));
        $this->assertNotNull($archiveData->date);
    }

    public function testSaveThreadEmailsWithFileSystemError() {
        // Create test data with invalid permissions
        $folderJson = '/root/test_thread';
        $thread = (object)[
            'my_email' => 'test@example.com',
            'labels' => []
        ];
        $folder = 'INBOX.Test';

        $testEmail = (object)[
            'uid' => 1,
            'timestamp' => time(),
            'mailHeaders' => (object)[
                'subject' => 'Test Email',
                'from' => 'sender@example.com'
            ]
        ];

        // Expect exception due to permission error
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ImapConnection-errorHandler: mkdir(): Permission denied');

        $this->threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);
    }

    public function testSaveThreadEmailsWithMalformedEmail() {
        // Create test data with malformed email
        $folderJson = $this->tempDir . '/test_thread';
        mkdir($folderJson, 0777, true);
        $thread = (object)[
            'my_email' => 'test@example.com',
            'labels' => []
        ];
        $folder = 'INBOX.Test';

        $malformedEmail = (object)[
            'uid' => 1,
            'timestamp' => time(),
            'mailHeaders' => null // Malformed headers
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('processEmails')
            ->willReturn([$malformedEmail]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to process email: Invalid email headers');

        $this->threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);
    }

    public function testSaveThreadEmailsWithConnectionFailure() {
        // Create test data
        $folderJson = $this->tempDir . '/test_thread';
        mkdir($folderJson, 0777, true);
        $thread = (object)[
            'my_email' => 'test@example.com',
            'labels' => []
        ];
        $folder = 'INBOX.Test';

        $testEmail = (object)[
            'uid' => 1,
            'timestamp' => time(),
            'mailHeaders' => (object)[
                'subject' => 'Test Email',
                'from' => 'sender@example.com'
            ]
        ];

        // Set up mock expectations
        $this->mockEmailProcessor->expects($this->once())
            ->method('processEmails')
            ->willReturn([$testEmail]);

        $this->mockEmailProcessor->expects($this->once())
            ->method('getEmailDirection')
            ->willReturn('incoming');

        $this->mockEmailProcessor->expects($this->once())
            ->method('generateEmailFilename')
            ->willReturn('test_email_1');

        // Simulate connection failure
        $this->mockConnection->expects($this->once())
            ->method('getRawEmail')
            ->willThrowException(new Exception('Connection lost'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to process email: Connection lost');

        $this->threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);
    }

    public function testSaveThreadEmailsWithConcurrentAccess() {
        // Create test data
        $folderJson = $this->tempDir . '/test_thread';
        mkdir($folderJson);
        
        // Create a lock file to simulate concurrent access
        file_put_contents($folderJson . '/thread.lock', '');
        
        $thread = (object)[
            'my_email' => 'test@example.com',
            'labels' => []
        ];
        $folder = 'INBOX.Test';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Thread is locked');

        $this->threadEmailSaver->saveThreadEmails($folderJson, $thread, $folder);
    }

    public function testFinishThreadProcessingWithError() {
        // Create test data with invalid permissions
        $folderJson = '/root/test_thread'; // Directory with no write permission
        $thread = (object)[
            'archived' => true
        ];

        $this->mockEmailProcessor->expects($this->once())
            ->method('updateFolderCache')
            ->willThrowException(new Exception('Failed to update folder cache'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to update folder cache');

        $this->threadEmailSaver->finishThreadProcessing($folderJson, $thread);
    }

    public function testEmailExistsInThread() {
        // Create test thread with existing email
        $thread = (object)[
            'emails' => [
                (object)['id' => 'existing_email_1']
            ]
        ];

        // Test with reflection to access private method
        $reflection = new ReflectionClass($this->threadEmailSaver);
        $method = $reflection->getMethod('emailExistsInThread');
        $method->setAccessible(true);

        // Verify existing email is found
        $this->assertTrue($method->invoke($this->threadEmailSaver, $thread, 'existing_email_1'));
        
        // Verify non-existing email returns false
        $this->assertFalse($method->invoke($this->threadEmailSaver, $thread, 'non_existing_email'));
        
        // Verify handling of thread without emails array
        $emptyThread = new stdClass();
        $this->assertFalse($method->invoke($this->threadEmailSaver, $emptyThread, 'any_email'));
    }
}
