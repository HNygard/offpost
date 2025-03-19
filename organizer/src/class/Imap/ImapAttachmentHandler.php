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

        $attachments = [];
        for ($i = 0; $i < count($structure->parts); $i++) {
            $attachment = $this->processAttachmentPart($uid, $structure->parts[$i], $i + 1);
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
            foreach ($part->dparameters as $object) {
                if (strtolower($object->attribute) == 'filename' || 
                    strtolower($object->attribute) == 'filename*') {
                    $attachment['is_attachment'] = true;
                    $attachment['filename'] = $this->decodeUtf8String($object->value);
                }
            }
        }

        // Check for name in parameters
        if ($part->ifparameters) {
            foreach ($part->parameters as $object) {
                if (strtolower($object->attribute) == 'name' || 
                    strtolower($object->attribute) == 'name*') {
                    $attachment['is_attachment'] = true;
                    $attachment['name'] = $this->decodeUtf8String($object->value);
                }
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
            return null;
        }

        return $att;
    }

    public function getAttachmentContent(int $uid, int $partNumber): string {
        $content = $this->connection->getFetchbody($uid, (string)$partNumber, FT_UID);
        $structure = $this->connection->getFetchstructure($uid, FT_UID);

        if ($structure->parts[$partNumber - 1]->encoding == 3) { // BASE64
            $content = base64_decode($content);
        } elseif ($structure->parts[$partNumber - 1]->encoding == 4) { // QUOTED-PRINTABLE
            $content = quoted_printable_decode($content);
        }
        else {
            throw new \Exception('Unsupported encoding: ' . $structure->parts[$partNumber - 1]->encoding);
        }
        return $content;
    }


    /**
     * Save attachment to file
     */
    public function saveAttachment(int $uid, int $partNumber, object $attachment, string $savePath): void {
        $content = $this->getAttachmentContent($uid, $partNumber);
        $this->connection->logDebug("Saving attachment to: $savePath");
        file_put_contents($savePath, $content);
        chmod($savePath, 0777);
    }

    /**
     * Decode UTF-8 string from IMAP
     */
    private function decodeUtf8String(string $string): string {
        // If it's a plain string without any encoding markers, return as is
        if (!str_starts_with($string, "iso-8859-1''") && 
            !str_starts_with($string, "ISO-8859-1''") &&
            !preg_match('/(\=\?utf\-8\?B\?[A-Za-z0-9=]*\?=)/', $string)) {
            return $string;
        }

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

        // Handle Base64 encoded UTF-8 strings
        if (preg_match_all('/(\=\?utf\-8\?B\?[A-Za-z0-9=]*\?=)/', $string, $matches)) {
            foreach ($matches[0] as $match) {
                $string = str_replace($match, $this->connection->utf8($match), $string);
            }
            return $this->connection->utf8($string);
        }

        return $string;
    }

    /**
     * Handle special cases for attachment names
     */
    private function handleSpecialCases(object $att): object {
        $specialCases = [
            '=?UTF-8?Q?Stortingsvalg_=2D_Valgstyrets=5Fm=C3=B8tebok=5F1806=5F2021=2D09=2D29=2Epdf?=' 
                => 'Stortingsvalg - Valgstyrets-møtebok-1806-2021.pdf',
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

        return null;
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
            '%F9' => 'ù', '%FA' => 'ú', '%FB' => 'û', '%FC' => 'ü'
        ];
    }
}
