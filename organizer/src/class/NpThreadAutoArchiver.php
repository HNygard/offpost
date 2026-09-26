<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/NpApiService.php';
require_once __DIR__ . '/ThreadHistory.php';
require_once __DIR__ . '/ThreadStatusRepository.php';
require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

/**
 * Archives norske-postlister.no threads that finished successfully: the entity
 * released the information and nothing is left for a human to do.
 *
 * Archiving only hides the thread in the Offpost GUI; the NP API still lists it.
 * There is no waiting period: a thread that is not finished stays open (and
 * keeps getting follow-ups); a finished one is archived at once. Note that
 * archived threads are left out of the email-to-folder mapping
 * (ThreadEmailMover::buildEmailToFolderMapping), so an email arriving after
 * archiving is not sorted into the thread.
 *
 * Decision rules are in decide() (pure, tested directly).
 */
class NpThreadAutoArchiver {
    const HISTORY_USER_ID = 'np-thread-auto-archiver';

    // Incoming classifications that do not need a human once the information
    // has been released. Everything else (unclassified, rejected, asking for
    // clarification/copy, unreadable, legacy values) keeps the thread open.
    private const DONE_STATUS_TYPES = [
        ThreadEmailStatusType::REQUEST_RECEIPT,
        ThreadEmailStatusType::ASKING_FOR_MORE_TIME,
        ThreadEmailStatusType::INFORMATION_RELEASE,
    ];

    /**
     * @param string $threadStatus ThreadStatusRepository status (STATUS_OK, ERROR_*, ...)
     * @param string[] $inStatusTypes status_type of each non-ignored IN email, oldest first
     * @param bool $sendingInFlight A thread_email_sendings row is not yet SENT
     * @return string|null null when the thread should be archived, otherwise why not
     */
    public static function decide(string $threadStatus, array $inStatusTypes, bool $sendingInFlight): ?string {
        if ($sendingInFlight) {
            return 'sending in flight';
        }
        if ($threadStatus !== ThreadStatusRepository::STATUS_OK) {
            return 'status ' . $threadStatus;
        }
        if (count($inStatusTypes) === 0) {
            return 'no incoming email';
        }

        $lastAnswer = null;
        foreach ($inStatusTypes as $statusType) {
            $type = ThreadEmailStatusType::tryFrom((string)$statusType);
            if ($type === null || !in_array($type, self::DONE_STATUS_TYPES, true)) {
                return 'incoming email classified ' . ($statusType ?? 'NULL') . ' needs a human';
            }
            if ($type !== ThreadEmailStatusType::REQUEST_RECEIPT) {
                $lastAnswer = $type;
            }
        }
        // A release followed by "we need more time" means more is coming.
        if ($lastAnswer !== ThreadEmailStatusType::INFORMATION_RELEASE) {
            return 'no information release as the latest answer';
        }
        return null;
    }

    /**
     * @return array{archived: string[], skipped: array<string,string>} thread ids,
     *   and skip reason per thread id
     */
    public function archiveFinishedThreads(bool $dryRun = false): array {
        $threadIds = array_column(Database::query(
            "SELECT id FROM threads WHERE ? = ANY(labels) AND archived = false ORDER BY created_at ASC",
            [NpApiService::NP_LABEL]
        ), 'id');
        if (count($threadIds) === 0) {
            return ['archived' => [], 'skipped' => []];
        }

        $statuses = ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: false);
        $history = new ThreadHistory();
        $archived = [];
        $skipped = [];
        foreach ($threadIds as $threadId) {
            $status = $statuses[$threadId] ?? null;
            $inStatusTypes = array_column(Database::query(
                "SELECT status_type FROM thread_emails
                  WHERE thread_id = ? AND email_type = 'IN' AND COALESCE(ignore, false) = false
                  ORDER BY timestamp_received ASC",
                [$threadId]
            ), 'status_type');
            $sendingInFlight = (int)Database::queryValue(
                "SELECT COUNT(*) FROM thread_email_sendings WHERE thread_id = ? AND status != ?",
                [$threadId, ThreadEmailSending::STATUS_SENT]
            ) > 0;

            $reason = self::decide(
                $status?->status ?? 'NO_STATUS',
                $inStatusTypes,
                $sendingInFlight
            );
            if ($reason !== null) {
                $skipped[$threadId] = $reason;
                continue;
            }

            if (!$dryRun) {
                Database::execute("UPDATE threads SET archived = true WHERE id = ? AND archived = false", [$threadId]);
                $history->logAction($threadId, 'archived', self::HISTORY_USER_ID,
                    ['reason' => 'information released, nothing left open']);
            }
            $archived[] = $threadId;
        }
        return ['archived' => $archived, 'skipped' => $skipped];
    }
}
