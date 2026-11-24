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

    public function testThreadIdsArchivesEachWithPerIdResults(): void {
        // :: Setup
        $first = $this->createThread(self::USER_A);
        $already = $this->createThread(self::USER_A);
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$already->id]);
        $foreign = $this->createThread(self::USER_C);
        $missing = '00000000-0000-4000-8000-000000000000';

        // :: Act
        $data = $this->callData(
            ['thread_ids' => [$first->id, $already->id, $foreign->id, 'abc', $missing, $first->id]],
            self::USER_A
        );

        // :: Assert
        $this->assertEquals([
            'archived' => true,
            'changed_count' => 1,
            'unchanged_count' => 1,
            'error_count' => 3,
            'results' => [
                ['thread_id' => $first->id, 'changed' => true],
                ['thread_id' => $already->id, 'changed' => false],
                ['thread_id' => $foreign->id, 'error' => 'Not found, or no access'],
                ['thread_id' => 'abc', 'error' => 'Invalid thread_id (expected UUID)'],
                ['thread_id' => $missing, 'error' => 'Not found, or no access'],
            ],
        ], $data, 'duplicates are processed once; one bad id does not stop the others');
        $this->assertTrue($this->isArchived($first->id));
        $this->assertFalse($this->isArchived($foreign->id));
        $rows = $this->historyRows($first->id, 'archived');
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertEquals(['via' => 'mcp'], json_decode($rows[0]['details'], true));
    }

    public function testThreadIdsWithArchivedFalseUnarchives(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$thread->id]);

        // :: Act
        $data = $this->callData(['thread_ids' => [$thread->id], 'archived' => false], self::USER_A);

        // :: Assert
        $this->assertEquals([['thread_id' => $thread->id, 'changed' => true]], $data['results']);
        $this->assertFalse($this->isArchived($thread->id));
    }

    public function testThreadIdAndThreadIdsTogetherIsError(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $error = $this->callExpectingError(['thread_id' => $thread->id, 'thread_ids' => [$thread->id]], self::USER_A);

        // :: Assert
        $this->assertEquals('Give either thread_id or thread_ids, not both', $error->getMessage());
        $this->assertFalse($this->isArchived($thread->id));
    }

    public function testEmptyThreadIdsIsError(): void {
        // :: Act
        $error = $this->callExpectingError(['thread_ids' => []], self::USER_A);

        // :: Assert
        $this->assertEquals('thread_ids must be a list of 1 to 100 thread ids', $error->getMessage());
    }

    public function testMoreThan100ThreadIdsIsError(): void {
        // :: Setup
        $ids = [];
        for ($i = 0; $i < 101; $i++) {
            $ids[] = sprintf('00000000-0000-4000-8000-%012d', $i);
        }

        // :: Act
        $error = $this->callExpectingError(['thread_ids' => $ids], self::USER_A);

        // :: Assert
        $this->assertEquals('thread_ids must be a list of 1 to 100 thread ids', $error->getMessage());
    }

    public function testMissingThreadIdAndThreadIdsIsError(): void {
        // :: Act
        $error = $this->callExpectingError([], self::USER_A);

        // :: Assert
        $this->assertEquals('Give thread_id or thread_ids', $error->getMessage());
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
