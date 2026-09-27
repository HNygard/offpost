<?php
// organizer/src/tests/ThreadEventAnalysisTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/analysis/ThreadEventAnalysis.php';

class ThreadEventAnalysisTest extends TestCase {
    // -- buildEventInput --

    public function testBuildEventInputWithNoPreviousStateAndNoAttachments(): void {
        // :: Setup
        $thread = [
            'id' => 't1',
            'title' => 'Valgprotokoll',
            'entity_name' => 'Testkommune',
            'entity_id' => 'e1',
            'initial_request' => 'Vi ber om valgprotokoll for 2023.',
        ];
        $email = [
            'id' => 'em1',
            'direction' => 'OUT',
            'datetime_received' => '2023-09-12T10:00:00+02:00',
            'from' => 'oss@example.org',
            'to' => ['post@testkommune.no'],
            'cc' => [],
            'subject' => 'Innsynskrav',
            'body_plain' => 'Vi ber om innsyn i valgprotokoll for 2023.',
        ];

        // :: Act
        $input = ThreadEventAnalysis::buildEventInput($thread, null, $email, []);

        // :: Assert
        $expected = <<<TXT
# Thread
id: t1
title: Valgprotokoll
entity: Testkommune (e1)
initial_request: Vi ber om valgprotokoll for 2023.

# Previous state
null

# Email
id: em1
direction: OUT
date: 2023-09-12T10:00:00+02:00
from: oss@example.org
to: post@testkommune.no
cc:
subject: Innsynskrav

# Body
Vi ber om innsyn i valgprotokoll for 2023.

# Attachments
(none)

TXT;
        // "cc:" has an empty value, so the real line ends "cc: " (trailing
        // space); heredoc editing tends to strip trailing whitespace, so it
        // is restored explicitly here rather than relying on the literal.
        $expected = str_replace("cc:\n", "cc: \n", $expected);
        $this->assertEquals(rtrim($expected) . "\n", $input);
    }

    public function testBuildEventInputWithPreviousStateAndHtmlBodyFallback(): void {
        // :: Setup
        $thread = ['id' => 't1', 'title' => 'X', 'entity_name' => 'Y', 'entity_id' => 'e1', 'initial_request' => null];
        $previousState = ['schema_version' => 1, 'waiting_for' => 'ENTITY'];
        $email = [
            'id' => 'em2',
            'direction' => 'IN',
            'datetime_received' => '2023-09-20T08:00:00+02:00',
            'from' => 'postmottak@testkommune.no',
            'to' => ['oss@example.org'],
            'cc' => ['annen@example.org', 'tredje@example.org'],
            'subject' => 'Re: Innsynskrav',
            'body_plain' => null,
            'body_html' => '<p>Vi har <b>mottatt</b> din henvendelse.</p>',
        ];

        // :: Act
        $input = ThreadEventAnalysis::buildEventInput($thread, $previousState, $email, []);

        // :: Assert
        $expected = <<<TXT
# Thread
id: t1
title: X
entity: Y (e1)
initial_request: (none)

# Previous state
{
    "schema_version": 1,
    "waiting_for": "ENTITY"
}

# Email
id: em2
direction: IN
date: 2023-09-20T08:00:00+02:00
from: postmottak@testkommune.no
to: oss@example.org
cc: annen@example.org, tredje@example.org
subject: Re: Innsynskrav

# Body
Vi har mottatt din henvendelse.

# Attachments
(none)

TXT;
        $this->assertEquals(rtrim($expected) . "\n", $input);
    }

    public function testBuildEventInputCutsLongBody(): void {
        // :: Setup
        $thread = ['id' => 't1', 'title' => 'X', 'entity_name' => 'Y', 'entity_id' => 'e1', 'initial_request' => null];
        $longBody = str_repeat('B', 15005);
        $email = [
            'id' => 'em3', 'direction' => 'IN', 'datetime_received' => '2023-01-01', 'from' => '', 'to' => [], 'cc' => [], 'subject' => '',
            'body_plain' => $longBody,
        ];

        // :: Act
        $input = ThreadEventAnalysis::buildEventInput($thread, null, $email, []);

        // :: Assert
        $expectedBody = str_repeat('B', 15000) . "\n[CUT, original length: 15005 chars]";
        $this->assertStringContainsString($expectedBody, $input);
    }

