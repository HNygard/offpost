<?php

require_once __DIR__ . '/Thread.php';
require_once __DIR__ . '/ThreadEmailSending.php';
require_once __DIR__ . '/ThreadStatusRepository.php';
require_once __DIR__ . '/ThreadHistory.php';
require_once __DIR__ . '/PostlistePeriod.php';
require_once __DIR__ . '/PostlisteFollowUpPlanner.php';
require_once __DIR__ . '/NpApiService.php';
require_once __DIR__ . '/Database.php';

/**
 * Class for handling scheduled thread follow-ups.
 *
 * Two families of plans:
 *  - `speedy` / `slow`: one reminder after 5 / 14 days, only for threads with
 *    exactly one OUT email and nothing received, left in STAGING for a human.
 *  - `postliste`: postjournal requests from norske-postlister.no. Reminders on
 *    day 10 and 20, queued READY_FOR_SENDING with no human release, working on
 *    threads with any number of emails. Decision rules in PostlisteFollowUpPlanner.
 *
 * One email per call, so the cron's one-per-minute ceiling holds.
 */
class ThreadScheduledFollowUpSender {
    // thread_history rows written when a postliste reminder is queued. The
    // planner counts these to know which reminder comes next.
    const HISTORY_ACTION_POSTLISTE_REMINDER = 'postliste_follow_up_queued';
    const HISTORY_USER_ID = 'scheduled-thread-follow-up';

    /** @var ?int test hook: unix time used as "now" instead of time() */
    public $now = null;

