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
}
