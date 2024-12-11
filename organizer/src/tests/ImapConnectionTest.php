<?php

use PHPUnit\Framework\TestCase;
use Imap\ImapConnection;
use Imap\ImapWrapper;

require_once __DIR__ . '/../class/Imap/ImapWrapper.php';
require_once __DIR__ . '/../class/Imap/ImapConnection.php';

class ImapConnectionTest extends TestCase
{
    private $imapConnection;
    private $mockWrapper;
    private string $testServer = '{imap.test.com:993/imap/ssl}';
    private string $testEmail = 'test@test.com';
    private string $testPassword = 'password123';

    protected function setUp(): void
    {
        $this->mockWrapper = $this->createMock(ImapWrapper::class);
        $this->imapConnection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            false,
            $this->mockWrapper
        );
    }

    protected function tearDown(): void
    {
        $this->imapConnection->closeConnection();
    }

    public function testOpenConnectionSuccess()
    {
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->expects($this->once())
            ->method('open')
            ->with(
                $this->testServer . 'INBOX',
                $this->testEmail,
                $this->testPassword,
                0,
                1,
                ['DISABLE_AUTHENTICATOR' => 'PLAIN']
            )
            ->willReturn($resource);

        $result = $this->imapConnection->openConnection();
        $this->assertSame($resource, $result);
        fclose($resource);
    }

    public function testOpenConnectionFailure()
    {
        $this->mockWrapper->expects($this->once())
            ->method('open')
            ->willReturn(false);

        $this->mockWrapper->expects($this->once())
            ->method('lastError')
            ->willReturn('Connection failed');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('IMAP error: Connection failed');
        
        $this->imapConnection->openConnection();
    }

    public function testListFoldersWithNoConnection()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active IMAP connection');
        $this->imapConnection->listFolders();
    }

    public function testListFoldersSuccess()
    {
        // Setup a mock connection first
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->imapConnection->openConnection();

        $folders = [
            $this->testServer . 'INBOX',
            $this->testServer . 'Sent',
            $this->testServer . 'Trash'
        ];

        $this->mockWrapper->expects($this->once())
            ->method('list')
            ->willReturn($folders);

        $result = $this->imapConnection->listFolders();
        $this->assertEquals(['INBOX', 'Sent', 'Trash'], $result);
        fclose($resource);
    }

    public function testListSubscribedFoldersWithNoConnection()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active IMAP connection');
        $this->imapConnection->listSubscribedFolders();
    }

    public function testListSubscribedFoldersSuccess()
    {
        // Setup a mock connection first
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->imapConnection->openConnection();

        $folders = [
            $this->testServer . 'INBOX',
            $this->testServer . 'Sent'
        ];

        $this->mockWrapper->expects($this->once())
            ->method('lsub')
            ->willReturn($folders);

        $result = $this->imapConnection->listSubscribedFolders();
        $this->assertEquals(['INBOX', 'Sent'], $result);
        fclose($resource);
    }

    public function testCreateFolderWithNoConnection()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active IMAP connection');
        $this->imapConnection->createFolder('TestFolder');
    }

    public function testCreateFolderSuccess()
    {
        // Setup a mock connection first
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->imapConnection->openConnection();

        $this->mockWrapper->expects($this->once())
            ->method('utf7Encode')
            ->with($this->testServer . 'TestFolder')
            ->willReturn($this->testServer . 'TestFolder');

        $this->mockWrapper->expects($this->once())
            ->method('createMailbox')
            ->willReturn(true);

        $this->imapConnection->createFolder('TestFolder');
        $this->assertTrue(true); // If we got here without exceptions, test passed
        fclose($resource);
    }

    public function testSubscribeFolderWithNoConnection()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active IMAP connection');
        $this->imapConnection->subscribeFolder('TestFolder');
    }

    public function testSubscribeFolderSuccess()
    {
        // Setup a mock connection first
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($resource);
        $this->imapConnection->openConnection();

        $this->mockWrapper->expects($this->once())
            ->method('utf7Encode')
            ->with($this->testServer . 'TestFolder')
            ->willReturn($this->testServer . 'TestFolder');

        $this->mockWrapper->expects($this->once())
            ->method('subscribe')
            ->willReturn(true);

        $this->imapConnection->subscribeFolder('TestFolder');
        $this->assertTrue(true); // If we got here without exceptions, test passed
        fclose($resource);
    }

    public function testDebugLogging()
    {
        $connection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            true,
            $this->mockWrapper
        );

        ob_start();
        $connection->logDebug('Test debug message');
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \(\+ \d+ sec\).*Test debug message\n$/',
            $output
        );
    }

    public function testErrorHandler()
    {
        $this->expectException(\ErrorException::class);
        $this->imapConnection->errorHandler(E_WARNING, 'Test error', __FILE__, __LINE__);
    }

    public function testCheckForImapError()
    {
        $this->mockWrapper->expects($this->once())
            ->method('lastError')
            ->willReturn('');

        $this->imapConnection->checkForImapError();
        $this->assertTrue(true);
    }

    public function testConnectionClosedOnDestruct()
    {
        $resource = fopen('php://memory', 'r');
        
        $mockWrapper = $this->createMock(ImapWrapper::class);
        $mockWrapper->method('open')->willReturn($resource);
        
        $connection = new ImapConnection(
            $this->testServer,
            $this->testEmail,
            $this->testPassword,
            false,
            $mockWrapper
        );

        $connection->openConnection();

        $mockWrapper->expects($this->once())
            ->method('close')
            ->with($resource)
            ->willReturn(true);

        $connection->closeConnection();
        fclose($resource);
    }
}
