<?php
// organizer/src/class/NpApiService.php
require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadStorageManager.php';
require_once __DIR__ . '/ThreadEmailSending.php';
require_once __DIR__ . '/ThreadStatusRepository.php';
require_once __DIR__ . '/ThreadUtils.php';
require_once __DIR__ . '/Entity.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/random-profile.php';

class NpApiEntityNotFoundException extends Exception {}
class NpApiValidationException extends Exception {}
class NpApiCapExceededException extends Exception {}

class NpApiService {
    const THREAD_OWNER_USER_ID = 'norske-postlister-api';
    const DAILY_CAP = 100;
    const NP_LABEL = 'norske_postlister_no';

    /** @var ?int test hook */
    public static $dailyCapOverride = null;

    /** @var bool test hook: throw a non-Exception Throwable mid-transaction to verify rollback */
    public static $forceThrowableForTest = false;

    public static function createThread(string $npEntityId, string $title, string $body, array $labels): array {
        $labels = array_values(array_filter(array_map('trim', $labels), fn($l) => $l !== ''));

        if (trim($title) === '' || trim($body) === '') {
            throw new NpApiValidationException('title and body are required');
        }
        $mappingLabel = self::mappingLabel($labels);
        if ($mappingLabel === null) {
            throw new NpApiValidationException('labels must contain a document_id: or case_num: label');
        }
        $entity = Entity::getByNorskePostlisterId($npEntityId);
        if ($entity === null) {
            throw new NpApiEntityNotFoundException('No entity for norske-postlister id: ' . $npEntityId);
        }
        if ($entity->email === null || $entity->email === '') {
            throw new NpApiValidationException('Entity has no email address: ' . $npEntityId);
        }

        $existing = self::findExistingThread($entity->entity_id, $labels);
        if ($existing !== null) {
            return [
                'created' => false,
                'existing' => true,
                'thread_id' => $existing->id,
                'thread_url' => self::threadUrl($existing->id),
                'status' => ThreadStatusRepository::getThreadStatus($existing->id),
            ];
        }

        $cap = self::$dailyCapOverride ?? self::DAILY_CAP;
        $createdToday = Database::queryValue(
            "SELECT count(*) FROM thread_history
             WHERE user_id = ? AND action = 'created' AND created_at >= date_trunc('day', now())",
            [self::THREAD_OWNER_USER_ID]
        );
        if ($createdToday >= $cap) {
            throw new NpApiCapExceededException('Daily thread creation cap reached: ' . $cap);
        }

        $profile = getRandomNameAndEmail();
        // Matches the name assembly used in start-thread.php: middleName already
        // carries its own leading space (or is '') from random-profile.php.
        $myName = $profile->firstName . $profile->middleName . ' ' . $profile->lastName;

        // Only wrap in our own transaction when we're not already inside one.
        // Real callers (HTTP endpoint) invoke this without an ambient
        // transaction, so we get atomicity across the thread + email-sending
        // inserts. Tests wrap the whole test in a transaction for rollback-based
        // isolation; PDO/Postgres has no true nested transactions, so a second
        // beginTransaction() there would throw "already an active transaction".
        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }
        try {
            $thread = new Thread();
            $thread->title = $title;
            $thread->my_name = $myName;
            $thread->my_email = $profile->email;
            $thread->labels = $labels;
            $thread->initial_request = $body;
            $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
            $thread->sent = false;
            $thread->archived = false;
            $thread->public = true;
            $thread->request_law_basis = Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
            $thread->request_follow_up_plan = Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;
            $thread->emails = [];

            $newThread = ThreadStorageManager::getInstance()
                ->createThread($entity->entity_id, $thread, self::THREAD_OWNER_USER_ID);
            $newThread->addUser(self::THREAD_OWNER_USER_ID, true);

            if (self::$forceThrowableForTest) {
                // Test-only hook: simulate a mid-transaction Error (not Exception) to
                // verify the transaction is rolled back regardless of Throwable subtype.
                throw new Error('forced test failure');
            }

            ThreadEmailSending::create(
                $newThread->id,
                $body . "\n\n--\n" . $myName,
                $title,
                $entity->email,
                $profile->email,
                $myName,
                ThreadEmailSending::STATUS_READY_FOR_SENDING
            );
            if ($ownsTransaction) {
                Database::commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'created' => true,
            'existing' => false,
            'thread_id' => $newThread->id,
            'thread_url' => self::threadUrl($newThread->id),
            'status' => ThreadStatusRepository::getThreadStatus($newThread->id),
        ];
    }

