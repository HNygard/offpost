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
                    'summary' => 'Some attachments on this email showed the wrong file or were missing. '
                        . 'They have been restored from the original email.',
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
                    'removed' => [],
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

    public function testSameAttachmentTwiceIsAddedOnceAndNotAgainOnNextApply(): void {
        // :: Setup
        // Production: after the first --apply, a re-check reported parts missing again in
        // emails with the same attachment twice, so every --apply would add another copy.
        $eml = "From: Postmottak <postmottak@example.com>\r\n"
            . "Subject: Logo to ganger\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n"
            . "\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hei,\r\n";
        foreach ([2, 3] as $partNumber) {
            $eml .= "--BOUNDARY\r\n"
                . "Content-Type: image/png; name=\"image001.png\"\r\n"
                . "Content-Disposition: attachment; filename=\"image001.png\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "\r\n"
                . base64_encode("PNG logo") . "\r\n";
        }
        $eml .= "--BOUNDARY--\r\n";
        Database::execute("DELETE FROM thread_email_extractions WHERE email_id = ?", [$this->emailId]);
        Database::execute("DELETE FROM thread_email_attachments WHERE email_id = ?", [$this->emailId]);
        Database::queryValueWithBinaryParam(
            "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, content)
             VALUES (:email_id, 'image001.png', 'image001.png', 'png', 'x - att 1-x.png', :content)
             RETURNING id",
            [':email_id' => $this->emailId],
            [':content' => "PNG logo"]
        );
        $firstActions = AttachmentPartRepair::repairEmail(
            $this->emailId, $eml, AttachmentPartCheck::check($eml, $this->storedAttachments()), true
        );

        // :: Act
        $secondActions = AttachmentPartRepair::repairEmail(
            $this->emailId, $eml, AttachmentPartCheck::check($eml, $this->storedAttachments()), true
        );

        // :: Assert
        $this->assertEquals(['add_missing part=3 image001.png'], $firstActions);
        $this->assertEquals([], $secondActions, 'Second apply must not add another copy');
        $this->assertCount(2, $this->storedAttachments(), json_encode(
            array_map(fn($a) => $a['name'], $this->storedAttachments()), JSON_PRETTY_PRINT
        ));
    }

    public function testExtraCopyAddedByAnEarlierRunIsRemoved(): void {
        // :: Setup
        // The first production run used a check that paired both stored copies of a
        // twice-attached file with the first part, reported the second part missing and
        // added a third copy. That copy, and only that one, is removed again.
        $logo = "PNG logo";
        $eml = "From: Postmottak <postmottak@example.com>\r\n"
            . "Subject: Logo to ganger\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n"
            . "\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hei,\r\n";
        foreach ([2, 3] as $partNumber) {
            $eml .= "--BOUNDARY\r\n"
                . "Content-Type: image/png; name=\"image001.png\"\r\n"
                . "Content-Disposition: attachment; filename=\"image001.png\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "\r\n"
                . base64_encode($logo) . "\r\n";
        }
        $eml .= "--BOUNDARY--\r\n";
        Database::execute("DELETE FROM thread_email_extractions WHERE email_id = ?", [$this->emailId]);
        Database::execute("DELETE FROM thread_email_attachments WHERE email_id = ?", [$this->emailId]);
        $ids = [];
        foreach (['2026-01-01 10:00:00+00', '2026-01-01 10:00:01+00', '2026-11-21 16:00:00+00'] as $createdAt) {
            $ids[] = Database::queryValueWithBinaryParam(
                "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, content, created_at)
                 VALUES (:email_id, 'image001.png', 'image001.png', 'png', 'x', :content, :created_at)
                 RETURNING id",
                [':email_id' => $this->emailId, ':created_at' => $createdAt],
                [':content' => $logo]
            );
        }
        [$firstId, $secondId, $addedId] = $ids;
        $threadId = Database::queryValue("SELECT thread_id FROM thread_emails WHERE id = ?", [$this->emailId]);
        (new ThreadEmailHistory())->logAction($threadId, $this->emailId, AttachmentPartRepair::HISTORY_ACTION,
            AttachmentPartRepair::HISTORY_USER_ID, [
                'repair' => AttachmentPartRepair::REPAIR_ID,
                'fixed' => [],
                'added' => [['attachment_id' => $addedId, 'name' => 'image001.png', 'part' => 3, 'problem' => 'was never stored']],
            ]);
        $findings = AttachmentPartCheck::check($eml, $this->storedAttachments());

        // :: Act
        $actions = AttachmentPartRepair::repairEmail($this->emailId, $eml, $findings, true);

        // :: Assert
        $this->assertEquals(["remove_own_duplicate attachment=$addedId image001.png"], $actions);
        $remaining = array_map(fn($a) => $a['id'], $this->storedAttachments());
        sort($remaining);
        $expected = [$firstId, $secondId];
        sort($expected);
        $this->assertEquals($expected, $remaining, 'Only the copy this repair added is removed');
        $history = $this->history();
        $lastHistory = end($history);
        $this->assertEquals([
            ['attachment_id' => $addedId, 'name' => 'image001.png', 'part' => null,
             'problem' => 'was added twice by an earlier run of this repair'],
        ], $lastHistory[2]['removed']);
        $this->assertEquals(AttachmentPartRepair::SUMMARY_REMOVED_ONLY, $lastHistory[2]['summary']);
    }

    public function testDuplicateNotAddedByThisRepairIsLeftAlone(): void {
        // :: Setup
        // Three stored copies for two parts, none added by this repair: left as they are.
        $logo = "PNG logo";
        $eml = "From: Postmottak <postmottak@example.com>\r\n"
            . "Subject: Logo to ganger\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n"
            . "\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hei,\r\n";
        foreach ([2, 3] as $partNumber) {
            $eml .= "--BOUNDARY\r\n"
                . "Content-Type: image/png; name=\"image001.png\"\r\n"
                . "Content-Disposition: attachment; filename=\"image001.png\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "\r\n"
                . base64_encode($logo) . "\r\n";
        }
        $eml .= "--BOUNDARY--\r\n";
        Database::execute("DELETE FROM thread_email_extractions WHERE email_id = ?", [$this->emailId]);
        Database::execute("DELETE FROM thread_email_attachments WHERE email_id = ?", [$this->emailId]);
        foreach ([1, 2, 3] as $n) {
            Database::queryValueWithBinaryParam(
                "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, content)
                 VALUES (:email_id, 'image001.png', 'image001.png', 'png', 'x', :content)
                 RETURNING id",
                [':email_id' => $this->emailId],
                [':content' => $logo]
            );
        }
        $findings = AttachmentPartCheck::check($eml, $this->storedAttachments());

        // :: Act
        $actions = AttachmentPartRepair::repairEmail($this->emailId, $eml, $findings, true);

        // :: Assert
        $this->assertEquals([], $actions);
        $this->assertCount(3, $this->storedAttachments(), 'Duplicates that existed before the repair are kept');
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
