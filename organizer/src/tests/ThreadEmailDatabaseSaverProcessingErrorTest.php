<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadEmailDatabaseSaver.php';
require_once __DIR__ . '/../class/ThreadEmailProcessingErrorManager.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/Thread.php';

/**
 * Tests for how ThreadEmailDatabaseSaver::saveThreadEmails() handles emails
 * that cannot be attributed to exactly one thread.
 *
 * These tests do NOT wrap in a transaction like other tests, because
 * saveThreadEmails() manages its own transactions (commits mid-flow when
 * saving processing errors). Cleanup is done explicitly in setUp/tearDown.
 */
class ThreadEmailDatabaseSaverProcessingErrorTest extends PHPUnit\Framework\TestCase {
    const FOLDER = 'INBOX.test-oneoff-alert-processing-error';
    const MY_EMAIL_A = 'oneoff-alert-a@example.com';
    const MY_EMAIL_B = 'oneoff-alert-b@example.com';
    const AMBIGUOUS_SUBJECT = 'Test oneoff alert ambiguous email';
    const NORMAL_SUBJECT = 'Test oneoff alert normal email';

    private $mockConnection;
    private $mockEmailProcessor;
    private $mockAttachmentHandler;
    private $saver;
    private $threadIdA;
    private $threadIdB;