    /**
     * Find and send the next follow-up email
     * 
     * @return array Result of the operation
     */
    public function sendNextFollowUpEmail() {
        // Find the next thread that needs follow-up
        $thread = $this->findNextThreadForProcessing();
        
        if (!$thread) {
            return $this->sendNextPostlisteFollowUpEmail();
        }
        
        // Get the entity for this thread
        $entity = $thread->getEntity();
        if (!$entity) {
            return [
                'success' => false,
                'message' => 'Entity not found for thread'
            ];
        }
        
        // Create a follow-up email
        $subject = "Purring - " . $thread->title;
        $content = $this->createFollowUpEmailContent($thread);
        
        // Create email sending record
        $emailSending = ThreadEmailSending::create(
            $thread->id,
            $content,
            $subject,
            $entity->email,
            $thread->my_email,
            $thread->my_name,
            ThreadEmailSending::STATUS_STAGING
        );
        
        if (!$emailSending) {
            return [
                'success' => false,
                'message' => 'Failed to create email sending record'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Follow-up email scheduled for sending',
            'thread_id' => $thread->id
        ];
    }
    
    /**
     * Find the next thread that needs processing
     * 
     * @return Thread|null The thread or null if none found
     */
    protected function findNextThreadForProcessing() {
        // Get threads with status EMAIL_SENT_NOTHING_RECEIVED
        $threadIds = ThreadStatusRepository::getThreadsByStatus(
            ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED
        );
        
        if (empty($threadIds)) {
            return null;
        }

        foreach ($threadIds as $thread_status) {
            if ($thread_status->request_law_basis != Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA) {
                // Skip threads that are not under the law basis for follow-up
                continue;
            }
            if (empty($thread_status->request_follow_up_plan)) {
                // Skip threads that are not under the follow-up plan
                continue;
            }
            if ($thread_status->request_follow_up_plan == Thread::REQUEST_FOLLOW_UP_PLAN_POSTLISTE) {
                // Handled by sendNextPostlisteFollowUpEmail()
                continue;
            }
            if (date('Y', $thread_status->email_last_activity) < 2024) {
                // Skip threads that are not sent in 2025 or later
                continue;
            }

            if ($thread_status->request_follow_up_plan == Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY) {
                $days = 5;
            }
            elseif($thread_status->request_follow_up_plan == Thread::REQUEST_FOLLOW_UP_PLAN_SLOW) {
                $days = 14;
            }
            else {
                throw new Exception("Unknown follow-up plan: " . $thread_status->request_follow_up_plan);
            }

            if ($thread_status->email_last_activity + ($days * 86400) > time()) {
                // Skip threads that are not due for follow-up
                continue;
            }

            $thread = Thread::loadFromDatabase($thread_status->thread_id);

            $sendings = ThreadEmailSending::getByThreadId($thread->id);
            foreach ($sendings as $sending) {
                if ($sending->status == ThreadEmailSending::STATUS_SENT) {
                    continue;
                }

                // Skip threads that already have some email in progress
                continue 2;
            }

            // Just an extra check that the thread is not archived.
            if ($thread->archived) {
                throw new Exception("Thread is archived: " . $thread->id . '. Archived threads should already have been excluded in query.');
            }

            return $thread;
        }

        return null;
    }
    
    /**
     * Create follow-up email content
     * 
     * @param Thread $thread The thread
     * @return string The email content
     */
    protected function createFollowUpEmailContent(Thread $thread) {
        // Get email for thread
        if (count($thread->emails) != 1) {
            throw new Exception("Thread should have exactly one email for this 'follow up implementation' to work");
        }
        $email_sent = $thread->emails[0];
        if ($email_sent->email_type != 'OUT') {
            throw new Exception("Thread should have exactly one email of type OUT for this 'follow up implementation' to work");
        }

        // Hardcoded template for follow-up email
        return "Hei,\n\n"
            . "Vi sendte et innsynskrav til dere med tittel \"" . $thread->title . "\" og har ikke mottatt svar."
            . " Vår hendvendelse ble sendt " . date('H:i:s d.m.Y', strtotime($email_sent->timestamp_received)) . ".\n\n"
            . "Vennligst gi meg en oppdatering på status for vår henvendelse.\n\n"
            . "Med vennlig hilsen,\n" . $thread->my_name;
    }

    // ---------------------------------------------------------------------
    // Plan `postliste`
    // ---------------------------------------------------------------------

    /**
     * Queue the next due postliste reminder, if any.
     *
     * @return array Result of the operation
     */
    protected function sendNextPostlisteFollowUpEmail() {
        $next = $this->findNextPostlisteFollowUp();
        if ($next === null) {
            return [
                'success' => false,
                'message' => 'No threads ready for follow-up'
            ];
        }
        /** @var Thread $thread */
        $thread = $next['thread'];
        /** @var PostlisteFollowUpDecision $decision */
        $decision = $next['decision'];

        $entity = $thread->getEntity();
        if (!$entity) {
            return [
                'success' => false,
                'message' => 'Entity not found for thread'
            ];
        }

        $subject = ($decision->reminder === 1 ? 'Purring - ' : 'Purring 2 - ') . $thread->title;
        $content = $this->createPostlisteFollowUpEmailContent($thread, $entity, $decision);

        $emailSending = ThreadEmailSending::create(
            $thread->id,
            $content,
            $subject,
            $entity->email,
            $thread->my_email,
            $thread->my_name,
            ThreadEmailSending::STATUS_READY_FOR_SENDING
        );
        if (!$emailSending) {
            return [
                'success' => false,
                'message' => 'Failed to create email sending record'
            ];
        }

        (new ThreadHistory())->logAction($thread->id, self::HISTORY_ACTION_POSTLISTE_REMINDER, self::HISTORY_USER_ID, [
            'reminder' => $decision->reminder,
            'email_sending_ids' => [$emailSending->id],
            'subject' => $subject,
            'anchor' => gmdate('c', $decision->anchor),
            'request_sent' => gmdate('c', $decision->firstOutSent),
        ]);

        return [
            'success' => true,
            'message' => 'Postliste follow-up email scheduled for sending',
            'thread_id' => $thread->id,
            'reminder' => $decision->reminder,
        ];
    }

    /**
     * The first postliste thread with a reminder due, with its decision.
     *
     * Candidates: plan `postliste`, law basis offentleglova, not archived, and
     * a healthy status (EMAIL_SENT_NOTHING_RECEIVED or STATUS_OK - an ERROR_*
     * status means the mailbox is not synced, so an answer may be sitting
     * unseen, and NOT_SENT means there is nothing to remind about yet).
     * Threads with a sending still in flight are skipped: the previous
     * reminder or reply has not left yet, or has not been synced back as an
     * OUT email.
     *
     * @return ?array{thread: Thread, decision: PostlisteFollowUpDecision}
     */
    protected function findNextPostlisteFollowUp() {
        $rows = Database::query(
            "SELECT id FROM threads
             WHERE request_follow_up_plan = ? AND request_law_basis = ? AND archived = false
             ORDER BY created_at ASC",
            [Thread::REQUEST_FOLLOW_UP_PLAN_POSTLISTE, Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA]
        );
        if (count($rows) === 0) {
            return null;
        }
        $threadIds = array_column($rows, 'id');
        $statuses = ThreadStatusRepository::getAllThreadStatusesEfficient($threadIds, archived: false);
        $now = $this->now ?? time();

        foreach ($threadIds as $threadId) {
            $status = $statuses[$threadId] ?? null;
            if ($status === null || !in_array($status->status, [
                ThreadStatusRepository::EMAIL_SENT_NOTHING_RECEIVED,
                ThreadStatusRepository::STATUS_OK,
            ], true)) {
                continue;
            }

            $inFlight = false;
            foreach (ThreadEmailSending::getByThreadId($threadId) as $sending) {
                if ($sending->status !== ThreadEmailSending::STATUS_SENT) {
                    $inFlight = true;
                    break;
                }
            }
            if ($inFlight) {
                continue;
            }

            $thread = Thread::loadFromDatabase($threadId);
            if ($thread->archived) {
                continue;
            }

            $history = Database::query(
                "SELECT action, created_at FROM thread_history
                 WHERE thread_id = ? AND action IN (?, ?)",
                [$threadId, NpApiService::HISTORY_ACTION_REPLY, self::HISTORY_ACTION_POSTLISTE_REMINDER]
            );
            $npReplyTimes = [];
            $reminderTimes = [];
            foreach ($history as $row) {
                $ts = strtotime($row['created_at']);
                if ($row['action'] === NpApiService::HISTORY_ACTION_REPLY) {
                    $npReplyTimes[] = $ts;
                } else {
                    $reminderTimes[] = $ts;
                }
            }

            $decision = PostlisteFollowUpPlanner::decide($thread->emails, $npReplyTimes, $reminderTimes, $now);
            if ($decision->reminder !== null) {
                return ['thread' => $thread, 'decision' => $decision];
            }
        }

        return null;
    }

    /**
     * Reminder text for a postjournal request. Norwegian; first draft from the
     * spec, to be iterated on once the flow runs.
     *
     * @param Thread $thread
     * @param object $entity Entity (only ->type is used)
     * @param PostlisteFollowUpDecision $decision reminder 1 or 2, firstOutSent
     * @return string
     */
    protected function createPostlisteFollowUpEmailContent(Thread $thread, $entity, PostlisteFollowUpDecision $decision) {
        $sentDate = (new DateTime('@' . $decision->firstOutSent))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))
            ->format('d.m.Y');
        $period = PostlistePeriod::fromLabels($thread->labels ?? []);
        $about = $period !== null
            ? 'om offentlig journal for perioden ' . $period->fromFormatted() . ' – ' . $period->toFormatted()
            : 'om offentlig journal';

