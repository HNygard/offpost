<?php
// organizer/src/class/ThreadAnalysis/ThreadAnalysisRepository.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ThreadState/ThreadState.php';
require_once __DIR__ . '/../ThreadState/ThreadStateTypeDeriver.php';
require_once __DIR__ . '/../Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

/**
 * Database operations for step 2c of the innsynskrav classification roadmap
 * (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md): the queue of
 * analysis runs, the provider-neutral events they produce, and the
 * Anthropic-specific calls behind each event. See docs/thread-analysis.md,
 * "Storage in prod".
 *
 * No process spawning, no HTTP - this class is pure database access. The
 * worker and the endpoints that will call it (later changes) are elsewhere.
 */
class ThreadAnalysisRepository {
    const OPEN_STATUSES = ['requested', 'claimed'];

    /**
     * Requests a new analysis run for a thread. If the thread already has an
     * open run (requested or claimed), returns that run's id instead of
     * creating a new one.
     */
    public static function requestRun(string $threadId, string $mode, string $requestedBy): int {
        $existing = Database::queryOneOrNone(
            "SELECT id FROM thread_analysis_runs
             WHERE thread_id = ? AND status IN ('requested', 'claimed')
             ORDER BY requested_at ASC
             LIMIT 1",
            [$threadId]
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return (int) Database::queryValue(
            "INSERT INTO thread_analysis_runs (thread_id, status, mode, requested_by)
             VALUES (?, 'requested', ?, ?)
             RETURNING id",
            [$threadId, $mode, $requestedBy]
        );
    }

    /**
     * Claims the oldest claimable run - one that is 'requested', or 'claimed'
     * with an expired lease - for $worker, and returns the updated run row.
     * Null when there is nothing to claim. Uses FOR UPDATE SKIP LOCKED so two
     * workers polling concurrently never claim the same run.
     */
    public static function claimNext(string $worker, int $leaseSeconds): ?array {
        return self::claim(
            "SELECT id FROM thread_analysis_runs
             WHERE status = 'requested' OR (status = 'claimed' AND lease_expires_at < CURRENT_TIMESTAMP)
             ORDER BY requested_at ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED",
            [],
            $worker,
            $leaseSeconds
        );
    }

    /**
     * The same as claimNext(), restricted to the given thread's open run.
     */
    public static function claimThread(string $threadId, string $worker, int $leaseSeconds): ?array {
        return self::claim(
            "SELECT id FROM thread_analysis_runs
             WHERE thread_id = ?
               AND (status = 'requested' OR (status = 'claimed' AND lease_expires_at < CURRENT_TIMESTAMP))
             ORDER BY requested_at ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED",
            [$threadId],
            $worker,
            $leaseSeconds
        );
    }

    private static function claim(string $selectSql, array $selectParams, string $worker, int $leaseSeconds): ?array {
        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }
        try {
            $row = Database::queryOneOrNone($selectSql, $selectParams);
            if ($row === null) {
                if ($ownsTransaction) {
                    Database::commit();
                }
                return null;
            }

            $runId = (int) $row['id'];
            Database::execute(
                "UPDATE thread_analysis_runs
                 SET status = 'claimed', claimed_at = CURRENT_TIMESTAMP,
                     lease_expires_at = CURRENT_TIMESTAMP + (? * INTERVAL '1 second'),
                     worker = ?
                 WHERE id = ?",
                [$leaseSeconds, $worker, $runId]
            );
            $updated = Database::queryOne("SELECT * FROM thread_analysis_runs WHERE id = ?", [$runId]);

            if ($ownsTransaction) {
                Database::commit();
            }
            return $updated;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /**
     * Upserts the system prompt text by its sha256 and returns the sha. The
     * same text always gives the same sha and never creates a second row.
     */
    public static function saveSystemPrompt(string $text): string {
        $sha256 = hash('sha256', $text);
        Database::execute(
            "INSERT INTO thread_analysis_system_prompts (sha256, text)
             VALUES (?, ?)
             ON CONFLICT (sha256) DO NOTHING",
            [$sha256, $text]
        );
        return $sha256;
    }

    /**
     * Saves a worker's result for a run: validates it, stores the events and
     * calls, sets the run to done/failed, and - on done - applies the run's
     * state onto thread_emails (never overwriting a 'manual' classification).
     * Runs in one transaction: any validation failure throws
     * InvalidArgumentException and writes nothing.
     *
     * @param array $result See the "Result format" in
     *   docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md.
     */
    public static function saveResult(int $runId, string $worker, array $result): void {
        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }
        try {
            $run = Database::queryOneOrNone("SELECT * FROM thread_analysis_runs WHERE id = ?", [$runId]);
            if ($run === null) {
                throw new InvalidArgumentException("saveResult: run $runId does not exist");
            }
            if ($run['status'] !== 'claimed') {
                throw new InvalidArgumentException("saveResult: run $runId is not claimed (status: '{$run['status']}')");
            }
            if ($run['worker'] !== $worker) {
                throw new InvalidArgumentException("saveResult: run $runId is claimed by '{$run['worker']}', not '$worker'");
            }

            $status = $result['status'] ?? null;
            if (!in_array($status, ['done', 'failed'], true)) {
                throw new InvalidArgumentException("saveResult: 'status' must be 'done' or 'failed', got " . json_encode($status));
            }

            $events = $result['events'] ?? null;
            if (!is_array($events)) {
                throw new InvalidArgumentException("saveResult: 'events' must be a list");
            }

            $validatedEvents = self::validateEvents($events, (string) $run['thread_id']);

            $error = $result['error'] ?? null;
            if ($error !== null && !is_string($error)) {
                throw new InvalidArgumentException("saveResult: 'error' must be a string or null");
            }

            $model = $result['model'] ?? null;
            if ($model !== null && !is_string($model)) {
                throw new InvalidArgumentException("saveResult: 'model' must be a string or null");
            }

            $systemPromptText = $result['system_prompt'] ?? null;
            if ($systemPromptText !== null && !is_string($systemPromptText)) {
                throw new InvalidArgumentException("saveResult: 'system_prompt' must be a string or null");
            }
            $systemPromptSha256 = $systemPromptText === null ? null : self::saveSystemPrompt($systemPromptText);

            $schemaVersion = $result['schema_version'] ?? null;
            if ($schemaVersion !== null && !is_int($schemaVersion)) {
                throw new InvalidArgumentException("saveResult: 'schema_version' must be an int or null");
            }

            // Store the events and their calls.
            $emailIdsAndStates = [];
            foreach ($validatedEvents as $event) {
                $eventId = Database::queryValue(
                    "INSERT INTO thread_analysis_events
                        (run_id, email_id, \"position\", email_type, email_note, email_type_gap,
                         thread_state, derived_thread_state_type, attempts, error)
                     VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)
                     RETURNING id",
                    [
                        $runId,
                        $event['email_id'],
                        $event['position'],
                        $event['email_type'],
                        $event['email_note'],
                        $event['email_type_gap'],
                        $event['thread_state_json'],
                        $event['derived_thread_state_type'],
                        $event['attempts'],
                        $event['error'],
                    ]
                );

                if ($event['error'] === null) {
                    $emailIdsAndStates[] = [
                        'email_id' => $event['email_id'],
                        'thread_state_json' => $event['thread_state_json'],
                        'derived_thread_state_type' => $event['derived_thread_state_type'],
                    ];
                }

                foreach ($event['calls'] as $call) {
                    Database::execute(
                        "INSERT INTO thread_analysis_claude_code_calls
                            (run_id, event_id, attempt, input_text, json_schema, response,
                             model, model_resolved, session_id, claude_code_version,
                             input_tokens, cache_creation_input_tokens, cache_read_input_tokens,
                             output_tokens, thinking_tokens, cost_usd, duration_ms, duration_api_ms,
                             is_error, stop_reason)
                         VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $runId,
                            $eventId,
                            $call['attempt'],
                            $call['input_text'],
                            $call['json_schema'],
                            $call['response'],
                            $call['model'],
                            $call['model_resolved'],
                            $call['session_id'],
                            $call['claude_code_version'],
                            $call['input_tokens'],
                            $call['cache_creation_input_tokens'],
                            $call['cache_read_input_tokens'],
                            $call['output_tokens'],
                            $call['thinking_tokens'],
                            $call['cost_usd'],
                            $call['duration_ms'],
                            $call['duration_api_ms'],
                            $call['is_error'] === null ? null : ($call['is_error'] ? 't' : 'f'),
                            $call['stop_reason'],
                        ]
                    );
                }
            }

            Database::execute(
                "UPDATE thread_analysis_runs
                 SET status = ?, finished_at = CURRENT_TIMESTAMP, model = ?,
                     system_prompt_sha256 = ?, schema_version = ?, error = ?
                 WHERE id = ?",
                [$status, $model, $systemPromptSha256, $schemaVersion, $error, $runId]
            );

            if ($status === 'done') {
                foreach ($emailIdsAndStates as $applied) {
                    Database::execute(
                        "UPDATE thread_emails
                         SET thread_state = ?::jsonb, thread_state_type = ?, thread_state_source = 'auto'
                         WHERE id = ? AND (thread_state_source IS NULL OR thread_state_source != 'manual')",
                        [$applied['thread_state_json'], $applied['derived_thread_state_type'], $applied['email_id']]
                    );
                }
            }

            if ($ownsTransaction) {
                Database::commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /**
     * Validates $events against the rules in the plan and returns them
     * normalised for storage (thread_state re-encoded through
     * ThreadState::fromArray()/toArray() so it round-trips exactly, and
     * derived_thread_state_type computed here - never trusting the worker's
     * value). Throws InvalidArgumentException with a precise message on the
     * first problem found.
     */
    private static function validateEvents(array $events, string $threadId): array {
        $validated = [];
        $expectedPosition = 1;

        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                throw new InvalidArgumentException("saveResult: event at index $index must be an object");
            }

            $position = $event['position'] ?? null;
            if ($position !== $expectedPosition) {
                throw new InvalidArgumentException(
                    "saveResult: event positions must start at 1 with no gaps; expected $expectedPosition, got " . json_encode($position)
                );
            }
            $expectedPosition++;

            $emailId = $event['email_id'] ?? null;
            if (!is_string($emailId) || $emailId === '') {
                throw new InvalidArgumentException("saveResult: event at position $position has an invalid 'email_id'");
            }
            $belongsToThread = Database::queryValue(
                "SELECT count(*) FROM thread_emails WHERE id = ? AND thread_id = ?",
                [$emailId, $threadId]
            );
            if ((int) $belongsToThread === 0) {
                throw new InvalidArgumentException("saveResult: email '$emailId' does not belong to thread '$threadId'");
            }

            $error = $event['error'] ?? null;
            if ($error !== null && !is_string($error)) {
                throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'error'");
            }

            // A failed event exists because the model's answer was invalid, so
            // it may have no usable email_type: required only without an error.
            $emailType = $event['email_type'] ?? null;
            $emailTypeValid = is_string($emailType) && ThreadEmailStatusType::tryFrom($emailType) !== null;
            if (!$emailTypeValid && !($error !== null && $emailType === null)) {
                throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'email_type': " . json_encode($emailType));
            }

            $threadStateJson = null;
            $derivedThreadStateType = null;
            if ($error === null) {
                $rawThreadState = $event['thread_state'] ?? null;
                if (!is_array($rawThreadState)) {
                    throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'thread_state'");
                }
                try {
                    $state = ThreadState::fromArray($rawThreadState);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'thread_state': " . $e->getMessage());
                }
                // Prod derives the status itself and ignores anything the worker sent for it.
                $derivedThreadStateType = ThreadStateTypeDeriver::derive($state)->value;
                $threadStateJson = json_encode($state->toArray());
            }

            $attempts = $event['attempts'] ?? null;
            if (!is_int($attempts) || $attempts < 0) {
                throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'attempts': " . json_encode($attempts));
            }

            $emailNote = $event['email_note'] ?? null;
            if ($emailNote !== null && !is_string($emailNote)) {
                throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'email_note'");
            }
            $emailTypeGap = $event['email_type_gap'] ?? null;
            if ($emailTypeGap !== null && !is_string($emailTypeGap)) {
                throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'email_type_gap'");
            }

            $calls = $event['calls'] ?? [];
            if (!is_array($calls)) {
                throw new InvalidArgumentException("saveResult: event for email '$emailId' has an invalid 'calls'");
            }

            $validated[] = [
                'email_id' => $emailId,
                'position' => $position,
                'email_type' => $emailType,
                'email_note' => $emailNote,
                'email_type_gap' => $emailTypeGap,
                'thread_state_json' => $threadStateJson,
                'derived_thread_state_type' => $derivedThreadStateType,
                'attempts' => $attempts,
                'error' => $error,
                'calls' => self::validateCalls($calls, $emailId),
            ];
        }

        return $validated;
    }

