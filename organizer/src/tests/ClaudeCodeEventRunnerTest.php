<?php
// organizer/src/tests/ClaudeCodeEventRunnerTest.php
//
// Unit tests for tools/analysis/ClaudeCodeEventRunner.php - see
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 3:
// the worker". Pure-logic pieces (interpretOutput, buildCallRecord) are
// fed fixed decoded responses directly; runEvent() is exercised end to end
// against the fake claude binary (fixtures/fake-claude.php), never the
// real claude CLI.
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/analysis/ClaudeCodeEventRunner.php';

class ClaudeCodeEventRunnerTest extends TestCase {
    private const VALID_THREAD_STATE = [
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
    ];

    // -- interpretOutput --

    public function testInterpretOutputValid(): void {
        // :: Setup
        $stdout = json_encode([
            'is_error' => false,
            'structured_output' => ['email_type' => 'unknown', 'email_note' => 'n', 'email_type_gap' => '', 'thread_state' => self::VALID_THREAD_STATE],
        ]);

        // :: Act
        $result = ClaudeCodeEventRunner::interpretOutput($stdout, '');

        // :: Assert
        $this->assertTrue($result['valid'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertNull($result['error']);
        $this->assertEquals('unknown', $result['structuredOutput']['email_type']);
        $this->assertEquals('WAITING_FOR_ENTITY', $result['derivedThreadStateType']);
    }

    public function testInterpretOutputNotJson(): void {
        // :: Act
        $result = ClaudeCodeEventRunner::interpretOutput('not json at all', 'boom on stderr');

        // :: Assert
        $this->assertFalse($result['valid']);
        $this->assertEquals("claude did not print a JSON object. stderr: boom on stderr", $result['error']);
        $this->assertNull($result['decoded']);
        $this->assertNull($result['structuredOutput']);
        $this->assertNull($result['derivedThreadStateType']);
    }

    public function testInterpretOutputIsError(): void {
        // :: Setup
        $stdout = json_encode(['is_error' => true, 'result' => 'budget exceeded']);

        // :: Act
        $result = ClaudeCodeEventRunner::interpretOutput($stdout, '');

        // :: Assert
        $this->assertFalse($result['valid']);
        $this->assertEquals('claude reported is_error: "budget exceeded"', $result['error']);
        $this->assertIsArray($result['decoded']);
        $this->assertNull($result['structuredOutput']);
    }

    public function testInterpretOutputMissingStructuredOutput(): void {
        // :: Setup
        $stdout = json_encode(['is_error' => false, 'result' => 'ok']);

        // :: Act
        $result = ClaudeCodeEventRunner::interpretOutput($stdout, '');

        // :: Assert
        $this->assertFalse($result['valid']);
        $this->assertEquals("claude's answer is missing 'structured_output'", $result['error']);
    }

    public function testInterpretOutputInvalidStructuredOutput(): void {
        // :: Setup
        $stdout = json_encode([
            'is_error' => false,
            'structured_output' => ['email_type' => 'BOGUS_TYPE', 'email_note' => '', 'email_type_gap' => '', 'thread_state' => ['schema_version' => 1]],
        ]);

        // :: Act
        $result = ClaudeCodeEventRunner::interpretOutput($stdout, '');

        // :: Assert
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("'email_type' must be one of", (string) $result['error']);
        $this->assertNull($result['derivedThreadStateType']);
    }

    // -- buildCallRecord --

    public function testBuildCallRecordFromDecodedResponse(): void {
        // :: Setup
        $decoded = [
            'is_error' => false,
            'result' => 'ok',
            'total_cost_usd' => 0.076,
            'duration_ms' => 12600,
            'duration_api_ms' => 12100,
            'session_id' => 'sess-1',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 2, 'output_tokens' => 1243,
                'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 6363,
                'output_tokens_details' => ['thinking_tokens' => 98],
            ],
            'modelUsage' => ['claude-opus-5-5' => ['input_tokens' => 2, 'output_tokens' => 1243]],
        ];
        $schema = ['type' => 'object'];

        // :: Act
        $call = ClaudeCodeEventRunner::buildCallRecord(1, 'the input', $schema, $decoded, 'claude-opus-5-5', '2.1.283');

        // :: Assert
        $this->assertEquals(
            [
                'attempt' => 1,
                'input_text' => 'the input',
                'json_schema' => ['type' => 'object'],
                'response' => $decoded,
                'model' => 'claude-opus-5-5',
                'model_resolved' => 'claude-opus-5-5',
                'session_id' => 'sess-1',
                'claude_code_version' => '2.1.283',
                'input_tokens' => 2,
                'cache_creation_input_tokens' => 6363,
                'cache_read_input_tokens' => 0,
                'output_tokens' => 1243,
                'thinking_tokens' => 98,
                'cost_usd' => 0.076,
                'duration_ms' => 12600,
                'duration_api_ms' => 12100,
                'is_error' => false,
                'stop_reason' => 'end_turn',
            ],
            $call,
            json_encode($call, JSON_PRETTY_PRINT)
        );
    }

    public function testBuildCallRecordWhenDecodedIsNull(): void {
        // :: Act
        $call = ClaudeCodeEventRunner::buildCallRecord(2, 'retry input', ['type' => 'object'], null, 'claude-opus-5-5', null);

        // :: Assert
        $this->assertEquals(2, $call['attempt']);
        $this->assertEquals('retry input', $call['input_text']);
        $this->assertNull($call['response']);
        $this->assertNull($call['model_resolved']);
        $this->assertNull($call['session_id']);
        $this->assertNull($call['claude_code_version']);
        $this->assertNull($call['input_tokens']);
        $this->assertNull($call['cost_usd']);
        $this->assertNull($call['duration_ms']);
        $this->assertNull($call['duration_api_ms']);
        $this->assertNull($call['is_error']);
        $this->assertNull($call['stop_reason']);
    }

    // -- runEvent (against fixtures/fake-claude.php, never the real claude CLI) --

    private function runEventWithFakeClaude(string $inputText): array {
        return ClaudeCodeEventRunner::runEvent(
            $inputText,
            __DIR__ . '/../../../tools/analysis/event-prompt.md',
            ['type' => 'object'],
            'claude-opus-5-5',
            __DIR__ . '/fixtures/fake-claude.php',
            20.0,
            '2.1.283' // fixed, so this never shells out to `fake-claude.php --version`
        );
    }

    public function testRunEventSucceedsOnFirstAttempt(): void {
        // :: Act
        $result = $this->runEventWithFakeClaude('plain input, no markers');

        // :: Assert
        $this->assertEquals(1, $result['attempts'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertNull($result['error']);
        $this->assertEquals('WAITING_FOR_ENTITY', $result['derivedThreadStateType']);
        $this->assertEquals('unknown', $result['output']['email_type']);
        $this->assertCount(1, $result['calls'], json_encode($result['calls'], JSON_PRETTY_PRINT));
        $this->assertEquals(1, $result['calls'][0]['attempt']);
        $this->assertEquals('2.1.283', $result['calls'][0]['claude_code_version']);
        $this->assertEquals('claude-opus-5-5', $result['calls'][0]['model_resolved']);
        $this->assertEquals(0.01, $result['calls'][0]['cost_usd']);
    }

    public function testRunEventRetriesOnceThenSucceeds(): void {
        // :: Act
        $result = $this->runEventWithFakeClaude('input with FAKE_INVALID_ONCE marker');

        // :: Assert
        $this->assertEquals(2, $result['attempts'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertNull($result['error']);
        $this->assertEquals('WAITING_FOR_ENTITY', $result['derivedThreadStateType']);
        $this->assertCount(2, $result['calls'], json_encode($result['calls'], JSON_PRETTY_PRINT));
        $this->assertEquals(1, $result['calls'][0]['attempt']);
        $this->assertEquals('BOGUS_TYPE', $result['calls'][0]['response']['structured_output']['email_type']);
        $this->assertEquals(2, $result['calls'][1]['attempt']);
        $this->assertEquals('unknown', $result['calls'][1]['response']['structured_output']['email_type']);
    }

    public function testRunEventFailsAfterTwoAttempts(): void {
        // :: Act
        $result = $this->runEventWithFakeClaude('input with FAKE_INVALID_ALWAYS marker');

        // :: Assert
        $this->assertEquals(2, $result['attempts'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertStringContainsString("'email_type' must be one of", (string) $result['error']);
        $this->assertNull($result['derivedThreadStateType']);
        $this->assertEquals('BOGUS_TYPE', $result['output']['email_type']);
        $this->assertCount(2, $result['calls'], json_encode($result['calls'], JSON_PRETTY_PRINT));
    }

    // -- claudeCodeVersion --

    public function testClaudeCodeVersionReadsFirstLineAndCaches(): void {
        // :: Setup
        $path = sys_get_temp_dir() . '/claude_code_event_runner_test_version_' . getmypid() . '.sh';
        file_put_contents($path, "#!/bin/sh\necho '2.1.283 (Claude Code)'\n");
        chmod($path, 0755);

        // :: Act
        $first = ClaudeCodeEventRunner::claudeCodeVersion($path);
        unlink($path); // proves the second call below is served from the cache, not re-executed
        $second = ClaudeCodeEventRunner::claudeCodeVersion($path);

        // :: Assert
        $this->assertEquals('2.1.283 (Claude Code)', $first);
        $this->assertEquals('2.1.283 (Claude Code)', $second);
    }

    public function testClaudeCodeVersionNullWhenCommandFails(): void {
        // :: Act
        $version = ClaudeCodeEventRunner::claudeCodeVersion('/no/such/binary-' . getmypid());

        // :: Assert
        $this->assertNull($version);
    }
}