    protected function setUp(): void {
        $this->cleanupTestData();

        $this->threadIdA = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email)
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread OneOffAlert A', 'Test User', ?)
             RETURNING id",
            [self::MY_EMAIL_A]
        );
        $this->threadIdB = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email)
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Test Thread OneOffAlert B', 'Test User', ?)
             RETURNING id",
            [self::MY_EMAIL_B]
        );

        $this->mockConnection = $this->createMock(\Imap\ImapConnection::class);
        $this->mockConnection->method('getRawEmail')->willReturn('raw email content');
        $this->mockEmailProcessor = $this->createMock(\Imap\ImapEmailProcessor::class);
        $this->mockAttachmentHandler = $this->createMock(\Imap\ImapAttachmentHandler::class);
        $this->mockAttachmentHandler->method('processAttachments')->willReturn([]);

        $this->saver = new ThreadEmailDatabaseSaver(
            $this->mockConnection,
            $this->mockEmailProcessor,
            $this->mockAttachmentHandler
        );
    }

    protected function tearDown(): void {
        if (Database::getInstance()->inTransaction()) {
            Database::rollBack();
        }
        $this->cleanupTestData();
    }

    private function cleanupTestData(): void {
        Database::execute(
            "DELETE FROM thread_email_processing_errors WHERE email_identifier IN (?, ?)",
            [$this->ambiguousEmailIdentifier(), $this->normalEmailIdentifier()]
        );
        Database::execute(
            "DELETE FROM thread_email_history WHERE thread_id IN (SELECT id FROM threads WHERE my_email IN (?, ?))",
            [self::MY_EMAIL_A, self::MY_EMAIL_B]
        );
        Database::execute(
            "DELETE FROM thread_emails WHERE thread_id IN (SELECT id FROM threads WHERE my_email IN (?, ?))",
            [self::MY_EMAIL_A, self::MY_EMAIL_B]
        );
        Database::execute(
            "DELETE FROM threads WHERE my_email IN (?, ?)",
            [self::MY_EMAIL_A, self::MY_EMAIL_B]
        );
        Database::execute(
            "DELETE FROM imap_folder_status WHERE folder_name = ?",
            [self::FOLDER]
        );
    }

    private function ambiguousEmailTimestamp(): int {
        return strtotime('2026-01-15 10:00:00');
    }

    private function normalEmailTimestamp(): int {
        return strtotime('2026-01-15 11:00:00');
    }

    private function ambiguousEmailIdentifier(): string {
        return date('Y-m-d__His', $this->ambiguousEmailTimestamp()) . '__' . md5(self::AMBIGUOUS_SUBJECT);
    }

    private function normalEmailIdentifier(): string {
        return date('Y-m-d__His', $this->normalEmailTimestamp()) . '__' . md5(self::NORMAL_SUBJECT);
    }

    private function makeEmail(int $uid, string $subject, int $timestamp, array $toAddresses): \Imap\ImapEmail {
        $email = new \Imap\ImapEmail();
        $email->uid = $uid;
        $email->subject = $subject;
        $email->timestamp = $timestamp;
        $email->date = date('r', $timestamp);
        $email->mailHeaders = (object) [
            'to' => array_map(function ($address) {
                list($mailbox, $host) = explode('@', $address);
                return (object) ['mailbox' => $mailbox, 'host' => $host];
            }, $toAddresses),
            'from' => [(object) ['mailbox' => 'sender', 'host' => 'external.example.com']],
        ];
        return $email;
    }

    /**
     * An email matching two threads: matches neither the ambiguous email being
     * saved, nor silence - it must register a processing error and throw so
     * the admin is alerted once.
     */
    public function testFirstEncounterRegistersErrorAndThrows(): void {
        // :: Setup
        $ambiguous = $this->makeEmail(1, self::AMBIGUOUS_SUBJECT, $this->ambiguousEmailTimestamp(),
            [self::MY_EMAIL_A, self::MY_EMAIL_B]);
        $this->mockEmailProcessor->method('getEmails')->willReturn([$ambiguous]);

        // :: Act
        $thrown = null;
        try {
            $this->saver->saveThreadEmails(self::FOLDER);
        } catch (Exception $e) {
            $thrown = $e;
        }

        // :: Assert
        $this->assertNotNull($thrown, 'First encounter of an ambiguous email should throw');
        $this->assertEquals('Rolling back DB transaction', $thrown->getMessage());
        $this->assertStringContainsString(
            'Multiple matching threads found',
            $thrown->getPrevious()->getMessage(),
            'Cause should describe the multiple matching threads'
        );

        $errors = Database::query(
            "SELECT error_type, resolved FROM thread_email_processing_errors WHERE email_identifier = ?",
            [$this->ambiguousEmailIdentifier()]
        );
        $this->assertCount(1, $errors,
            'One processing error should be registered: ' . json_encode($errors, JSON_PRETTY_PRINT));
        $this->assertEquals('multiple_matching_threads', $errors[0]['error_type']);
        $this->assertFalse((bool) $errors[0]['resolved']);
    }

    /**
     * When the processing error is already registered (admin already alerted),
     * the ambiguous email is skipped without throwing and the rest of the
     * folder is processed.
     */
    public function testKnownErrorIsSkippedAndRestOfFolderProcessed(): void {
        // :: Setup
        // Simulate that a previous run already registered the error
        Database::execute(
            "INSERT INTO thread_email_processing_errors
             (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved)
             VALUES (?, ?, ?, ?, ?, ?, false)",
            [
                $this->ambiguousEmailIdentifier(),
                self::AMBIGUOUS_SUBJECT,
                self::MY_EMAIL_A . ', ' . self::MY_EMAIL_B,
                'multiple_matching_threads',
                'Multiple matching threads found for email(s): ' . self::MY_EMAIL_A . ', ' . self::MY_EMAIL_B,
                self::FOLDER,
            ]
        );

        $ambiguous = $this->makeEmail(1, self::AMBIGUOUS_SUBJECT, $this->ambiguousEmailTimestamp(),
            [self::MY_EMAIL_A, self::MY_EMAIL_B]);
        $normal = $this->makeEmail(2, self::NORMAL_SUBJECT, $this->normalEmailTimestamp(),
            [self::MY_EMAIL_A]);
        $this->mockEmailProcessor->method('getEmails')->willReturn([$ambiguous, $normal]);

        // :: Act
        $savedEmails = $this->saver->saveThreadEmails(self::FOLDER);

        // :: Assert
        $this->assertCount(1, $savedEmails,
            'Only the unambiguous email should be saved: ' . json_encode($savedEmails, JSON_PRETTY_PRINT));

        $emailsA = Database::query(
            "SELECT id, id_old FROM thread_emails WHERE thread_id = ?",
            [$this->threadIdA]
        );
        $this->assertCount(1, $emailsA,
            'Thread A should have the normal email: ' . json_encode($emailsA, JSON_PRETTY_PRINT));

        $emailsB = Database::query(
            "SELECT id, id_old FROM thread_emails WHERE thread_id = ?",
            [$this->threadIdB]
        );
        $this->assertCount(0, $emailsB,
            'Thread B should have no emails: ' . json_encode($emailsB, JSON_PRETTY_PRINT));

        $errorCount = Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_processing_errors WHERE email_identifier = ? AND resolved = false",
            [$this->ambiguousEmailIdentifier()]
        );
        $this->assertEquals(1, $errorCount, 'The processing error should still be registered for GUI resolution');
    }

    public function testHasUnresolvedError(): void {
        // :: Setup
        $this->assertFalse(
            ThreadEmailProcessingErrorManager::hasUnresolvedError($this->ambiguousEmailIdentifier()),
            'No error registered yet'
        );

        Database::execute(
            "INSERT INTO thread_email_processing_errors
             (email_identifier, email_subject, email_addresses, error_type, error_message, folder_name, resolved)
             VALUES (?, ?, ?, ?, ?, ?, false)",
            [
                $this->ambiguousEmailIdentifier(),
                self::AMBIGUOUS_SUBJECT,
                self::MY_EMAIL_A,
                'multiple_matching_threads',
                'Multiple matching threads found',
                self::FOLDER,
            ]
        );

        // :: Act & Assert
        $this->assertTrue(
            ThreadEmailProcessingErrorManager::hasUnresolvedError($this->ambiguousEmailIdentifier()),
            'Unresolved error should be detected'
        );

        // :: Act & Assert - resolved errors do not count
        Database::execute(
            "UPDATE thread_email_processing_errors SET resolved = true WHERE email_identifier = ?",
            [$this->ambiguousEmailIdentifier()]
        );
        $this->assertFalse(
            ThreadEmailProcessingErrorManager::hasUnresolvedError($this->ambiguousEmailIdentifier()),
            'Resolved errors should not count as unresolved'
        );
    }
}
