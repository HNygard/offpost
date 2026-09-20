<?php
// organizer/src/tests/ThreadScheduledFollowUpSenderPostlisteTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadScheduledFollowUpSender.php';
require_once __DIR__ . '/../class/NpApiService.php';
require_once __DIR__ . '/../class/ImapFolderStatus.php';

/**
 * The `postliste` follow-up plan end to end against the database: candidate
 * selection, both reminders, the in-flight and stop conditions, and the
 * norske-postlister reply path. Rolled back after each test.
 */
class ThreadScheduledFollowUpSenderPostlisteTest extends TestCase {
    const REQUEST_SENT = '2026-09-01T08:00:00+00:00';
    const ENTITY_EMAIL = 'public-entity@dev.offpost.no';

    private ThreadScheduledFollowUpSender $sender;

    protected function setUp(): void {
        Database::beginTransaction();
        $this->sender = new ThreadScheduledFollowUpSender();
        // Nothing else in the shared dev database may be picked up by either
        // plan: with no sync time every other thread reports ERROR_NO_SYNC.
        Database::execute("UPDATE imap_folder_status SET last_checked_at = NULL");
        ImapFolderStatus::createOrUpdate('INBOX', updateLastChecked: true);
        ImapFolderStatus::createOrUpdate('INBOX.Sent', updateLastChecked: true);
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function at(string $iso): int {
        return strtotime($iso);
    }

    private function createSyncedPostlisteThread(string $period = '2026-W36', string $plan = Thread::REQUEST_FOLLOW_UP_PLAN_POSTLISTE): string {
        $created = NpApiService::createThread(
            '9999-test-entity-development', 'Innsyn i offentlig journal', 'Innhold',
            ['postliste', 'postliste:' . $period], $plan
        );
        $threadId = $created['thread_id'];
        // The initial request has left the building.
        Database::execute("UPDATE thread_email_sendings SET status = 'SENT' WHERE thread_id = ?", [$threadId]);
        ImapFolderStatus::createOrUpdate('INBOX.test-postliste-' . $period, $threadId, updateLastChecked: true);
        $this->insertEmail($threadId, 'OUT', self::REQUEST_SENT, 'OUR_REQUEST');
        return $threadId;
    }

    private function insertEmail(string $threadId, string $type, string $ts, ?string $statusType, bool $ignore = false): string {
        return Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers, status_type, ignore)
             VALUES (?, ?, ?, ?, ?::bytea, NULL, ?, ?) RETURNING id",
            [$threadId, $ts, $ts, $type, 'content', $statusType, $ignore ? 't' : 'f']
        );
    }

    private function reminderHistory(string $threadId): array {
        return Database::query(
            "SELECT user_id, details, created_at FROM thread_history WHERE thread_id = ? AND action = ? ORDER BY id",
            [$threadId, ThreadScheduledFollowUpSender::HISTORY_ACTION_POSTLISTE_REMINDER]
        );
    }

    private function markAllSent(string $threadId): void {
        Database::execute("UPDATE thread_email_sendings SET status = 'SENT' WHERE thread_id = ?", [$threadId]);
    }

    public function testBothRemindersAreQueuedOnTimeAndThenNothing(): void {
        // :: Setup
        $threadId = $this->createSyncedPostlisteThread();
        $thread = Thread::loadFromDatabase($threadId);

        // :: Act - day 9: not due
        $this->sender->now = $this->at('2026-09-10T08:00:00+00:00');
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertEquals('No threads ready for follow-up', $result['message']);
        $this->assertCount(1, ThreadEmailSending::getByThreadId($threadId), 'only the initial request');

        // :: Act - day 10: reminder 1
        $this->sender->now = $this->at('2026-09-11T08:00:00+00:00');
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert
        $this->assertEquals([
            'success' => true,
            'message' => 'Postliste follow-up email scheduled for sending',
            'thread_id' => $threadId,
            'reminder' => 1,
        ], $result);
        $sendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertCount(2, $sendings, json_encode($sendings, JSON_PRETTY_PRINT));
        $reminder1 = $sendings[1];
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $reminder1->status, 'no human release for postliste reminders');
        $this->assertEquals('Purring - Innsyn i offentlig journal', $reminder1->email_subject);
        $this->assertEquals(self::ENTITY_EMAIL, $reminder1->email_to);
        $this->assertEquals($thread->my_email, $reminder1->email_from);
        $this->assertEquals($thread->my_name, $reminder1->email_from_name);
        $this->assertStringContainsString('sendt 01.09.2026 om offentlig journal for perioden 31.08.2026 – 06.09.2026', $reminder1->email_content);
        $this->assertStringNotContainsString('§ 32', $reminder1->email_content);

        $history = $this->reminderHistory($threadId);
        $this->assertCount(1, $history, json_encode($history, JSON_PRETTY_PRINT));
        $this->assertEquals(ThreadScheduledFollowUpSender::HISTORY_USER_ID, $history[0]['user_id']);
        $details = json_decode($history[0]['details'], true);
        $this->assertEquals(1, $details['reminder']);
        $this->assertEquals([$reminder1->id], $details['email_sending_ids']);
        $this->assertEquals('2026-09-01T08:00:00+00:00', $details['request_sent']);
        $this->assertStringContainsString(
            'reminder 1',
            (new ThreadHistory())->formatActionForDisplay(ThreadScheduledFollowUpSender::HISTORY_ACTION_POSTLISTE_REMINDER, $history[0]['details'])
        );

        // :: Act - same day again: reminder 1 is still in flight
        $result = $this->sender->sendNextFollowUpEmail();
        $this->assertFalse($result['success'], 'a sending in flight blocks further reminders');
        $this->assertCount(2, ThreadEmailSending::getByThreadId($threadId));

        // :: Setup - reminder 1 went out on day 10
        $this->markAllSent($threadId);
        Database::execute(
            "UPDATE thread_history SET created_at = '2026-09-11T08:05:00+00:00' WHERE thread_id = ? AND action = ?",
            [$threadId, ThreadScheduledFollowUpSender::HISTORY_ACTION_POSTLISTE_REMINDER]
        );

        // :: Act - day 19: not due
        $this->sender->now = $this->at('2026-09-20T08:00:00+00:00');
        $this->assertFalse($this->sender->sendNextFollowUpEmail()['success']);

        // :: Act - day 20: reminder 2
        $this->sender->now = $this->at('2026-09-21T08:00:00+00:00');
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertEquals(2, $result['reminder']);
        $sendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertCount(3, $sendings, json_encode($sendings, JSON_PRETTY_PRINT));
        $reminder2 = $sendings[2];
        $this->assertEquals('Purring 2 - Innsyn i offentlig journal', $reminder2->email_subject);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $reminder2->status);
        $this->assertStringContainsString('avslag etter § 32 tredje ledd, og jeg vil vurdere å klage til klageinstansen.', $reminder2->email_content);

        // :: Act - day 60, everything sent: nothing more, klage is a human decision
        $this->markAllSent($threadId);
        $this->sender->now = $this->at('2026-10-31T08:00:00+00:00');
        $result = $this->sender->sendNextFollowUpEmail();
        $this->assertFalse($result['success']);
        $this->assertCount(3, ThreadEmailSending::getByThreadId($threadId));
        $this->assertCount(2, $this->reminderHistory($threadId));
    }

    public function testReceiptDoesNotStopButSubstantiveReplyDoes(): void {
        // :: Setup
        $threadId = $this->createSyncedPostlisteThread();
        $this->insertEmail($threadId, 'IN', '2026-09-01T08:01:00+00:00', 'REQUEST_RECEIPT');
        $this->sender->now = $this->at('2026-09-11T08:00:00+00:00');

        // :: Act
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert
        $this->assertTrue($result['success'], 'a receipt is not an answer: ' . json_encode($result));
        $this->assertEquals($threadId, $result['thread_id']);

        // :: Setup - the journal arrives
        $this->markAllSent($threadId);
        $this->insertEmail($threadId, 'IN', '2026-09-12T08:00:00+00:00', 'INFORMATION_RELEASE');
        $this->sender->now = $this->at('2026-09-30T08:00:00+00:00');

        // :: Act
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertCount(2, ThreadEmailSending::getByThreadId($threadId), 'no reminder 2 after a real answer');
    }

    public function testUnreadableReplyReclassifiedThroughNpApiRestartsNagging(): void {
        // :: Setup - journal arrives day 4, norske-postlister cannot read it
        $threadId = $this->createSyncedPostlisteThread();
        $emailId = $this->insertEmail($threadId, 'IN', '2026-09-05T08:00:00+00:00', 'INFORMATION_RELEASE');

        $this->sender->now = $this->at('2026-09-11T08:00:00+00:00');
        $this->assertFalse($this->sender->sendNextFollowUpEmail()['success'], 'an INFORMATION_RELEASE pauses nagging');

        NpApiService::classifyEmail($threadId, $emailId, 'RESPONSE_UNREADABLE', 'Skannet uten tekst');
        NpApiService::replyToThread($threadId, 'Re: Innsyn i offentlig journal', 'Vedlegget kunne ikke leses.');
        // Deterministic reply time: day 5.
        Database::execute(
            "UPDATE thread_history SET created_at = '2026-09-06T08:00:00+00:00' WHERE thread_id = ? AND action = ?",
            [$threadId, NpApiService::HISTORY_ACTION_REPLY]
        );

        // :: Act - the NP reply is still queued
        $result = $this->sender->sendNextFollowUpEmail();
        $this->assertFalse($result['success'], 'NP reply in flight blocks reminders');

        // :: Act - reply sent; day 10 after the request but only day 5 after the reply
        $this->markAllSent($threadId);
        $result = $this->sender->sendNextFollowUpEmail();
        $this->assertFalse($result['success'], 'count restarts from the NP reply: ' . json_encode($result));

        // :: Act - day 10 after the NP reply
        $this->sender->now = $this->at('2026-09-16T08:00:00+00:00');
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertEquals(1, $result['reminder']);
        $sendings = ThreadEmailSending::getByThreadId($threadId);
        $this->assertCount(3, $sendings, 'request, NP reply, reminder 1: ' . json_encode($sendings, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('sendt 01.09.2026', $sendings[2]->email_content, 'the template quotes the original request date');
    }

    public function testRejectionArchivedAndOtherPlansAreLeftAlone(): void {
        // :: Setup
        $rejected = $this->createSyncedPostlisteThread('2026-W30');
        $this->insertEmail($rejected, 'IN', '2026-09-03T08:00:00+00:00', 'REQUEST_REJECTED');

        $archived = $this->createSyncedPostlisteThread('2026-W31');
        Database::execute("UPDATE threads SET archived = true WHERE id = ?", [$archived]);

        $speedyOverdue = $this->createSyncedPostlisteThread('2026-W32', Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY);

        $this->sender->now = $this->at('2026-10-15T08:00:00+00:00');

        // :: Act
        $result = $this->sender->sendNextFollowUpEmail();

        // :: Assert - the speedy thread is handled by the legacy plan, exactly as
        // before: one STAGING reminder; the postliste stop conditions hold.
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertEquals('Follow-up email scheduled for sending', $result['message']);
        $this->assertEquals($speedyOverdue, $result['thread_id']);
        $speedySendings = ThreadEmailSending::getByThreadId($speedyOverdue);
        $this->assertCount(2, $speedySendings);
        $this->assertEquals(ThreadEmailSending::STATUS_STAGING, $speedySendings[1]->status);

        // The staged legacy reminder stays in flight (a human releases it), so
        // the legacy plan now skips that thread and the postliste plan gets its turn.
        $result = $this->sender->sendNextFollowUpEmail();
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertCount(1, ThreadEmailSending::getByThreadId($rejected), 'REQUEST_REJECTED stops reminders');
        $this->assertCount(1, ThreadEmailSending::getByThreadId($archived), 'archived threads get no reminders');
        $this->assertEquals([], $this->reminderHistory($rejected));
        $this->assertEquals([], $this->reminderHistory($archived));
    }

    public function testPostlisteThreadDoesNotBreakLegacyLoop(): void {
        // Before this plan existed the legacy loop threw "Unknown follow-up plan"
        // on any plan other than speedy/slow. A postliste thread sitting in
        // EMAIL_SENT_NOTHING_RECEIVED must simply be skipped there.
        $this->createSyncedPostlisteThread('2026-W33');
        $this->sender->now = $this->at('2026-09-02T08:00:00+00:00');

        $result = $this->sender->sendNextFollowUpEmail();

        $this->assertFalse($result['success']);
        $this->assertEquals('No threads ready for follow-up', $result['message']);
    }

    public function testUnsyncedThreadIsSkipped(): void {
        // An ERROR_* status means the mailbox is not synced; an answer may be
        // sitting unseen, so no reminder.
        $threadId = $this->createSyncedPostlisteThread('2026-W34');
        Database::execute("UPDATE imap_folder_status SET last_checked_at = NULL WHERE thread_id = ?", [$threadId]);
        $this->sender->now = $this->at('2026-10-15T08:00:00+00:00');

        $result = $this->sender->sendNextFollowUpEmail();

        $this->assertFalse($result['success'], json_encode($result));
        $this->assertCount(1, ThreadEmailSending::getByThreadId($threadId));
    }
}
