<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Mcp/McpToolProvider.php';
require_once __DIR__ . '/../../class/Mcp/McpTools.php';
require_once __DIR__ . '/../../class/ThreadCreationService.php';
require_once __DIR__ . '/../../class/ThreadAuthorization.php';

class McpToolsWriteTest extends TestCase {
    const ENTITY = '000000000-test-entity-development';
    const ENTITY_EMAIL = 'public-entity@dev.offpost.no';
    const USER_A = 'mcp-user-a';
    const USER_B = 'mcp-user-b';
    const USER_C = 'mcp-user-c';

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

    private function callData(string $tool, array $args, string $userId): array {
        $content = $this->tools->call($tool, $args, $userId);
        $this->assertCount(1, $content, json_encode($content, JSON_PRETTY_PRINT));
        return json_decode($content[0]['text'], true);
    }

    private function historyDetails(string $threadId, string $action): array {
        $rows = Database::query("SELECT details FROM thread_history WHERE thread_id = ? AND action = ?", [$threadId, $action]);
        $this->assertCount(1, $rows, json_encode($rows, JSON_PRETTY_PRINT));
        return json_decode($rows[0]['details'], true);
    }

    private function startArgs(array $extra = []): array {
        return $extra + ['entity_id' => self::ENTITY, 'title' => 'Innsyn i saker', 'body' => 'Jeg ber om innsyn i alle saker.'];
    }

    private function replyArgs(string $threadId, array $extra = []): array {
        return $extra + ['thread_id' => $threadId, 'subject' => 'Re: Tittel', 'body' => 'Takk for svaret.', 'recipient' => self::ENTITY_EMAIL];
    }

    private function sendingCount(string $threadId): int {
        return count(Database::query("SELECT id FROM thread_email_sendings WHERE thread_id = ?", [$threadId]));
    }

    public function testStartThreadDefaults(): void {
        // :: Act
        $data = $this->callData('start_thread', $this->startArgs(), self::USER_A);

        // :: Assert
        $this->assertEquals(['thread_id', 'thread_url', 'sending_status', 'my_name', 'my_email'], array_keys($data));
        $this->assertEquals('READY_FOR_SENDING', $data['sending_status']);
        $thread = Thread::loadFromDatabase($data['thread_id']);
        $this->assertEquals(
            ['my_name' => $thread->my_name, 'my_email' => $thread->my_email],
            ['my_name' => $data['my_name'], 'my_email' => $data['my_email']],
            'start_thread returns the generated identity stored on the thread'
        );
        $this->assertEquals('offentleglova', $thread->request_law_basis);
        $this->assertEquals('speedy', $thread->request_follow_up_plan);
        $this->assertFalse((bool)$thread->public);
        $this->assertEquals(self::USER_A, Database::queryOne(
            "SELECT user_id FROM thread_authorizations WHERE thread_id = ? AND is_owner = true", [$data['thread_id']]
        )['user_id']);
        $sending = Database::queryOne("SELECT * FROM thread_email_sendings WHERE thread_id = ?", [$data['thread_id']]);
        $this->assertEquals('READY_FOR_SENDING', $sending['status']);
        $this->assertEquals(self::ENTITY_EMAIL, $sending['email_to']);
        $this->assertEquals('Innsyn i saker', $sending['email_subject']);
        $details = $this->historyDetails($data['thread_id'], 'created');
        $this->assertEquals('mcp', $details['via'], json_encode($details, JSON_PRETTY_PRINT));
        $this->assertEquals(NpApiService::threadUrl($data['thread_id']), $data['thread_url']);
    }

    public function testStartThreadPlanNoneStoresNull(): void {
        // :: Act
        $data = $this->callData('start_thread', $this->startArgs(['follow_up_plan' => 'none', 'law_basis' => 'other']), self::USER_A);

        // :: Assert
        $row = Database::queryOne("SELECT request_law_basis, request_follow_up_plan FROM threads WHERE id = ?", [$data['thread_id']]);
        $this->assertEquals('other', $row['request_law_basis']);
        $this->assertNull($row['request_follow_up_plan']);
    }

    public function testStartThreadUnknownEntity(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Unknown entity_id: nope');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['entity_id' => 'nope']), self::USER_A);
    }

    public function testStartThreadInvalidPlan(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Invalid follow_up_plan');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['follow_up_plan' => 'fast']), self::USER_A);
    }

    public function testStartThreadInvalidLawBasis(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Invalid law_basis');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['law_basis' => 'x']), self::USER_A);
    }