    /**
     * @return array<int, array{attempt:int, input_text:string, json_schema:?string,
     *   response:?string, model:?string, model_resolved:?string, session_id:?string,
     *   claude_code_version:?string, input_tokens:?int, cache_creation_input_tokens:?int,
     *   cache_read_input_tokens:?int, output_tokens:?int, thinking_tokens:?int,
     *   cost_usd:?float, duration_ms:?int, duration_api_ms:?int, is_error:?bool, stop_reason:?string}>
     */
    private static function validateCalls(array $calls, string $emailId): array {
        $countKeys = [
            'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens',
            'output_tokens', 'thinking_tokens', 'duration_ms', 'duration_api_ms',
        ];
        $stringKeys = ['model', 'model_resolved', 'session_id', 'claude_code_version', 'stop_reason'];

        $validated = [];
        foreach ($calls as $index => $call) {
            if (!is_array($call)) {
                throw new InvalidArgumentException("saveResult: call at index $index for email '$emailId' must be an object");
            }
            $attempt = $call['attempt'] ?? null;
            if (!is_int($attempt)) {
                throw new InvalidArgumentException("saveResult: call at index $index for email '$emailId' has an invalid 'attempt'");
            }

            $inputText = $call['input_text'] ?? null;
            if (!is_string($inputText)) {
                throw new InvalidArgumentException("saveResult: call attempt $attempt for email '$emailId' has an invalid 'input_text'");
            }

            $normalised = [
                'attempt' => $attempt,
                'input_text' => $inputText,
                'json_schema' => isset($call['json_schema']) ? json_encode($call['json_schema']) : null,
                'response' => isset($call['response']) ? json_encode($call['response']) : null,
            ];

            foreach ($stringKeys as $key) {
                $value = $call[$key] ?? null;
                if ($value !== null && !is_string($value)) {
                    throw new InvalidArgumentException("saveResult: call attempt $attempt for email '$emailId' has an invalid '$key'");
                }
                $normalised[$key] = $value;
            }

            foreach ($countKeys as $key) {
                $value = $call[$key] ?? null;
                if ($value !== null && (!is_int($value) || $value < 0)) {
                    throw new InvalidArgumentException("saveResult: call attempt $attempt for email '$emailId' has an invalid '$key': " . json_encode($value));
                }
                $normalised[$key] = $value;
            }

            $costUsd = $call['cost_usd'] ?? null;
            if ($costUsd !== null && !is_int($costUsd) && !is_float($costUsd)) {
                throw new InvalidArgumentException("saveResult: call attempt $attempt for email '$emailId' has an invalid 'cost_usd': " . json_encode($costUsd));
            }
            $normalised['cost_usd'] = $costUsd;

            $isError = $call['is_error'] ?? null;
            if ($isError !== null && !is_bool($isError)) {
                throw new InvalidArgumentException("saveResult: call attempt $attempt for email '$emailId' has an invalid 'is_error'");
            }
            $normalised['is_error'] = $isError;

            $validated[] = $normalised;
        }

        return $validated;
    }

