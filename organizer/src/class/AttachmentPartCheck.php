<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Extraction/MimeEmailReader.php';
require_once __DIR__ . '/Imap/ImapAttachmentHandler.php';

use ZBateson\MailMimeParser\Message;

/**
 * Compares the attachments stored for an email with the top-level MIME parts of
 * its raw EML.
 *
 * Before the partNumber fix, ThreadEmailDatabaseSaver fetched attachment content
 * by the position among *kept* attachments, and parts of unknown types (e.g.
 * .webp) were skipped. A skipped part shifted every later attachment, which then
 * got the bytes of the part before it. This finds those rows (WRONG_CONTENT) and
 * the named parts that were never stored (MISSING). Read-only.
 *
 * Part numbers follow imap_fetchstructure(): the n-th direct child of the root
 * multipart is part n.
 */
class AttachmentPartCheck {
    public const OK = 'ok';
    public const WRONG_CONTENT = 'wrong_content';
    public const STORED_CONTENT_MISSING = 'stored_content_missing';
    public const NO_MATCHING_PART = 'no_matching_part';
    public const MISSING = 'missing';

    /**
     * Top-level parts that ImapAttachmentHandler stores, keyed by IMAP part
     * number: those with a filename, and in multipart/mixed the parts after the
     * body without one, under ImapAttachmentHandler::unnamedPartName().
     *
     * @return array<int, array{filename: string, content: string}>
     */
    public static function namedParts(string $eml): array {
        $message = Message::from(MimeEmailReader::repairEncodedWordsInHeaders($eml), false);
        $isMixed = strtolower((string)$message->getContentType()) === 'multipart/mixed';
        $parts = [];
        foreach (array_values($message->getChildParts()) as $i => $child) {
            $filename = $child->getFilename();
            if ($filename === null || $filename === '') {
                if (!$isMixed || $i === 0 || $child->isMultiPart()) {
                    continue;
                }
                $filename = \Imap\ImapAttachmentHandler::unnamedPartName($i + 1, (string)$child->getContentType());
            }
            $stream = $child->getBinaryContentStream();
            $parts[$i + 1] = [
                'filename' => $filename,
                'content' => $stream === null ? '' : $stream->getContents(),
            ];
        }
        return $parts;
    }

    /**
     * @param array $stored list of ['id', 'name', 'filename', 'content' (string|null)]
     *                      in the order they were saved
     * @return array list of ['status', 'attachment_id', 'name', 'part_number', 'content_matches_part'];
     *               one per stored attachment (same order), then one per MISSING part
     */
    public static function check(string $eml, array $stored): array {
        $parts = self::namedParts($eml);
        $claimed = [];
        $findings = [];

        foreach ($stored as $att) {
            $content = $att['content'];
            $contentMatchesPart = null;
            if ($content !== null) {
                foreach ($parts as $partNumber => $part) {
                    if ($part['content'] === $content) {
                        $contentMatchesPart = $partNumber;
                        break;
                    }
                }
            }

            // Parts with the same name, unclaimed ones first so duplicate names
            // (image001.png twice) pair up one to one.
            $candidates = [];
            foreach ($parts as $partNumber => $part) {
                if ($part['filename'] === $att['name'] || $part['filename'] === $att['filename']) {
                    $candidates[] = $partNumber;
                }
            }
            $unclaimed = array_values(array_diff($candidates, $claimed));

            if (empty($candidates)) {
                $status = self::NO_MATCHING_PART;
                $partNumber = null;
            }
            elseif ($content === null) {
                $status = self::STORED_CONTENT_MISSING;
                $partNumber = $unclaimed[0] ?? $candidates[0];
            }
            elseif ($contentMatchesPart !== null && in_array($contentMatchesPart, $candidates, true)) {
                $status = self::OK;
                $partNumber = $contentMatchesPart;
            }
            else {
                $status = self::WRONG_CONTENT;
                $partNumber = $unclaimed[0] ?? $candidates[0];
            }

            if ($partNumber !== null) {
                $claimed[] = $partNumber;
            }
            $findings[] = [
                'status' => $status,
                'attachment_id' => $att['id'],
                'name' => $att['name'],
                'part_number' => $partNumber,
                'content_matches_part' => $contentMatchesPart,
            ];
        }

        foreach ($parts as $partNumber => $part) {
            if (in_array($partNumber, $claimed, true)) {
                continue;
            }
            $findings[] = [
                'status' => self::MISSING,
                'attachment_id' => null,
                'name' => $part['filename'],
                'part_number' => $partNumber,
                'content_matches_part' => null,
            ];
        }

        return $findings;
    }
}
