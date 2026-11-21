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
 *
 * Names are never changed (NAME_DIFFERS has the right bytes), and rows that match
 * no part (NO_MATCHING_PART) are left alone. When anything was changed, one
 * thread_email_history entry (HISTORY_ACTION) lists what. Does not open a
 * transaction: the caller wraps each email in one.
 */
class AttachmentPartRepair {
    const HISTORY_ACTION = 'attachments_repaired';
    const HISTORY_USER_ID = 'attachment-part-repair';

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

        foreach ($findings as $f) {
            if ($f['status'] === AttachmentPartCheck::WRONG_CONTENT
                || $f['status'] === AttachmentPartCheck::STORED_CONTENT_MISSING) {
                $actions[] = "fix_content attachment={$f['attachment_id']} part={$f['part_number']} {$f['name']}";
                if ($apply) {
                    self::fixContent($emailId, $f['attachment_id'], $parts[$f['part_number']]['content']);
                    $fixed[] = ['attachment_id' => $f['attachment_id'], 'name' => $f['name'], 'part' => $f['part_number']];
                }
            }
            elseif ($f['status'] === AttachmentPartCheck::MISSING) {
                $actions[] = "add_missing part={$f['part_number']} {$f['name']}";
                if ($apply) {
                    $attachmentId = self::addMissing($emailId, $f['part_number'], $parts[$f['part_number']]);
                    $added[] = ['attachment_id' => $attachmentId, 'name' => $f['name'], 'part' => $f['part_number']];
                }
            }
        }

        if (!empty($fixed) || !empty($added)) {
            $threadId = Database::queryValue("SELECT thread_id FROM thread_emails WHERE id = ?", [$emailId]);
            (new ThreadEmailHistory())->logAction($threadId, $emailId, self::HISTORY_ACTION, self::HISTORY_USER_ID, [
                'fixed' => $fixed,
                'added' => $added,
            ]);
        }
        return $actions;
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