    public static function getRun(int $runId): ?array {
        return Database::queryOneOrNone("SELECT * FROM thread_analysis_runs WHERE id = ?", [$runId]);
    }

    public static function getRunsForThread(string $threadId): array {
        return Database::query(
            "SELECT * FROM thread_analysis_runs WHERE thread_id = ? ORDER BY requested_at ASC",
            [$threadId]
        );
    }

    public static function getEventsForRun(int $runId): array {
        $events = Database::query(
            "SELECT * FROM thread_analysis_events WHERE run_id = ? ORDER BY \"position\" ASC",
            [$runId]
        );
        foreach ($events as &$event) {
            if ($event['thread_state'] !== null) {
                $event['thread_state'] = json_decode($event['thread_state'], true);
            }
        }
        return $events;
    }

    /**
     * Every call for a run, ordered by its event's position then attempt -
     * for the debug page (system-pages/thread-analysis-thread.php), which
     * groups them back onto their event by event_id.
     *
     * @return array<int, array> thread_analysis_claude_code_calls rows, with
     *   json_schema/response decoded.
     */
    public static function getCallsForRun(int $runId): array {
        $calls = Database::query(
            "SELECT c.* FROM thread_analysis_claude_code_calls c
             JOIN thread_analysis_events e ON e.id = c.event_id
             WHERE c.run_id = ?
             ORDER BY e.\"position\" ASC, c.attempt ASC",
            [$runId]
        );
        foreach ($calls as &$call) {
            if ($call['json_schema'] !== null) {
                $call['json_schema'] = json_decode($call['json_schema'], true);
            }
            if ($call['response'] !== null) {
                $call['response'] = json_decode($call['response'], true);
            }
        }
        return $calls;
    }
}
