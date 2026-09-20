<?php
// organizer/src/tests/NpApiServiceClassifyReplyTest.php
use PHPUnit\Framework\TestCase;
use App\Enums\ThreadEmailStatusType;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/NpApiService.php';

/**
 * Package 2 of the postliste spec: follow-up plan on creation, reclassifying an
 * IN email, and queueing a reply from the thread's own profile.
 */
class NpApiServiceClassifyReplyTest extends TestCase {
    const NP_ENTITY = '9999-test-entity-development';
    const ENTITY_EMAIL = 'public-entity@dev.offpost.no';

    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private function createPostlisteThread(string $period = '2026-W38'): Thread {
        $created = NpApiService::createThread(
            self::NP_ENTITY, 'Offentlig journal uke 38', 'Innhold',
            ['postliste', 'postliste:' . $period], Thread::REQUEST_FOLLOW_UP_PLAN_POSTLISTE
        );
        return Thread::loadFromDatabase($created['thread_id']);
    }

    private function insertEmail(string $threadId, string $type, string $ts, ?string $statusType, ?string $autoClassification = null, bool $ignore = false, ?string $answer = null): string {
        return Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers,
                 status_type, status_text, auto_classification, ignore, answer)
             VALUES (?, ?, ?, ?, ?::bytea, NULL, ?, ?, ?, ?, ?) RETURNING id",
            [$threadId, $ts, $ts, $type, 'content', $statusType, 'Svar mottatt', $autoClassification, $ignore ? 't' : 'f', $answer]
        );
    }

    // --- request_follow_up_plan on createThread ---

    public function testCreateThreadWithPostlistePlan(): void {
        $thread = $this->createPostlisteThread();

        $this->assertEquals(Thread::REQUEST_FOLLOW_UP_PLAN_POSTLISTE, $thread->request_follow_up_plan);
        $this->assertEquals(Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA, $thread->request_law_basis);
    }

    public function testCreateThreadPlanDefaultsToSpeedy(): void {
        $nullPlan = NpApiService::createThread(self::NP_ENTITY, 'T', 'B', ['postliste', 'postliste:2026-W01']);
        $emptyPlan = NpApiService::createThread(self::NP_ENTITY, 'T', 'B', ['postliste', 'postliste:2026-W02'], '');
        $slowPlan = NpApiService::createThread(self::NP_ENTITY, 'T', 'B', ['postliste', 'postliste:2026-W03'], 'slow');

        $this->assertEquals('speedy', Thread::loadFromDatabase($nullPlan['thread_id'])->request_follow_up_plan);
        $this->assertEquals('speedy', Thread::loadFromDatabase($emptyPlan['thread_id'])->request_follow_up_plan);
        $this->assertEquals('slow', Thread::loadFromDatabase($slowPlan['thread_id'])->request_follow_up_plan);
    }

    public function testCreateThreadUnknownPlanThrows(): void {
        $this->expectException(NpApiValidationException::class);
        $this->expectExceptionMessage('Unknown request_follow_up_plan: fast');
        NpApiService::createThread(self::NP_ENTITY, 'T', 'B', ['postliste', 'postliste:2026-W04'], 'fast');
    }

    // --- classifyEmail ---

    public function testClassifyEmailSetsStatusClearsAutoClassificationAndLogs(): void {
        // :: Setup
        $thread = $this->createPostlisteThread();
        $this->insertEmail($thread->id, 'OUT', '2026-09-21T08:00:00+00:00', 'OUR_REQUEST');
        $emailId = $this->insertEmail(
            $thread->id, 'IN', '2026-09-25T08:00:00+00:00', 'INFORMATION_RELEASE',
            autoClassification: 'prompt', ignore: false, answer: 'Journal vedlagt'
        );

        // :: Act
        $result = NpApiService::classifyEmail(
            $thread->id, $emailId, 'RESPONSE_UNREADABLE', 'Skannet journal uten tekst'
        );

        // :: Assert
        $this->assertEquals([
            'classified' => true,
            'thread_id' => $thread->id,
            'email_id' => $emailId,
            'status_type' => 'RESPONSE_UNREADABLE',
            'status_text' => 'Skannet journal uten tekst',
        ], $result);

        $row = Database::queryOne(
            "SELECT status_type, status_text, auto_classification, ignore, answer FROM thread_emails WHERE id = ?",
            [$emailId]
        );
        $this->assertEquals('RESPONSE_UNREADABLE', $row['status_type']);
        $this->assertEquals('Skannet journal uten tekst', $row['status_text']);
        $this->assertNull($row['auto_classification'], 'manual classification must clear the AI marker so extraction does not overwrite it');
        $this->assertFalse((bool)$row['ignore'], 'ignore flag is left as it was');
        $this->assertEquals('Journal vedlagt', $row['answer'], 'answer is left as it was');

        $emailHistory = Database::query(
            "SELECT action, user_id, details FROM thread_email_history WHERE thread_id = ? AND email_id = ?",
            [$thread->id, $emailId]
        );
        $this->assertCount(1, $emailHistory, json_encode($emailHistory, JSON_PRETTY_PRINT));
        $this->assertEquals('classified', $emailHistory[0]['action']);
        $this->assertEquals(NpApiService::THREAD_OWNER_USER_ID, $emailHistory[0]['user_id']);

        $threadHistory = Database::query(
            "SELECT action, user_id, details FROM thread_history WHERE thread_id = ? AND action = ?",
            [$thread->id, NpApiService::HISTORY_ACTION_EMAIL_CLASSIFIED]
        );
        $this->assertCount(1, $threadHistory, json_encode($threadHistory, JSON_PRETTY_PRINT));
        $this->assertEquals(NpApiService::THREAD_OWNER_USER_ID, $threadHistory[0]['user_id']);
        $details = json_decode($threadHistory[0]['details'], true);
        $this->assertEquals([
            'status_type' => 'RESPONSE_UNREADABLE',
            'status_text' => 'Skannet journal uten tekst',
            'previous_status_type' => 'INFORMATION_RELEASE',
            'email_id' => $emailId,
        ], $details);

        // The thread view renders every history action; an unknown one throws.
        $this->assertStringContainsString(
            'RESPONSE_UNREADABLE',
            (new ThreadHistory())->formatActionForDisplay($threadHistory[0]['action'], $threadHistory[0]['details'])
        );
    }

    public function testClassifyEmailDropsIngestPlaceholderStatusText(): void {
        $thread = $this->createPostlisteThread();
        $emailId = $this->insertEmail($thread->id, 'IN', '2026-09-25T08:00:00+00:00', null);

        $result = NpApiService::classifyEmail($thread->id, $emailId, 'RESPONSE_UNREADABLE', ThreadEmail::UNCLASSIFIED_STATUS_TEXT);

        $this->assertEquals('', $result['status_text']);
    }

    public function testClassifyEmailRejectsOutEmail(): void {
        $thread = $this->createPostlisteThread();
        $emailId = $this->insertEmail($thread->id, 'OUT', '2026-09-21T08:00:00+00:00', 'OUR_REQUEST');

        $this->expectException(NpApiValidationException::class);
        NpApiService::classifyEmail($thread->id, $emailId, 'RESPONSE_UNREADABLE', '');
    }

    /**
     * @dataProvider badStatusTypes
     */
    public function testClassifyEmailRejectsUnknownStatusType(string $statusType): void {
        $thread = $this->createPostlisteThread();
        $emailId = $this->insertEmail($thread->id, 'IN', '2026-09-25T08:00:00+00:00', null);

        $this->expectException(NpApiValidationException::class);
        NpApiService::classifyEmail($thread->id, $emailId, $statusType, '');
    }

    public static function badStatusTypes(): array {
        return [
            'not an enum value' => ['UNREADABLE'],
            'lowercase' => ['response_unreadable'],
            'unknown is the absence of a classification' => ['unknown'],
        ];
    }

    public function testClassifyEmailUnknownEmailIsNotFound(): void {
        $thread = $this->createPostlisteThread();
        $other = $this->createPostlisteThread('2026-W39');
        $emailInOtherThread = $this->insertEmail($other->id, 'IN', '2026-09-25T08:00:00+00:00', null);

        $this->expectException(NpApiEntityNotFoundException::class);
        NpApiService::classifyEmail($thread->id, $emailInOtherThread, 'RESPONSE_UNREADABLE', '');
    }

    public function testClassifyEmailOnNonNpThreadIsNotFound(): void {
        $thread = new Thread();
        $thread->title = 'Privat';
        $thread->my_name = 'Test Person';
        $thread->my_email = 'test@offpost.no';
        $thread->labels = ['annet'];
        $thread->initial_request = 'x';
        $thread->sending_status = Thread::SENDING_STATUS_STAGING;
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = false;
        $thread->emails = [];
        $other = ThreadStorageManager::getInstance()->createThread('000000000-test-entity-development', $thread, 'test-user');
        $emailId = $this->insertEmail($other->id, 'IN', '2026-09-25T08:00:00+00:00', null);

        $this->expectException(NpApiEntityNotFoundException::class);
        $this->expectExceptionMessage('Unknown thread');
        NpApiService::classifyEmail($other->id, $emailId, 'RESPONSE_UNREADABLE', '');
    }

    // --- replyToThread ---

    public function testReplyQueuesSendingFromThreadProfileToEntity(): void {
        // :: Setup
        $thread = $this->createPostlisteThread();
        $this->insertEmail($thread->id, 'OUT', '2026-09-21T08:00:00+00:00', 'OUR_REQUEST');

        // :: Act
        $result = NpApiService::replyToThread($thread->id, 'Re: Offentlig journal uke 38', "Hei,\n\nVedlegget kunne ikke leses.");

        // :: Assert
        $this->assertTrue($result['queued']);
        $this->assertEquals($thread->id, $result['thread_id']);

        $sending = ThreadEmailSending::getById($result['sending_id']);
        $this->assertNotNull($sending);
        $this->assertEquals(ThreadEmailSending::STATUS_READY_FOR_SENDING, $sending->status);
        $this->assertEquals(self::ENTITY_EMAIL, $sending->email_to);
        $this->assertEquals($thread->my_email, $sending->email_from);
        $this->assertEquals($thread->my_name, $sending->email_from_name);
        $this->assertEquals('Re: Offentlig journal uke 38', $sending->email_subject);
        $this->assertEquals("Hei,\n\nVedlegget kunne ikke leses.\n\n--\n" . $thread->my_name, $sending->email_content);

        $history = Database::query(
            "SELECT action, user_id, details FROM thread_history WHERE thread_id = ? AND action = ?",
            [$thread->id, NpApiService::HISTORY_ACTION_REPLY]
        );
        $this->assertCount(1, $history, json_encode($history, JSON_PRETTY_PRINT));
        $this->assertEquals(NpApiService::THREAD_OWNER_USER_ID, $history[0]['user_id']);
        $this->assertEquals([
            'email_sending_ids' => [$result['sending_id']],
            'recipient' => self::ENTITY_EMAIL,
            'subject' => 'Re: Offentlig journal uke 38',
        ], json_decode($history[0]['details'], true));
        $this->assertStringContainsString(
            'Re: Offentlig journal uke 38',
            (new ThreadHistory())->formatActionForDisplay($history[0]['action'], $history[0]['details'])
        );
    }

    public function testSecondReplySameDayIsRefused(): void {
        $thread = $this->createPostlisteThread();
        NpApiService::replyToThread($thread->id, 'Re', 'Første');

        $this->expectException(NpApiCapExceededException::class);
        NpApiService::replyToThread($thread->id, 'Re', 'Andre');
    }

    public function testReplyCapIsPerThread(): void {
        $first = $this->createPostlisteThread('2026-W38');
        $second = $this->createPostlisteThread('2026-W39');
        NpApiService::replyToThread($first->id, 'Re', 'Første');

        $result = NpApiService::replyToThread($second->id, 'Re', 'Første på annen tråd');

        $this->assertTrue($result['queued']);
    }

    public function testReplyRequiresSubjectAndBody(): void {
        $thread = $this->createPostlisteThread();

        $this->expectException(NpApiValidationException::class);
        NpApiService::replyToThread($thread->id, '  ', 'Body');
    }

    public function testReplyOnUnknownThreadIsNotFound(): void {
        $this->expectException(NpApiEntityNotFoundException::class);
        NpApiService::replyToThread('00000000-0000-4000-8000-000000000000', 'Re', 'Body');
    }
}
