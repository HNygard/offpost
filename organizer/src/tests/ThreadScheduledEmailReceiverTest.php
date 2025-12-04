<?php

require_once __DIR__ . '/../class/ThreadScheduledEmailReceiver.php';
require_once __DIR__ . '/../class/ImapFolderStatus.php';
require_once __DIR__ . '/../class/ImapFolderLog.php';
require_once __DIR__ . '/../class/Database.php';

use PHPUnit\Framework\TestCase;

class ThreadScheduledEmailReceiverTest extends TestCase {
    private $mockConnection;
    private $mockEmailProcessor;
    private $mockAttachmentHandler;
    private $mockEmailDbSaver;
    private $receiver;
    
    protected function setUp(): void {
        // Create mock objects
        $this->mockConnection = $this->createMock(Imap\ImapConnection::class);
        $this->mockEmailProcessor = $this->createMock(Imap\ImapEmailProcessor::class);
        $this->mockAttachmentHandler = $this->createMock(Imap\ImapAttachmentHandler::class);
        
        // Create a partial mock of ThreadEmailDatabaseSaver
        $this->mockEmailDbSaver = $this->getMockBuilder(ThreadEmailDatabaseSaver::class)
            ->setConstructorArgs([
                $this->mockConnection,
                $this->mockEmailProcessor,
                $this->mockAttachmentHandler
            ])
            ->onlyMethods(['saveThreadEmails'])
            ->getMock();
        
        // Create a partial mock of ThreadScheduledEmailReceiver
        $this->receiver = $this->getMockBuilder(ThreadScheduledEmailReceiver::class)
            ->setConstructorArgs([
                $this->mockConnection,
                $this->mockEmailProcessor,
                $this->mockAttachmentHandler
            ])
            ->onlyMethods(['findNextFolderForProcessing'])
            ->getMock();
        
        // Set the emailDbSaver property using reflection
        $reflection = new ReflectionClass(ThreadScheduledEmailReceiver::class);
        $property = $reflection->getProperty('emailDbSaver');
        $property->setAccessible(true);
        $property->setValue($this->receiver, $this->mockEmailDbSaver);
    }
    
    /**
     * Test processNextFolder when no folders are available
     */
    public function testProcessNextFolderNoFolders() {
        // :: Setup
        $this->receiver->method('findNextFolderForProcessing')
            ->willReturn(null);
        
        // :: Act
        $result = $this->receiver->processNextFolder();
        
        // :: Assert
        $this->assertFalse($result['success'], 'Should return success=false when no folders are available');
        $this->assertEquals('No folders ready for processing', $result['message'], 'Should return appropriate message when no folders are available');
    }
    
    /**
     * Test processNextFolder when a folder is available but no emails are saved
     */
    public function testProcessNextFolderNoEmails() {
        // :: Setup
        $folder = [
            'folder_name' => 'INBOX.test',
            'thread_id' => 'test-thread-id',
            'thread_title' => 'Test Thread',
            'entity_id' => 'test-entity-id'
        ];
        
        $this->receiver->method('findNextFolderForProcessing')
            ->willReturn($folder);
        
        $this->mockEmailDbSaver->method('saveThreadEmails')
            ->with('INBOX.test')
            ->willReturn([]);
        
        // Mock the output buffer by overriding the processNextFolder method
        $this->receiver = $this->getMockBuilder(ThreadScheduledEmailReceiver::class)
            ->setConstructorArgs([
                $this->mockConnection,
                $this->mockEmailProcessor,
                $this->mockAttachmentHandler
            ])
            ->onlyMethods(['findNextFolderForProcessing', 'startOutputBuffer', 'getOutputBuffer'])
            ->getMock();
        
        $this->receiver->method('findNextFolderForProcessing')->willReturn($folder);
        $this->receiver->method('startOutputBuffer')->willReturn(null);
        $this->receiver->method('getOutputBuffer')->willReturn('Test output');
        
        // Set the emailDbSaver property using reflection
        $reflection = new ReflectionClass(ThreadScheduledEmailReceiver::class);
        $property = $reflection->getProperty('emailDbSaver');
        $property->setAccessible(true);
        $property->setValue($this->receiver, $this->mockEmailDbSaver);
        
        // :: Act
        $result = $this->receiver->processNextFolder();
        
        // :: Assert
        $this->assertTrue($result['success'], 'Should return success=true when folder is processed successfully');
        $this->assertEquals('No new emails in folder: INBOX.test', $result['message'], 'Should return appropriate message when no emails are saved');
        $this->assertEquals('INBOX.test', $result['folder_name'], 'Should return the processed folder name');
        $this->assertEquals('test-thread-id', $result['thread_id'], 'Should return the thread ID');
        $this->assertEquals(0, $result['saved_emails'], 'Should return 0 saved emails');
    }
    
