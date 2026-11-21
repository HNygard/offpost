<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AttachmentPartCheck.php';
require_once __DIR__ . '/ThreadEmailAttachment.php';
require_once __DIR__ . '/ThreadEmailHistory.php';
require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

/**
 * Repairs what AttachmentPartCheck found for one email, from its raw EML:
 *
 * - WRONG_CONTENT / STORED_CONTENT_MISSING: the row gets the bytes (and size) of
 *   its own part, and its extractions are deleted. They were made from the wrong
 *   bytes; the extractors pick the attachment up again.
 * - MISSING: the part is inserted as a new attachment, like ThreadEmailDatabaseSaver
 *   would have stored it.
 * - DUPLICATE: only when this repair itself added the extra copy (an earlier run's
 *   check paired both copies of a twice-attached file with one part and added a third),
 *   that copy is removed again. Duplicates that existed before are left alone.
 *
 * Names are never changed (NAME_DIFFERS has the right bytes), and rows that match
 * no part (NO_MATCHING_PART) are left alone. When anything was changed, one
 * thread_email_history entry (HISTORY_ACTION) lists what. Does not open a
 * transaction: the caller wraps each email in one.
 */
class AttachmentPartRepair {
    const HISTORY_ACTION = 'attachments_repaired';
    const HISTORY_USER_ID = 'attachment-part-repair';

    // Identifies this repair in thread_email_history.details, since other attachment
    // repairs may use the same action later.
    const REPAIR_ID = 'attachment-mime-part-2026-11';
    // Short, non-technical; this is what the history shows.
    const SUMMARY = 'Some attachments on this email showed the wrong file or were missing. '
        . 'They have been restored from the original email.';
    // For an entry that only removes copies this repair added by mistake.
    const SUMMARY_REMOVED_ONLY = 'An extra copy of an attachment, added by mistake during an earlier '
        . 'correction, has been removed.';
    // The technical explanation, for whoever looks into the details.
    const REASON = 'Offpost stored the content of each attachment by its position among the attachments it '
        . 'recognised, not by its MIME part. An attachment it did not recognise (a type such as .webp, '
        . 'or no name) was skipped and never stored, and every later attachment in the same email got '
        . 'the content of the part before it. Fixed 2026-11-20. Separately, some .zip attachments were '
        . 'already empty when attachments moved from files to the database (2025-02-08). The content is '
        . 'restored from the raw email Offpost has stored.';

    /**
     * @param array $findings AttachmentPartCheck::check() for this email
     * @param bool $apply false: only return what would be done
     * @return string[] one line per action, done or planned
     */
    public static function repairEmail(string $emailId, string $eml, array $findings, bool $apply): array {
        $parts = AttachmentPartCheck::namedParts($eml);
        $actions = [];
        $fixed = [];
        $added = [];
        $removed = [];

        foreach ($findings as $f) {
            if ($f['status'] === AttachmentPartCheck::WRONG_CONTENT
                || $f['status'] === AttachmentPartCheck::STORED_CONTENT_MISSING) {
                $actions[] = "fix_content attachment={$f['attachment_id']} part={$f['part_number']} {$f['name']}";
                if ($apply) {
                    $problem = self::contentProblem($f, $parts);
                    self::fixContent($emailId, $f['attachment_id'], $parts[$f['part_number']]['content']);
                    $fixed[] = [
                        'attachment_id' => $f['attachment_id'],
                        'name' => $f['name'],
                        'part' => $f['part_number'],
                        'problem' => $problem,
                    ];
                }
            }
            elseif ($f['status'] === AttachmentPartCheck::MISSING) {
                $actions[] = "add_missing part={$f['part_number']} {$f['name']}";
                if ($apply) {
                    $attachmentId = self::addMissing($emailId, $f['part_number'], $parts[$f['part_number']]);
                    $added[] = [
                        'attachment_id' => $attachmentId,
                        'name' => $f['name'],
                        'part' => $f['part_number'],
                        'problem' => 'was never stored',
                    ];
                }
            }
        }

        // Extra copies this repair added in an earlier run. Identical copies are
        // interchangeable, so the check may label an original copy as the duplicate:
        // per group of rows with the same name and bytes, remove as many of our own
        // added copies as the group has duplicates.
        $ownAdded = self::idsAddedByThisRepair($emailId);
        $groups = [];
        foreach ($findings as $f) {
            if (($f['status'] === AttachmentPartCheck::OK || $f['status'] === AttachmentPartCheck::DUPLICATE)
                && $f['part_number'] !== null) {
                $key = $f['name'] . "\0" . md5($parts[$f['part_number']]['content']);
                $groups[$key][] = $f;
            }
        }
        foreach ($groups as $group) {
            $extra = count(array_filter($group, fn($f) => $f['status'] === AttachmentPartCheck::DUPLICATE));
            foreach ($group as $f) {
                if ($extra === 0) {
                    break;
                }
                if (!in_array($f['attachment_id'], $ownAdded, true)) {
                    continue;
                }
                $extra--;
                $actions[] = "remove_own_duplicate attachment={$f['attachment_id']} {$f['name']}";
                if ($apply) {
                    Database::execute("DELETE FROM thread_email_extractions WHERE attachment_id = ?", [$f['attachment_id']]);
                    Database::execute(
                        "DELETE FROM thread_email_attachments WHERE id = ? AND email_id = ?",
                        [$f['attachment_id'], $emailId]
                    );
                    $removed[] = [
                        'attachment_id' => $f['attachment_id'],
                        'name' => $f['name'],
                        'part' => null,
                        'problem' => 'was added twice by an earlier run of this repair',
                    ];
                }
            }
        }

        if (!empty($fixed) || !empty($added) || !empty($removed)) {
            $threadId = Database::queryValue("SELECT thread_id FROM thread_emails WHERE id = ?", [$emailId]);
            (new ThreadEmailHistory())->logAction($threadId, $emailId, self::HISTORY_ACTION, self::HISTORY_USER_ID, [
                'repair' => self::REPAIR_ID,
                'summary' => (empty($fixed) && empty($added)) ? self::SUMMARY_REMOVED_ONLY : self::SUMMARY,
                'reason' => self::REASON,
                'fixed' => $fixed,
                'added' => $added,
                'removed' => $removed,
            ]);
        }
        return $actions;
    }

