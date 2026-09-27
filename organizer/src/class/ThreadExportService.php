<?php
// organizer/src/class/ThreadExportService.php
// Builds the JSON payloads for the admin thread-export API
// (organizer/src/api/admin/export_threads_list.php and export_thread_get.php).
// See docs/superpowers/plans/2026-09-27-step1-thread-export-api.md.

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Entity.php';
require_once __DIR__ . '/ThreadUtils.php';
require_once __DIR__ . '/Extraction/ThreadEmailExtractorEmailBody.php';
require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

class ThreadExportService {
    const EXPORT_VERSION = 1;

    /**
     * SQL for the single instant behind both `last_changed_at` and the
     * fingerprint: the newest of the thread's own updated_at, its emails,
     * their extractions, its sendings, and both history tables. Kept as one
     * fragment so listThreads() and exportThread() can never drift apart.
     * Assumes the query aliases the threads row as `t`.
     */
    private static function latestActivitySql(): string {
        return "
            GREATEST(
                t.updated_at,
                COALESCE((SELECT MAX(created_at) FROM thread_emails WHERE thread_id = t.id), t.updated_at),
                COALESCE(
                    (SELECT MAX(tee.updated_at) FROM thread_email_extractions tee
                        JOIN thread_emails te ON te.id = tee.email_id WHERE te.thread_id = t.id),
                    t.updated_at
                ),
                COALESCE((SELECT MAX(updated_at) FROM thread_email_sendings WHERE thread_id = t.id), t.updated_at),
                COALESCE((SELECT MAX(created_at) FROM thread_history WHERE thread_id = t.id), t.updated_at),
                COALESCE((SELECT MAX(created_at) FROM thread_email_history WHERE thread_id = t.id), t.updated_at)
            )
        ";
    }

    /**
     * SQL for the per-email ingredient of the fingerprint: `id|status_type|
     * auto_classification|ignore` for every email of the thread, so a
     * classification change is picked up even when it moves no timestamp.
     * Assumes the query aliases the threads row as `t`.
     */
    private static function emailFingerprintPartSql(): string {
        return "
            COALESCE((
                SELECT string_agg(
                    te.id::text || '|' || COALESCE(te.status_type, '') || '|' ||
                    COALESCE(te.auto_classification, '') || '|' || te.ignore::text,
                    ',' ORDER BY te.id
                )
                FROM thread_emails te WHERE te.thread_id = t.id
            ), '')
        ";
    }

    /**
     * The two raw ingredients the fingerprint is computed from, selected once
     * so listThreads() (all threads) and exportThread() (one thread) run the
     * exact same SQL and then the exact same PHP formatting (computeFingerprint()).
     */
    private static function fingerprintIngredientsSelectSql(): string {
        return self::latestActivitySql() . " AS latest_activity, "
            . self::emailFingerprintPartSql() . " AS email_fingerprint_part";
    }

    private static function computeFingerprint(string $latestActivityRaw, string $emailFingerprintPart): string {
        $latest = new DateTime($latestActivityRaw);
        $latest->setTimezone(new DateTimeZone('UTC'));
        return md5($latest->format('Y-m-d H:i:s.u') . '|' . $emailFingerprintPart);
    }

    private static function isoTimestamp(?string $raw): ?string {
        if ($raw === null) {
            return null;
        }
        return (new DateTime($raw))->format(DateTimeInterface::ATOM);
    }