    public function testBuildEventInputAttachmentWithExtractedTextIsCut(): void {
        // :: Setup
        $thread = ['id' => 't1', 'title' => 'X', 'entity_name' => 'Y', 'entity_id' => 'e1', 'initial_request' => null];
        $email = ['id' => 'em4', 'direction' => 'IN', 'datetime_received' => '2023-01-01', 'from' => '', 'to' => [], 'cc' => [], 'subject' => ''];
        $longText = str_repeat('A', 8005);
        $attachments = [
            [
                'filename' => 'vedtak.pdf',
                'filetype' => 'application/pdf',
                'extractions' => [
                    ['extracted_text' => ''],
                    ['extracted_text' => $longText],
                ],
            ],
        ];

        // :: Act
        $input = ThreadEventAnalysis::buildEventInput($thread, null, $email, $attachments);

        // :: Assert
        $this->assertStringContainsString("## vedtak.pdf (application/pdf)\n", $input);
        $this->assertStringContainsString(str_repeat('A', 8000) . "\n[CUT, original length: 8005 chars]", $input);
    }

    public function testBuildEventInputAttachmentWithNoExtractedText(): void {
        // :: Setup
        $thread = ['id' => 't1', 'title' => 'X', 'entity_name' => 'Y', 'entity_id' => 'e1', 'initial_request' => null];
        $email = ['id' => 'em5', 'direction' => 'IN', 'datetime_received' => '2023-01-01', 'from' => '', 'to' => [], 'cc' => [], 'subject' => ''];
        $attachments = [['filename' => 'scan.pdf', 'filetype' => 'application/pdf', 'extractions' => []]];

        // :: Act
        $input = ThreadEventAnalysis::buildEventInput($thread, null, $email, $attachments);

        // :: Assert
        $this->assertStringContainsString("## scan.pdf (application/pdf)\n(no extracted text)", $input);
    }

    // -- buildJsonSchema --