    /** The label that identifies the doc/case this thread requests. */
    public static function mappingLabel(array $labels): ?string {
        foreach ($labels as $label) {
            if (str_starts_with($label, 'document_id:') || str_starts_with($label, 'case_num:')) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Deliberately tolerant of duplicates: two concurrent createThread() calls for
     * the same entity+mapping label can both pass this check before either has
     * inserted (classic check-then-act race), leaving 2+ non-archived threads
     * carrying the same label. Database::queryOneOrNone() would throw on that
     * ("Expected 1 row, got 2..."), permanently 500ing every later create for
     * that doc/case. Using Database::query() + ORDER BY created_at ASC LIMIT 1
     * instead makes this self-healing: the oldest thread is always treated as
     * canonical and later duplicates just get returned as "existing" too.
     *
     * No DB-level unique constraint backs this. It IS expressible - labels is a
     * text[], but Postgres allows unique indexes on immutable expressions, so a
     * small IMMUTABLE SQL function extracting the document_id:/case_num: label
     * (mirroring mappingLabel() below) plus
     *   CREATE UNIQUE INDEX ... ON threads (entity_id, that_function(labels))
     *   WHERE archived = false
     * would work, and was verified to CREATE cleanly against the current schema.
     * It's deliberately not added as a migration here: migrate.php runs every
     * pending migration inside one transaction and is auto-applied unattended by
     * servers/production/deploy-cronjob.sh every 10 minutes, and CREATE INDEX
     * CONCURRENTLY (needed to add it without locking the table) is rejected
     * inside a transaction block. A plain CREATE UNIQUE INDEX would run, but
     * this exact race is presumably why production may already carry duplicate
     * rows for the same entity+label - which isn't verifiable from a dev
     * checkout, and would abort that whole unattended migration batch if hit.
     * The tolerant read above is the real fix; the unique index is a follow-up
     * that needs a data audit (and likely CONCURRENTLY run out-of-band) first.
     */
    public static function findExistingThread(string $entityId, array $labels): ?Thread {
        $labels = array_values(array_filter(array_map('trim', $labels), fn($l) => $l !== ''));
        $mappingLabel = self::mappingLabel($labels);
        $rows = Database::query(
            "SELECT id FROM threads
             WHERE entity_id = ? AND archived = false AND ? = ANY(labels)
             ORDER BY created_at ASC LIMIT 1",
            [$entityId, $mappingLabel]
        );
        return count($rows) === 0 ? null : Thread::loadFromDatabase($rows[0]['id']);
    }

    public static function threadUrl(string $threadId): string {
        global $environment;
        $base = ($environment ?? 'production') === 'development'
            ? 'http://localhost:25081' : 'https://offpost.no';
        return $base . '/thread-view?threadId=' . urlencode($threadId);
    }

    /**
     * All threads carrying the NP label, across entities. Direct SQL: the API
     * has no session user, and NP threads are public by construction.
     */
    public static function listNpThreads(): array {
        $rows = Database::query(
            "SELECT id, entity_id, labels FROM threads
             WHERE archived = false AND ? = ANY(labels)",
            [self::NP_LABEL]
        );

        // entity_id (offpost) -> NP id, for translating each thread's entity.
        $npIdByEntityId = [];
        foreach (Entity::getAll() as $entity) {
            if (isset($entity->entity_id_norske_postlister)) {
                $npIdByEntityId[$entity->entity_id] = $entity->entity_id_norske_postlister;
            }
        }

        $threadIds = array_column($rows, 'id');
        $statuses = count($threadIds) > 0
            ? ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds)
            : [];

        $threads = [];
        foreach ($rows as $row) {
            $thread = Thread::loadFromDatabase($row['id']);
            $status = $statuses[$row['id']] ?? null;

            // thread_emails.imap_headers isn't copied onto ThreadEmail by
            // Thread::mapFromDatabase(), so fetch subjects separately rather
            // than change that shared mapping's behavior for other callers.
            $subjectsByEmailId = self::emailSubjectsByThreadId($row['id']);

            $emails = [];
            foreach ($thread->getEmails() as $email) {
                if ($email->email_type !== 'IN' && $email->email_type !== 'OUT') {
                    // Skip rows with an unrecognized email_type rather than 500ing the
                    // whole (polled-by-everyone) list endpoint over one bad row. The
                    // thread itself and its status still show up below.
                    error_log(
                        'NpApiService::listNpThreads: skipping thread_emails row with '
                        . 'unexpected email_type: thread_id=' . $thread->id
                        . ' email_id=' . $email->id . ' email_type=' . var_export($email->email_type, true)
                    );
                    continue;
                }

                if (isset($email->ignore) && $email->ignore) {
                    // Classified as ignore (e.g. an internal forwarding notice at the
                    // entity) - not part of the conversation the consumer should see.
                    continue;
                }

                $attachments = [];
                foreach ($email->attachments ?? [] as $att) {
                    $attachments[] = [
                        'id' => $att->id,
                        'name' => $att->name,
                        'content_type' => self::attachmentContentType($att->filetype),
                    ];
                }

                $emails[] = [
                    'email_type' => $email->email_type,
                    'timestamp' => $email->timestamp_received !== null
                        ? strtotime($email->timestamp_received) : null,
                    'subject' => $subjectsByEmailId[$email->id] ?? null,
                    // Classification (ThreadEmailStatusType value or legacy string/null).
                    // Lets the consumer distinguish substantive answers from auto-replies:
                    // an unclassified incoming email must not count as "answered".
                    'status_type' => $email->status_type ?? null,
                    'attachments' => $attachments,
                ];
            }

            $threads[] = [
                'thread_id' => $thread->id,
                'thread_url' => self::threadUrl($thread->id),
                'entity_id_norske_postlister' => $npIdByEntityId[$row['entity_id']] ?? null,
                'labels' => $thread->labels,
                'status' => $status !== null ? $status->status : ThreadStatusRepository::ERROR_THREAD_NOT_FOUND,
                'email_count_in' => $status !== null ? (int)$status->email_count_in : 0,
                'email_count_out' => $status !== null ? (int)$status->email_count_out : 0,
                'email_last_activity' => $status !== null ? $status->email_last_activity : null,
                'emails' => $emails,
            ];
        }

        return [
            'supported_entities' => Entity::getAllNorskePostlisterIds(),
            'threads' => $threads,
        ];
    }

    /**
     * Attachment bytes for norske-postlister.no to re-serve. Only threads carrying
     * the NP label are visible here - not-found and not-NP are deliberately the
     * same exception/message so the response can't reveal whether a foreign
     * (non-NP) thread exists.
     *
     * @return array{content: string, content_type: string, name: string}
     */
    public static function getNpAttachment(string $threadId, string $attachmentId): array {
        $thread = Thread::loadFromDatabaseOrNone($threadId);
        // No `archived` filter here (unlike listNpThreads()) - deliberate: once
        // norske-postlister.no has published a link to an attachment, it must keep
        // working even after the thread is later archived, so published links don't break.
        if ($thread === null || !in_array(self::NP_LABEL, $thread->labels)) {
            throw new NpApiEntityNotFoundException('Unknown attachment');
        }

        $row = Database::queryOneOrNone(
            "SELECT tea.name, tea.filetype, tea.content
             FROM thread_email_attachments tea
             JOIN thread_emails te ON te.id = tea.email_id
             WHERE te.thread_id = ? AND tea.id = ?",
            [$threadId, $attachmentId]
        );
        if ($row === null || $row['content'] === null) {
            // thread_email_attachments.content is nullable (e.g. attachment rows
            // whose content failed to download/store). Treat a NULL row the same
            // as an unknown attachment - same exception, same message - rather
            // than let strlen(null) 500 downstream in np_attachment_get.php.
            throw new NpApiEntityNotFoundException('Unknown attachment');
        }

        // Same bytea decoding as ThreadStorageManager::getThreadEmailAttachment():
        // depending on driver/insert path, content comes back either as a stream
        // resource or as a Postgres hex-escaped string ("\x...").
        $content = $row['content'];
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }
        if (is_string($content) && substr($content, 0, 2) === '\\x') {
            $content = hex2bin(substr($content, 2));
        }

        return [
            'content' => $content,
            'content_type' => self::attachmentContentType($row['filetype']),
            'name' => $row['name'],
        ];
    }

