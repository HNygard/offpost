<?php

use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/../class/ThreadEmailResponseClassifier.php';

class ThreadEmailResponseClassifierTest extends TestCase {

    public static function autoReplySubjects(): array {
        // Subjects seen on norske-postlister.no threads.
        return [
            ['Automatic reply: Innsynshenvendelse - sak 2021/23530 - Gnr/bnr 59/1692'],
            ['Automatisk svar: Innsynshenvendelse - sak 2024/34453 - Nesodden kommune'],
            ['Automatisk svar'],
            ['Autosvar - Vennesla kommune'],
            ['Bekreftelse på mottatt e-post'],
            ['Bekreftelse på mottak av e-post'],
            ['Takk for e-posten din!'],
            ['Automatisk kvittering for mottak av Deres e-post - Vestby kommune'],
            ['SV: Automatic reply: Innsynshenvendelse'],
        ];
    }

    /** @dataProvider autoReplySubjects */
    public function testAutoReplySubjectIsRequestReceipt(string $subject) {
        // :: Act
        // Summary that the old keyword rules turned into REQUEST_REJECTED.
        $result = ThreadEmailResponseClassifier::classifyIncoming($subject, 'Vi kan ikke svare på e-posten nå.', ['png']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::REQUEST_RECEIPT, $result);
    }

    public static function notAutoReplySubjects(): array {
        return [
            ['SV: Innsynshenvendelse - 2018/14642-6 - Søknad om dispensasjon'],
            ['Brev fra Stavanger kommune'],
            ['Svar på innsynsbegjæring nr. 797'],
            ['Midlertidig svar på innsynsbegjæring nr. 797'],
            ['E-postforsendelse'],
        ];
    }

    /** @dataProvider notAutoReplySubjects */
    public function testOrdinarySubjectIsNotAutoReply(string $subject) {
        // :: Act
        $result = ThreadEmailResponseClassifier::isAutoReplySubject($subject);

        // :: Assert
        $this->assertFalse($result);
    }

    public function testDocumentWithoutSummaryIsInformationRelease() {
        // :: Act
        // "Brev fra X kommune" from the archive system: no body, only documents.
        $result = ThreadEmailResponseClassifier::classifyIncoming('Brev fra Stavanger kommune', null, ['pdf', 'pdf', 'PDF']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::INFORMATION_RELEASE, $result);
    }

    public function testImageOnlyWithoutSummaryIsUnknown() {
        // :: Act
        // Signature logo only.
        $result = ThreadEmailResponseClassifier::classifyIncoming('SV: Innsynshenvendelse - sak 2024/1', null, ['png', 'jpg']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::UNKNOWN, $result);
    }

    public function testPlainReplyWithoutSignalIsUnknown() {
        // :: Act
        // No timing rule: however fast it came, a plain "SV:" without summary or document is not placed.
        $result = ThreadEmailResponseClassifier::classifyIncoming('SV: Innsynshenvendelse - sak 2024/1', null, []);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::UNKNOWN, $result);
    }

    public static function nextDayConfirmationSummaries(): array {
        return [
            ['Kommunen bekrefter mottak av innsynskravet, som er registrert med saksnummer 2026/123.'],
            ['Innsynskravet er journalført og vil bli behandlet så snart som mulig.'],
            ['Henvendelsen er videresendt til byggesaksavdelingen, som vil svare innen fristen.'],
        ];
    }

    /** @dataProvider nextDayConfirmationSummaries */
    public function testNextDayConfirmationSummaryIsRequestReceipt(string $summary) {
        // :: Act
        // Typically the next working day: no timing hint, only the wording.
        $result = ThreadEmailResponseClassifier::classifyIncoming('SV: Innsynshenvendelse - sak 2026/123', $summary, []);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::REQUEST_RECEIPT, $result);
    }

    public function testReceivedAndReleasedSummaryIsInformationRelease() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('SV: Innsynshenvendelse', 'Kommunen har registrert innsynskravet og sender vedlagt dokumentene.', []);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::INFORMATION_RELEASE, $result);
    }

    public function testSummaryWithDocumentIsNotReceipt() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('SV: Innsynshenvendelse', 'Innsynskravet er registrert.', ['pdf']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::INFORMATION_RELEASE, $result);
    }

    public function testRejectionSummaryStillRejected() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('Svar på innsynskrav', 'Kommunen avslår innsynskravet med hjemmel i offentleglova § 13.', ['pdf']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::REQUEST_REJECTED, $result);
    }

    public function testMoreTimeSummary() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('Midlertidig svar på innsynsbegjæring nr. 797', 'Kommunen trenger mer tid for å behandle innsynskravet.', ['pdf']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::ASKING_FOR_MORE_TIME, $result);
    }

    public function testPdfRefusingInnsynIsRejected() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('Brev fra Stavanger kommune', null, ['pdf'],
            ["Svar på innsynskrav\nKommunen avslår innsynskravet ditt, jf. offentleglova § 13."]);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::REQUEST_REJECTED, $result);
    }

    public function testPdfRefusalOverridesReleaseFromSummary() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('SV: Innsynshenvendelse', 'Kommunen sender vedlagt et brev.', ['pdf'],
            ['Det gis ikke innsyn i dokumentet.']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::REQUEST_REJECTED, $result);
    }

    public function testReleasedRejectionDecisionIsRelease() {
        // :: Act
        // The released document is itself a rejection of a permit application.
        $result = ThreadEmailResponseClassifier::classifyIncoming('Brev fra Rana kommune', null, ['pdf'],
            ['Vedtak: Avslag på søknad om fritak fra renovasjon. Søknaden avslås.']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::INFORMATION_RELEASE, $result);
    }

    public function testTxtAttachmentIsNotDocument() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming('VS: Innsynskrav - Sak 2024/34453', null, ['TXT', 'TXT']);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::UNKNOWN, $result);
    }

    public function testNoSignalIsUnknown() {
        // :: Act
        $result = ThreadEmailResponseClassifier::classifyIncoming(null, 'Hei.', []);

        // :: Assert
        $this->assertEquals(ThreadEmailStatusType::UNKNOWN, $result);
    }
}
