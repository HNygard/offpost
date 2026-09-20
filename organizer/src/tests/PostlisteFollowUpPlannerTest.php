<?php
// organizer/src/tests/PostlisteFollowUpPlannerTest.php
use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/../class/PostlisteFollowUpPlanner.php';

/**
 * Decision rules of the `postliste` follow-up plan, with a fixed clock.
 * Day 0 = the request; "day N" below means N days after it.
 */
class PostlisteFollowUpPlannerTest extends TestCase {
    const REQUEST_SENT = 1756713600; // 2025-09-01T08:00:00Z

    private function day(int $days, int $hours = 0): int {
        return self::REQUEST_SENT + $days * 86400 + $hours * 3600;
    }

    private function email(string $type, int $ts, $statusType = null, bool $ignore = false): ThreadEmail {
        $email = new ThreadEmail();
        $email->email_type = $type;
        $email->timestamp_received = $ts;
        $email->status_type = $statusType;
        $email->ignore = $ignore;
        return $email;
    }

    private function request(): ThreadEmail {
        return $this->email('OUT', self::REQUEST_SENT, ThreadEmailStatusType::OUR_REQUEST);
    }

    private function decide(array $emails, int $now, array $npReplies = [], array $reminders = []): PostlisteFollowUpDecision {
        return PostlisteFollowUpPlanner::decide($emails, $npReplies, $reminders, $now);
    }

    // --- timing ---

    public function testNothingWithoutOutEmail(): void {
        $decision = $this->decide([$this->email('IN', $this->day(1))], $this->day(30));

        $this->assertNull($decision->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_NO_OUT_EMAIL, $decision->reason);
    }

    public function testReminder1DueOnDay10NotBefore(): void {
        $day9 = $this->decide([$this->request()], $this->day(9, 23));
        $day10 = $this->decide([$this->request()], $this->day(10));

        $this->assertNull($day9->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_NOT_DUE, $day9->reason);

        $this->assertEquals(1, $day10->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_REMINDER_DUE, $day10->reason);
        $this->assertEquals(self::REQUEST_SENT, $day10->anchor);
        $this->assertEquals(self::REQUEST_SENT, $day10->firstOutSent);
    }

    public function testReminder2DueOnDay20AfterReminder1(): void {
        $reminders = [$this->day(10)];

        $day19 = $this->decide([$this->request()], $this->day(19, 23), [], $reminders);
        $day20 = $this->decide([$this->request()], $this->day(20), [], $reminders);

        $this->assertNull($day19->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_NOT_DUE, $day19->reason);
        $this->assertEquals(2, $day20->reminder);
    }

    public function testReminder2WaitsForDay20EvenIfReminder1WasLate(): void {
        // Reminder 1 went out on day 15 (e.g. the cron was down). Reminder 2 is
        // still measured from the request, not from reminder 1.
        $decision = $this->decide([$this->request()], $this->day(20), [], [$this->day(15)]);

        $this->assertEquals(2, $decision->reminder);
    }

    public function testNothingAfterTwoReminders(): void {
        $decision = $this->decide([$this->request()], $this->day(60), [], [$this->day(10), $this->day(20)]);

        $this->assertNull($decision->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_ALL_REMINDERS_SENT, $decision->reason);
    }

    public function testFirstOutEmailIsTheAnchorWhenSeveralOutEmails(): void {
        // Reminder 1 has been synced back as a second OUT email; the count
        // still runs from the original request.
        $emails = [$this->request(), $this->email('OUT', $this->day(10), ThreadEmailStatusType::OUR_REQUEST)];

        $decision = $this->decide($emails, $this->day(20), [], [$this->day(10)]);

        $this->assertEquals(2, $decision->reminder);
        $this->assertEquals(self::REQUEST_SENT, $decision->anchor);
    }

    // --- incoming emails ---

    public function testReceiptAndMoreTimeDoNotCountAsAnswers(): void {
        $emails = [
            $this->request(),
            $this->email('IN', $this->day(0, 1), ThreadEmailStatusType::REQUEST_RECEIPT),
            $this->email('IN', $this->day(3), 'ASKING_FOR_MORE_TIME'),
        ];

        $decision = $this->decide($emails, $this->day(10));

        $this->assertEquals(1, $decision->reminder);
    }

    /**
     * @dataProvider substantiveStatuses
     */
    public function testSubstantiveReplyPausesReminders($statusType): void {
        $emails = [$this->request(), $this->email('IN', $this->day(5), $statusType)];

        $decision = $this->decide($emails, $this->day(10));

        $this->assertNull($decision->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_ANSWERED, $decision->reason);
    }