    /**
     * thread_email_attachments.filetype is NOT a MIME type in the real data: it's
     * usually a bare extension (see file.php's Content-Type-header switch and
     * ImapAttachmentHandler::$supportedTypes, which this map mirrors), though a
     * few legacy rows already hold a full MIME type, and some hold the 'UNKNOWN'
     * sentinel ImapAttachmentHandler uses when it can't determine a type. This
     * endpoint is polled for every thread, so an unrecognized value must not 500
     * the whole list - fall back to application/octet-stream and log it instead.
     */
    public static function attachmentContentType(?string $filetype): string {
        $extensionToMime = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'txt' => 'text/plain',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'gz' => 'application/gzip',
            'eml' => 'message/rfc822',
            'csv' => 'text/csv',
            'UNKNOWN' => 'application/octet-stream',
        ];
        if ($filetype !== null && isset($extensionToMime[$filetype])) {
            return $extensionToMime[$filetype];
        }
        if ($filetype !== null && str_contains($filetype, '/')) {
            // Already a MIME type.
            return $filetype;
        }
        error_log(
            'NpApiService::attachmentContentType: unrecognized thread_email_attachments.filetype: '
            . var_export($filetype, true)
        );
        return 'application/octet-stream';
    }

    /**
     * @return array<string,?string> email id -> subject (or null), for one thread.
     * TODO scaling: this issues one query per thread in listNpThreads()'s loop. If the
     * NP thread count grows large enough for that to matter, batch it into a single
     * "WHERE thread_id = ANY(?)" query keyed by all thread ids up front, like
     * getAllThreadStatusesEfficient() does for statuses.
     */
    private static function emailSubjectsByThreadId(string $threadId): array {
        $rows = Database::query(
            "SELECT id, imap_headers FROM thread_emails WHERE thread_id = ?",
            [$threadId]
        );
        $subjects = [];
        foreach ($rows as $row) {
            $subject = $row['imap_headers'] !== null
                ? getEmailSubjectFromImapHeaders($row['imap_headers']) : '';
            $subjects[$row['id']] = $subject !== '' ? $subject : null;
        }
        return $subjects;
    }
}
