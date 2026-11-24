<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Mcp/McpToolProvider.php';
require_once __DIR__ . '/../../class/Mcp/McpTools.php';
require_once __DIR__ . '/../../class/ThreadCreationService.php';
require_once __DIR__ . '/../../class/ThreadAuthorization.php';

class McpToolsArchiveTest extends TestCase {
    const ENTITY = '000000000-test-entity-development';
    const USER_A = 'mcp-archive-user-a';
    const USER_B = 'mcp-archive-user-b';
    const USER_C = 'mcp-archive-user-c';

    private McpTools $tools;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->tools = new McpTools();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function createThread(string $userId, bool $public = false): Thread {
        return (new ThreadCreationService())->createThread(
            self::ENTITY, 'Tittel', 'Jeg ber om innsyn.', $userId,
            Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, null, false, $public, []
        );
    }

    private function callData(array $args, string $userId): array {
        $content = $this->tools->call('archive_thread', $args, $userId);
        $this->assertCount(1, $content, json_encode($content, JSON_PRETTY_PRINT));
        return json_decode($content[0]['text'], true);
    }

    private function isArchived(string $threadId): bool {
        $value = Database::queryValue("SELECT archived FROM threads WHERE id = ?", [$threadId]);
        return $value === true || $value === 't';
    }

    private function historyRows(string $threadId, string $action): array {
        return Database::query(
            "SELECT user_id, details FROM thread_history WHERE thread_id = ? AND action = ?",
            [$threadId, $action]
        );
    }

    private function callExpectingError(array $args, string $userId): McpToolException {
        try {
            $this->tools->call('archive_thread', $args, $userId);
        } catch (McpToolException $e) {
            return $e;
        }
        $this->fail('Expected McpToolException for ' . json_encode($args));
    }

    public function testOwnerArchivesThread(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $data = $this->callData(['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertEquals(['thread_id' => $thread->id, 'archived' => true, 'changed' => true], $data);
        $this->assertTrue($this->isArchived($thread->id));
        $rows = $this->historyRows($thread->id, 'archived');
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals(self::USER_A, $rows[0]['user_id']);
        $this->assertEquals(['via' => 'mcp'], json_decode($rows[0]['details'], true));
    }

    public function testArchivedFalseUnarchives(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$thread->id]);

        // :: Act
        $data = $this->callData(['thread_id' => $thread->id, 'archived' => false], self::USER_A);

        // :: Assert
        $this->assertEquals(['thread_id' => $thread->id, 'archived' => false, 'changed' => true], $data);
        $this->assertFalse($this->isArchived($thread->id));
        $rows = $this->historyRows($thread->id, 'unarchived');
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals(['via' => 'mcp'], json_decode($rows[0]['details'], true));
    }

    public function testArchivingAnArchivedThreadIsANoOp(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$thread->id]);

        // :: Act
        $data = $this->callData(['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertEquals(['thread_id' => $thread->id, 'archived' => true, 'changed' => false], $data);
        $rows = $this->historyRows($thread->id, 'archived');
        $this->assertCount(0, $rows, json_encode($rows, JSON_PRETTY_PRINT));
    }

    public function testGrantedUserCanArchive(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        $thread->addUser(self::USER_B, false);

        // :: Act
        $data = $this->callData(['thread_id' => $thread->id], self::USER_B);

        // :: Assert
        $this->assertTrue($data['archived']);
        $this->assertTrue($this->isArchived($thread->id));
    }

    public function testPublicThreadOfOtherUserIsNotFound(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A, true);

        // :: Act
        $error = $this->callExpectingError(['thread_id' => $thread->id], self::USER_C);

        // :: Assert
        $this->assertInstanceOf(McpNotFoundException::class, $error);
        $this->assertEquals('Not found, or no access', $error->getMessage());
        $this->assertFalse($this->isArchived($thread->id), 'a public thread must stay unarchivable for a stranger');
    }

    public function testPrivateThreadOfOtherUserIsNotFound(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $error = $this->callExpectingError(['thread_id' => $thread->id], self::USER_C);

        // :: Assert
        $this->assertEquals('Not found, or no access', $error->getMessage());
        $this->assertFalse($this->isArchived($thread->id));
    }

    public function testMalformedThreadIdIsInvalidArgument(): void {
        // :: Act
        $error = $this->callExpectingError(['thread_id' => 'abc'], self::USER_A);

        // :: Assert
        $this->assertEquals('Invalid thread_id (expected UUID)', $error->getMessage());
    }

    public function testNonBooleanArchivedIsError(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $error = $this->callExpectingError(['thread_id' => $thread->id, 'archived' => 'yes'], self::USER_A);

        // :: Assert
        $this->assertEquals('archived must be a boolean', $error->getMessage());
        $this->assertFalse($this->isArchived($thread->id));
    }
}
