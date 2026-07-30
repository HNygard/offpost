<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';

/**
 * Regression: the classify UI mutated in-memory Thread emails and called
 * updateThread(), which only writes the threads row - manual classifications
 * were silently lost after the JSON->Postgres migration.
 */
class ThreadEmailClassificationPersistenceTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    public function testUpdateEmailClassificationPersists(): void {
        $created = NpApiService::createThread('9999-test-entity-development', 'T', 'B',
            ['norske_postlister_no', 'document', 'document_id:2030-1-1']);
        $threadId = $created['thread_id'];

        $emailId = Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
             VALUES (?, now(), now(), 'IN', ?::bytea, NULL) RETURNING id",
            [$threadId, 'content']
        );

        ThreadStorageManager::getInstance()->updateEmailClassification(
            $threadId, $emailId, 'INFORMATION_RELEASE', 'Svar med dokument', false, '', null
        );

        $row = Database::queryOneOrNone(
            "SELECT status_type, status_text, ignore FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('INFORMATION_RELEASE', $row['status_type']);
        $this->assertEquals('Svar med dokument', $row['status_text']);
        $this->assertFalse((bool)$row['ignore'] === true && false); // ignore stored as false
        $this->assertSame(false, is_string($row['ignore']) ? $row['ignore'] === 't' : (bool)$row['ignore']);

        // Scoping: wrong thread id must not update
        ThreadStorageManager::getInstance()->updateEmailClassification(
            '00000000-0000-4000-8000-000000000000', $emailId, 'REQUEST_REJECTED', 'x', true, '', null
        );
        $row = Database::queryOneOrNone(
            "SELECT status_type FROM thread_emails WHERE id = ?", [$emailId]);
        $this->assertEquals('INFORMATION_RELEASE', $row['status_type']);
    }
}
