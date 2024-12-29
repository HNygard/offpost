<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapFolderManager;

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../class/ThreadFolderManager.php');

class ThreadFolderManagerTest extends TestCase {
    private $mockConnection;
    private $mockImapFolderManager;
    private $threadFolderManager;

    protected function setUp(): void {
        parent::setUp();
        
        // Create mocks
        $this->mockConnection = $this->createMock(ImapConnection::class);
        $this->mockImapFolderManager = $this->createMock(ImapFolderManager::class);
        
        // Create ThreadFolderManager with mock connection
        $this->threadFolderManager = new ThreadFolderManager($this->mockConnection);
        
        // Use reflection to replace the folderManager with our mock
        $reflection = new ReflectionClass($this->threadFolderManager);
        $property = $reflection->getProperty('folderManager');
        $property->setAccessible(true);
        $property->setValue($this->threadFolderManager, $this->mockImapFolderManager);
    }

    public function testCreateRequiredFolders() {
        // Create test thread data
        $threads = [
            (object)[
                'title_prefix' => 'Test',
                'threads' => [
                    (object)[
                        'title' => 'Thread 1',
                        'archived' => false
                    ],
                    (object)[
                        'title' => 'Thread 2',
                        'archived' => true
                    ]
                ]
            ]
        ];

        // Set up mock expectations
        $this->mockImapFolderManager->expects($this->once())
            ->method('createThreadFolders')
            ->with($this->callback(function($folders) {
                return in_array('INBOX.Archive', $folders) &&
                       in_array('INBOX.Test - Thread 1', $folders) &&
                       in_array('INBOX.Archive.Test - Thread 2', $folders);
            }));

        // Call method and verify results
        $folders = $this->threadFolderManager->createRequiredFolders($threads);
        $this->assertContains('INBOX.Archive', $folders);
        $this->assertContains('INBOX.Test - Thread 1', $folders);
        $this->assertContains('INBOX.Archive.Test - Thread 2', $folders);
    }

    public function testArchiveThreadFolder() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        $thread = (object)[
            'title' => 'Thread 1',
            'archived' => true
        ];

        // Set up mock expectations
        $this->mockImapFolderManager->expects($this->once())
            ->method('getExistingFolders')
            ->willReturn(['INBOX.Test - Thread 1']);

        $this->mockImapFolderManager->expects($this->once())
            ->method('archiveFolder')
            ->with('INBOX.Test - Thread 1');

        // Call method
        $this->threadFolderManager->archiveThreadFolder($entityThreads, $thread);
    }

    public function testGetThreadEmailFolderWithSpecialCharacters() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        $thread = (object)[
            'title' => 'Æble/Øre/Åre',
            'archived' => false
        ];

        $folder = $this->threadFolderManager->getThreadEmailFolder($entityThreads, $thread);
        
        // Verify special characters are replaced
        $this->assertEquals('INBOX.Test - AEble-OEre-AAre', $folder);
    }

    public function testGetThreadEmailFolderArchived() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        $thread = (object)[
            'title' => 'Thread 1',
            'archived' => true
        ];

        $folder = $this->threadFolderManager->getThreadEmailFolder($entityThreads, $thread);
        $this->assertEquals('INBOX.Archive.Test - Thread 1', $folder);
    }

    public function testInitialize() {
        // Set up mock expectations
        $this->mockImapFolderManager->expects($this->once())
            ->method('initialize');

        // Call method
        $this->threadFolderManager->initialize();
    }

    public function testCreateRequiredFoldersWithError() {
        $threads = [
            (object)[
                'title_prefix' => 'Test',
                'threads' => [
                    (object)[
                        'title' => 'Thread 1',
                        'archived' => false
                    ]
                ]
            ]
        ];

        // Simulate folder creation error
        $this->mockImapFolderManager->expects($this->once())
            ->method('createThreadFolders')
            ->willThrowException(new Exception('Failed to create folder'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to create folder');

        $this->threadFolderManager->createRequiredFolders($threads);
    }

    public function testArchiveThreadFolderWithNonexistentFolder() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        $thread = (object)[
            'title' => 'Thread 1',
            'archived' => true
        ];

        // Simulate folder doesn't exist
        $this->mockImapFolderManager->expects($this->once())
            ->method('getExistingFolders')
            ->willReturn(['INBOX.Other']);

        // Should not attempt to archive non-existent folder
        $this->mockImapFolderManager->expects($this->never())
            ->method('archiveFolder');

        $this->threadFolderManager->archiveThreadFolder($entityThreads, $thread);
    }

    public function testArchiveThreadFolderWithError() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        $thread = (object)[
            'title' => 'Thread 1',
            'archived' => true
        ];

        // Folder exists
        $this->mockImapFolderManager->expects($this->once())
            ->method('getExistingFolders')
            ->willReturn(['INBOX.Test - Thread 1']);

        // Simulate archiving error
        $this->mockImapFolderManager->expects($this->once())
            ->method('archiveFolder')
            ->willThrowException(new Exception('Failed to archive folder'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to archive folder');

        $this->threadFolderManager->archiveThreadFolder($entityThreads, $thread);
    }

    public function testGetThreadEmailFolderWithInvalidCharacters() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        $thread = (object)[
            'title' => 'Thread/With\\Invalid:Characters*?"<>|',
            'archived' => false
        ];

        $folder = $this->threadFolderManager->getThreadEmailFolder($entityThreads, $thread);
        
        // Verify invalid characters are replaced with dashes
        $this->assertEquals('INBOX.Test - Thread-With-Invalid-Characters------', $folder);
    }

    public function testGetThreadEmailFolderWithLongTitle() {
        $entityThreads = (object)[
            'title_prefix' => 'Test',
            'threads' => []
        ];
        
        // Create a very long title (80 chars max for folder name)
        $longTitle = str_repeat('a', 100);
        $expectedTitle = substr($longTitle, 0, 70) . '...';
        
        $thread = (object)[
            'title' => $longTitle,
            'archived' => false
        ];

        $folder = $this->threadFolderManager->getThreadEmailFolder($entityThreads, $thread);
        
        // Verify folder name is truncated correctly
        $this->assertEquals('INBOX.Test - ' . $expectedTitle, $folder);
    }

    public function testCreateRequiredFoldersWithConcurrentOperations() {
        $threads = [
            (object)[
                'title_prefix' => 'Test',
                'threads' => [
                    (object)[
                        'title' => 'Thread 1',
                        'archived' => false
                    ]
                ]
            ]
        ];

        // Simulate folder creation error
        $this->mockImapFolderManager->expects($this->once())
            ->method('createThreadFolders')
            ->willThrowException(new Exception('Failed to create folder: already exists'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to create folder: already exists');

        $this->threadFolderManager->createRequiredFolders($threads);
    }
}