        $text = "Hei,\n\n"
            . 'Jeg viser til innsynskrav «' . $thread->title . '» sendt ' . $sentDate . ' ' . $about . '.'
            . ' Kravet skal etter offentleglova § 29 avgjøres uten ugrunnet opphold.'
            . ' Jeg ber om at journalen sendes snarest.';

        if ($decision->reminder >= 2) {
            $text .= "\n\n"
                . 'Dersom kravet ikke blir behandlet, regnes det som avslag etter § 32 tredje ledd,'
                . ' og jeg vil vurdere å klage til ' . self::klageinstansForEntityType($entity->type ?? null) . '.';
        }

        return $text . "\n\nMed vennlig hilsen,\n" . $thread->my_name;
    }

    /**
     * Who hears a klage over this entity (offentleglova § 32): Statsforvalteren
     * for kommuner and fylkeskommuner, the parent department for state bodies.
     * Anything else (including departments themselves) falls back to the
     * generic word.
     */
    public static function klageinstansForEntityType(?string $entityType): string {
        switch ($entityType) {
            case 'municipality':
            case 'municipality-unit':
            case 'county':
            case 'county-unit':
                return 'Statsforvalteren';
            case 'agency':
            case 'directorate':
            case 'health':
            case 'technical':
                return 'overordnet departement';
            default:
                return 'klageinstansen';
        }
    }
}
