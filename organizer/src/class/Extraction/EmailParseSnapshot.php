<?php

require_once __DIR__ . '/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/MimeEmailReader.php';
require_once __DIR__ . '/../Imap/ImapEmail.php';
require_once __DIR__ . '/../ThreadUtils.php';

/**
 * Everything Offpost reads from an EML through the mail parser library, as one
 * comparable array.
 *
 * Calls the production code paths unchanged, so a snapshot shows exactly what
 * the rest of the system sees. Used by the fixture tests and by
 * bin/email-parse-snapshot.php to compare parser versions on stored emails.
 * See docs/email-parsing.md.
 */
class EmailParseSnapshot {

    /** Current production parsing (laminas-mail) */
    public const PARSER_LAMINAS = 'laminas';
    /** Replacement under evaluation (MimeEmailReader, zbateson/mail-mime-parser) */
    public const PARSER_MIME = 'mime';

    /**
     * @return array{subject: string, x_forwarded_for: string[], plain_text: ?string, html: ?string, parse_error: ?string}
     */
    public static function fromEml(string $eml, string $parser = self::PARSER_LAMINAS): array {
        if ($parser === self::PARSER_MIME) {
            return self::fromEmlWithMimeReader($eml);
        }
        if ($parser !== self::PARSER_LAMINAS) {
            throw new InvalidArgumentException("Unknown parser: $parser");
        }

        try {
            $body = ThreadEmailExtractorEmailBody::extractContentFromEmail($eml);
        }
        catch (\Throwable $e) {
            // Escaped extraction entirely - the caller would see this exception.
            // Marked separately from errors extraction handles itself.
            $body = new ExtractedEmailBody();
            $uncaught = 'UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage();
        }

        $parseError = $uncaught ?? null;
        $plainText = $body->plain_text;
        $html = $body->html;
        if (is_string($plainText) && str_starts_with($plainText, "ERROR\n\n")) {
            // extractContentFromEmail() returns the raw EML plus a stack trace on
            // failure. Keep only "ExceptionClass: message" - the trace has file
            // paths and line numbers that differ between machines.
            $trace = preg_replace('/^<pre>/', '', (string)$html);
            $parseError = strtok($trace, "\n");
            $plainText = null;
            $html = null;
        }

        return self::jsonSafe([
            'subject' => Imap\ImapEmail::getEmailSubject($eml),
            'x_forwarded_for' => self::xForwardedFor($eml),
            'plain_text' => $plainText,
            'html' => $html,
            'parse_error' => $parseError,
        ]);
    }

    private static function fromEmlWithMimeReader(string $eml): array {
        // Mirrors the laminas side: a failing field records the error instead of
        // aborting, and body failure is recorded in parse_error
        try {
            $subject = MimeEmailReader::subject($eml);
        }
        catch (\Throwable $e) {
            $subject = 'Error getting subject - ' . $e->getMessage();
        }
        try {
            $xForwardedFor = MimeEmailReader::xForwardedFor($eml);
        }
        catch (\Throwable $e) {
            $xForwardedFor = ['exception-during-getting-x-forwarded-for (' . strtolower($e->getMessage()) . ')'];
        }
        $parseError = null;
        try {
            $body = MimeEmailReader::body($eml);
        }
        catch (\Throwable $e) {
            $body = new ExtractedEmailBody();
            $parseError = get_class($e) . ': ' . $e->getMessage();
        }

        return self::jsonSafe([
            'subject' => $subject,
            'x_forwarded_for' => $xForwardedFor,
            'plain_text' => $body->plain_text,
            'html' => $body->html,
            'parse_error' => $parseError,
        ]);
    }

    /**
     * Strings that are not valid UTF-8 become ['invalid_utf8_base64' => ...], so
     * snapshots survive a JSON round trip without losing the bytes and such
     * output stands out in a diff.
     */
    public static function jsonSafe($value) {
        if (is_array($value)) {
            return array_map([self::class, 'jsonSafe'], $value);
        }
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['invalid_utf8_base64' => base64_encode($value)];
        }
        return $value;
    }

    /**
     * Subject as shown in lists, the NP API, exports and used by the
     * classification rules: the IMAP-stored subject run through the parser.
     */
    public static function subjectFromImapHeaders($imapHeaders, string $parser = self::PARSER_LAMINAS): string {
        if ($parser === self::PARSER_LAMINAS) {
            return getEmailSubjectFromImapHeaders($imapHeaders);
        }
        // Same steps as getEmailSubjectFromImapHeaders(), decoding with the replacement
        $headers = parseImapHeaders($imapHeaders);
        if (!$headers || !isset($headers->subject)) {
            return '';
        }
        return MimeEmailReader::subject('Subject: ' . (string)$headers->subject);
    }

    /**
     * The parser-derived part of ImapEmail::getEmailAddresses(). With empty IMAP
     * headers, the method returns only what it read from the raw EML.
     */
    private static function xForwardedFor(string $eml): array {
        $email = new Imap\ImapEmail();
        $email->mailHeaders = new stdClass();
        $addresses = $email->getEmailAddresses($eml);

        // The failure marker embeds a base64 stack trace; keep the message only
        return array_map(
            fn(string $address) => preg_replace('/ b64 trace: .*\)$/s', ')', $address),
            $addresses
        );
    }
}
