#!/usr/bin/env php
<?php
// organizer/src/tests/fixtures/fake-claude.php
//
// A fake `claude` binary for AnalyzeThreadsCliTest: reads the event input on
// stdin (as the real CLI does) and prints one canned JSON response, in the
// same shape as `claude -p --output-format json --json-schema ...` (see
// docs/superpowers/plans/2026-09-27-step2-2-thread-analysis.md). Never calls
// the real claude CLI - this exists so tests cost nothing.
//
// Its answer is driven entirely by markers found in stdin, so a single
// process (no shared state between invocations) can still act invalid-then-
// valid across a retry:
//   - If stdin contains "Your previous answer was invalid", this is a retry:
//     always answer valid.
//   - Else if stdin contains "FAKE_INVALID_ALWAYS": answer invalid, on the
//     first attempt AND on any retry (so the event fails).
//   - Else if stdin contains "FAKE_INVALID_ONCE": answer invalid (retried
//     next, which is caught by the first rule above).
//   - Else: answer valid.
// "FAKE_COST=<number>" anywhere in stdin sets total_cost_usd (default 0.01).
// argv is ignored entirely - only stdin drives the answer.

$stdin = stream_get_contents(STDIN);

$isRetry = str_contains($stdin, 'Your previous answer was invalid');
$invalidAlways = str_contains($stdin, 'FAKE_INVALID_ALWAYS');
$invalidOnce = str_contains($stdin, 'FAKE_INVALID_ONCE');

$cost = 0.01;
if (preg_match('/FAKE_COST=([0-9.]+)/', $stdin, $m)) {
    $cost = (float) $m[1];
}

$makeInvalid = $invalidAlways || ($invalidOnce && !$isRetry);

if ($makeInvalid) {
    // Fails validation at the first check (email_type not in the allowed
    // enum), regardless of the rest of the shape.
    $structuredOutput = [
        'email_type' => 'BOGUS_TYPE',
        'email_note' => 'invalid on purpose (fake-claude.php)',
        'email_type_gap' => '',
        'thread_state' => ['schema_version' => 1],
    ];
}
else {
    $structuredOutput = [
        'email_type' => 'unknown',
        'email_note' => 'fake-claude.php canned answer',
        'email_type_gap' => '',
        'thread_state' => [
            'schema_version' => 1,
            'request' => ['summary' => 'fake request', 'law_basis' => 'offentleglova', 'sent_at' => null],
            'items' => [
                ['id' => '1', 'asked_for' => 'fake document', 'status' => 'NOT_ANSWERED', 'denial_basis' => null, 'released_in_email_ids' => [], 'note' => ''],
            ],
            'waiting_for' => 'ENTITY',
            'asks_to_us' => [],
            'case_numbers' => [],
            'dates' => [],
            'complaints' => [],
            'notes' => '',
            'extra' => [],
        ],
    ];
}

echo json_encode([
    'is_error' => false,
    'structured_output' => $structuredOutput,
    'result' => 'ok',
    'total_cost_usd' => $cost,
    'duration_ms' => 5,
    'session_id' => 'fake-session',
    // Fixed, nonzero cache/thinking figures (mirroring how headless Claude
    // Code reports almost all input as cache tokens rather than
    // input_tokens), so tests that sum totals actually exercise those keys.
    'usage' => [
        'input_tokens' => 100,
        'output_tokens' => 20,
        'cache_read_input_tokens' => 30,
        'cache_creation_input_tokens' => 50,
        'output_tokens_details' => ['thinking_tokens' => 5],
    ],
    'modelUsage' => ['claude-opus-5-5' => ['input_tokens' => 100, 'output_tokens' => 20]],
]);
