<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../class/AttachmentPartCheck.php';

class AttachmentPartCheckTest extends TestCase {

    private const WEBP_BYTES = "RIFF\x10\x00\x00\x00WEBPVP8 org chart";
    private const PDF_BYTES = "%PDF-1.4 mobilabonnement";

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

    private function stored(string $id, string $name, ?string $content): array {
        return ['id' => $id, 'name' => $name, 'filename' => $name, 'content' => $content];
    }

    public function testDetectsShiftedContentAndDroppedAttachment(): void {
        // :: Setup
        // What the old saver stored: only the pdf, holding the bytes of part 2 (the webp).
        $stored = [$this->stored('att-1', 'Mobilabonnement Gjesdal.pdf', self::WEBP_BYTES)];

        // :: Act
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $stored);

        // :: Assert
        $this->assertEquals([
            [
                'status' => AttachmentPartCheck::WRONG_CONTENT,
                'attachment_id' => 'att-1',
                'name' => 'Mobilabonnement Gjesdal.pdf',
                'part_number' => 3,
                'content_matches_part' => 2,
            ],
            [
                'status' => AttachmentPartCheck::MISSING,
                'attachment_id' => null,
                'name' => 'Organisasjonskart (2).webp',
                'part_number' => 2,
                'content_matches_part' => null,
            ],
        ], $findings);
    }

    public function testCorrectlyStoredAttachmentsAreOk(): void {
        // :: Setup
        $stored = [
            $this->stored('att-1', 'Organisasjonskart (2).webp', self::WEBP_BYTES),
            $this->stored('att-2', 'Mobilabonnement Gjesdal.pdf', self::PDF_BYTES),
        ];

        // :: Act
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $stored);

        // :: Assert
        $this->assertEquals([
            [
                'status' => AttachmentPartCheck::OK,
                'attachment_id' => 'att-1',
                'name' => 'Organisasjonskart (2).webp',
                'part_number' => 2,
                'content_matches_part' => 2,
            ],
            [
                'status' => AttachmentPartCheck::OK,
                'attachment_id' => 'att-2',
                'name' => 'Mobilabonnement Gjesdal.pdf',
                'part_number' => 3,
                'content_matches_part' => 3,
            ],
        ], $findings);
    }

    public function testStoredAttachmentWithoutPartOfSameName(): void {
        // :: Setup
        $stored = [
            $this->stored('att-1', 'Organisasjonskart (2).webp', self::WEBP_BYTES),
            $this->stored('att-2', 'Mobilabonnement Gjesdal.pdf', self::PDF_BYTES),
            $this->stored('att-3', 'renamed.pdf', self::PDF_BYTES),
        ];

        // :: Act
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $stored);

        // :: Assert
        $this->assertEquals([
            'status' => AttachmentPartCheck::NO_MATCHING_PART,
            'attachment_id' => 'att-3',
            'name' => 'renamed.pdf',
            'part_number' => null,
            'content_matches_part' => 3,
        ], $findings[2]);
    }

    public function testStoredAttachmentWithoutContent(): void {
        // :: Setup
        $stored = [
            $this->stored('att-1', 'Organisasjonskart (2).webp', self::WEBP_BYTES),
            $this->stored('att-2', 'Mobilabonnement Gjesdal.pdf', null),
        ];

        // :: Act
        $findings = AttachmentPartCheck::check($this->gjesdalShapedEml(), $stored);

        // :: Assert
        $this->assertEquals([
            'status' => AttachmentPartCheck::STORED_CONTENT_MISSING,
            'attachment_id' => 'att-2',
            'name' => 'Mobilabonnement Gjesdal.pdf',
            'part_number' => 3,
            'content_matches_part' => null,
        ], $findings[1]);
    }

    private const FORWARDED_EMAIL = "From: Saksbehandler <saksbehandler@example.com>\r\n"
        . "Subject: Videresendt\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "\r\n"
        . "Innholdet i den videresendte e-posten.\r\n";

    /**
     * multipart/mixed: body (part 1), a forwarded email without name (part 2),
     * a pdf without name (part 3).
     */
    private function unnamedPartsEml(): string {
        return "From: Postmottak <postmottak@example.com>\r\n"
            . "To: someone@example.com\r\n"
            . "Subject: Svar\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n"
            . "\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hei,\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Type: message/rfc822\r\n"
            . "\r\n"
            . self::FORWARDED_EMAIL
            . "--BOUNDARY\r\n"
            . "Content-Type: application/pdf\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode(self::PDF_BYTES) . "\r\n"
            . "--BOUNDARY--\r\n";
    }

    public function testUnnamedPartsAreComparedUnderTheirGeneratedNames(): void {
        // :: Setup
        // The CRLF before a boundary belongs to the boundary (RFC 2046), so IMAP's
        // BODY[2] - what ingestion stores - ends without it.
        $stored = [
            $this->stored('att-1', 'unnamed-part-2.eml', substr(self::FORWARDED_EMAIL, 0, -2)),
            $this->stored('att-2', 'unnamed-part-3.pdf', self::PDF_BYTES),
        ];

        // :: Act
        $findings = AttachmentPartCheck::check($this->unnamedPartsEml(), $stored);

        // :: Assert
        $this->assertEquals([
            [
                'status' => AttachmentPartCheck::OK,
                'attachment_id' => 'att-1',
                'name' => 'unnamed-part-2.eml',
                'part_number' => 2,
                'content_matches_part' => 2,
            ],
            [
                'status' => AttachmentPartCheck::OK,
                'attachment_id' => 'att-2',
                'name' => 'unnamed-part-3.pdf',
                'part_number' => 3,
                'content_matches_part' => 3,
            ],
        ], $findings);
    }

    public function testUnnamedPartsNeverStoredAreReportedAsMissing(): void {
        // :: Setup
        // What every email with unnamed attachments looks like before this change.
        $stored = [];

        // :: Act
        $findings = AttachmentPartCheck::check($this->unnamedPartsEml(), $stored);

        // :: Assert
        $this->assertEquals([
            [
                'status' => AttachmentPartCheck::MISSING,
                'attachment_id' => null,
                'name' => 'unnamed-part-2.eml',
                'part_number' => 2,
                'content_matches_part' => null,
            ],
            [
                'status' => AttachmentPartCheck::MISSING,
                'attachment_id' => null,
                'name' => 'unnamed-part-3.pdf',
                'part_number' => 3,
                'content_matches_part' => null,
            ],
        ], $findings);
    }

    public function testPartOfUnknownTypeIsReportedAsMissing(): void {
        // :: Setup
        // Ingestion now keeps attachments of any type, so one of an unknown type that
        // was never stored is missing like any other.
        $eml = str_replace('Organisasjonskart (2).webp', 'Organisasjonskart.xyz', $this->gjesdalShapedEml());
        $stored = [$this->stored('att-1', 'Mobilabonnement Gjesdal.pdf', self::PDF_BYTES)];

        // :: Act
        $findings = AttachmentPartCheck::check($eml, $stored);

        // :: Assert
        $this->assertEquals([
            [
                'status' => AttachmentPartCheck::OK,
                'attachment_id' => 'att-1',
                'name' => 'Mobilabonnement Gjesdal.pdf',
                'part_number' => 3,
                'content_matches_part' => 3,
            ],
            [
                'status' => AttachmentPartCheck::MISSING,
                'attachment_id' => null,
                'name' => 'Organisasjonskart.xyz',
                'part_number' => 2,
                'content_matches_part' => null,
            ],
        ], $findings);
    }
}
