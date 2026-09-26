<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../bootstrap.php');
require_once(__DIR__ . '/../../class/Extraction/ThreadEmailStatusUpdater.php');
require_once(__DIR__ . '/../../class/Thread.php');

/**
 * ThreadEmailStatusUpdater with ThreadEmailResponseClassifier: subject from
 * imap_headers, attachment types and time since our last OUT email.
 */
class ThreadEmailStatusUpdaterRulesTest extends TestCase {
    private $thread;
    private ThreadEmailStatusUpdater $statusUpdater;

    protected function setUp(): void {
        parent::setUp();
        Database::beginTransaction();

        $thread = new Thread();
        $thread->title = "Test Thread for rule classification";
        $thread->my_name = "Test User";
        $thread->my_email = "rules-classification-test@example.com";
        $thread->labels = [];
        $thread->sending_status = Thread::SENDING_STATUS_SENT;
        $thread->sent = true;
        $thread->archived = false;
        $thread->public = false;
        $this->thread = createThread('000000000-test-entity-development', $thread);

        $this->statusUpdater = new ThreadEmailStatusUpdater();
    }

    protected function tearDown(): void {
        Database::rollBack();
        parent::tearDown();
    }

    private function insertEmail(string $type, string $received, string $subject, array $filetypes = [],
                                 string $statusType = 'unknown', ?string $autoClassification = null): string {
        $emailId = Database::queryValue(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, email_type, status_type, status_text, content, imap_headers, auto_classification)
             VALUES (?, ?, ?, ?, ?, 'Uklassifisert', 'content', ?, ?) RETURNING id",
            [$this->thread->id, $received, $received, $type, $statusType, json_encode(['subject' => $subject]), $autoClassification]
        );
        foreach ($filetypes as $i => $filetype) {
            Database::execute(
                "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location) VALUES (?, ?, ?, ?, ?)",
                [$emailId, "file$i.$filetype", "file$i.$filetype", $filetype, "loc$i"]
            );
        }
        return $emailId;
    }

    private function statusOf(string $emailId): array {
        return Database::queryOne("SELECT status_type, status_text, auto_classification FROM thread_emails WHERE id = ?", [$emailId]);
    }

    public function testAutoReplySubjectIsReceipt() {
        // :: Setup
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-02 10:00:00+02', 'Automatic reply: Innsynshenvendelse - sak 2026/1');

        // :: Act
        $result = $this->statusUpdater->classifyByRules($emailId);

        // :: Assert
        $this->assertEquals('REQUEST_RECEIPT', $result?->value);
        $this->assertEquals(
            ['status_type' => 'REQUEST_RECEIPT', 'status_text' => 'Automatisk svar / mottaksbekreftelse', 'auto_classification' => 'algo'],
            $this->statusOf($emailId));
    }

    public function testFastReplyIsNotReceiptByTimingAlone() {
        // :: Setup
        // The entity may answer just as we send; timing alone never decides.
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-01 10:03:00+02', 'SV: Innsynshenvendelse - sak 2026/1');

        // :: Act
        $result = $this->statusUpdater->classifyByRules($emailId);

        // :: Assert
        $this->assertEquals('unknown', $result?->value);
    }

    public function testNextDayReplyWithDocumentIsRelease() {
        // :: Setup
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-02 09:00:00+02', 'Brev fra Stavanger kommune', ['pdf', 'pdf']);

        // :: Act
        $result = $this->statusUpdater->classifyByRules($emailId);

        // :: Assert
        $this->assertEquals('INFORMATION_RELEASE', $result?->value);
        $this->assertEquals(
            ['status_type' => 'INFORMATION_RELEASE', 'status_text' => 'Dokumenter mottatt', 'auto_classification' => 'algo'],
            $this->statusOf($emailId));
    }

    public function testRecentPdfWithoutExtractionWaits() {
        // :: Setup
        // Received relative to NOW(): the one-day wait window is evaluated by the database clock.
        $this->insertEmail('OUT', '2026-01-01 00:00:00+00', 'Innsynshenvendelse - sak 2026/1');
        $received = Database::queryValue("SELECT to_char(NOW() - INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SSOF')");
        $emailId = $this->insertEmail('IN', $received, 'Brev fra Stavanger kommune', ['pdf']);

        // :: Act
        $result = $this->statusUpdater->classifyByRules($emailId);

        // :: Assert
        $this->assertNull($result);
        $this->assertEquals(['status_type' => 'unknown', 'status_text' => 'Uklassifisert', 'auto_classification' => null],
            $this->statusOf($emailId), 'Left for a later run, when the PDF text is there');
    }

    public function testPdfTextRefusingInnsynIsRejected() {
        // :: Setup
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-03 10:00:00+02', 'Brev fra Stavanger kommune', ['pdf']);
        $attachmentId = Database::queryValue("SELECT id FROM thread_email_attachments WHERE email_id = ?", [$emailId]);
        Database::execute(
            "INSERT INTO thread_email_extractions (email_id, attachment_id, prompt_text, prompt_service, extracted_text)
             VALUES (?, ?, 'attachment_pdf', 'code', ?)",
            [$emailId, $attachmentId, 'Kommunen avslår innsynskravet, jf. offentleglova § 13.']
        );

        // :: Act
        $result = $this->statusUpdater->classifyByRules($emailId);

        // :: Assert
        $this->assertEquals('REQUEST_REJECTED', $result?->value);
    }

    public function testNoSignalStaysUnknownButIsMarkedDone() {
        // :: Setup
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-02 09:00:00+02', 'SV: Innsynshenvendelse - sak 2026/1');

        // :: Act
        $result = $this->statusUpdater->classifyByRules($emailId);
        $pending = $this->statusUpdater->classifyPendingByRules(1000);

        // :: Assert
        $this->assertEquals('unknown', $result?->value);
        $this->assertEquals(
            ['status_type' => 'unknown', 'status_text' => 'Uklassifisert', 'auto_classification' => 'algo'],
            $this->statusOf($emailId), 'Status text is kept, the email is marked so the scan skips it');
        $this->assertNotContains($emailId, array_column(Database::query(
            "SELECT id FROM thread_emails WHERE auto_classification IS NULL AND thread_id = ?", [$this->thread->id]), 'id'));
    }

    public function testManualClassificationIsNotTouched() {
        // :: Setup
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-01 10:01:00+02', 'Automatic reply: Innsynshenvendelse',
            [], 'INFORMATION_RELEASE', null);

        // :: Act
        $rulesResult = $this->statusUpdater->classifyByRules($emailId);
        $summaryResult = $this->statusUpdater->updateFromAISummary($emailId, 'Automatisk svar.');

        // :: Assert
        $this->assertNull($rulesResult);
        $this->assertFalse($summaryResult);
        $this->assertEquals('INFORMATION_RELEASE', $this->statusOf($emailId)['status_type']);
    }

    public function testSummaryReplacesEarlierAutomaticClassification() {
        // :: Setup
        // An auto-reply the old keyword rules classified as a rejection ("kan ikke").
        $this->insertEmail('OUT', '2026-09-01 10:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        // Subject MIME-encoded as stored in imap_headers: "Bekreftelse på mottatt e-post".
        // Received the next day, so only the subject makes it a receipt.
        $emailId = $this->insertEmail('IN', '2026-09-02 08:00:00+02', '=?UTF-8?Q?Bekreftelse_p=C3=A5_mottatt_e-post?=',
            [], 'REQUEST_REJECTED', 'prompt');

        // :: Act
        $result = $this->statusUpdater->updateFromAISummary($emailId, 'Vi kan ikke svare på e-post i dag.');

        // :: Assert
        $this->assertTrue($result);
        $this->assertEquals('REQUEST_RECEIPT', $this->statusOf($emailId)['status_type']);
    }

    public function testNextDayConfirmationSummaryIsReceipt() {
        // :: Setup
        $this->insertEmail('OUT', '2026-09-01 15:00:00+02', 'Innsynshenvendelse - sak 2026/1');
        $emailId = $this->insertEmail('IN', '2026-09-02 09:30:00+02', 'SV: Innsynshenvendelse - sak 2026/1');

        // :: Act
        $this->statusUpdater->updateFromAISummary($emailId, 'Innsynskravet er journalført og vil bli behandlet fortløpende.');

        // :: Assert
        $this->assertEquals('REQUEST_RECEIPT', $this->statusOf($emailId)['status_type']);
    }
}
