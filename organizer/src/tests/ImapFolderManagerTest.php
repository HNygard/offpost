<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapFolderManager;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';
require_once __DIR__ . '/../class/Imap/ImapFolderManager.php';

class ImapFolderManagerTest extends TestCase
{
    private $mockWrapper;
    private $connection;
    private $folderManager;
    private $testServer = '{test.server.com:993/imap/ssl}';
    private $testEmail = 'test@example.com';
    private $testPassword = 'testpass';
    private $mockStream;

    protected function setUp(): void
    {
        // Create a mock IMAP stream resource
        $this->mockStream = fopen('php://memory', 'r+');
        
        $this->mockWrapper = $this->createMock(ImapWrapper::class);
        $this->mockWrapper->method('open')
            ->willReturn($this->mockStream);
        $this->mockWrapper->method('lastError')
            ->willReturn('');
        $this->mockWrapper->method('utf7Encode')
            ->willReturnCallback(function($str) { return $str; });
            
        $this->connection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            false,
            $this->mockWrapper
        );
        $this->connection->openConnection();
        
        $this->folderManager = new ImapFolderManager($this->connection);
    }

    protected function tearDown(): void
    {
        $this->mockWrapper->method('close')
            ->willReturn(true);
        $this->connection->closeConnection();
        
        if (is_resource($this->mockStream)) {
            fclose($this->mockStream);
        }
    }

    public function testInitialize()
    {
        $existingFolders = [
            $this->testServer . 'INBOX',
            $this->testServer . 'Sent',
            $this->testServer . 'Trash'
        ];
        $subscribedFolders = [
            $this->testServer . 'INBOX',
            $this->testServer . 'Sent'
        ];

        $this->mockWrapper->expects($this->once())
            ->method('list')
            ->with($this->mockStream, $this->testServer, '*')
            ->willReturn($existingFolders);

        $this->mockWrapper->expects($this->once())
            ->method('lsub')
            ->with($this->mockStream, $this->testServer, '*')
            ->willReturn($subscribedFolders);

        $this->folderManager->initialize();

        $this->assertEquals(['INBOX', 'Sent', 'Trash'], $this->folderManager->getExistingFolders());
        $this->assertEquals(['INBOX', 'Sent'], $this->folderManager->getSubscribedFolders());
    }

    public function testEnsureFolderExistsWhenFolderDoesNotExist()
    {
        $this->mockWrapper->method('list')
            ->willReturn([]);

        $folderName = 'TestFolder';
        
        $this->mockWrapper->expects($this->once())
            ->method('createMailbox')
            ->with($this->mockStream, $this->testServer . $folderName)
            ->willReturn(true);

        $this->folderManager->ensureFolderExists($folderName);
        
        $this->assertContains($folderName, $this->folderManager->getExistingFolders());
    }

    public function testEnsureFolderExistsWhenFolderAlreadyExists()
    {
        $folderName = 'ExistingFolder';
        $existingFolders = [$this->testServer . $folderName];
        
        $this->mockWrapper->method('list')
            ->willReturn($existingFolders);
        
        $this->folderManager->initialize();

        $this->mockWrapper->expects($this->never())
            ->method('createMailbox');

        $this->folderManager->ensureFolderExists($folderName);
        
        $this->assertContains($folderName, $this->folderManager->getExistingFolders());
    }

    public function testEnsureFolderExistsIsCaseInsensitive()
    {
        $folderName = 'ExistingFolder';
        $existingFolders = [$this->testServer . $folderName];
        
        $this->mockWrapper->method('list')
            ->willReturn($existingFolders);
        
        $this->folderManager->initialize();

        $this->mockWrapper->expects($this->never())
            ->method('createMailbox');

        // Test with different case
        $this->folderManager->ensureFolderExists('existingfolder');
        $this->folderManager->ensureFolderExists('EXISTINGFOLDER');
        
        $this->assertContains($folderName, $this->folderManager->getExistingFolders());
    }

    public function testEnsureFolderSubscribedWhenNotSubscribed()
    {
        $this->mockWrapper->method('lsub')
            ->willReturn([]);

        $folderName = 'TestFolder';
        
        $this->mockWrapper->expects($this->once())
            ->method('subscribe')
            ->with($this->mockStream, $this->testServer . $folderName)
            ->willReturn(true);

        $this->folderManager->ensureFolderSubscribed($folderName);
        
        $this->assertContains($folderName, $this->folderManager->getSubscribedFolders());
    }

    public function testEnsureFolderSubscribedWhenAlreadySubscribed()
    {
        $folderName = 'SubscribedFolder';
        $subscribedFolders = [$this->testServer . $folderName];
        
        $this->mockWrapper->method('lsub')
            ->willReturn($subscribedFolders);
        
        $this->folderManager->initialize();

        $this->mockWrapper->expects($this->never())
            ->method('subscribe');

        $this->folderManager->ensureFolderSubscribed($folderName);
        
        $this->assertContains($folderName, $this->folderManager->getSubscribedFolders());
    }

    public function testMoveEmailSuccess()
    {
        $uid = 123;
        $targetFolder = 'TargetFolder';

        $this->mockWrapper->expects($this->once())
            ->method('mailMove')
            ->with($this->mockStream, (string)$uid, $targetFolder)
            ->willReturn(true);

        $this->folderManager->moveEmail($uid, $targetFolder);
    }

    public function testMoveEmailWithNoConnection()
    {
        $this->mockWrapper->method('open')
            ->willReturn(false);

        $connection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            false,
            $this->mockWrapper
        );

        $folderManager = new ImapFolderManager($connection);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active IMAP connection');

        $folderManager->moveEmail(123, 'TargetFolder');
    }

    public function testRenameFolderSuccess()
    {
        $oldName = 'OldFolder';
        $newName = 'NewFolder';

        $this->mockWrapper->expects($this->once())
            ->method('renameMailbox')
            ->with(
                $this->mockStream,
                $this->testServer . $oldName,
                $this->testServer . $newName
            )
            ->willReturn(true);

        // Initialize with old folder name in lists
        $this->mockWrapper->method('list')
            ->willReturn([$this->testServer . $oldName]);
        $this->mockWrapper->method('lsub')
            ->willReturn([$this->testServer . $oldName]);
        
        $this->folderManager->initialize();
        $this->folderManager->renameFolder($oldName, $newName);

        // Verify folder lists were updated
        $this->assertContains($newName, $this->folderManager->getExistingFolders());
        $this->assertContains($newName, $this->folderManager->getSubscribedFolders());
        $this->assertNotContains($oldName, $this->folderManager->getExistingFolders());
        $this->assertNotContains($oldName, $this->folderManager->getSubscribedFolders());
    }

    public function testRenameFolderWithNoConnection()
    {
        $this->mockWrapper->method('open')
            ->willReturn(false);

        $connection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            false,
            $this->mockWrapper
        );

        $folderManager = new ImapFolderManager($connection);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active IMAP connection');

        $folderManager->renameFolder('OldFolder', 'NewFolder');
    }

    public function testCreateThreadFolders()
    {
        $requiredFolders = ['Folder1', 'Folder2', 'Folder3'];

        $this->mockWrapper->method('list')
            ->willReturn([]);
        $this->mockWrapper->method('lsub')
            ->willReturn([]);

        // Set up expectations for createMailbox calls
        $this->mockWrapper->expects($this->exactly(3))
            ->method('createMailbox')
            ->willReturnCallback(function($stream, $folderPath) {
                $this->assertEquals($this->mockStream, $stream);
                $this->assertMatchesRegularExpression('/^{test\.server\.com:993\/imap\/ssl}Folder[123]$/', $folderPath);
                return true;
            });

        // Set up expectations for subscribe calls
        $this->mockWrapper->expects($this->exactly(3))
            ->method('subscribe')
            ->willReturnCallback(function($stream, $folderPath) {
                $this->assertEquals($this->mockStream, $stream);
                $this->assertMatchesRegularExpression('/^{test\.server\.com:993\/imap\/ssl}Folder[123]$/', $folderPath);
                return true;
            });

        $this->folderManager->createThreadFolders($requiredFolders);

        // Verify folders were added to the lists
        foreach ($requiredFolders as $folder) {
            $this->assertContains($folder, $this->folderManager->getExistingFolders());
            $this->assertContains($folder, $this->folderManager->getSubscribedFolders());
        }
    }

    public function testArchiveFolderForNonArchivedFolder()
    {
        $folderName = 'INBOX.TestFolder';
        $expectedArchiveName = 'INBOX.Archive.TestFolder';

        $this->mockWrapper->expects($this->once())
            ->method('renameMailbox')
            ->with(
                $this->mockStream,
                $this->testServer . $folderName,
                $this->testServer . $expectedArchiveName
            )
            ->willReturn(true);

        $this->folderManager->archiveFolder($folderName);
    }

    public function testArchiveFolderForAlreadyArchivedFolder()
    {
        $archivedFolder = 'INBOX.Archive.TestFolder';

        $this->mockWrapper->expects($this->never())
            ->method('renameMailbox');

        $this->folderManager->archiveFolder($archivedFolder);
    }
}
