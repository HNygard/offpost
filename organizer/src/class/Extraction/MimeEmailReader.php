<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ThreadEmailExtractorEmailBody.php';

use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Reads what Offpost needs from a raw EML using zbateson/mail-mime-parser.
 *
 * Replacement for the laminas-mail based reading in ThreadEmailExtractorEmailBody
 * and ImapEmail. Not used in production yet: EmailParseSnapshot compares it with
 * the current parsing, see docs/email-parsing.md.
 *
 * zbateson is lenient (raw 8-bit headers, invalid addresses, missing boundaries
 * do not throw), so the laminas strictness workarounds are not needed. The
 * encoded-word repairs are kept: they fix genuinely malformed input from
 * Exchange/Outlook that no parser decodes correctly on its own.
 */
class MimeEmailReader {

    /**
     * Decoded subject, '' when there is none.
     */
    public static function subject(string $eml): string {
        return (string)self::parse($eml)->getSubject();
    }

    /**
     * Addresses from all X-Forwarded-For headers, lower case.
     *
     * @return string[]
     */
    public static function xForwardedFor(string $eml): array {
        $addresses = [];
        foreach (self::parse($eml)->getAllHeadersByName('X-Forwarded-For') as $header) {
            // Split on commas outside double quotes: "Doe, Jane" <jane@x> is one address
            foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $header->getRawValue()) as $value) {
                // Accept both "a@b.no" and "Name <a@b.no>"
                if (preg_match('/<([^>]+)>/', $value, $matches)) {
                    $value = $matches[1];
                }
                $value = strtolower(trim($value));
                if ($value !== '') {
                    $addresses[] = $value;
                }
            }
        }
        return array_values(array_unique($addresses));
    }

    /**
     * Plain text and HTML-derived text, cleaned as ThreadEmailExtractorEmailBody does.
     *
     * Same shape as the current extraction: for a multipart message a missing
     * text/html part gives '' (plain text likewise); for a single-part message
     * the missing one is null.
     */
    public static function body(string $eml): ExtractedEmailBody {
        $message = self::parse($eml);
        $missing = $message->isMultiPart() ? '' : null;

        $textPart = $message->getTextPart();
        $htmlPart = $message->getHtmlPart();

        $body = new ExtractedEmailBody();
        $body->plain_text = $textPart
            ? ThreadEmailExtractorEmailBody::cleanText(self::partTextAsUtf8($textPart))
            : ($htmlPart && !$message->isMultiPart() ? '' : $missing);
        // Line endings normalised like laminas did; cleanText() does it for plain text
        $body->html = $htmlPart
            ? ThreadEmailExtractorEmailBody::convertHtmlToText(str_replace("\r\n", "\n", self::partTextAsUtf8($htmlPart)))
            : $missing;
        return $body;
    }

    /**
     * Transfer-decoded part content converted to UTF-8.
     *
     * With no charset or a single-byte one declared, valid UTF-8 is kept as is:
     * mail labelled iso-8859-1 but sent as UTF-8 is common, and real 8-bit text
     * is practically never valid UTF-8 by accident. Other declared charsets
     * (UTF-16, ISO-2022-JP, ...) are always decoded - their bytes can pass as
     * UTF-8 without being the text. Windows-1252 (a superset of ISO-8859-1 for
     * printable characters) is the fallback.
     */
    private static function partTextAsUtf8(IMessagePart $part): string {
        $stream = $part->getBinaryContentStream();
        $bytes = $stream ? $stream->getContents() : '';
        $declared = $part instanceof \ZBateson\MailMimeParser\Message\IMimePart
            ? $part->getHeaderParameter('Content-Type', 'charset')
            : null;
        if ($declared !== null && strcasecmp($declared, 'binary') === 0) {
            $declared = null;
        }
        $singleByteOrUtf8 = $declared === null
            || preg_match('/^(us-ascii|ascii|utf-?8|iso-?8859-\d+|latin-?\d|windows-125\d|cp-?125\d)$/i', $declared);
        if ($singleByteOrUtf8 && mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        if ($declared !== null) {
            $converted = $part->getContent('UTF-8');
            if ($converted !== null && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }
        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private static function parse(string $eml): IMessage {
        return Message::from(self::repairEncodedWordsInHeaders($eml), false);
    }

    /**
     * Applies the encoded-word repairs of ThreadEmailExtractorEmailBody to every
     * header line (glued next-header names, lone-? terminators, nested words,
     * UTF-8 bytes in iso-8859-1 words). The body is not touched.
     */
    public static function repairEncodedWordsInHeaders(string $eml): string {
        $parts = preg_split('/(\r?\n\r?\n)/', $eml, 2, PREG_SPLIT_DELIM_CAPTURE);
        $headerLines = preg_split('/(\r?\n)/', $parts[0], -1, PREG_SPLIT_DELIM_CAPTURE);

        $repaired = '';
        $dropContinuations = false;
        for ($i = 0; $i < count($headerLines); $i += 2) {
            $line = $headerLines[$i];
            $newline = $headerLines[$i + 1] ?? '';
            $isContinuation = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
            if ($isContinuation && $dropContinuations) {
                // Continuation lines of a glued next-header that was truncated away
                continue;
            }
            if (!$isContinuation) {
                $dropContinuations = false;
            }
            $line = ThreadEmailExtractorEmailBody::fixMalformedEncodedWords($line, $truncatedGluedHeader);
            $dropContinuations = $truncatedGluedHeader || ($isContinuation && $dropContinuations);
            $repaired .= $line . $newline;
        }

        return $repaired . ($parts[1] ?? '') . ($parts[2] ?? '');
    }
}