    public function testStartThreadEmptyTitle(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('title and body are required');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['title' => '  ']), self::USER_A);
    }

    public function testStartThreadEmptyBody(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('title and body are required');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['body' => '']), self::USER_A);
    }

    public function testSendReplyOwnerQueues(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $data = $this->callData('send_reply', $this->replyArgs($thread->id), self::USER_A);

        // :: Assert
        $this->assertEquals(['email_sending_id', 'thread_id', 'recipient', 'status'], array_keys($data));
        $this->assertEquals(
            ['thread_id' => $thread->id, 'recipient' => self::ENTITY_EMAIL, 'status' => 'READY_FOR_SENDING'],
            ['thread_id' => $data['thread_id'], 'recipient' => $data['recipient'], 'status' => $data['status']]
        );
        $row = Database::queryOne("SELECT * FROM thread_email_sendings WHERE id = ?", [$data['email_sending_id']]);
        $this->assertEquals('READY_FOR_SENDING', $row['status']);
        $this->assertEquals('Re: Tittel', $row['email_subject']);
        $this->assertEquals(self::ENTITY_EMAIL, $row['email_to']);
        $this->assertEquals(
            [
                'email_sending_ids' => [$data['email_sending_id']],
                'recipient' => self::ENTITY_EMAIL,
                'subject' => 'Re: Tittel',
                'via' => 'mcp',
            ],
            $this->historyDetails($thread->id, 'thread_reply_created_and_queued_sending')
        );
    }

    public function testSendReplyStoresTrimmedSubjectAndBody(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $data = $this->callData('send_reply', $this->replyArgs($thread->id, ['subject' => "  Re: Tittel \n", 'body' => "\n Takk. \n"]), self::USER_A);

        // :: Assert
        $row = Database::queryOne("SELECT email_subject, email_content FROM thread_email_sendings WHERE id = ?", [$data['email_sending_id']]);
        $this->assertEquals(['email_subject' => 'Re: Tittel', 'email_content' => "Takk.\n\n--\n" . $thread->my_name], $row);
    }

    public function testStartThreadEntityWithoutEmail(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Entity has no email address: 000000000-test-entity-no-email');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['entity_id' => '000000000-test-entity-no-email']), self::USER_A);
    }

    public function testStartThreadDefunctEntity(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Entity no longer exists: 000000000-test-entity-defunct');

        // :: Act
        $this->tools->call('start_thread', $this->startArgs(['entity_id' => '000000000-test-entity-defunct']), self::USER_A);
    }

    public function testSendReplyGrantedUserQueues(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        $thread->addUser(self::USER_B, false);

        // :: Act
        $data = $this->callData('send_reply', $this->replyArgs($thread->id), self::USER_B);

        // :: Assert
        $this->assertEquals('READY_FOR_SENDING', $data['status']);
        $this->assertEquals(2, $this->sendingCount($thread->id), 'initial request plus the reply');
    }

    public function testSendReplyPublicNotGrantedIsNotFound(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A, true);
        $before = $this->sendingCount($thread->id);

        // :: Act
        $error = null;
        try {
            $this->tools->call('send_reply', $this->replyArgs($thread->id), self::USER_C);
        } catch (McpNotFoundException $e) {
            $error = $e;
        }

        // :: Assert
        $this->assertNotNull($error, 'public thread must still be unwritable for a stranger');
        $this->assertEquals('Not found, or no access', $error->getMessage());
        $this->assertEquals($before, $this->sendingCount($thread->id));
    }

    public function testSendReplyInvalidRecipientIsError(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        $before = $this->sendingCount($thread->id);

        // :: Act
        $error = null;
        try {
            $this->tools->call('send_reply', $this->replyArgs($thread->id, ['recipient' => 'stranger@example.com']), self::USER_A);
        } catch (McpToolException $e) {
            $error = $e;
        }

        // :: Assert
        $this->assertNotNull($error);
        $this->assertEquals('Invalid recipient selected', $error->getMessage());
        $this->assertEquals($before, $this->sendingCount($thread->id));
    }

    public function testSendReplyWhitespaceBodyIsError(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        $before = $this->sendingCount($thread->id);

        // :: Act
        $error = null;
        try {
            $this->tools->call('send_reply', $this->replyArgs($thread->id, ['body' => "  \n"]), self::USER_A);
        } catch (McpToolException $e) {
            $error = $e;
        }

        // :: Assert
        $this->assertNotNull($error);
        $this->assertEquals('Subject, body and recipient are required', $error->getMessage());
        $this->assertEquals($before, $this->sendingCount($thread->id));
    }

    public function testSendReplyNonUuidIsInvalidArgument(): void {
        // :: Setup
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Invalid thread_id (expected UUID)');

        // :: Act
        $this->tools->call('send_reply', $this->replyArgs('not-a-uuid'), self::USER_A);
    }

    public function testSendReplyMissingArgumentIsError(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);
        $args = $this->replyArgs($thread->id);
        unset($args['subject']);
        $this->expectException(McpToolException::class);

        // :: Act
        $this->tools->call('send_reply', $args, self::USER_A);
    }

    public function testGetThreadIncludesReplyRecipients(): void {
        // :: Setup
        $thread = $this->createThread(self::USER_A);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertEquals([self::ENTITY_EMAIL], $data['reply_recipients'], json_encode($data['reply_recipients'], JSON_PRETTY_PRINT));
    }
}
