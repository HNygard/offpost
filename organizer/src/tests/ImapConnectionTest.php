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

    public function testMailboxStatePreservesFailureAndChecksUidWithoutChangingMailbox(): void
    {
        // :: Setup
        $stream = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($stream);
        $this->imapConnection->openConnection('INBOX');
        $this->mockWrapper->method('utf7Encode')->willReturnArgument(0);
        $this->mockWrapper->expects($this->exactly(7))->method('errors')
            ->willReturnOnConsecutiveCalls(['UID does not exist'], [], [], [], [], [], []);
        $this->mockWrapper->expects($this->exactly(7))->method('alerts')
            ->willReturnOnConsecutiveCalls(['Server alert'], [], [], [], [], [], []);
        $this->mockWrapper->expects($this->once())->method('ping')->with($stream)->willReturn(true);
        $selected = (object)['Mailbox' => $this->testServer . 'OtherFolder', 'Nmsgs' => 2, 'Recent' => 0];
        $nativeSelected = clone $selected;
        $nativeSelected->Mailbox = '{imap.test.com:993/imap/ssl/user="test@test.com"/authuser="proxy"}OtherFolder';
        $status = (object)['messages' => 3, 'recent' => 0, 'unseen' => 1, 'uidnext' => 44, 'uidvalidity' => 99];
        $this->mockWrapper->expects($this->once())->method('check')->with($stream)->willReturn($nativeSelected);
        $this->mockWrapper->expects($this->once())->method('status')
            ->with($stream, $this->testServer . 'INBOX')->willReturn($status);
        $this->mockWrapper->expects($this->once())->method('search')
            ->with($stream, 'UID 42', SE_UID)->willReturn([42]);
        $this->mockWrapper->expects($this->once())->method('msgno')->with($stream, 42)->willReturn(2);
        $this->mockWrapper->expects($this->once())->method('fetchOverview')->with($stream, 42)
            ->willReturn([(object)['uid' => 42, 'msgno' => 2, 'deleted' => 1, 'seen' => 1,
                'subject' => 'Do not retain', 'from' => 'private@example.com']]);
        $this->mockWrapper->expects($this->never())->method('mailMove');
        $this->mockWrapper->expects($this->never())->method('fetchbody');

        // :: Act
        $state = $this->imapConnection->getMailboxState('INBOX', 42);

        // :: Assert
        $this->assertIsFloat($state['time']);
        unset($state['time']);
        $emptyQueues = array_fill_keys(['connection_alive', 'selected_mailbox', 'mailbox_status',
            'uid_search', 'uid_message_number', 'uid_flags'], []);
        $this->assertEquals([
            'mailbox' => 'INBOX',
            'errors_before_probes' => ['UID does not exist'],
            'alerts_before_probes' => ['Server alert'],
            'connection_alive' => true,
            'selected_mailbox' => $selected,
            'mailbox_status' => $status,
            'uid_search' => [42],
            'uid_message_number' => 2,
            'uid_flags' => [['uid' => 42, 'msgno' => 2, 'deleted' => 1, 'seen' => 1]],
            'probe_errors' => $emptyQueues,
            'probe_alerts' => $emptyQueues,
        ], $state);
        fclose($stream);
    }

    public function testMailboxStateContinuesWhenProbesFail(): void
    {
        // :: Setup
        $stream = fopen('php://memory', 'r');
        $this->mockWrapper->method('open')->willReturn($stream);
        $this->imapConnection->openConnection();
        $this->mockWrapper->method('ping')->willReturn(false);
        $this->mockWrapper->method('check')->willThrowException(new RuntimeException('Connection closed'));
        $this->mockWrapper->method('status')->willReturn(false);
        $this->mockWrapper->method('search')->willThrowException(new RuntimeException('Search failed'));
        $this->mockWrapper->method('msgno')->willReturn(0);
        $this->mockWrapper->expects($this->once())->method('fetchOverview')->willReturn([]);
        $this->mockWrapper->method('errors')->willReturnOnConsecutiveCalls(
            ['Original fetch error'], [], [], ['Status failed'], [], [], []
        );

        // :: Act
        $state = $this->imapConnection->getMailboxState('INBOX', 42);

        // :: Assert
        $this->assertEquals(['Original fetch error'], $state['errors_before_probes']);
        $this->assertFalse($state['connection_alive']);
        $this->assertEquals(['probe_error' => 'Connection closed'], $state['selected_mailbox']);
        $this->assertFalse($state['mailbox_status']);
        $this->assertEquals(['Status failed'], $state['probe_errors']['mailbox_status']);
        $this->assertEquals(['probe_error' => 'Search failed'], $state['uid_search']);
        $this->assertEquals(0, $state['uid_message_number']);
        $this->assertEquals([], $state['uid_flags']);
        fclose($stream);
    }

    public function testOpenConnectionSuccess()
    {
        $resource = fopen('php://memory', 'r');
        $this->mockWrapper->method('utf7Encode')->willReturnArgument(0);
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

    public function testOpenConnectionEncodesNonAsciiFolderAsModifiedUtf7()
    {
        // :: Setup
        // openConnection() used to build the mailbox string without utf7Encode(),
        // unlike createFolder()/subscribeFolder()/renameFolder()/moveEmail(). Opening
        // a thread folder with a non-ASCII character (e.g. the en dash "–") would then
        // be rejected by the server as invalid mUTF-7.
        $resource = fopen('php://memory', 'r');
        $folder = "986965610-helfo - Innsyn i offentlig journal uke 38 2026 \u{2013} Helfo";
        $encodedMailbox = $this->testServer . '986965610-helfo - Innsyn i offentlig journal uke 38 2026 &IBM- Helfo';
        $this->mockWrapper->method('utf7Encode')
            ->willReturnCallback(fn($str) => \mb_convert_encoding($str, 'UTF7-IMAP', 'UTF-8'));

        // :: Act
        $this->mockWrapper->expects($this->once())
            ->method('open')
            ->with($encodedMailbox, $this->testEmail, $this->testPassword, 0, 1, ['DISABLE_AUTHENTICATOR' => 'PLAIN'])
            ->willReturn($resource);
        $result = $this->imapConnection->openConnection($folder);

        // :: Assert
        $this->assertSame($resource, $result);
        fclose($resource);
    }

    public function testOpenConnectionFailure()
    {
        $this->mockWrapper->expects($this->once())
            ->method('open')
            ->willReturn(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to establish IMAP connection');
        
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
        $this->mockWrapper->method('utf7Decode')->willReturnArgument(0);

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
        $this->mockWrapper->method('utf7Decode')->willReturnArgument(0);

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
