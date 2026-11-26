<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Mcp/McpToolProvider.php';
require_once __DIR__ . '/../../class/Mcp/McpServer.php';
require_once __DIR__ . '/../../class/Mcp/McpTools.php';
require_once __DIR__ . '/../../class/ThreadCreationService.php';
require_once __DIR__ . '/../../class/ThreadAuthorization.php';

class McpToolsReadTest extends TestCase {
    const ENTITY = '000000000-test-entity-development';
    const USER_A = 'mcp-user-a';
    const USER_B = 'mcp-user-b';

    private McpTools $tools;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->tools = new McpTools();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function createThread(string $title, string $userId, bool $public = false): Thread {
        return (new ThreadCreationService())->createThread(
            self::ENTITY, $title, 'Jeg ber om innsyn.', $userId,
            Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, null, false, $public, []
        );
    }

    private function callData(string $tool, array $args, string $userId): array {
        $content = $this->tools->call($tool, $args, $userId);
        $this->assertCount(1, $content, json_encode($content, JSON_PRETTY_PRINT));
        $this->assertEquals('text', $content[0]['type']);
        return json_decode($content[0]['text'], true);
    }

    private function callThroughServer(string $tool, array $args, string $userId): array {
        $server = new McpServer($this->tools);
        return $server->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ], $userId);
    }

    private function insertEmail(string $threadId, string $type, string $received, bool $ignore): void {
        Database::execute(
            "INSERT INTO thread_emails (thread_id, id_old, timestamp_received, datetime_received, email_type, ignore, content)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$threadId, 'old-' . $type . '-' . $received, $received, $received, $type, $ignore ? 't' : 'f', '']
        );
    }

    public function testDefinitionsListReadTools(): void {
        // :: Act
        $names = array_column($this->tools->definitions(), 'name');

        // :: Assert
        $this->assertEquals(
            ['list_entities', 'list_my_threads', 'get_thread', 'get_attachment_link', 'get_attachment_extraction',
             'start_thread', 'send_reply', 'archive_thread'],
            $names
        );
    }

    public function testUnknownToolThrows(): void {
        // :: Setup
        $this->expectException(McpToolException::class);

        // :: Act
        $this->tools->call('nope', [], self::USER_A);
    }

    public function testListEntitiesSearchByName(): void {
        // :: Act
        $data = $this->callData('list_entities', ['search' => 'test municipality'], self::USER_A);

        // :: Assert
        $this->assertEquals(
            ['000000000-test-municipality'],
            array_column($data['entities'], 'entity_id'),
            json_encode($data, JSON_PRETTY_PRINT)
        );
        $this->assertFalse($data['truncated']);
        $this->assertEquals(['entity_id', 'name', 'type', 'org_num', 'email'], array_keys($data['entities'][0]));
    }

    public function testListEntitiesByOrgNum(): void {
        // :: Act
        $data = $this->callData('list_entities', ['search' => '000000000', 'limit' => 200], self::USER_A);

        // :: Assert
        $this->assertContains(self::ENTITY, array_column($data['entities'], 'entity_id'));
    }

    public function testListEntitiesOrgNumIsSubstringMatch(): void {
        // :: Act
        $data = $this->callData('list_entities', ['search' => '00000', 'limit' => 200], self::USER_A);

        // :: Assert
        $this->assertContains(self::ENTITY, array_column($data['entities'], 'entity_id'), json_encode($data, JSON_PRETTY_PRINT));
    }

    public function testListEntitiesOmitsEntitiesWithoutEmail(): void {
        // :: Act
        $data = $this->callData('list_entities', ['search' => 'No Email', 'limit' => 200], self::USER_A);

        // :: Assert
        $this->assertEquals([], $data['entities'], json_encode($data, JSON_PRETTY_PRINT));
    }

    public function testListEntitiesOmitsDefunctEntities(): void {
        // :: Act
        $data = $this->callData('list_entities', ['search' => 'Defunct', 'limit' => 200], self::USER_A);

        // :: Assert
        $this->assertEquals([], $data['entities'], json_encode($data, JSON_PRETTY_PRINT));
    }

    public function testGetThreadOwnerCanReply(): void {
        // :: Setup
        $thread = $this->createThread('Min', self::USER_A);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertTrue($data['can_reply']);
        $this->assertEquals(['public-entity@dev.offpost.no'], $data['reply_recipients']);
    }

    public function testGetThreadPublicReaderWithoutGrantCannotReply(): void {
        // :: Setup
        $thread = $this->createThread('Offentlig', self::USER_A, true);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_B);

        // :: Assert
        $this->assertFalse($data['can_reply']);
        $this->assertEquals([], $data['reply_recipients']);
    }

    public function testListEntitiesLimitAndTruncated(): void {
        // :: Act
        $data = $this->callData('list_entities', ['search' => 'test', 'limit' => 1], self::USER_A);

        // :: Assert
        $this->assertCount(1, $data['entities'], json_encode($data, JSON_PRETTY_PRINT));
        $this->assertTrue($data['truncated']);
    }

    public function testListEntitiesWrongTypeThrows(): void {
        // :: Setup
        $this->expectException(McpToolException::class);

        // :: Act
        $this->tools->call('list_entities', ['limit' => 'ten'], self::USER_A);
    }

    public function testListMyThreadsOnlyAuthorized(): void {
        // :: Setup
        $granted = $this->createThread('Delt', self::USER_A);
        $this->createThread('Privat', self::USER_A);
        $this->createThread('Offentlig', self::USER_A, true);
        ThreadAuthorizationManager::addUserToThread($granted->id, self::USER_B, false);

        // :: Act
        $data = $this->callData('list_my_threads', [], self::USER_B);

        // :: Assert
        $this->assertEquals([$granted->id], array_column($data['threads'], 'thread_id'), json_encode($data, JSON_PRETTY_PRINT));
        $thread = $data['threads'][0];
        $this->assertEquals('Delt', $thread['title']);
        $this->assertEquals(self::ENTITY, $thread['entity_id']);
        $this->assertEquals('TODO - Test Entity (Development)', $thread['entity_name']);
        $this->assertEquals(0, $thread['email_count_in']);
        $this->assertEquals(0, $thread['email_count_out']);
        $this->assertFalse($thread['is_owner']);
        $this->assertFalse($thread['archived']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $thread['last_activity']);
    }

    public function testListMyThreadsOwnerFlagAndSearch(): void {
        // :: Setup
        $a = $this->createThread('Journal fra kommunen', self::USER_A);
        $this->createThread('Noe annet', self::USER_A);

        // :: Act
        $data = $this->callData('list_my_threads', ['search' => 'JOURNAL'], self::USER_A);

        // :: Assert
        $this->assertEquals([$a->id], array_column($data['threads'], 'thread_id'), json_encode($data, JSON_PRETTY_PRINT));
        $this->assertTrue($data['threads'][0]['is_owner']);
    }

    public function testListMyThreadsHidesArchivedByDefault(): void {
        // :: Setup
        $thread = $this->createThread('Arkivert', self::USER_A);
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$thread->id]);

        // :: Act
        $data = $this->callData('list_my_threads', [], self::USER_A);

        // :: Assert
        $this->assertEquals([], $data['threads']);
    }

    public function testListMyThreadsIncludeArchived(): void {
        // :: Setup
        $thread = $this->createThread('Arkivert', self::USER_A);
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$thread->id]);

        // :: Act
        $data = $this->callData('list_my_threads', ['include_archived' => true], self::USER_A);

        // :: Assert
        $this->assertEquals([$thread->id], array_column($data['threads'], 'thread_id'), json_encode($data, JSON_PRETTY_PRINT));
        $this->assertTrue($data['threads'][0]['archived']);
    }

    public function testListMyThreadsLimitTruncates(): void {
        // :: Setup
        $this->createThread('En', self::USER_A);
        $this->createThread('To', self::USER_A);

        // :: Act
        $data = $this->callData('list_my_threads', ['limit' => 1], self::USER_A);

        // :: Assert
        $this->assertCount(1, $data['threads'], json_encode($data, JSON_PRETTY_PRINT));
        $this->assertTrue($data['truncated']);
    }

    /** Three threads with fixed, distinct last activity: returns [oldest, middle, newest]. */
    private function createThreadsWithActivity(): array {
        $old = $this->createThread('Gammel', self::USER_A);
        $mid = $this->createThread('Midt', self::USER_A);
        $new = $this->createThread('Ny', self::USER_A);
        $this->insertEmail($old->id, 'IN', '2020-01-01 10:00:00+00', false);
        $this->insertEmail($mid->id, 'IN', '2020-01-02 10:00:00+00', false);
        $this->insertEmail($new->id, 'IN', '2020-01-03 10:00:00+00', false);
        return [$old, $mid, $new];
    }

    public function testListMyThreadsReturnsTotalAndNextCursor(): void {
        // :: Setup
        $this->createThreadsWithActivity();

        // :: Act
        $data = $this->callData('list_my_threads', ['limit' => 2], self::USER_A);

        // :: Assert
        $this->assertEquals(3, $data['total'], json_encode($data, JSON_PRETTY_PRINT));
        $this->assertCount(2, $data['threads'], json_encode($data, JSON_PRETTY_PRINT));
        $this->assertIsString($data['next_cursor']);
        $this->assertTrue($data['truncated']);
    }

    public function testListMyThreadsCursorPagesThroughAllNewestFirst(): void {
        // :: Setup
        [$old, $mid, $new] = $this->createThreadsWithActivity();

        // :: Act
        $page1 = $this->callData('list_my_threads', ['limit' => 2], self::USER_A);
        $page2 = $this->callData('list_my_threads', ['limit' => 2, 'cursor' => $page1['next_cursor']], self::USER_A);

        // :: Assert
        $this->assertEquals([$new->id, $mid->id], array_column($page1['threads'], 'thread_id'));
        $this->assertEquals([$old->id], array_column($page2['threads'], 'thread_id'));
        $this->assertNull($page2['next_cursor'], 'last page has no next cursor');
        $this->assertFalse($page2['truncated']);
        $this->assertEquals(3, $page2['total'], 'total counts all matching threads, not the page');
    }

    public function testListMyThreadsCursorSkipsNothingWhenThreadsAreArchivedBetweenPages(): void {
        // :: Setup
        [$old, $mid, $new] = $this->createThreadsWithActivity();
        $page1 = $this->callData('list_my_threads', ['limit' => 1], self::USER_A);
        $this->callData('archive_thread', ['thread_id' => $page1['threads'][0]['thread_id']], self::USER_A);

        // :: Act
        $page2 = $this->callData('list_my_threads', ['limit' => 1, 'cursor' => $page1['next_cursor']], self::USER_A);

        // :: Assert
        $this->assertEquals([$new->id], array_column($page1['threads'], 'thread_id'));
        $this->assertEquals([$mid->id], array_column($page2['threads'], 'thread_id'),
            'archiving the first page must not shift the next one');
        $this->assertEquals(2, $page2['total'], 'the archived thread no longer matches');
    }

    public function testListMyThreadsCursorHandlesEqualLastActivity(): void {
        // :: Setup
        $first = $this->createThread('Lik 1', self::USER_A);
        $second = $this->createThread('Lik 2', self::USER_A);
        $this->insertEmail($first->id, 'IN', '2020-01-01 10:00:00+00', false);
        $this->insertEmail($second->id, 'IN', '2020-01-01 10:00:00+00', false);
        $expected = [$first->id, $second->id];
        sort($expected);

        // :: Act
        $page1 = $this->callData('list_my_threads', ['limit' => 1], self::USER_A);
        $page2 = $this->callData('list_my_threads', ['limit' => 1, 'cursor' => $page1['next_cursor']], self::USER_A);

        // :: Assert
        $this->assertEquals(
            $expected,
            array_merge(array_column($page1['threads'], 'thread_id'), array_column($page2['threads'], 'thread_id')),
            'ties on last activity are ordered by thread id and none is skipped'
        );
        $this->assertNull($page2['next_cursor']);
    }

    public function testListMyThreadsInvalidCursorIsError(): void {
        // :: Act
        $error = null;
        try {
            $this->tools->call('list_my_threads', ['cursor' => 'not-a-cursor'], self::USER_A);
        } catch (McpToolException $e) {
            $error = $e;
        }

        // :: Assert
        $this->assertNotNull($error);
        $this->assertEquals('Invalid cursor', $error->getMessage());
    }

    public function testListMyThreadsCountsExcludeIgnoredAndSetLastActivity(): void {
        // :: Setup
        $thread = $this->createThread('Med epost', self::USER_A);
        $this->insertEmail($thread->id, 'IN', '2024-01-02 10:00:00+00', false);
        $this->insertEmail($thread->id, 'IN', '2024-03-04 10:00:00+00', true);
        $this->insertEmail($thread->id, 'OUT', '2024-01-01 10:00:00+00', false);

        // :: Act
        $data = $this->callData('list_my_threads', [], self::USER_A);

        // :: Assert
        $row = $data['threads'][0];
        $this->assertEquals(1, $row['email_count_in'], json_encode($row, JSON_PRETTY_PRINT));
        $this->assertEquals(1, $row['email_count_out'], json_encode($row, JSON_PRETTY_PRINT));
        $this->assertEquals('2024-01-02T10:00:00+00:00', $row['last_activity']);
    }

    public function testGetThreadOwn(): void {
        // :: Setup
        $thread = $this->createThread('Min tråd', self::USER_A);
        $this->insertEmail($thread->id, 'IN', '2024-01-02 10:00:00+00', false);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertEquals('Min tråd', $data['thread']['title']);
        $this->assertEquals(self::ENTITY, $data['entity']['entity_id']);
        $this->assertCount(1, $data['emails'], json_encode($data['emails'], JSON_PRETTY_PRINT));
        $this->assertIsArray($data['sendings']);
        $this->assertEquals(NpApiService::threadUrl($thread->id), $data['thread_url']);
        $this->assertArrayNotHasKey('history', $data);
        $email = $data['emails'][0];
        foreach (['body_html', 'imap_headers', 'history', 'eml_base64'] as $key) {
            $this->assertArrayNotHasKey($key, $email, "$key must be stripped");
        }
        $this->assertArrayHasKey('body_plain', $email);
        $this->assertArrayHasKey('extractions', $email);
        $this->assertArrayHasKey('attachments', $email);
        $this->assertEquals(1, $data['email_total']);
        $this->assertNull($data['next_cursor'], 'one email fits on the first page');
        $this->assertFalse($data['truncated']);
    }

    /** Inserts $count IN emails one hour apart from 2024-01-01 00:00 UTC; returns their datetimes in order. */
    private function insertEmails(string $threadId, int $count, int $startHour = 0): array {
        $received = [];
        for ($i = $startHour; $i < $startHour + $count; $i++) {
            $time = sprintf('2024-01-%02d %02d:00:00+00', 1 + intdiv($i, 24), $i % 24);
            $this->insertEmail($threadId, 'IN', $time, false);
            $received[] = (new DateTime($time))->format('c');
        }
        return $received;
    }

    private function receivedTimes(array $emails): array {
        return array_map(fn($email) => (new DateTime($email['datetime_received']))->format('c'), $emails);
    }

    public function testGetThreadDefaultPageIsTwentyOldestEmails(): void {
        // :: Setup
        $thread = $this->createThread('Lang tråd', self::USER_A);
        $received = $this->insertEmails($thread->id, 25);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertEquals(array_slice($received, 0, 20), $this->receivedTimes($data['emails']));
        $this->assertEquals(25, $data['email_total']);
        $this->assertTrue($data['truncated']);
        $this->assertIsString($data['next_cursor']);
        $this->assertEquals('Lang tråd', $data['thread']['title'], 'thread data is on every page');
    }

    public function testGetThreadCursorPagesThroughAllEmailsOldestFirst(): void {
        // :: Setup
        $thread = $this->createThread('Lang tråd', self::USER_A);
        $received = $this->insertEmails($thread->id, 7);

        // :: Act
        $pages = [];
        $cursor = null;
        do {
            $args = ['thread_id' => $thread->id, 'limit' => 3] + ($cursor === null ? [] : ['cursor' => $cursor]);
            $data = $this->callData('get_thread', $args, self::USER_A);
            $pages[] = $this->receivedTimes($data['emails']);
            $cursor = $data['next_cursor'];
        } while ($cursor !== null && count($pages) < 10);

        // :: Assert
        $this->assertEquals([array_slice($received, 0, 3), array_slice($received, 3, 3), array_slice($received, 6, 1)], $pages);
        $this->assertFalse($data['truncated'], 'last page');
        $this->assertEquals(7, $data['email_total']);
    }

    public function testGetThreadCursorIncludesEmailArrivingBetweenPages(): void {
        // :: Setup
        $thread = $this->createThread('Lang tråd', self::USER_A);
        $received = $this->insertEmails($thread->id, 4);
        $first = $this->callData('get_thread', ['thread_id' => $thread->id, 'limit' => 2], self::USER_A);
        $received = array_merge($received, $this->insertEmails($thread->id, 1, 4));

        // :: Act
        $second = $this->callData('get_thread',
            ['thread_id' => $thread->id, 'limit' => 5, 'cursor' => $first['next_cursor']], self::USER_A);

        // :: Assert
        $this->assertEquals(array_slice($received, 2), $this->receivedTimes($second['emails']));
        $this->assertEquals(5, $second['email_total']);
        $this->assertNull($second['next_cursor']);
    }

    public function testGetThreadLimitIsCappedAtFifty(): void {
        // :: Setup
        $thread = $this->createThread('Lang tråd', self::USER_A);
        $this->insertEmails($thread->id, 51);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id, 'limit' => 200], self::USER_A);

        // :: Assert
        $this->assertCount(50, $data['emails'], json_encode($this->receivedTimes($data['emails']), JSON_PRETTY_PRINT));
        $this->assertTrue($data['truncated']);
    }

    public function testGetThreadInvalidCursorIsError(): void {
        // :: Setup
        $thread = $this->createThread('Lang tråd', self::USER_A);
        $this->insertEmails($thread->id, 2);

        // :: Act
        $error = null;
        try {
            $this->tools->call('get_thread', ['thread_id' => $thread->id, 'cursor' => 'not-a-cursor'], self::USER_A);
        } catch (McpToolException $e) {
            $error = $e;
        }

        // :: Assert
        $this->assertNotNull($error);
        $this->assertEquals('Invalid cursor', $error->getMessage());
    }

    public function testGetThreadCursorFromAnotherThreadIsError(): void {
        // :: Setup
        $other = $this->createThread('Annen tråd', self::USER_A);
        $this->insertEmails($other->id, 2);
        $cursor = $this->callData('get_thread', ['thread_id' => $other->id, 'limit' => 1], self::USER_A)['next_cursor'];
        $thread = $this->createThread('Lang tråd', self::USER_A);
        $this->insertEmails($thread->id, 2);

        // :: Act
        $error = null;
        try {
            $this->tools->call('get_thread', ['thread_id' => $thread->id, 'cursor' => $cursor], self::USER_A);
        } catch (McpToolException $e) {
            $error = $e;
        }

        // :: Assert
        $this->assertNotNull($error, 'a cursor naming an email of another thread is rejected');
        $this->assertEquals('Invalid cursor', $error->getMessage());
    }

    private function setSending(string $threadId, string $status, ?string $error): void {
        Database::execute(
            "UPDATE thread_email_sendings SET status = ?, error_message = ? WHERE thread_id = ?",
            [$status, $error, $threadId]
        );
    }

    public function testGetThreadFailedSendingIsDeliveryProblem(): void {
        // :: Setup
        $thread = $this->createThread('Feilet sending', self::USER_A);
        $this->setSending($thread->id, ThreadEmailSending::STATUS_READY_FOR_SENDING, 'SMTP connect failed');

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $sending = $data['sendings'][0];
        // updated_at is maintained by a database trigger, so it is taken from the export.
        $this->assertEquals([[
            'sending_id' => $sending['id'],
            'recipient' => $sending['email_to'],
            'subject' => $sending['email_subject'],
            'status' => 'READY_FOR_SENDING',
            'error' => 'SMTP connect failed',
            'updated_at' => $sending['updated_at'],
        ]], $data['delivery_problems'], json_encode($data['delivery_problems'], JSON_PRETTY_PRINT));
    }

    public function testGetThreadQueuedSendingWithoutErrorIsNotDeliveryProblem(): void {
        // :: Setup
        $thread = $this->createThread('Ingen feil', self::USER_A);
        $this->setSending($thread->id, ThreadEmailSending::STATUS_SENT, null);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertSame([], $data['delivery_problems']);
    }

    public function testGetThreadErrorThatLaterSucceededIsNotDeliveryProblem(): void {
        // :: Setup
        // The sender keeps error_message when a retry succeeds, so a SENT row can still carry one.
        $thread = $this->createThread('Sendt etter feil', self::USER_A);
        $this->setSending($thread->id, ThreadEmailSending::STATUS_SENT, 'SMTP connect failed');

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertSame([], $data['delivery_problems']);
    }

    public function testListMyThreadsFlagsOnlyThreadsWithDeliveryProblem(): void {
        // :: Setup
        $failed = $this->createThread('Feilet', self::USER_A);
        $fine = $this->createThread('Fin', self::USER_A);
        $recovered = $this->createThread('Gjenopprettet', self::USER_A);
        $this->setSending($failed->id, ThreadEmailSending::STATUS_READY_FOR_SENDING, 'Mailbox full');
        $this->setSending($fine->id, ThreadEmailSending::STATUS_SENT, null);
        $this->setSending($recovered->id, ThreadEmailSending::STATUS_SENT, 'Mailbox full');

        // :: Act
        $data = $this->callData('list_my_threads', [], self::USER_A);

        // :: Assert
        $byId = array_column($data['threads'], 'has_delivery_problem', 'thread_id');
        $this->assertEquals(
            [$failed->id => true, $fine->id => false, $recovered->id => false],
            $byId,
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    public function testGetThreadSendingsOmitSmtpTranscripts(): void {
        // :: Setup
        $thread = $this->createThread('Med sending', self::USER_A);
        Database::execute(
            "UPDATE thread_email_sendings SET smtp_debug = 'debug', smtp_response = '250 OK' WHERE thread_id = ?",
            [$thread->id]
        );

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $this->assertNotEmpty($data['sendings'], json_encode($data['sendings'], JSON_PRETTY_PRINT));
        foreach ($data['sendings'] as $sending) {
            $this->assertArrayNotHasKey('smtp_debug', $sending);
            $this->assertArrayNotHasKey('smtp_response', $sending);
            $this->assertArrayHasKey('status', $sending);
        }
    }

    public function testGetThreadReducesExtractionsAndDropsAttachmentLocation(): void {
        // :: Setup
        $thread = $this->createThread('Med vedlegg', self::USER_A);
        $this->insertEmail($thread->id, 'IN', '2024-01-02 10:00:00+00', false);
        $emailId = Database::queryValue("SELECT id FROM thread_emails WHERE thread_id = ?", [$thread->id]);
        $attachmentId = Database::queryValue(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location)
             VALUES (?, 'a', 'a.pdf', 'application/pdf', '/secret/path') RETURNING id",
            [$emailId]
        );
        Database::execute(
            "INSERT INTO thread_email_extractions (email_id, attachment_id, prompt_id, prompt_service, prompt_text, extracted_text)
             VALUES (?, ?, 'p', 'svc', 'PROMPT TEXT', 'EXTRACTED TEXT')",
            [$emailId, $attachmentId]
        );
        Database::execute(
            "INSERT INTO thread_email_extractions (email_id, prompt_id, prompt_service, prompt_text, extracted_text)
             VALUES (?, 'q', 'svc', 'PROMPT TEXT', NULL)",
            [$emailId]
        );

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_A);

        // :: Assert
        $attachment = $data['emails'][0]['attachments'][0];
        $this->assertArrayNotHasKey('location', $attachment);
        $keys = ['extraction_id', 'prompt_id', 'prompt_service', 'has_text', 'error_message'];
        $this->assertEquals($keys, array_keys($attachment['extractions'][0]));
        $this->assertTrue($attachment['extractions'][0]['has_text']);
        $emailExtraction = $data['emails'][0]['extractions'][0];
        $this->assertEquals($keys, array_keys($emailExtraction));
        $this->assertFalse($emailExtraction['has_text']);
    }

    public function testGetThreadPublicReadableByOther(): void {
        // :: Setup
        $thread = $this->createThread('Offentlig', self::USER_A, true);

        // :: Act
        $data = $this->callData('get_thread', ['thread_id' => $thread->id], self::USER_B);

        // :: Assert
        $this->assertEquals('Offentlig', $data['thread']['title']);
    }

    public function testGetThreadOtherUsersPrivateIsNotFound(): void {
        // :: Setup
        $thread = $this->createThread('Privat', self::USER_A);

        // :: Act
        $response = $this->callThroughServer('get_thread', ['thread_id' => $thread->id], self::USER_B);

        // :: Assert
        $this->assertTrue($response['result']['isError']);
        $this->assertEquals('Not found, or no access', $response['result']['content'][0]['text']);
    }

    public function testGetThreadNonUuidIsInvalidArgument(): void {
        // :: Act
        $response = $this->callThroughServer('get_thread', ['thread_id' => 'abc'], self::USER_A);

        // :: Assert
        $this->assertTrue($response['result']['isError']);
        $this->assertEquals('Invalid thread_id (expected UUID)', $response['result']['content'][0]['text']);
    }

    public function testGetThreadUnknownUuidIsNotFound(): void {
        // :: Act
        $response = $this->callThroughServer('get_thread', ['thread_id' => '11111111-1111-1111-1111-111111111111'], self::USER_A);

        // :: Assert
        $this->assertTrue($response['result']['isError']);
        $this->assertEquals('Not found, or no access', $response['result']['content'][0]['text']);
    }
}
