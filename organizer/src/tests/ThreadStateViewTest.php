<?php
// organizer/src/tests/ThreadStateViewTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../class/ThreadState/ThreadStateView.php';

class ThreadStateViewTest extends TestCase {
    protected function setUp(): void {
        Database::beginTransaction();
    }

    protected function tearDown(): void {
        Database::rollBack();
    }

    private int $threadCounter = 0;

    private function createFixedThread(): string {
        $this->threadCounter++;
        $thread = new Thread();
        $thread->title = 'Thread state view test thread';
        $thread->my_name = 'Test Person';
        $thread->my_email = "test-person-threadstateview-{$this->threadCounter}@example.com";
        $thread->labels = [];
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = true;
        $thread->initial_request = 'Please release the documents.';
        $thread->sending_status = Thread::SENDING_STATUS_READY_FOR_SENDING;
        $thread->request_law_basis = Thread::REQUEST_LAW_BASIS_OFFENTLEGLOVA;
        $thread->request_follow_up_plan = Thread::REQUEST_FOLLOW_UP_PLAN_SPEEDY;
        $thread->sentComment = null;
        $created = ThreadStorageManager::getInstance()->createThread(
            '000000000-test-entity-development',
            $thread,
            'test-user'
        );
        return $created->id;
    }

    /**
     * @return string the inserted email's id
     */
    private function insertEmail(
        string $threadId,
        string $timestampReceived,
        ?array $threadState,
        ?string $threadStateType = null,
        ?string $threadStateSource = null
    ): string {
        return Database::queryValue(
            "INSERT INTO thread_emails
                (thread_id, timestamp_received, datetime_received, content, thread_state, thread_state_type, thread_state_source)
             VALUES (?, ?, ?, ?::bytea, ?::jsonb, ?, ?) RETURNING id",
            [
                $threadId,
                $timestampReceived,
                $timestampReceived,
                'Body text',
                $threadState === null ? null : json_encode($threadState),
                $threadStateType,
                $threadStateSource,
            ]
        );
    }

    private function smallState(string $askedFor = 'Valgprotokoll 2023', string $notes = ''): array {
        return [
            'schema_version' => 1,
            'request' => ['summary' => 'Innsyn i valgprotokoll', 'law_basis' => 'offentleglova', 'sent_at' => '2023-09-12'],
            'items' => [
                [
                    'id' => '1',
                    'asked_for' => $askedFor,
                    'status' => 'PARTLY_RELEASED',
                    'denial_basis' => [
                        'refs' => ['offentleglova § 13'],
                        'text' => 'Delvis unntatt',
                        'issues' => ['INCOMPLETE_REFERENCE'],
                    ],
                    'released_in_email_ids' => [],
                    'note' => '',
                ],
            ],
            'waiting_for' => 'NOBODY',
            'asks_to_us' => [],
            'case_numbers' => ['23/1234'],
            'dates' => [],
            'complaints' => [],
            'notes' => $notes,
            'extra' => [],
        ];
    }

    public function testRenderBlockExactHtmlForSmallState(): void {
        // :: Setup
        $state = $this->smallState();

        // :: Act
        $html = ThreadStateView::renderBlock($state, 'PARTLY_DENIED_PARTLY_RELEASED', 'auto', false, 'thread-1');

        // :: Assert
        $expected = '<div class="thread-state">'
            . '<h2>Thread state</h2>'
            . '<p class="thread-state-status">'
            . '<span class="label classification label_warn" title="PARTLY_DENIED_PARTLY_RELEASED">Delvis avslått, delvis utlevert</span>'
            . ' <span class="thread-state-source">(auto)</span>'
            . '</p>'
            . '<p class="thread-state-waiting-for"><strong>Waiting for:</strong> Ingen venter</p>'
            . '<table class="thread-state-items">'
            . '<thead><tr><th>Asked for</th><th>Status</th><th>Denial basis</th></tr></thead>'
            . '<tbody>'
            . '<tr>'
            . '<td>Valgprotokoll 2023</td>'
            . '<td><span title="PARTLY_RELEASED">Delvis utlevert</span></td>'
            . '<td>'
            . '<span class="denial-refs">offentleglova § 13</span>'
            . ' <span class="denial-text">Delvis unntatt</span>'
            . ' <span class="label classification label_warn" title="INCOMPLETE_REFERENCE">Mangelfull henvisning</span>'
            . '</td>'
            . '</tr>'
            . '</tbody></table>'
            . '<p class="thread-state-case-numbers"><strong>Case numbers:</strong> 23/1234</p>'
            . '</div>';
        $this->assertEquals($expected, $html);
    }