    public function testBuildJsonSchema(): void {
        // :: Act
        $schema = ThreadEventAnalysis::buildJsonSchema();

        // :: Assert
        $expected = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['email_type', 'email_note', 'email_type_gap', 'thread_state'],
            'properties' => [
                'email_type' => ['type' => 'string', 'enum' => [
                    'OUR_REQUEST', 'CLARIFICATION_SENT', 'COPY_SENT',
                    'REQUEST_RECEIPT', 'ASKING_FOR_MORE_TIME', 'ASKING_FOR_COPY', 'ASKING_FOR_CLARIFICATION',
                    'RESPONSE_TO_REQUEST', 'REQUEST_REJECTED', 'INFORMATION_RELEASE', 'RESPONSE_UNREADABLE',
                    'unknown',
                ]],
                'email_note' => ['type' => 'string'],
                'email_type_gap' => ['type' => 'string'],
                'thread_state' => [
                    'type' => 'object',
                    'required' => ['schema_version', 'request', 'items', 'waiting_for', 'asks_to_us', 'case_numbers', 'dates', 'complaints', 'notes', 'extra'],
                    'properties' => [
                        'schema_version' => ['type' => 'integer', 'const' => 1],
                        'request' => [
                            'type' => 'object',
                            'required' => ['summary', 'law_basis', 'sent_at'],
                            'properties' => [
                                'summary' => ['type' => 'string'],
                                'law_basis' => ['type' => 'string'],
                                'sent_at' => ['type' => ['string', 'null']],
                            ],
                        ],
                        'items' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'asked_for', 'status', 'denial_basis', 'released_in_email_ids', 'note'],
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'asked_for' => ['type' => 'string'],
                                    'status' => ['type' => 'string', 'enum' => [
                                        'NOT_ANSWERED', 'ACKNOWLEDGED', 'BEING_EVALUATED', 'WILL_RELEASE',
                                        'WILL_RELEASE_PARTLY', 'PARTLY_RELEASED', 'RELEASED', 'DENIED',
                                        'NO_DOCUMENTS', 'WITHDRAWN',
                                    ]],
                                    'denial_basis' => [
                                        'type' => ['object', 'null'],
                                        'required' => ['refs', 'text', 'issues'],
                                        'properties' => [
                                            'refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'text' => ['type' => 'string'],
                                            'issues' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['NO_REASON_GIVEN', 'NO_LEGAL_REFERENCE', 'INCOMPLETE_REFERENCE']]],
                                        ],
                                    ],
                                    'released_in_email_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'note' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'waiting_for' => ['type' => 'string', 'enum' => ['ENTITY', 'US', 'NOBODY']],
                        'asks_to_us' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'case_numbers' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'dates' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['date', 'what', 'email_id'],
                                'properties' => [
                                    'date' => ['type' => 'string'],
                                    'what' => ['type' => 'string'],
                                    'email_id' => ['type' => ['string', 'null']],
                                ],
                            ],
                        ],
                        'complaints' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['status', 'item_ids', 'sent_email_id', 'decision_email_id', 'outcome'],
                                'properties' => [
                                    'status' => ['type' => 'string', 'enum' => ['SENT', 'FORWARDED', 'DECIDED', 'OMBUD_SENT', 'OMBUD_DECIDED']],
                                    'item_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'sent_email_id' => ['type' => ['string', 'null']],
                                    'decision_email_id' => ['type' => ['string', 'null']],
                                    'outcome' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'notes' => ['type' => 'string'],
                        'extra' => ['type' => 'object'],
                    ],
                ],
            ],
        ];
        $this->assertEquals($expected, $schema);
    }

    // -- validateOutput --

    private function validThreadStateArray(): array {
        return [
            'schema_version' => 1,
            'request' => ['summary' => 'Valgprotokoll 2023', 'law_basis' => 'offentleglova', 'sent_at' => '2023-09-12'],
            'items' => [
                ['id' => '1', 'asked_for' => 'Valgprotokoll', 'status' => 'NOT_ANSWERED', 'denial_basis' => null, 'released_in_email_ids' => [], 'note' => ''],
            ],
            'waiting_for' => 'ENTITY',
            'asks_to_us' => [],
            'case_numbers' => [],
            'dates' => [],
            'complaints' => [],
            'notes' => '',
            'extra' => [],
        ];
    }

    public function testValidateOutputWithValidAnswer(): void {
        // :: Setup
        $output = [
            'email_type' => 'OUR_REQUEST',
            'email_note' => 'Vi ber om valgprotokoll.',
            'email_type_gap' => '',
            'thread_state' => $this->validThreadStateArray(),
        ];

        // :: Act
        $result = ThreadEventAnalysis::validateOutput($output);

        // :: Assert
        $this->assertEquals(['valid' => true, 'error' => null, 'derivedThreadStateType' => 'WAITING_FOR_ENTITY'], $result);
    }

    public function testValidateOutputRejectsUnknownEmailType(): void {
        // :: Setup
        $output = [
            'email_type' => 'not_a_type',
            'email_note' => '',
            'email_type_gap' => '',
            'thread_state' => $this->validThreadStateArray(),
        ];

        // :: Act
        $result = ThreadEventAnalysis::validateOutput($output);

        // :: Assert
        $this->assertFalse($result['valid']);
        $this->assertEquals(
            "'email_type' must be one of " . json_encode(ThreadEventAnalysis::allowedEmailTypes()) . ', got "not_a_type"',
            $result['error']
        );
        $this->assertNull($result['derivedThreadStateType']);
    }

    public function testValidateOutputRejectsInvalidThreadState(): void {
        // :: Setup
        $threadState = $this->validThreadStateArray();
        unset($threadState['items']);
        $output = [
            'email_type' => 'OUR_REQUEST',
            'email_note' => '',
            'email_type_gap' => '',
            'thread_state' => $threadState,
        ];

        // :: Act
        $result = ThreadEventAnalysis::validateOutput($output);

        // :: Assert
        $this->assertFalse($result['valid']);
        $this->assertEquals("ThreadState: missing required key 'items'", $result['error']);
        $this->assertNull($result['derivedThreadStateType']);
    }

    // -- selectEvents --

    public function testSelectEventsFiltersIgnoredAndSortsByDateThenId(): void {
        // :: Setup
        $emails = [
            ['id' => 'b', 'datetime_received' => '2023-01-02', 'ignore' => false],
            ['id' => 'a', 'datetime_received' => '2023-01-02', 'ignore' => false],
            ['id' => 'c', 'datetime_received' => '2023-01-01', 'ignore' => false],
            ['id' => 'd', 'datetime_received' => '2023-01-03', 'ignore' => true],
        ];

        // :: Act
        $events = ThreadEventAnalysis::selectEvents($emails);

        // :: Assert
        $ids = array_column($events, 'id');
        $this->assertEquals(['c', 'a', 'b'], $ids, 'Ordered events: ' . json_encode($ids, JSON_PRETTY_PRINT));
    }

    // -- planResume --

    public function testPlanResumeWithNoExistingFileStartsFresh(): void {
        // :: Act
        $plan = ThreadEventAnalysis::planResume(null);

        // :: Assert
        $this->assertEquals(['skip' => false, 'keptEvents' => [], 'startIndex' => 0], $plan);
    }

    public function testPlanResumeSkipsDoneThreads(): void {
        // :: Setup
        $existing = ['status' => 'done', 'events' => [['email_id' => 'e1'], ['email_id' => 'e2'], ['email_id' => 'e3']]];

        // :: Act
        $plan = ThreadEventAnalysis::planResume($existing);

        // :: Assert
        $this->assertTrue($plan['skip']);
        $this->assertEquals(3, $plan['startIndex']);
        $this->assertCount(3, $plan['keptEvents'], json_encode($plan['keptEvents'], JSON_PRETTY_PRINT));
    }

    public function testPlanResumeRetriesFailedEvent(): void {
        // :: Setup
        $existing = ['status' => 'failed', 'events' => [['email_id' => 'e1'], ['email_id' => 'e2']]];

        // :: Act
        $plan = ThreadEventAnalysis::planResume($existing);

        // :: Assert
        $this->assertFalse($plan['skip']);
        $this->assertEquals(1, $plan['startIndex']);
        $this->assertEquals([['email_id' => 'e1']], $plan['keptEvents']);
    }

    public function testPlanResumeContinuesInProgressThread(): void {
        // :: Setup
        $existing = ['status' => 'in_progress', 'events' => [['email_id' => 'e1'], ['email_id' => 'e2']]];

        // :: Act
        $plan = ThreadEventAnalysis::planResume($existing);

        // :: Assert
        $this->assertFalse($plan['skip']);
        $this->assertEquals(2, $plan['startIndex']);
        $this->assertEquals($existing['events'], $plan['keptEvents']);
    }

    // -- computeTotals --

    public function testComputeTotalsWithNoEvents(): void {
        // :: Act
        $totals = ThreadEventAnalysis::computeTotals([]);

        // :: Assert
        $this->assertEquals(
            [
                'events' => 0, 'cost_usd' => 0.0,
                'input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
                'output_tokens' => 0, 'thinking_tokens' => 0, 'total_input_tokens' => 0,
            ],
            $totals
        );
    }

    public function testComputeTotalsSumsAcrossEvents(): void {
        // :: Setup
        // Cache/thinking tokens differ per event so the sums (and
        // total_input_tokens = input + cache_creation + cache_read) are
        // actually exercised, not just input/output_tokens.
        $events = [
            ['cost_usd' => 0.12, 'usage' => ['input_tokens' => 100, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 3, 'output_tokens' => 10, 'thinking_tokens' => 1]],
            ['cost_usd' => 0.08, 'usage' => ['input_tokens' => 200, 'cache_creation_input_tokens' => 7, 'cache_read_input_tokens' => 4, 'output_tokens' => 20, 'thinking_tokens' => 2]],
        ];

        // :: Act
        $totals = ThreadEventAnalysis::computeTotals($events);

        // :: Assert
        $this->assertEquals(
            [
                'events' => 2, 'cost_usd' => 0.2,
                'input_tokens' => 300, 'cache_creation_input_tokens' => 12, 'cache_read_input_tokens' => 7,
                'output_tokens' => 30, 'thinking_tokens' => 3, 'total_input_tokens' => 319,
            ],
            $totals
        );
    }
}
