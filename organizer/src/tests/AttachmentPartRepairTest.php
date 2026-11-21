<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/AttachmentPartCheck.php';
require_once __DIR__ . '/../class/AttachmentPartRepair.php';

class AttachmentPartRepairTest extends TestCase {

    private const WEBP_BYTES = "RIFF\x10\x00\x00\x00WEBPVP8 org chart";
    private const PDF_BYTES = "%PDF-1.4 mobilabonnement";

    private string $emailId;
    private string $pdfAttachmentId;

    /**
     * Same shape as Gjesdal kommune's "Telefonlogg for ansatte": body (part 1),
     * webp image (part 2), pdf (part 3).
     */
    private function gjesdalShapedEml(): string {
        return "From: Postmottak <postmottak@example.com>\r\n"
            . "To: someone@example.com\r\n"
            . "Subject: Telefonlogg for ansatte\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n"
            . "\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hei,\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: image/webp; name=\"Organisasjonskart (2).webp\"\r\n"
            . "Content-Disposition: attachment; filename=\"Organisasjonskart (2).webp\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode(self::WEBP_BYTES) . "\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: application/pdf; name=\"Mobilabonnement Gjesdal.pdf\"\r\n"
            . "Content-Disposition: attachment; filename=\"Mobilabonnement Gjesdal.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode(self::PDF_BYTES) . "\r\n"
            . "--BOUNDARY--\r\n";
    }

    protected function setUp(): void {
        Database::beginTransaction();

        // What the old saver stored for the Gjesdal email: only the pdf, holding the
        // bytes of part 2 (the webp), with a text extraction made from those bytes.
        $threadId = Database::queryValue(
            "INSERT INTO threads (id, entity_id, title, my_name, my_email)
             VALUES (gen_random_uuid(), '000000000-test-entity-development', 'Attachment repair', 'Test User',
                     'attachment-repair-test@example.com')
             RETURNING id"
        );
        $this->emailId = Database::queryValueWithBinaryParam(
            "INSERT INTO thread_emails (thread_id, timestamp_received, email_type, id_old, content)
             VALUES (:thread_id, '2026-11-09 10:00:00+01', 'IN', '2026-11-09_090000 - IN', :content)
             RETURNING id",
            [':thread_id' => $threadId],
            [':content' => $this->gjesdalShapedEml()]
        );
        $this->pdfAttachmentId = Database::queryValueWithBinaryParam(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, content)
             VALUES (:email_id, 'Mobilabonnement Gjesdal.pdf', 'Mobilabonnement Gjesdal.pdf', 'pdf',
                     '2026-11-09_090000 - IN - att 1-x.pdf', :content)
             RETURNING id",
            [':email_id' => $this->emailId],
            [':content' => self::WEBP_BYTES]
        );
        Database::execute(
            "INSERT INTO thread_email_extractions (email_id, attachment_id, prompt_text, prompt_service, extracted_text)
             VALUES (?, ?, 'attachment_pdf', 'code', 'text extracted from the wrong file')",
            [$this->emailId, $this->pdfAttachmentId]
        );
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function storedAttachments(): array {
        return array_map(
            fn($row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'filename' => $row['filename'],
                'content' => is_resource($row['content']) ? stream_get_contents($row['content']) : $row['content'],
            ],
            Database::query(
                "SELECT id, name, filename, content FROM thread_email_attachments WHERE email_id = ? ORDER BY name",
                [$this->emailId]
            )
        );
    }

    private function storedSummary(): array {
        return array_map(
            fn($row) => [
                $row['name'],
                $row['filetype'],
                is_resource($row['content']) ? stream_get_contents($row['content']) : $row['content'],
                $row['size'] === null ? null : (int)$row['size'],
            ],
            Database::query(
                "SELECT name, filetype, content, size FROM thread_email_attachments WHERE email_id = ? ORDER BY name",
                [$this->emailId]
            )
        );
    }

    private function history(): array {
        return array_map(
            fn($row) => [$row['action'], $row['user_id'], json_decode($row['details'], true)],
            Database::query(
                "SELECT action, user_id, details FROM thread_email_history WHERE email_id = ? ORDER BY id",
                [$this->emailId]
            )
        );
    }

    private function extractionCount(): int {
        return (int)Database::queryValue(
            "SELECT COUNT(*) FROM thread_email_extractions WHERE email_id = ?",
            [$this->emailId]
        );
    }

