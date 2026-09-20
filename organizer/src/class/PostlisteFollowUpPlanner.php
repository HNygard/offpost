<?php
// organizer/src/class/PostlisteFollowUpPlanner.php
require_once __DIR__ . '/ThreadEmail.php';
require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

/**
 * Outcome of PostlisteFollowUpPlanner::decide(). `reminder` is 1 or 2 when a
 * reminder should be queued now, null otherwise; `reason` says why.
 */
class PostlisteFollowUpDecision {
    const REASON_REMINDER_DUE = 'reminder_due';
    const REASON_NO_OUT_EMAIL = 'no_out_email';
    const REASON_REQUEST_REJECTED = 'request_rejected';
    const REASON_ANSWERED = 'answered';
    const REASON_NOT_DUE = 'not_due';
    const REASON_ALL_REMINDERS_SENT = 'all_reminders_sent';

    public ?int $reminder = null;
    public string $reason;
    /** @var ?int unix time the day count is measured from */
    public ?int $anchor = null;
    /** @var ?int unix time the original request was sent (first OUT email) */
    public ?int $firstOutSent = null;
}

/**
 * The `postliste` follow-up plan: when to send which reminder for a
 * postjournal request. Pure decision logic over the thread's emails and the
 * relevant thread_history entries, so it can be tested with fixed clocks;
 * ThreadScheduledFollowUpSender does the loading and the sending.
 *
 * Rules (spec package 3):
 *  - Day count starts at the first OUT email (the request), or at
 *    norske-postlister's own reply through the NP API when there is a later
 *    one: a reply the NP side reclassified as unreadable does not count as an
 *    answer, and the nagging restarts from their reply.
 *  - Reminder 1 on day 10, reminder 2 on day 20 (which adds the § 32 refusal
 *    wording). Nothing after that; a klage is a human decision.
 *  - A REQUEST_REJECTED IN email stops everything.
 *  - Any other non-ignored IN email received after the anchor pauses the
 *    nagging unless it is classified as non-substantive (receipt, more-time,
 *    unreadable). An unclassified email counts as an answer: better to miss a
 *    reminder than to nag an entity that has just answered.
 */
class PostlisteFollowUpPlanner {
    const REMINDER_1_DAYS = 10;
    const REMINDER_2_DAYS = 20;
    const MAX_REMINDERS = 2;

    /** IN emails with these statuses do not count as an answer. */
    const NON_SUBSTANTIVE_STATUSES = [
        'REQUEST_RECEIPT',
        'ASKING_FOR_MORE_TIME',
        'RESPONSE_UNREADABLE',
    ];

    /**
     * @param ThreadEmail[] $emails The thread's emails (any order)
     * @param int[] $npReplyTimes Unix times of replies queued through the NP
     *   API (thread_history action NpApiService::HISTORY_ACTION_REPLY)
     * @param int[] $reminderTimes Unix times of reminders this plan already
     *   queued (thread_history action
     *   ThreadScheduledFollowUpSender::HISTORY_ACTION_POSTLISTE_REMINDER)
     * @param int $now Unix time
     */
    public static function decide(array $emails, array $npReplyTimes, array $reminderTimes, int $now): PostlisteFollowUpDecision {
        $decision = new PostlisteFollowUpDecision();

        $firstOut = null;
        foreach ($emails as $email) {
            if ($email->email_type === 'OUT') {
                $ts = self::emailTime($email);
                if ($firstOut === null || $ts < $firstOut) {
                    $firstOut = $ts;
                }
            }
        }
        if ($firstOut === null) {
            $decision->reason = PostlisteFollowUpDecision::REASON_NO_OUT_EMAIL;
            return $decision;
        }
        $decision->firstOutSent = $firstOut;

        $anchor = $firstOut;
        foreach ($npReplyTimes as $replyTime) {
            if ($replyTime > $anchor) {
                $anchor = $replyTime;
            }
        }
        $decision->anchor = $anchor;

        foreach ($emails as $email) {
            if ($email->email_type !== 'IN' || !empty($email->ignore)) {
                continue;
            }
            if (self::statusValue($email) === ThreadEmailStatusType::REQUEST_REJECTED->value) {
                $decision->reason = PostlisteFollowUpDecision::REASON_REQUEST_REJECTED;
                return $decision;
            }
        }

        foreach ($emails as $email) {
            if ($email->email_type !== 'IN' || !empty($email->ignore)) {
                continue;
            }
            if (self::emailTime($email) <= $anchor) {
                continue;
            }
            $status = self::statusValue($email);
            if ($status !== null && in_array($status, self::NON_SUBSTANTIVE_STATUSES, true)) {
                continue;
            }
            $decision->reason = PostlisteFollowUpDecision::REASON_ANSWERED;
            return $decision;
        }

        $remindersSinceAnchor = 0;
        foreach ($reminderTimes as $reminderTime) {
            if ($reminderTime > $anchor) {
                $remindersSinceAnchor++;
            }
        }
        if ($remindersSinceAnchor >= self::MAX_REMINDERS) {
            $decision->reason = PostlisteFollowUpDecision::REASON_ALL_REMINDERS_SENT;
            return $decision;
        }

        $nextReminder = $remindersSinceAnchor + 1;
        $dueDays = $nextReminder === 1 ? self::REMINDER_1_DAYS : self::REMINDER_2_DAYS;
        if ($now < $anchor + $dueDays * 86400) {
            $decision->reason = PostlisteFollowUpDecision::REASON_NOT_DUE;
            return $decision;
        }

        $decision->reminder = $nextReminder;
        $decision->reason = PostlisteFollowUpDecision::REASON_REMINDER_DUE;
        return $decision;
    }

    private static function emailTime(ThreadEmail $email): int {
        return is_int($email->timestamp_received)
            ? $email->timestamp_received
            : strtotime($email->timestamp_received);
    }

    private static function statusValue(ThreadEmail $email): ?string {
        if ($email->status_type instanceof ThreadEmailStatusType) {
            return $email->status_type->value;
        }
        if ($email->status_type === null || $email->status_type === '' || $email->status_type === 'unknown') {
            return null;
        }
        return $email->status_type;
    }
}
