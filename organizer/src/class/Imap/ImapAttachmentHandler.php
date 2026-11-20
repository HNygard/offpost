<?php

namespace Imap;

class ImapAttachmentHandler {
    private ImapConnection $connection;
    private array $supportedTypes = [
        'pdf', 'jpg', 'png', 'docx', 'doc', 'xlsx', 'xlsm', 'pptx',
        'zip', 'gz', 'gif', 'eml', 'csv', 'txt'
    ];

    public function __construct(ImapConnection $connection) {
        $this->connection = $connection;
    }

    /**
     * Process attachments from an email
     */
    public function processAttachments(int $uid): array {
        $structure = $this->connection->getFetchstructure($uid, FT_UID);

        if (!isset($structure->parts) || !count($structure->parts)) {
            return [];
        }

        // In multipart/mixed, part 1 is the body and every later part is something
        // attached, also when it has no name (e.g. a forwarded email). Other
        // multiparts (alternative, related) hold the body itself in unnamed parts.
        $isMixed = strtoupper($structure->subtype ?? '') === 'MIXED';

        $attachments = [];
        for ($i = 0; $i < count($structure->parts); $i++) {
            $attachment = $this->processAttachmentPart($uid, $structure->parts[$i], $i + 1);
            if (!$attachment && $isMixed && $i > 0) {
                $attachment = $this->processUnnamedPart($structure->parts[$i], $i + 1);
            }
            if ($attachment) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * Process a single attachment part
     */
    private function processAttachmentPart(int $uid, object $part, int $partNumber): ?object {
        $attachment = [
            'is_attachment' => false,
            'filename' => '',
            'name' => ''
        ];

        // Check for filename in dparameters
        if ($part->ifdparameters) {
            $continuationParts = [];
            foreach ($part->dparameters as $object) {
                $attr = strtolower($object->attribute);
                if ($attr == 'filename' || $attr == 'filename*') {
                    $attachment['is_attachment'] = true;
                    $attachment['filename'] = $this->decodeUtf8String($object->value);
                } elseif (preg_match('/^filename\*(\d+)(\*)?$/', $attr, $matches)) {
                    // Handle RFC 2231 continuation parameters
                    $attachment['is_attachment'] = true;
                    $continuationParts[$attr] = $object->value;
                }
            }
            
            // Process continuation parts for filename
            if (!empty($continuationParts)) {
                $attachment['filename'] = $this->processContinuationParameters($continuationParts);
            }
        }

        // Check for name in parameters
        if ($part->ifparameters) {
            $continuationParts = [];
            foreach ($part->parameters as $object) {
                $attr = strtolower($object->attribute);
                if ($attr == 'name' || $attr == 'name*') {
                    $attachment['is_attachment'] = true;
                    $attachment['name'] = $this->decodeUtf8String($object->value);
                } elseif (preg_match('/^name\*(\d+)(\*)?$/', $attr, $matches)) {
                    // Handle RFC 2231 continuation parameters
                    $attachment['is_attachment'] = true;
                    $continuationParts[$attr] = $object->value;
                }
            }
            
            // Process continuation parts for name
            if (!empty($continuationParts)) {
                $attachment['name'] = $this->processContinuationParameters($continuationParts);
            }
        }

        if (!$attachment['is_attachment']) {
            return null;
        }

        $att = new \stdClass();
        $att->name = $attachment['name'];
        $att->filename = $attachment['filename'];

        // Handle special cases
        $att = $this->handleSpecialCases($att);

        // If name is empty, use filename
        if (empty($att->name)) {
            $att->name = $att->filename;
        }
        // If filename is empty, use name
        if (empty($att->filename)) {
            $att->filename = $att->name;
        }

        // Determine file type from either name or filename
        $att->filetype = $this->determineFileType($att->name);
        if ($att->filetype === null) {
            $att->filetype = $this->determineFileType($att->filename);
        }
        if ($att->filetype === null) {
            if (empty($att->name)) {
                return null;
            }
            // No usable extension. Keep it anyway: an attachment we cannot type is
            // still something the entity sent us and must be visible in the thread.
            $att->filetype = 'UNKNOWN';
        }

        // MIME part number to fetch the content from. Not derivable from the index
        // in the returned array, since parts we skip leave gaps.
        $att->partNumber = $partNumber;

        return $att;
    }

    /**
     * A part of multipart/mixed after the body that has no name. Kept under a
     * generated name so it is stored and shown like any other attachment.
     */
    private function processUnnamedPart(object $part, int $partNumber): ?object {
        $type = $part->type ?? null;
        if ($type === null || $type === TYPEMULTIPART) {
            // A nested multipart is not one file.
            return null;
        }
        $types = [TYPETEXT => 'text', TYPEMESSAGE => 'message', TYPEAPPLICATION => 'application',
                  TYPEAUDIO => 'audio', TYPEIMAGE => 'image', TYPEVIDEO => 'video', TYPEMODEL => 'model'];
        $mimeType = ($types[$type] ?? 'application') . '/' . strtolower($part->subtype ?? 'octet-stream');

        $att = new \stdClass();
        $att->name = self::unnamedPartName($partNumber, $mimeType);
        $att->filename = $att->name;
        $att->filetype = self::extensionForMimeType($mimeType) ?? 'UNKNOWN';
        $att->partNumber = $partNumber;
        return $att;
    }

    /**
     * Name for an attachment without one: "unnamed-part-<n>.<ext>", or without
     * extension when the MIME type is not one we know. Also used by
     * AttachmentPartCheck to match stored rows to parts.
     */
    public static function unnamedPartName(int $partNumber, string $mimeType): string {
        $extension = self::extensionForMimeType($mimeType);
        return 'unnamed-part-' . $partNumber . ($extension === null ? '' : '.' . $extension);
    }

    private static function extensionForMimeType(string $mimeType): ?string {
        $map = [
            'application/pdf' => 'pdf',
            'message/rfc822' => 'eml',
            'text/plain' => 'txt',
            'text/html' => 'htm',
            'text/calendar' => 'ics',
            'text/csv' => 'csv',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/zip' => 'zip',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];
        return $map[strtolower($mimeType)] ?? null;
    }

    public function getAttachmentContent(int $uid, int $partNumber): string {
        $content = $this->connection->getFetchbody($uid, (string)$partNumber, FT_UID);
        $structure = $this->connection->getFetchstructure($uid, FT_UID);

        if ($structure->parts[$partNumber - 1]->encoding == 3) { // BASE64
            $content = base64_decode($content);
        } elseif ($structure->parts[$partNumber - 1]->encoding == 4) { // QUOTED-PRINTABLE
            $content = quoted_printable_decode($content);
        }
        elseif (in_array($structure->parts[$partNumber - 1]->encoding, [0, 1, 2], true)) {
            // 7BIT, 8BIT, BINARY: not transfer-encoded, the body is the content.
        }
        else {
            throw new \Exception('Unsupported encoding: ' . $structure->parts[$partNumber - 1]->encoding);
        }
        return $content;
    }

    /**
     * Decode UTF-8 string from IMAP
     */
    private function decodeUtf8String(string $string): string {
        // Handle RFC 2047 MIME encoded-words: =?charset?encoding?text?=
        // Examples: =?utf-8?B?...?=, =?iso-8859-1?Q?...?=, =?windows-1252?Q?...?=
        if (preg_match('/=\?[^?]+\?[BQbq]\?[^?]*\?=/i', $string)) {
            // iconv_mime_decode() implements RFC 2047 section 4.2 rule 2, where "_" in a
            // Q-encoded word means a space. mb_decode_mimeheader() leaves "_" as-is, which
            // turns "Klage_p=E5" into "Klage_på" instead of "Klage på".
            $decoded = iconv_mime_decode($string, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded === false) {
                $decoded = mb_decode_mimeheader($string);
            }

            // Ensure result is valid UTF-8
            if (!mb_check_encoding($decoded, 'UTF-8')) {
                $detected = mb_detect_encoding($decoded, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                $decoded = mb_convert_encoding($decoded, 'UTF-8', $detected ?: 'ISO-8859-1');
            }

            return $decoded;
        }

        // Handle RFC 2231 parameter encoding: charset''encoded%20text
        if (str_starts_with($string, "iso-8859-1''") ||
            str_starts_with($string, "ISO-8859-1''")) {
            $string = str_replace(["iso-8859-1''", "ISO-8859-1''"], '', $string);

            if (str_contains($string, '%20') ||
                str_contains($string, '%E6') ||
                str_contains($string, '%F8')) {

                $replacements = $this->getIso88591Replacements();
                foreach ($replacements as $from => $to) {
                    $string = str_replace($from, $to, $string);
                }
                $string = urldecode($string);
            }
        }

        return $string;
    }

    /**
     * Handle special cases for attachment names
     */
    private function handleSpecialCases(object $att): object {
        $specialCases = [
            // Stortingsvalg: encoded form (legacy) and decoded form (after RFC 2047 fix)
            '=?UTF-8?Q?Stortingsvalg_=2D_Valgstyrets=5Fm=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epdf?='
                => 'Stortingsvalg - Valgstyrets-møtebok-1806-2021.pdf',
            'Stortingsvalg - Valgstyrets_møtebok_1806_2021-09-29.pdf'
                => 'Stortingsvalg - Valgstyrets-møtebok-1806-2021.pdf',
            // Samtingsvalg: malformed encoded-word with split .pdf extension
            '=?UTF-8?Q?Samtingsvalg_=2D_Samevalgstyrets_m=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epd?=	f'
                => 'Samtingsvalg.pdf'
        ];

        foreach ($specialCases as $from => $to) {
            $att->name = str_replace($from, $to, $att->name);
            $att->filename = str_replace($from, $to, $att->filename);
        }

        return $att;
    }

    /**
     * Determine file type from filename
     */
    private function determineFileType(string $filename): ?string {
        if (empty($filename)) {
            return null;
        }

        $filename = strtolower($filename);
        
        // Handle special cases where filename might have spaces
        $filename = str_replace(['. pdf', '.p df', '.pd f'], '.pdf', $filename);

        // Get the file extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array($extension, $this->supportedTypes)) {
            return $extension;
        }

        // Special cases where we return UNKNOWN
        if (str_starts_with($filename, 'Valgstyrets_møtebok_4649_2021-11-18') ||
            str_starts_with($filename, 'Outlook-kvafjord k') ||
            str_ends_with($filename, '.rda')) {
            return 'UNKNOWN';
        }

        // Any other plain extension (.xls, .jpeg, .odt, .msg, .webp, ...) is kept as
        // the type, so the attachment is stored and shown instead of dropped.
        if (preg_match('/^[a-z0-9]{1,16}$/', $extension)) {
            return $extension;
        }

        return null;
    }

    /**
     * Process RFC 2231 continuation parameters
     */
    private function processContinuationParameters(array $continuationParts): string {
        // Sort by part number
        ksort($continuationParts);
        
        $result = implode('', $continuationParts);
        $result = $this->decodeUtf8String($result);
        return $result;
    }

    /**
     * Get ISO-8859-1 character replacements
     */
    private function getIso88591Replacements(): array {
        return [
            '%E6' => 'æ', '%C0' => 'À', '%C1' => 'Á', '%C2' => 'Â',
            '%C3' => 'Ã', '%C4' => 'Ä', '%C5' => 'Å', '%C6' => 'Æ',
            '%C7' => 'Ç', '%C8' => 'È', '%C9' => 'É', '%CA' => 'Ê',
            '%CB' => 'Ë', '%CC' => 'Ì', '%CD' => 'Í', '%CE' => 'Î',
            '%CF' => 'Ï', '%D0' => 'Ð', '%D1' => 'Ñ', '%D2' => 'Ò',
            '%D3' => 'Ó', '%D4' => 'Ô', '%D5' => 'Õ', '%D6' => 'Ö',
            '%D8' => 'Ø', '%D9' => 'Ù', '%DA' => 'Ú', '%DB' => 'Û',
            '%DC' => 'Ü', '%DD' => 'Ý', '%DE' => 'Þ', '%DF' => 'ß',
            '%E0' => 'à', '%E1' => 'á', '%E2' => 'â', '%E3' => 'ã',
            '%E4' => 'ä', '%E5' => 'å', '%E6' => 'æ', '%E7' => 'ç',
            '%E8' => 'è', '%E9' => 'é', '%EA' => 'ê', '%EB' => 'ë',
            '%EC' => 'ì', '%ED' => 'í', '%EE' => 'î', '%EF' => 'ï',
            '%F0' => 'ð', '%F1' => 'ñ', '%F2' => 'ò', '%F3' => 'ó',
            '%F4' => 'ô', '%F5' => 'õ', '%F6' => 'ö', '%F8' => 'ø',
            '%F9' => 'ù', '%FA' => 'ú', '%FB' => 'û', '%FC' => 'ü',

            '%A7' => '§',
        ];
    }
}
