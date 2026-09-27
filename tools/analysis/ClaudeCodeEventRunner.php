<?php
// tools/analysis/ClaudeCodeEventRunner.php
//
// Shared "call headless Claude Code for one event, validate the answer,
// retry once" logic, extracted from tools/analyze-threads.php so
// tools/analysis-worker.php (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md,
// "Change 3: the worker") can use the same call/validate/retry behaviour
// without duplicating it.
//
// The low-level pieces (buildClaudeArgv, spawnNonBlocking, usageOf,
// sumUsage, modelNameOf, interpretOutput) are reused as-is by
// analyze-threads.php's own async, many-threads-at-once pump loop, so its
// behaviour (including real parallelism across threads) is unchanged.
// runEvent() is the single blocking call+validate+retry method the worker
// uses directly: it takes the input text, prompt file, schema, model and
// claude bin, and returns the parsed output, the derived thread-state type,
// the error, the attempt count and the call records (the "calls" format
// from the plan's "Result format").

require_once __DIR__ . '/ThreadEventAnalysis.php';

class ClaudeCodeEventRunner {
    /** Builds the argv for one `claude -p ...` invocation. */
    public static function buildClaudeArgv(string $claudeBin, string $model, string $promptFile, string $schemaJson, float $maxBudgetUsd): array {
        return [
            $claudeBin, '-p',
            '--model', $model,
            '--output-format', 'json',
            '--tools', '',
            '--no-session-persistence',
            '--setting-sources', '',
            '--strict-mcp-config',
            '--system-prompt-file', $promptFile,
            '--json-schema', $schemaJson,
            '--max-budget-usd', (string) $maxBudgetUsd,
        ];
    }