    public function testRenderBlockShowsNotMachineReadableBadge(): void {
        // :: Setup
        $state = $this->smallState();
        $state['items'][0]['denial_basis']['issues'] = ['NOT_MACHINE_READABLE'];

        // :: Act
        $html = ThreadStateView::renderBlock($state, 'PARTLY_DENIED_PARTLY_RELEASED', 'auto', false, 'thread-1');

        // :: Assert
        $this->assertStringContainsString(
            '<span class="label classification label_warn" title="NOT_MACHINE_READABLE">Ikke maskinlesbart</span>',
            $html
        );
    }

    public function testRenderBlockEscapesScriptInAskedForAndNotes(): void {
        // :: Setup
        $state = [
            'schema_version' => 1,
            'request' => ['summary' => '', 'law_basis' => '', 'sent_at' => null],
            'items' => [
                [
                    'id' => '1',
                    'asked_for' => '<script>alert(1)</script>',
                    'status' => 'NOT_ANSWERED',
                    'denial_basis' => null,
                    'released_in_email_ids' => [],
                    'note' => '',
                ],
            ],
            'waiting_for' => 'ENTITY',
            'asks_to_us' => [],
            'case_numbers' => [],
            'dates' => [],
            'complaints' => [],
            'notes' => '<script>alert(2)</script>',
            'extra' => [],
        ];

        // :: Act
        $html = ThreadStateView::renderBlock($state, 'WAITING_FOR_ENTITY', null, false, 'thread-1');

        // :: Assert
        $expected = '<div class="thread-state">'
            . '<h2>Thread state</h2>'
            . '<p class="thread-state-status">'
            . '<span class="label classification label_info" title="WAITING_FOR_ENTITY">Venter på offentlig organ</span>'
            . '</p>'
            . '<p class="thread-state-waiting-for"><strong>Waiting for:</strong> Venter på offentlig organ</p>'
            . '<table class="thread-state-items">'
            . '<thead><tr><th>Asked for</th><th>Status</th><th>Denial basis</th></tr></thead>'
            . '<tbody>'
            . '<tr>'
            . '<td>&lt;script&gt;alert(1)&lt;/script&gt;</td>'
            . '<td><span title="NOT_ANSWERED">Ikke besvart</span></td>'
            . '<td></td>'
            . '</tr>'
            . '</tbody></table>'
            . '<p class="thread-state-notes"><strong>Notes:</strong> &lt;script&gt;alert(2)&lt;/script&gt;</p>'
            . '</div>';
        $this->assertEquals($expected, $html, 'Raw <script> must never appear unescaped: ' . $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderBlockShowsAdminLinkOnlyForAdmins(): void {
        // :: Setup
        $state = $this->smallState();

        // :: Act
        $htmlForAdmin = ThreadStateView::renderBlock($state, 'ANSWERED', 'manual', true, 'thread-42');
        $htmlForNonAdmin = ThreadStateView::renderBlock($state, 'ANSWERED', 'manual', false, 'thread-42');

        // :: Assert
        $this->assertStringContainsString(
            '<p class="thread-state-admin-link"><a href="/thread-analysis/thread?id=thread-42">Analysis details</a></p>',
            $htmlForAdmin
        );
        $this->assertStringNotContainsString('thread-analysis', $htmlForNonAdmin);
    }

    public function testStatusTypeLabelClassCoversEveryThreadStateType(): void {
        // :: Setup
        $allowed = ['label_ok', 'label_warn', 'label_error', 'label_info'];

        // :: Act
        $mapping = ThreadStateView::STATUS_TYPE_LABEL_CLASS;

        // :: Assert
        foreach (App\Enums\ThreadStateType::cases() as $case) {
            $this->assertArrayHasKey(
                $case->value,
                $mapping,
                'ThreadStateType::' . $case->name . ' has no badge class mapping: ' . json_encode($mapping, JSON_PRETTY_PRINT)
            );
            $this->assertContains(
                $mapping[$case->value],
                $allowed,
                'ThreadStateType::' . $case->name . " maps to an unknown class '{$mapping[$case->value]}'"
            );
        }
    }

    public function testRenderEmailBadgeShowsTypeAndFoldedState(): void {
        // :: Setup
        $state = $this->smallState();

        // :: Act
        $html = ThreadStateView::renderEmailBadge($state, 'WAITING_FOR_ENTITY', 'email-1');

        // :: Assert
        $expected = '<span class="thread-state-badge">'
            . '<span class="label classification label_info" title="WAITING_FOR_ENTITY">Venter på offentlig organ (after this email)</span>'
            . '</span>'
            . ' <a href="#" class="content-dialog-link" data-dialog-title="Thread state after this email"'
            . ' data-dialog-template="thread-state-email-1">Show state</a>'
            . '<template id="thread-state-email-1"><pre>'
            . htmlspecialchars(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES)
            . '</pre></template>';
        $this->assertEquals($expected, $html);
    }

    public function testRenderEmailBadgeEscapesTheStateBlob(): void {
        // :: Setup
        $state = $this->smallState('<script>alert(3)</script>');

        // :: Act
        $html = ThreadStateView::renderEmailBadge($state, 'ANSWERED', 'email-1');

        // :: Assert
        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
    }

    public function testRenderEmailBadgeEscapesScriptInsideTheTemplate(): void {
        // A <script> inside the state must stay escaped inside the hidden
        // <template> too - the dialog only ever reads the template's own
        // cloned DOM, never raw HTML, so nothing here may execute.
        // :: Setup
        $state = $this->smallState('<script>alert(4)</script>');

        // :: Act
        $html = ThreadStateView::renderEmailBadge($state, 'ANSWERED', 'email-2');

        // :: Assert
        $this->assertStringContainsString('<template id="thread-state-email-2">', $html);
        $this->assertStringNotContainsString('<script>alert(4)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(4)&lt;/script&gt;', $html);
    }

    public function testLoadEmailStatesReturnsOnlyEmailsWithStateOrderedOldestFirst(): void {
        // :: Setup
        $threadId = $this->createFixedThread();
        $stateA = $this->smallState('First request');
        $stateB = $this->smallState('Second request');
        $this->insertEmail($threadId, '2023-01-01 10:00:00+00', null); // no state - excluded
        $emailIdOld = $this->insertEmail($threadId, '2023-01-02 10:00:00+00', $stateA, 'WAITING_FOR_ENTITY', 'auto');
        $emailIdNew = $this->insertEmail($threadId, '2023-01-03 10:00:00+00', $stateB, 'ANSWERED', 'manual');

        // :: Act
        $states = ThreadStateView::loadEmailStates($threadId);

        // :: Assert
        $this->assertCount(2, $states, 'Expected only the two emails with a thread_state: ' . json_encode($states, JSON_PRETTY_PRINT));
        $this->assertEquals($emailIdOld, $states[0]['id']);
        $this->assertEquals($emailIdNew, $states[1]['id']);
        $this->assertEquals('WAITING_FOR_ENTITY', $states[0]['thread_state_type']);
        $this->assertEquals('auto', $states[0]['thread_state_source']);
        $this->assertEquals($stateA, $states[0]['thread_state']);
        $this->assertEquals('ANSWERED', $states[1]['thread_state_type']);
        $this->assertEquals('manual', $states[1]['thread_state_source']);
        $this->assertEquals($stateB, $states[1]['thread_state']);
    }
}