    private static function toBool($value): bool {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    /**
     * bytea columns come back from PDO pgsql as a stream resource (see
     * ThreadStorageManager::getThreadEmailContent()); handle the hex-string
     * fallback too, as ThreadDatabaseOperations::getThreadEmailAttachment() does.
     */
    private static function byteaToString($value): string {
        if ($value === null) {
            return '';
        }
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (is_string($value) && substr($value, 0, 2) === '\\x') {
            return hex2bin(substr($value, 2));
        }
        return (string) $value;
    }

    private static function decodeLabels(?string $labelsJson): array {
        if ($labelsJson === null) {
            return [];
        }
        $decoded = json_decode($labelsJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * `none` when status_type is unset/unknown; otherwise the auto_classification
     * value ('algo'/'prompt') if one is set; otherwise 'manual'.
     */
    private static function classificationSource(?string $statusType, ?string $autoClassification): string {
        if ($statusType === null || $statusType === ThreadEmailStatusType::UNKNOWN->value) {
            return 'none';
        }
        if ($autoClassification === 'algo' || $autoClassification === 'prompt') {
            return $autoClassification;
        }
        return 'manual';
    }

    /**
     * Every thread (archived included), ordered by created_at, as one query.
     *
     * @return array<int, array{id: string, title: ?string, entity_id: string,
     *   labels: array, email_count: int, last_changed_at: string, fingerprint: string}>
     */
    public static function listThreads(): array {
        $sql = "
            SELECT
                t.id,
                t.title,
                t.entity_id,
                COALESCE(array_to_json(t.labels)::text, '[]') AS labels_json,
                (SELECT COUNT(*) FROM thread_emails WHERE thread_id = t.id) AS email_count,
                " . self::fingerprintIngredientsSelectSql() . "
            FROM threads t
            ORDER BY t.created_at
        ";
        $rows = Database::query($sql);

        $threads = [];
        foreach ($rows as $row) {
            $threads[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'entity_id' => $row['entity_id'],
                'labels' => self::decodeLabels($row['labels_json']),
                'email_count' => (int) $row['email_count'],
                'last_changed_at' => self::isoTimestamp($row['latest_activity']),
                'fingerprint' => self::computeFingerprint($row['latest_activity'], $row['email_fingerprint_part']),
            ];
        }
        return $threads;
    }

    /**
     * Full export of one thread, or null when the id does not exist.
     */
    public static function exportThread(string $threadId): ?array {
        $threadRow = Database::queryOneOrNone(
            "SELECT t.*, COALESCE(array_to_json(t.labels)::text, '[]') AS labels_json, "
                . self::fingerprintIngredientsSelectSql() . "
             FROM threads t WHERE t.id = ?",
            [$threadId]
        );
        if ($threadRow === null) {
            return null;
        }

        $fingerprint = self::computeFingerprint($threadRow['latest_activity'], $threadRow['email_fingerprint_part']);

        $entity = null;
        try {
            $e = Entity::getById($threadRow['entity_id']);
            $entity = [
                'entity_id' => $e->entity_id,
                'name' => $e->name,
                'email' => $e->email,
                'type' => $e->type,
                'org_num' => $e->org_num,
                'entity_id_norske_postlister' => $e->entity_id_norske_postlister,
            ];
        } catch (Throwable $ex) {
            $entity = null;
        }

        return [
            'export_version' => self::EXPORT_VERSION,
            'exported_at' => (new DateTime())->format(DateTimeInterface::ATOM),
            'fingerprint' => $fingerprint,
            'thread' => [
                'id' => $threadRow['id'],
                'entity_id' => $threadRow['entity_id'],
                'title' => $threadRow['title'],
                'my_name' => $threadRow['my_name'],
                'my_email' => $threadRow['my_email'],
                'labels' => self::decodeLabels($threadRow['labels_json']),
                'sent' => self::toBool($threadRow['sent']),
                'archived' => self::toBool($threadRow['archived']),
                'public' => self::toBool($threadRow['public']),
                'sent_comment' => $threadRow['sent_comment'],
                'sending_status' => $threadRow['sending_status'],
                'initial_request' => $threadRow['initial_request'],
                'request_law_basis' => $threadRow['request_law_basis'],
                'request_follow_up_plan' => $threadRow['request_follow_up_plan'],
                'created_at' => self::isoTimestamp($threadRow['created_at']),
                'updated_at' => self::isoTimestamp($threadRow['updated_at']),
            ],
            'entity' => $entity,
            'emails' => self::exportEmails($threadId),
            'sendings' => self::exportSendings($threadId),
            'history' => self::exportThreadHistory($threadId),
        ];
    }

    private static function exportEmails(string $threadId): array {
        $emailRows = Database::query(
            "SELECT id, email_type, datetime_received, timestamp_received, created_at, ignore,
                    status_type, status_text, auto_classification, description, answer,
                    imap_headers, content, thread_state, thread_state_type, thread_state_source
             FROM thread_emails
             WHERE thread_id = ?
             ORDER BY datetime_received, id",
            [$threadId]
        );

        $emails = [];
        foreach ($emailRows as $row) {
            $imapHeadersRaw = $row['imap_headers'];
            $rawEml = self::byteaToString($row['content']);

            $bodyPlain = null;
            $bodyHtml = null;
            $bodyParseError = null;
            try {
                $extracted = ThreadEmailExtractorEmailBody::extractContentFromEmail($rawEml);
                $bodyPlain = $extracted->plain_text;
                $bodyHtml = $extracted->html;
            } catch (Throwable $e) {
                $bodyParseError = $e->getMessage();
            }

            $emails[] = [
                'id' => $row['id'],
                'email_type' => $row['email_type'],
                'datetime_received' => self::isoTimestamp($row['datetime_received']),
                'timestamp_received' => self::isoTimestamp($row['timestamp_received']),
                'created_at' => self::isoTimestamp($row['created_at']),
                'ignore' => self::toBool($row['ignore']),
                'status_type' => $row['status_type'],
                'status_text' => $row['status_text'],
                'auto_classification' => $row['auto_classification'],
                'classification_source' => self::classificationSource($row['status_type'], $row['auto_classification']),
                'description' => $row['description'],
                'answer' => $row['answer'],
                'thread_state' => $row['thread_state'] !== null ? json_decode($row['thread_state'], true) : null,
                'thread_state_type' => $row['thread_state_type'],
                'thread_state_source' => $row['thread_state_source'],
                'subject' => $imapHeadersRaw !== null ? getEmailSubjectFromImapHeaders($imapHeadersRaw) : null,
                'from' => $imapHeadersRaw !== null ? getEmailFromAddressFromImapHeaders($imapHeadersRaw) : null,
                'to' => $imapHeadersRaw !== null ? getEmailToAddressesFromImapHeaders($imapHeadersRaw) : [],
                'cc' => $imapHeadersRaw !== null ? getEmailCcAddressesFromImapHeaders($imapHeadersRaw) : [],
                'imap_headers' => $imapHeadersRaw !== null ? json_decode($imapHeadersRaw, true) : null,
                'body_plain' => $bodyPlain,
                'body_html' => $bodyHtml,
                'body_parse_error' => $bodyParseError,
                'eml_base64' => base64_encode($rawEml),
                'extractions' => self::exportExtractions(emailId: $row['id'], attachmentId: null),
                'attachments' => self::exportAttachments($row['id']),
                'history' => self::exportEmailHistory($threadId, $row['id']),
            ];
        }
        return $emails;
    }

    private static function exportExtractions(string $emailId, ?string $attachmentId): array {
        if ($attachmentId === null) {
            $rows = Database::query(
                "SELECT extraction_id, prompt_id, prompt_service, prompt_text, extracted_text,
                        error_message, created_at, updated_at
                 FROM thread_email_extractions
                 WHERE email_id = ? AND attachment_id IS NULL
                 ORDER BY created_at, extraction_id",
                [$emailId]
            );
        } else {
            $rows = Database::query(
                "SELECT extraction_id, prompt_id, prompt_service, prompt_text, extracted_text,
                        error_message, created_at, updated_at
                 FROM thread_email_extractions
                 WHERE attachment_id = ?
                 ORDER BY created_at, extraction_id",
                [$attachmentId]
            );
        }

        $extractions = [];
        foreach ($rows as $row) {
            $extractions[] = [
                'extraction_id' => $row['extraction_id'],
                'prompt_id' => $row['prompt_id'],
                'prompt_service' => $row['prompt_service'],
                'prompt_text' => $row['prompt_text'],
                'extracted_text' => $row['extracted_text'],
                'error_message' => $row['error_message'],
                'created_at' => self::isoTimestamp($row['created_at']),
                'updated_at' => self::isoTimestamp($row['updated_at']),
            ];
        }
        return $extractions;
    }

    private static function exportAttachments(string $emailId): array {
        $rows = Database::query(
            "SELECT id, name, filename, filetype, size, location, status_type, status_text, created_at
             FROM thread_email_attachments
             WHERE email_id = ?
             ORDER BY created_at, id",
            [$emailId]
        );

        $attachments = [];
        foreach ($rows as $row) {
            $attachments[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'filename' => $row['filename'],
                'filetype' => $row['filetype'],
                'size' => $row['size'] !== null ? (int) $row['size'] : null,
                'location' => $row['location'],
                'status_type' => $row['status_type'],
                'status_text' => $row['status_text'],
                'created_at' => self::isoTimestamp($row['created_at']),
                'extractions' => self::exportExtractions(emailId: '', attachmentId: $row['id']),
            ];
        }
        return $attachments;
    }

    private static function exportEmailHistory(string $threadId, string $emailId): array {
        $rows = Database::query(
            "SELECT id, thread_id, email_id, action, user_id, details, created_at
             FROM thread_email_history
             WHERE thread_id = ? AND email_id = ?
             ORDER BY created_at, id",
            [$threadId, $emailId]
        );

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'id' => $row['id'],
                'thread_id' => $row['thread_id'],
                'email_id' => $row['email_id'],
                'action' => $row['action'],
                'user_id' => $row['user_id'],
                'details' => $row['details'] !== null ? json_decode($row['details'], true) : null,
                'created_at' => self::isoTimestamp($row['created_at']),
            ];
        }
        return $history;
    }

    private static function exportSendings(string $threadId): array {
        $rows = Database::query(
            "SELECT id, thread_id, email_content, email_subject, email_to, email_from, email_from_name,
                    status, smtp_response, smtp_debug, error_message, created_at, updated_at
             FROM thread_email_sendings
             WHERE thread_id = ?
             ORDER BY created_at, id",
            [$threadId]
        );

        $sendings = [];
        foreach ($rows as $row) {
            $sendings[] = [
                'id' => $row['id'],
                'thread_id' => $row['thread_id'],
                'email_content' => $row['email_content'],
                'email_subject' => $row['email_subject'],
                'email_to' => $row['email_to'],
                'email_from' => $row['email_from'],
                'email_from_name' => $row['email_from_name'],
                'status' => $row['status'],
                'smtp_response' => $row['smtp_response'],
                'smtp_debug' => $row['smtp_debug'],
                'error_message' => $row['error_message'],
                'created_at' => self::isoTimestamp($row['created_at']),
                'updated_at' => self::isoTimestamp($row['updated_at']),
            ];
        }
        return $sendings;
    }

    private static function exportThreadHistory(string $threadId): array {
        $rows = Database::query(
            "SELECT id, thread_id, action, user_id, details, created_at
             FROM thread_history
             WHERE thread_id = ?
             ORDER BY created_at, id",
            [$threadId]
        );

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'id' => $row['id'],
                'thread_id' => $row['thread_id'],
                'action' => $row['action'],
                'user_id' => $row['user_id'],
                'details' => $row['details'] !== null ? json_decode($row['details'], true) : null,
                'created_at' => self::isoTimestamp($row['created_at']),
            ];
        }
        return $history;
    }
}
