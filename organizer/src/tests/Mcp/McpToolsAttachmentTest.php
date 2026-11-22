<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../class/Mcp/McpToolProvider.php';
require_once __DIR__ . '/../../class/Mcp/McpTools.php';
require_once __DIR__ . '/../../class/ThreadCreationService.php';

class McpToolsAttachmentTest extends TestCase {
    const ENTITY = '000000000-test-entity-development';
    const USER_A = 'mcp-user-a';
    const USER_B = 'mcp-user-b';
    const PDF_BYTES = '%PDF-1.4 test';
    const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    const NOT_FOUND = 'Not found, or no access';
    const TOO_LARGE = 'Attachment is larger than 5 MB; use get_attachment_extraction for its text';

    private McpTools $tools;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->tools = new McpTools();
    }

    protected function tearDown(): void {
        McpTools::$maxRawBytesOverride = null;
        Database::rollBack();
    }

    private function createThread(string $title, string $userId, bool $public = false): Thread {
        return (new ThreadCreationService())->createThread(
            self::ENTITY, $title, 'Jeg ber om innsyn.', $userId,
            Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, null, false, $public, []
        );
    }

    private function insertEmail(string $threadId): string {
        $row = Database::queryOne(
            "INSERT INTO thread_emails (thread_id, id_old, timestamp_received, datetime_received, email_type, ignore, content)
             VALUES (?, ?, ?, ?, 'IN', 'f', '') RETURNING id",
            [$threadId, 'old-' . $threadId, '2024-01-01 10:00:00', '2024-01-01 10:00:00']
        );
        return $row['id'];
    }

    private function insertAttachment(string $emailId, string $filetype, ?string $content, ?int $size = null): string {
        $row = Database::queryOne(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, size, content)
             VALUES (?, 'doc', ?, ?, 'loc', ?, decode(?, 'hex')) RETURNING id",
            [$emailId, 'doc.' . $filetype, $filetype, $size, $content === null ? null : bin2hex($content)]
        );
        return $row['id'];
    }

    private function insertExtraction(string $emailId, string $attachmentId, string $promptId, string $text): void {
        Database::execute(
            "INSERT INTO thread_email_extractions (email_id, attachment_id, prompt_id, prompt_text, prompt_service, extracted_text)
             VALUES (?, ?, ?, 'prompt', 'openai', ?)",
            [$emailId, $attachmentId, $promptId, $text]
        );
    }

    /** @return array{0: string, 1: string} thread id and attachment id */
    private function threadWithAttachment(string $userId, string $filetype, ?string $content, bool $public = false, ?int $size = null): array {
        $thread = $this->createThread('Attachment thread', $userId, $public);
        $attachmentId = $this->insertAttachment($this->insertEmail($thread->id), $filetype, $content, $size);
        return [$thread->id, $attachmentId];
    }

    private function callRaw(string $threadId, string $attachmentId, string $userId): array {
        return $this->tools->call('get_attachment_raw', ['thread_id' => $threadId, 'attachment_id' => $attachmentId], $userId);
    }

    private function assertToolError(string $message, callable $call): void {
        try {
            $call();
        } catch (McpToolException $e) {
            $this->assertEquals($message, $e->getMessage());
            return;
        }
        $this->fail("Expected McpToolException with message '$message'");
    }

    private function extractionData(string $threadId, string $attachmentId, string $userId, array $extra = []): array {
        $content = $this->tools->call('get_attachment_extraction',
            ['thread_id' => $threadId, 'attachment_id' => $attachmentId] + $extra, $userId);
        return json_decode($content[0]['text'], true);
    }

    public function testDefinitionsIncludeAttachmentToolsAfterGetThread(): void {
        // :: Act
        $names = array_column($this->tools->definitions(), 'name');

        // :: Assert
        $this->assertEquals(
            ['list_entities', 'list_my_threads', 'get_thread', 'get_attachment_raw', 'get_attachment_extraction'],
            $names
        );
    }

    public function testRawPdfReturnsResourceBlob(): void {
        // :: Setup
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', self::PDF_BYTES);

        // :: Act
        $content = $this->callRaw($threadId, $attachmentId, self::USER_A);

        // :: Assert
        $this->assertCount(2, $content, json_encode($content, JSON_PRETTY_PRINT));
        $this->assertEquals('text', $content[0]['type']);
        $this->assertEquals(
            ['name' => 'doc', 'filename' => 'doc.pdf', 'mimeType' => 'application/pdf', 'size' => strlen(self::PDF_BYTES)],
            json_decode($content[0]['text'], true)
        );
        $this->assertEquals([
            'type' => 'resource',
            'resource' => [
                'uri' => "offpost://thread/$threadId/attachment/$attachmentId",
                'mimeType' => 'application/pdf',
                'blob' => base64_encode('%PDF-1.4 test'),
            ],
        ], $content[1]);
    }

    public function testRawImageReturnsImageContent(): void {
        // :: Setup
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'png', base64_decode(self::PNG_BASE64));

        // :: Act
        $content = $this->callRaw($threadId, $attachmentId, self::USER_A);

        // :: Assert
        $this->assertEquals([['type' => 'image', 'data' => self::PNG_BASE64, 'mimeType' => 'image/png']], $content);
    }

    public function testRawNullContentIsError(): void {
        // :: Setup
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', null);

        // :: Act / Assert
        $this->assertToolError('Attachment content was never stored; try get_attachment_extraction',
            fn() => $this->callRaw($threadId, $attachmentId, self::USER_A));
    }

    public function testRawOverCapIsError(): void {
        // :: Setup
        McpTools::$maxRawBytesOverride = 5;
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', self::PDF_BYTES);

        // :: Act / Assert
        $this->assertToolError(self::TOO_LARGE, fn() => $this->callRaw($threadId, $attachmentId, self::USER_A));
    }

    public function testRawCapUsesStoredSizeColumnWhenSet(): void {
        // :: Setup
        McpTools::$maxRawBytesOverride = 100;
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', self::PDF_BYTES, false, 101);

        // :: Act / Assert
        $this->assertToolError(self::TOO_LARGE, fn() => $this->callRaw($threadId, $attachmentId, self::USER_A));
    }

    public function testRawAttachmentFromOtherThreadIsNotFound(): void {
        // :: Setup
        $thread = $this->createThread('Own thread', self::USER_A);
        [, $otherAttachmentId] = $this->threadWithAttachment(self::USER_B, 'pdf', self::PDF_BYTES);

        // :: Act / Assert
        $this->assertToolError(self::NOT_FOUND, fn() => $this->callRaw($thread->id, $otherAttachmentId, self::USER_A));
    }

    public function testRawPublicThreadReadableByOther(): void {
        // :: Setup
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', self::PDF_BYTES, true);

        // :: Act
        $content = $this->callRaw($threadId, $attachmentId, self::USER_B);

        // :: Assert
        $this->assertEquals(base64_encode(self::PDF_BYTES), $content[1]['resource']['blob']);
    }

    public function testRawPrivateThreadOtherUserNotFound(): void {
        // :: Setup
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', self::PDF_BYTES);

        // :: Act / Assert
        $this->assertToolError(self::NOT_FOUND, fn() => $this->callRaw($threadId, $attachmentId, self::USER_B));
    }

    public function testRawNonUuidNotFound(): void {
        // :: Act / Assert
        $this->assertToolError(self::NOT_FOUND, fn() => $this->callRaw('nope', 'nope', self::USER_A));
    }

    public function testExtractionReturnsAll(): void {
        // :: Setup
        $thread = $this->createThread('Extraction thread', self::USER_A);
        $emailId = $this->insertEmail($thread->id);
        $attachmentId = $this->insertAttachment($emailId, 'pdf', self::PDF_BYTES);
        $this->insertExtraction($emailId, $attachmentId, 'prompt-a', 'Text A');
        $this->insertExtraction($emailId, $attachmentId, 'prompt-b', 'Text B');

        // :: Act
        $data = $this->extractionData($thread->id, $attachmentId, self::USER_A);

        // :: Assert
        $this->assertEquals($attachmentId, $data['attachment_id']);
        $this->assertCount(2, $data['extractions'], json_encode($data, JSON_PRETTY_PRINT));
        $byPrompt = array_column($data['extractions'], null, 'prompt_id');
        $this->assertEquals('Text A', $byPrompt['prompt-a']['extracted_text']);
        $this->assertEquals('openai', $byPrompt['prompt-b']['prompt_service']);
        $this->assertEquals(
            ['extraction_id', 'prompt_id', 'prompt_service', 'extracted_text', 'error_message', 'created_at', 'updated_at'],
            array_keys($byPrompt['prompt-a'])
        );
    }

    public function testExtractionFilterByPromptId(): void {
        // :: Setup
        $thread = $this->createThread('Extraction thread', self::USER_A);
        $emailId = $this->insertEmail($thread->id);
        $attachmentId = $this->insertAttachment($emailId, 'pdf', self::PDF_BYTES);
        $this->insertExtraction($emailId, $attachmentId, 'prompt-a', 'Text A');
        $this->insertExtraction($emailId, $attachmentId, 'prompt-b', 'Text B');

        // :: Act
        $data = $this->extractionData($thread->id, $attachmentId, self::USER_A, ['prompt_id' => 'prompt-b']);

        // :: Assert
        $this->assertEquals(['Text B'], array_column($data['extractions'], 'extracted_text'), json_encode($data, JSON_PRETTY_PRINT));
    }

    public function testExtractionNoneIsEmptyList(): void {
        // :: Setup
        [$threadId, $attachmentId] = $this->threadWithAttachment(self::USER_A, 'pdf', self::PDF_BYTES);

        // :: Act
        $data = $this->extractionData($threadId, $attachmentId, self::USER_A);

        // :: Assert
        $this->assertEquals(['attachment_id' => $attachmentId, 'extractions' => []], $data);
    }

    public function testExtractionFromOtherThreadIsNotFound(): void {
        // :: Setup
        $thread = $this->createThread('Own thread', self::USER_A);
        [, $otherAttachmentId] = $this->threadWithAttachment(self::USER_B, 'pdf', self::PDF_BYTES);

        // :: Act / Assert
        $this->assertToolError(self::NOT_FOUND, fn() => $this->extractionData($thread->id, $otherAttachmentId, self::USER_A));
    }
}
