<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

/**
 * Pure rules for classifying an incoming (IN) email from what we know about it:
 * the subject, the AI summary (when the summary prompt has run) and the
 * attachment file types. No database access, so the rules are tested directly.
 *
 * Order of the rules:
 * 1. An auto-reply subject ("Automatic reply", "Automatisk svar", "Bekreftelse på
 *    mottatt e-post", ...) is a REQUEST_RECEIPT, whatever the summary says. The
 *    summary keywords used to turn these into REQUEST_REJECTED ("kan ikke") or
 *    INFORMATION_RELEASE ("informasjon").
 * 2. The AI summary keywords. The receipt check runs first: the "we have
 *    registered your request" confirmation typically comes the next working day.
 *    No rule is based on timing alone - the entity may reply just as we send.
 * 3. Nothing matched, but the email carries a document (pdf, docx, ...): the
 *    entity sent us something, so INFORMATION_RELEASE. Covers the common
 *    "Brev fra X kommune" emails from archive systems, whose body is empty or
 *    boilerplate and never gets a usable summary. Unless the attached letter's
 *    text refuses the innsyn request: then REQUEST_REJECTED, so a human looks
 *    at it and NpThreadAutoArchiver leaves the thread open.
 */
class ThreadEmailResponseClassifier {

    // Matched against the start of the subject, after stripping "SV:", "RE:" etc.
    private const AUTO_REPLY_SUBJECT_PATTERN = '/^(automatic reply|auto(matisk)?[ -]?(svar|reply|kvittering)|autosvar|' .
        'out of office|fraværende|fravær|ikke til stede|' .
        'bekreftelse på (mottatt|mottak)|kvittering (for|på) mottak|mottakskvittering|mottatt e-?post|' .
        'takk for (din |deres )?(e-?post|henvendelse|melding))/u';

    // Summary keywords that mean "we got your email" without an answer. Only
    // used when the email has no document attachment and does not look like a
    // release, since "Vi har mottatt innsynskravet og sender vedlagt ..." is a
    // release.
    private const RECEIPT_SUMMARY_PATTERN = '/(automatisk (svar|kvittering|generert)|autosvar|bekreft\w* (på |av )?mottak|' .
        'bekreft\w* at .{0,60}mottatt|kvittering|takk for (din |deres )?(e-?post|henvendelse)|fravær|ikke på kontoret|' .
        // Next-working-day confirmation from the archive/postmottak
        '(er|blir|har blitt) (registrert|journalført|mottatt|videresendt|oversendt til)|' .
        '(vil|skal) (bli )?(behandle|besvare|svare|følge opp)|vil bli (behandlet|besvart)|' .
        'behandles (så snart|fortløpende|innen))/u';
    private const RELEASE_HINT_PATTERN = '/(vedlagt|vedlegg|oversend|gir innsyn|innvilg|følger (her|vedlagt))/u';

    // Refusal of the innsyn request itself, in an attached letter (PDF text). Kept
    // specific to innsyn: released documents often contain "avslag" on their own
    // (e.g. a released "Avslag på søknad om ..." decision), which is not a refusal.
    private const INNSYN_REJECTION_PATTERN = '/(avslår|avslag på) (\w+ )?(krav|begjæring|anmodning|forespørsel|førespurnad|innsynskrav\w*|innsynsbegjæring\w*|innsynsforespørsel\w*)|' .
        'innsyn\w* (blir|er|vert|må) (derfor )?(avslått|avvist)|gis ikke innsyn|gir (deg )?ikke innsyn|kan ikke gi (deg )?innsyn|' .
        'innsyn (blir|vert) ikkje gitt|får ikke innsyn/u';

    // Attachment types that count as "the entity sent a document". Images are
    // left out: they are mostly logos in email signatures.
    private const DOCUMENT_FILETYPES = [
        // No 'txt': plain text parts of the email body are often stored as .txt attachments.
        'pdf', 'doc', 'docx', 'odt', 'rtf',
        'xls', 'xlsx', 'ods', 'csv',
        'ppt', 'pptx', 'odp',
        'zip', '7z',
        'msg', 'eml',
        'tif', 'tiff',
    ];