    public function testDryRunListsActionsAndChangesNothing(): void {
        // :: Setup
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $this->storedAttachments());

        // :: Act
        $actions = AttachmentPartRepair::repairEmail($this->emailId, $this->gjesdalShapedEml(), $findings, false);

        // :: Assert
        $this->assertEquals([
            "fix_content attachment={$this->pdfAttachmentId} part=3 Mobilabonnement Gjesdal.pdf",
            "add_missing part=2 Organisasjonskart (2).webp",
        ], $actions);
        $this->assertEquals([
            ['Mobilabonnement Gjesdal.pdf', 'pdf', self::WEBP_BYTES, null],
        ], $this->storedSummary(), 'Dry run must not change stored attachments');
        $this->assertEquals(1, $this->extractionCount(), 'Dry run must not delete extractions');
        $this->assertEquals([], $this->history(), 'Dry run must not write history');
    }

    public function testApplyFixesContentAddsMissingAndResetsExtractions(): void {
        // :: Setup
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $this->storedAttachments());

        // :: Act
        AttachmentPartRepair::repairEmail($this->emailId, $this->gjesdalShapedEml(), $findings, true);

        // :: Assert
        $this->assertEquals([
            ['Mobilabonnement Gjesdal.pdf', 'pdf', self::PDF_BYTES, strlen(self::PDF_BYTES)],
            ['Organisasjonskart (2).webp', 'webp', self::WEBP_BYTES, strlen(self::WEBP_BYTES)],
        ], $this->storedSummary());
        $this->assertEquals(0, $this->extractionCount(), 'Extraction made from the wrong bytes must be deleted so it runs again');

        $webpId = Database::queryValue(
            "SELECT id FROM thread_email_attachments WHERE email_id = ? AND name = 'Organisasjonskart (2).webp'",
            [$this->emailId]
        );
        $this->assertEquals([
            [
                AttachmentPartRepair::HISTORY_ACTION,
                AttachmentPartRepair::HISTORY_USER_ID,
                [
                    'repair' => AttachmentPartRepair::REPAIR_ID,
                    'reason' => AttachmentPartRepair::REASON,
                    'fixed' => [[
                        'attachment_id' => $this->pdfAttachmentId,
                        'name' => 'Mobilabonnement Gjesdal.pdf',
                        'part' => 3,
                        'problem' => 'held the content of part 2 (Organisasjonskart (2).webp)',
                    ]],
                    'added' => [[
                        'attachment_id' => $webpId,
                        'name' => 'Organisasjonskart (2).webp',
                        'part' => 2,
                        'problem' => 'was never stored',
                    ]],
                ],
            ],
        ], $this->history(), 'One history entry per repaired email, listing what was changed');

        $statuses = array_map(
            fn($f) => $f['status'],
            AttachmentPartCheck::check($this->gjesdalShapedEml(), $this->storedAttachments())
        );
        $this->assertEquals([AttachmentPartCheck::OK, AttachmentPartCheck::OK], $statuses, 'After repair the check finds nothing');
    }

    public function testEmptyStoredAttachmentIsExplainedAsEmpty(): void {
        // :: Setup
        // Production has 94 .zip rows like this: already 0 bytes on disk when attachments
        // moved from files to the database.
        Database::queryValueWithBinaryParam(
            "UPDATE thread_email_attachments SET content = :content WHERE id = :id RETURNING id",
            [':id' => $this->pdfAttachmentId],
            [':content' => '']
        );
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $this->storedAttachments());

        // :: Act
        AttachmentPartRepair::repairEmail($this->emailId, $this->gjesdalShapedEml(), $findings, true);

        // :: Assert
        $problems = array_map(fn($item) => $item['problem'], $this->history()[0][2]['fixed']);
        $this->assertEquals(['was empty'], $problems);
    }

    public function testApplyIsANoOpWhenEverythingIsOk(): void {
        // :: Setup
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $this->storedAttachments());
        AttachmentPartRepair::repairEmail($this->emailId, $this->gjesdalShapedEml(), $findings, true);
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $this->storedAttachments());

        // :: Act
        $actions = AttachmentPartRepair::repairEmail($this->emailId, $this->gjesdalShapedEml(), $findings, true);

        // :: Assert
        $this->assertEquals([], $actions);
        $this->assertCount(1, $this->history(), 'Only the first apply changed something, so only it writes history: '
            . json_encode($this->history(), JSON_PRETTY_PRINT));
    }
}