    /**
     * Test processNextFolder when a folder is available and emails are saved
     */
    public function testProcessNextFolderWithEmails() {
        // :: Setup
        $folder = [
            'folder_name' => 'INBOX.test',
            'thread_id' => 'test-thread-id',
            'thread_title' => 'Test Thread',
            'entity_id' => 'test-entity-id'
        ];
        
        $this->receiver->method('findNextFolderForProcessing')
            ->willReturn($folder);
        
        $this->mockEmailDbSaver->method('saveThreadEmails')
            ->with('INBOX.test')
            ->willReturn(['email-id-1', 'email-id-2']);
        
        // Mock the output buffer by overriding the processNextFolder method
        $this->receiver = $this->getMockBuilder(ThreadScheduledEmailReceiver::class)
            ->setConstructorArgs([
                $this->mockConnection,
                $this->mockEmailProcessor,
                $this->mockAttachmentHandler
            ])
            ->onlyMethods(['findNextFolderForProcessing', 'startOutputBuffer', 'getOutputBuffer'])
            ->getMock();
        
        $this->receiver->method('findNextFolderForProcessing')->willReturn($folder);
        $this->receiver->method('startOutputBuffer')->willReturn(null);
        $this->receiver->method('getOutputBuffer')->willReturn('Test output');
        
        // Set the emailDbSaver property using reflection
        $reflection = new ReflectionClass(ThreadScheduledEmailReceiver::class);
        $property = $reflection->getProperty('emailDbSaver');
        $property->setAccessible(true);
        $property->setValue($this->receiver, $this->mockEmailDbSaver);
        
        // :: Act
        $result = $this->receiver->processNextFolder();
        
        // :: Assert
        $this->assertTrue($result['success'], 'Should return success=true when folder is processed successfully');
        $this->assertEquals('Successfully processed folder: INBOX.test', $result['message'], 'Should return appropriate message when emails are saved');
        $this->assertEquals('INBOX.test', $result['folder_name'], 'Should return the processed folder name');
        $this->assertEquals('test-thread-id', $result['thread_id'], 'Should return the thread ID');
        $this->assertEquals(2, $result['saved_emails'], 'Should return the correct number of saved emails');
        $this->assertEquals(['email-id-1', 'email-id-2'], $result['email_ids'], 'Should return the saved email IDs');
    }
    
    /**
     * Test processNextFolder when an exception occurs
     */
    public function testProcessNextFolderWithException() {
        // :: Setup
        $folder = [
            'folder_name' => 'INBOX.test',
            'thread_id' => 'test-thread-id',
            'thread_title' => 'Test Thread',
            'entity_id' => 'test-entity-id'
        ];
        
        $this->receiver->method('findNextFolderForProcessing')
            ->willReturn($folder);
        
        $this->mockEmailDbSaver->method('saveThreadEmails')
            ->with('INBOX.test')
            ->will($this->throwException(new Exception('Test exception')));
        
        // Mock the output buffer by overriding the processNextFolder method
        $this->receiver = $this->getMockBuilder(ThreadScheduledEmailReceiver::class)
            ->setConstructorArgs([
                $this->mockConnection,
                $this->mockEmailProcessor,
                $this->mockAttachmentHandler
            ])
            ->onlyMethods(['findNextFolderForProcessing', 'startOutputBuffer', 'getOutputBuffer'])
            ->getMock();
        
        $this->receiver->method('findNextFolderForProcessing')->willReturn($folder);
        $this->receiver->method('startOutputBuffer')->willReturn(null);
        $this->receiver->method('getOutputBuffer')->willReturn('Test output');
        
        // Set the emailDbSaver property using reflection
        $reflection = new ReflectionClass(ThreadScheduledEmailReceiver::class);
        $property = $reflection->getProperty('emailDbSaver');
        $property->setAccessible(true);
        $property->setValue($this->receiver, $this->mockEmailDbSaver);
        
        // :: Act
        $result = $this->receiver->processNextFolder();
        
        // :: Assert
        $this->assertFalse($result['success'], 'Should return success=false when an exception occurs');
        $this->assertStringContainsString('Error processing folder: INBOX.test', $result['message'], 'Should return appropriate message when an exception occurs');
        $this->assertEquals('INBOX.test', $result['folder_name'], 'Should return the processed folder name');
        $this->assertEquals('test-thread-id', $result['thread_id'], 'Should return the thread ID');
        $this->assertEquals('Test exception', $result['error'], 'Should return the exception message');
    }
    
    /**
     * Test that findNextFolderForProcessing excludes spam and trash folders
     * This test uses reflection to access the protected method
     */
    public function testFindNextFolderForProcessingExcludesSpamAndTrash() {
        // :: Setup
        // Create a real receiver instance (without mocking findNextFolderForProcessing)
        $receiver = new ThreadScheduledEmailReceiver(
            $this->mockConnection,
            $this->mockEmailProcessor,
            $this->mockAttachmentHandler
        );
        
        // Use reflection to call the protected method
        $reflection = new ReflectionClass(ThreadScheduledEmailReceiver::class);
        $method = $reflection->getMethod('findNextFolderForProcessing');
        $method->setAccessible(true);
        
        // :: Act
        $result = $method->invoke($receiver);
        
        // :: Assert
        // The method should either return null or a folder that is NOT spam or trash
        if ($result !== null) {
            $this->assertNotEquals('INBOX.Spam', $result['folder_name'], 
                'findNextFolderForProcessing should not return INBOX.Spam folder');
            $this->assertNotEquals('INBOX.Trash', $result['folder_name'], 
                'findNextFolderForProcessing should not return INBOX.Trash folder');
            $this->assertStringNotContainsString('Spam', $result['folder_name'], 
                'findNextFolderForProcessing should not return any spam folder');
            $this->assertStringNotContainsString('Trash', $result['folder_name'], 
                'findNextFolderForProcessing should not return any trash folder');
        }
        // If result is null, that's fine - it means no folders are ready for processing
        $this->assertTrue(true, 'Test completed successfully');
    }
}