    /**
     * @param string|null $subject Decoded subject, or null when unknown
     * @param string|null $summary AI summary (prompt thread-email-summary), or null when not made yet
     * @param string[] $attachmentFiletypes File type (extension) of each attachment
     * @param string[] $attachmentTexts Extracted text of the attachments (PDF extraction), where available
     */
    public static function classifyIncoming(?string $subject, ?string $summary, array $attachmentFiletypes,
                                            array $attachmentTexts = []): ThreadEmailStatusType {
        if ($subject !== null && self::isAutoReplySubject($subject)) {
            return ThreadEmailStatusType::REQUEST_RECEIPT;
        }

        $hasDocument = self::hasDocumentAttachment($attachmentFiletypes);

        $attachmentRejects = $hasDocument && self::attachmentRejectsInnsyn($attachmentTexts);

        if ($summary !== null && trim($summary) !== '') {
            $fromSummary = self::classifyFromSummary($summary, $hasDocument);
            // The summary only sees the email body ("vedlagt følger brev"); the
            // attached letter decides whether it is a release or a refusal.
            if ($fromSummary === ThreadEmailStatusType::INFORMATION_RELEASE && $attachmentRejects) {
                return ThreadEmailStatusType::REQUEST_REJECTED;
            }
            if ($fromSummary !== ThreadEmailStatusType::UNKNOWN) {
                return $fromSummary;
            }
        }

        if ($hasDocument) {
            return $attachmentRejects
                ? ThreadEmailStatusType::REQUEST_REJECTED
                : ThreadEmailStatusType::INFORMATION_RELEASE;
        }

        return ThreadEmailStatusType::UNKNOWN;
    }

    /**
     * @param string[] $attachmentTexts
     */
    public static function attachmentRejectsInnsyn(array $attachmentTexts): bool {
        foreach ($attachmentTexts as $text) {
            if (preg_match(self::INNSYN_REJECTION_PATTERN, mb_strtolower((string)$text, 'UTF-8'))) {
                return true;
            }
        }
        return false;
    }

    public static function isAutoReplySubject(string $subject): bool {
        $subject = mb_strtolower(trim($subject), 'UTF-8');
        // Reply/forward prefixes, possibly repeated ("SV: VS: ...").
        $subject = preg_replace('/^((sv|re|vs|fw|fwd|aw)\s*:\s*)+/u', '', $subject);
        return preg_match(self::AUTO_REPLY_SUBJECT_PATTERN, $subject) === 1;
    }

    /**
     * @param string[] $attachmentFiletypes
     */
    public static function hasDocumentAttachment(array $attachmentFiletypes): bool {
        foreach ($attachmentFiletypes as $filetype) {
            if (in_array(mb_strtolower(trim((string)$filetype), 'UTF-8'), self::DOCUMENT_FILETYPES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keyword rules on the AI summary. Order matters - more specific first.
     */
    public static function classifyFromSummary(string $summary, bool $hasDocument = false): ThreadEmailStatusType {
        $summary_lower = mb_strtolower($summary, 'UTF-8');

        // Receipts first: auto-replies often say things like "kan ikke svare nå"
        // or mention "frist", which the patterns below would take.
        if (!$hasDocument
            && preg_match(self::RECEIPT_SUMMARY_PATTERN, $summary_lower)
            && !preg_match(self::RELEASE_HINT_PATTERN, $summary_lower)) {
            return ThreadEmailStatusType::REQUEST_RECEIPT;
        }

        if (preg_match('/\b(mer tid|utsette|forlenge|frist)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::ASKING_FOR_MORE_TIME;
        }

        // Check for rejection patterns - more specific patterns first
        if (preg_match('/\b(avslag|avslår|avslå|avslås|kan ikke|avvise)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::REQUEST_REJECTED;
        }

        // Check for clarification requests - must come before the copy and
        // information release patterns, which would otherwise swallow
        // summaries like "ber om tilbakemelding på hvilke ... innsyn i"
        if (preg_match('/\b(presiser\w*|avklar\w*|konkretiser\w*|spesifiser\w*|tilbakemelding på hvilke|hvilke .{0,60}innsyn)/u', $summary_lower)) {
            return ThreadEmailStatusType::ASKING_FOR_CLARIFICATION;
        }

        // Check for copy requests - include "ber om" and "videresend" patterns
        if (preg_match('/\b(kopi|kopi av|kan vi få|send|videresend|ber om.*dokumenter)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::ASKING_FOR_COPY;
        }

        // Check for information release - only if not already matched above
        if (preg_match('/\b(sendt|vedlagt|informasjon|vedlegg|oversend\w*|innvilg\w*|gir innsyn)\b/u', $summary_lower)) {
            return ThreadEmailStatusType::INFORMATION_RELEASE;
        }

        return ThreadEmailStatusType::UNKNOWN;
    }
}
