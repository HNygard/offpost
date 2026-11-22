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
            ['list_entities', 'list_my_threads', 'get_thread', 'get_attachment_raw', 'get_attachment_extraction'],
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
            ['000000000-test-municipality-no-email', '000000000-test-municipality'],
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

    public function testGetThreadNonUuidIsNotFound(): void {
        // :: Act
        $response = $this->callThroughServer('get_thread', ['thread_id' => 'abc'], self::USER_A);

        // :: Assert
        $this->assertTrue($response['result']['isError']);
        $this->assertEquals('Not found, or no access', $response['result']['content'][0]['text']);
    }

    public function testGetThreadUnknownUuidIsNotFound(): void {
        // :: Act
        $response = $this->callThroughServer('get_thread', ['thread_id' => '11111111-1111-1111-1111-111111111111'], self::USER_A);

        // :: Assert
        $this->assertTrue($response['result']['isError']);
        $this->assertEquals('Not found, or no access', $response['result']['content'][0]['text']);
    }
}