    /**
     * Starts one call with non-blocking stdout/stderr pipes, for a caller
     * that multiplexes many calls at once (analyze-threads.php). Returns
     * [proc, stdout, stderr]; the caller pumps and finalizes
     * (fclose/proc_close) itself, exactly as before this was extracted.
     */
    public static function spawnNonBlocking(array $argv, string $inputText): array {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start claude process: ' . implode(' ', $argv));
        }
        fwrite($pipes[0], $inputText);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return [$proc, $pipes[1], $pipes[2]];
    }

    /**
     * Runs one call to completion, blocking until it exits - for a caller
     * that only ever runs one call at a time (analysis-worker.php).
     *
     * @return array{stdout: string, stderr: string}
     */
    public static function runBlocking(array $argv, string $inputText): array {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start claude process: ' . implode(' ', $argv));
        }
        fwrite($pipes[0], $inputText);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    public static function usageOf(array $decoded): array {
        $usage = $decoded['usage'] ?? [];
        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_input_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation_input_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'thinking_tokens' => (int) ($usage['output_tokens_details']['thinking_tokens'] ?? 0),
        ];
    }

    public static function sumUsage(array $a, array $b): array {
        $out = [];
        foreach ($a as $key => $value) {
            $out[$key] = $value + ($b[$key] ?? 0);
        }
        return $out;
    }

    public static function modelNameOf(array $decoded, string $fallback): string {
        $modelUsage = $decoded['modelUsage'] ?? [];
        if (is_array($modelUsage) && $modelUsage !== []) {
            return (string) array_key_first($modelUsage);
        }
        return $fallback;
    }

    /**
     * The installed claude CLI's version (`claude --version`, first line),
     * read once per process and cached - it cannot change mid-run. Null if
     * the command fails (e.g. --claude-bin isn't a real claude, in a test).
     *
     * Uses proc_open with stdin explicitly closed (no shell, array argv),
     * rather than exec(): a test's fake claude binary
     * (fixtures/fake-claude.php) always reads all of stdin regardless of
     * argv, so an inherited, still-open stdin here would hang it forever.
     * An explicitly-closed pipe gives it (and the real claude CLI, which
     * never reads stdin for --version) immediate EOF either way.
     */
    public static function claudeCodeVersion(string $claudeBin): ?string {
        static $cache = [];
        if (array_key_exists($claudeBin, $cache)) {
            return $cache[$claudeBin];
        }
        $version = null;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // A nonexistent $claudeBin (e.g. in a test) makes proc_open() emit a
        // PHP warning as well as returning false - the false check below
        // already handles that case, so the warning is just noise. A plain
        // @ does not reliably suppress it under PHPUnit's error handler (it
        // is called regardless of the runtime error_reporting level @ sets,
        // and can turn the warning into a test error), so a handler that
        // swallows everything is installed and restored explicitly instead.
        set_error_handler(fn () => true);
        try {
            $proc = proc_open([$claudeBin, '--version'], $descriptors, $pipes);
        } finally {
            restore_error_handler();
        }
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            $firstLine = trim((string) strtok($stdout, "\n"));
            $version = ($exitCode === 0 && $firstLine !== '') ? $firstLine : null;
        }
        $cache[$claudeBin] = $version;
        return $version;
    }

    /**
     * Interprets one finished call's raw stdout/stderr: decodes the JSON,
     * checks is_error/structured_output, and validates the answer through
     * ThreadEventAnalysis::validateOutput(). Pure given its inputs - no I/O.
     * Mirrors the checks tools/analyze-threads.php's handleCallResult() did
     * inline before this was extracted.
     *
     * @return array{valid: bool, error: ?string, decoded: ?array,
     *   structuredOutput: ?array, derivedThreadStateType: ?string}
     */
    public static function interpretOutput(string $stdout, string $stderr): array {
        $decoded = json_decode($stdout, true);

        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'error' => 'claude did not print a JSON object. stderr: ' . trim(mb_substr($stderr, 0, 2000)),
                'decoded' => null, 'structuredOutput' => null, 'derivedThreadStateType' => null,
            ];
        }
        if (!empty($decoded['is_error'])) {
            return [
                'valid' => false,
                'error' => 'claude reported is_error: ' . json_encode($decoded['result'] ?? null),
                'decoded' => $decoded, 'structuredOutput' => null, 'derivedThreadStateType' => null,
            ];
        }
        if (!is_array($decoded['structured_output'] ?? null)) {
            return [
                'valid' => false,
                'error' => "claude's answer is missing 'structured_output'",
                'decoded' => $decoded, 'structuredOutput' => null, 'derivedThreadStateType' => null,
            ];
        }

        $structuredOutput = $decoded['structured_output'];
        $validation = ThreadEventAnalysis::validateOutput($structuredOutput);
        return [
            'valid' => $validation['valid'],
            'error' => $validation['error'],
            'decoded' => $decoded,
            'structuredOutput' => $structuredOutput,
            'derivedThreadStateType' => $validation['derivedThreadStateType'],
        ];
    }

    /**
     * Builds one call record in the plan's "Result format" `calls` shape
     * from a finished call's decoded response (or null, if it never
     * decoded) plus the request-side fields the caller already knows.
     */
    public static function buildCallRecord(int $attempt, string $inputText, array $schema, ?array $decoded, string $model, ?string $claudeCodeVersion): array {
        $usage = $decoded !== null
            ? self::usageOf($decoded)
            : ['input_tokens' => null, 'output_tokens' => null, 'cache_read_input_tokens' => null, 'cache_creation_input_tokens' => null, 'thinking_tokens' => null];

        return [
            'attempt' => $attempt,
            'input_text' => $inputText,
            'json_schema' => $schema,
            'response' => $decoded,
            'model' => $model,
            'model_resolved' => $decoded !== null ? self::modelNameOf($decoded, $model) : null,
            'session_id' => $decoded['session_id'] ?? null,
            'claude_code_version' => $claudeCodeVersion,
            'input_tokens' => $usage['input_tokens'],
            'cache_creation_input_tokens' => $usage['cache_creation_input_tokens'],
            'cache_read_input_tokens' => $usage['cache_read_input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'thinking_tokens' => $usage['thinking_tokens'],
            'cost_usd' => $decoded['total_cost_usd'] ?? null,
            'duration_ms' => $decoded['duration_ms'] ?? null,
            'duration_api_ms' => $decoded['duration_api_ms'] ?? null,
            'is_error' => $decoded['is_error'] ?? null,
            'stop_reason' => $decoded['stop_reason'] ?? null,
        ];
    }

    /**
     * Runs one event to completion: calls claude, validates the answer,
     * retries once (with the error appended to the input) if invalid, and
     * returns the outcome plus every call made. Blocking - for a caller
     * that processes one event at a time (analysis-worker.php).
     *
     * @return array{output: ?array, derivedThreadStateType: ?string,
     *   error: ?string, attempts: int, calls: array}
     */
    public static function runEvent(
        string $inputText,
        string $promptFile,
        array $schema,
        string $model,
        string $claudeBin,
        float $maxBudgetUsd,
        ?string $claudeCodeVersion = null
    ): array {
        $schemaJson = json_encode($schema);
        $argv = self::buildClaudeArgv($claudeBin, $model, $promptFile, $schemaJson, $maxBudgetUsd);
        $version = $claudeCodeVersion ?? self::claudeCodeVersion($claudeBin);

        $calls = [];
        $currentInput = $inputText;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $run = self::runBlocking($argv, $currentInput);
            $interpreted = self::interpretOutput($run['stdout'], $run['stderr']);
            $calls[] = self::buildCallRecord($attempt, $currentInput, $schema, $interpreted['decoded'], $model, $version);

            if ($interpreted['valid']) {
                return [
                    'output' => $interpreted['structuredOutput'],
                    'derivedThreadStateType' => $interpreted['derivedThreadStateType'],
                    'error' => null,
                    'attempts' => $attempt,
                    'calls' => $calls,
                ];
            }

            if ($attempt === 1) {
                $currentInput = $inputText . "\n\nYour previous answer was invalid: {$interpreted['error']}. Return a corrected answer.";
                continue;
            }

            return [
                'output' => $interpreted['structuredOutput'],
                'derivedThreadStateType' => null,
                'error' => $interpreted['error'],
                'attempts' => $attempt,
                'calls' => $calls,
            ];
        }

        // Unreachable (the loop above always returns on attempt 1 or 2).
        throw new RuntimeException('runEvent: unexpected fallthrough');
    }
}