    public static function substantiveStatuses(): array {
        return [
            'information release' => [ThreadEmailStatusType::INFORMATION_RELEASE],
            'response to request' => ['RESPONSE_TO_REQUEST'],
            'asking for clarification needs a human' => ['ASKING_FOR_CLARIFICATION'],
            'asking for copy needs a human' => ['ASKING_FOR_COPY'],
            'unclassified (null)' => [null],
            'unclassified (unknown)' => ['unknown'],
        ];
    }

    public function testIgnoredIncomingEmailIsNotAnAnswer(): void {
        $emails = [$this->request(), $this->email('IN', $this->day(5), null, ignore: true)];

        $decision = $this->decide($emails, $this->day(10));

        $this->assertEquals(1, $decision->reminder);
    }

    public function testRejectionStopsEverything(): void {
        $emails = [$this->request(), $this->email('IN', $this->day(5), ThreadEmailStatusType::REQUEST_REJECTED)];

        $decision = $this->decide($emails, $this->day(10));

        $this->assertNull($decision->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_REQUEST_REJECTED, $decision->reason);
    }

    public function testRejectionStopsEvenWhenBeforeNpReply(): void {
        // A rejection is final regardless of where in the timeline it sits.
        $emails = [$this->request(), $this->email('IN', $this->day(5), 'REQUEST_REJECTED')];

        $decision = $this->decide($emails, $this->day(30), [$this->day(6)]);

        $this->assertEquals(PostlisteFollowUpDecision::REASON_REQUEST_REJECTED, $decision->reason);
    }

    // --- reclassified (unreadable) reply and norske-postlister's own reply ---

    public function testUnreadableReplyPlusNpReplyRestartsTheCount(): void {
        // Day 5: entity sends an unreadable journal. norske-postlister marks it
        // RESPONSE_UNREADABLE and replies on day 6. Nagging restarts from day 6.
        $emails = [$this->request(), $this->email('IN', $this->day(5), 'RESPONSE_UNREADABLE')];
        $npReplies = [$this->day(6)];

        $day15 = $this->decide($emails, $this->day(15, 23), $npReplies);
        $day16 = $this->decide($emails, $this->day(16), $npReplies);

        $this->assertNull($day15->reminder);
        $this->assertEquals(PostlisteFollowUpDecision::REASON_NOT_DUE, $day15->reason);

        $this->assertEquals(1, $day16->reminder);
        $this->assertEquals($this->day(6), $day16->anchor);
        $this->assertEquals(self::REQUEST_SENT, $day16->firstOutSent, 'the template still quotes the original request date');
    }

    public function testRemindersBeforeNpReplyAreNotCounted(): void {
        // Reminder 1 on day 10, unreadable reply day 12, NP reply day 13:
        // the two-reminder budget starts over from day 13.
        $emails = [$this->request(), $this->email('IN', $this->day(12), 'RESPONSE_UNREADABLE')];
        $npReplies = [$this->day(13)];
        $reminders = [$this->day(10)];

        $day23 = $this->decide($emails, $this->day(23), $npReplies, $reminders);
        $day33 = $this->decide($emails, $this->day(33), $npReplies, array_merge($reminders, [$this->day(23)]));

        $this->assertEquals(1, $day23->reminder);
        $this->assertEquals(2, $day33->reminder);
    }

    public function testSubstantiveReplyAfterNpReplyPauses(): void {
        $emails = [
            $this->request(),
            $this->email('IN', $this->day(5), 'RESPONSE_UNREADABLE'),
            $this->email('IN', $this->day(8), 'INFORMATION_RELEASE'),
        ];

        $decision = $this->decide($emails, $this->day(30), [$this->day(6)]);

        $this->assertEquals(PostlisteFollowUpDecision::REASON_ANSWERED, $decision->reason);
    }

    public function testSubstantiveReplyBeforeNpReplyIsConsideredHandled(): void {
        // norske-postlister replied after the entity's answer: whatever came
        // before their reply has been dealt with on their side.
        $emails = [$this->request(), $this->email('IN', $this->day(5), 'INFORMATION_RELEASE')];

        $decision = $this->decide($emails, $this->day(16), [$this->day(6)]);

        $this->assertEquals(1, $decision->reminder);
    }

    public function testStringTimestampsAccepted(): void {
        // Thread::loadFromDatabase() hands timestamps over as strings.
        $out = $this->email('OUT', 0);
        $out->timestamp_received = '2025-09-01T08:00:00+00:00';

        $decision = $this->decide([$out], $this->day(10));

        $this->assertEquals(1, $decision->reminder);
        $this->assertEquals(self::REQUEST_SENT, $decision->anchor);
    }
}