    /**
     * @return string[] attachment ids this repair (REPAIR_ID) has added to the email
     */
    private static function idsAddedByThisRepair(string $emailId): array {
        $ids = [];
        $rows = Database::query(
            "SELECT details FROM thread_email_history WHERE email_id = ? AND action = ?",
            [$emailId, self::HISTORY_ACTION]
        );
        foreach ($rows as $row) {
            $details = json_decode($row['details'], true);
            if (($details['repair'] ?? null) !== self::REPAIR_ID) {
                continue;
            }
            foreach ($details['added'] ?? [] as $item) {
                $ids[] = $item['attachment_id'];
            }
        }
        return $ids;
    }

    /**
     * What was wrong with a stored attachment, read before its content is replaced.
     */
    private static function contentProblem(array $finding, array $parts): string {
        if ($finding['status'] === AttachmentPartCheck::STORED_CONTENT_MISSING) {
            return 'had no content';
        }
        $heldPart = $finding['content_matches_part'];
        if ($heldPart !== null) {
            return "held the content of part $heldPart ({$parts[$heldPart]['filename']})";
        }
        $storedLength = Database::queryValue(
            "SELECT octet_length(content) FROM thread_email_attachments WHERE id = ?",
            [$finding['attachment_id']]
        );
        return (int)$storedLength === 0 ? 'was empty' : 'matched no part of the email';
    }

    private static function fixContent(string $emailId, string $attachmentId, string $content): void {
        $updated = Database::queryValueWithBinaryParam(
            "UPDATE thread_email_attachments SET content = :content, size = :size
             WHERE id = :id AND email_id = :email_id
             RETURNING id",
            [':size' => strlen($content), ':id' => $attachmentId, ':email_id' => $emailId],
            [':content' => $content]
        );
        if ($updated === null) {
            throw new Exception("Attachment $attachmentId of email $emailId not found");
        }
        Database::execute(
            "DELETE FROM thread_email_extractions WHERE attachment_id = ?",
            [$attachmentId]
        );
    }

    private static function addMissing(string $emailId, int $partNumber, array $part): string {
        $idOld = (string)Database::queryValue("SELECT id_old FROM thread_emails WHERE id = ?", [$emailId]);
        $name = $part['filename'];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $filetype = preg_match('/^[a-z0-9]{1,16}$/', $extension) ? $extension : 'UNKNOWN';

        return Database::queryValueWithBinaryParam(
            "INSERT INTO thread_email_attachments
                (email_id, name, filename, filetype, location, status_type, status_text, content, size)
             VALUES (:email_id, :name, :filename, :filetype, :location, :status_type, :status_text, :content, :size)
             RETURNING id",
            [
                ':email_id' => $emailId,
                ':name' => $name,
                ':filename' => $name,
                ':filetype' => $filetype,
                ':location' => $idOld . ' - att ' . $partNumber . '-' . md5($name) . '.' . $filetype,
                ':status_type' => ThreadEmailStatusType::UNKNOWN->value,
                ':status_text' => ThreadEmailAttachment::UNCLASSIFIED_STATUS_TEXT,
                ':size' => strlen($part['content']),
            ],
            [':content' => $part['content']]
        );
    }
}
