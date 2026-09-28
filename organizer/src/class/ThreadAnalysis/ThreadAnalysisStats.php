<?php
// organizer/src/class/ThreadAnalysis/ThreadAnalysisStats.php
// Read-only queries backing the admin debug pages for step 2c of the
// innsynskrav classification roadmap
// (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 4b:
// admin debug pages"). See docs/thread-analysis.md, "Debug pages".
//
// No writes here - ThreadAnalysisRepository owns the queue/run mutations.
// This class only reports on the same four tables.

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

class ThreadAnalysisStats {
    /**
     * Legacy/absent classification values on thread_emails.status_type that
     * are never a meaningful "disagreement" with an analysis event, because
     * they mean "not really classified" rather than "classified differently".
     */
    private const NOT_A_REAL_CLASSIFICATION = [
        ThreadEmailStatusType::UNKNOWN->value,
        ThreadEmailStatusType::INFO->value,
        ThreadEmailStatusType::ERROR->value,
        ThreadEmailStatusType::SUCCESS->value,
    ];

    /**
     * @return array<string, int> status => count, for every run ever created.
     */
    public static function getRunCountsByStatus(): array {
        $rows = Database::query(
            "SELECT status, COUNT(*) AS count FROM thread_analysis_runs GROUP BY status"
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * @return array<string, int> review_status => count, for every run ever
     *   created (step 2c "Change 9: review status and notes per run").
     */
    public static function getRunCountsByReviewStatus(): array {
        $rows = Database::query(
            "SELECT review_status, COUNT(*) AS count FROM thread_analysis_runs GROUP BY review_status"
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['review_status']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * Cost and token sums from thread_analysis_claude_code_calls for three
     * periods: today, the last 7 days, and all time.
     *
     * @return array<string, array{calls:int, cost_usd:float, input_tokens:int,
     *   cache_creation_input_tokens:int, cache_read_input_tokens:int,
     *   output_tokens:int, thinking_tokens:int}>
     */
    public static function getUsageTotals(): array {
        return [
            'today' => self::usageTotalsSince(date('Y-m-d 00:00:00')),
            'last_7_days' => self::usageTotalsSince(date('Y-m-d 00:00:00', strtotime('-6 days'))),
            'all_time' => self::usageTotalsSince(null),
        ];
    }

    private static function usageTotalsSince(?string $sinceSql): array {
        $sql = "SELECT
                    COUNT(*) AS calls,
                    COALESCE(SUM(cost_usd), 0) AS cost_usd,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(cache_creation_input_tokens), 0) AS cache_creation_input_tokens,
                    COALESCE(SUM(cache_read_input_tokens), 0) AS cache_read_input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(thinking_tokens), 0) AS thinking_tokens
                FROM thread_analysis_claude_code_calls";
        $params = [];
        if ($sinceSql !== null) {
            $sql .= " WHERE created_at >= ?";
            $params[] = $sinceSql;
        }
        $row = Database::queryOne($sql, $params);
        return [
            'calls' => (int) $row['calls'],
            'cost_usd' => (float) $row['cost_usd'],
            'input_tokens' => (int) $row['input_tokens'],
            'cache_creation_input_tokens' => (int) $row['cache_creation_input_tokens'],
            'cache_read_input_tokens' => (int) $row['cache_read_input_tokens'],
            'output_tokens' => (int) $row['output_tokens'],
            'thinking_tokens' => (int) $row['thinking_tokens'],
        ];
    }

    /**
     * @return array<int, array{model:?string, calls:int, cost_usd:float}> ordered by cost desc.
     */
    public static function getCostByModel(): array {
        $rows = Database::query(
            "SELECT model, COUNT(*) AS calls, COALESCE(SUM(cost_usd), 0) AS cost_usd
             FROM thread_analysis_claude_code_calls
             GROUP BY model
             ORDER BY cost_usd DESC"
        );
        return array_map(fn(array $row): array => [
            'model' => $row['model'],
            'calls' => (int) $row['calls'],
            'cost_usd' => (float) $row['cost_usd'],
        ], $rows);
    }

    /**
     * Cost per system-prompt version, keyed by the prompt's sha256, with when
     * that version was first used (the prompt row's created_at - it is
     * inserted the first time ThreadAnalysisRepository::saveSystemPrompt()
     * sees that exact text).
     *
     * @return array<int, array{sha256:string, first_used_at:string, calls:int, cost_usd:float}> ordered by first use.
     */
    public static function getCostBySystemPrompt(): array {
        $rows = Database::query(
            "SELECT p.sha256, p.created_at AS first_used_at, COUNT(c.id) AS calls, COALESCE(SUM(c.cost_usd), 0) AS cost_usd
             FROM thread_analysis_system_prompts p
             JOIN thread_analysis_runs r ON r.system_prompt_sha256 = p.sha256
             JOIN thread_analysis_claude_code_calls c ON c.run_id = r.id
             GROUP BY p.sha256, p.created_at
             ORDER BY p.created_at ASC"
        );
        return array_map(fn(array $row): array => [
            'sha256' => $row['sha256'],
            'first_used_at' => $row['first_used_at'],
            'calls' => (int) $row['calls'],
            'cost_usd' => (float) $row['cost_usd'],
        ], $rows);
    }

    /**
     * Runs that are 'requested' or 'claimed', oldest first (the queue).
     *
     * @return array<int, array> thread_analysis_runs rows plus thread_title.
     */
    public static function getQueue(): array {
        return Database::query(
            "SELECT r.*, t.title AS thread_title
             FROM thread_analysis_runs r
             JOIN threads t ON t.id = r.thread_id
             WHERE r.status IN ('requested', 'claimed')
             ORDER BY r.requested_at ASC"
        );
    }

    /**
     * The most recent runs of any status, newest first.
     *
     * @return array<int, array> thread_analysis_runs rows plus thread_title,
     *   event_count and cost_usd (summed over the run's calls).
     */
    public static function getRecentRuns(int $limit = 100): array {
        $rows = Database::query(
            "SELECT r.*, t.title AS thread_title,
                    (SELECT COUNT(*) FROM thread_analysis_events e WHERE e.run_id = r.id) AS event_count,
                    (SELECT COALESCE(SUM(c.cost_usd), 0) FROM thread_analysis_claude_code_calls c WHERE c.run_id = r.id) AS cost_usd
             FROM thread_analysis_runs r
             JOIN threads t ON t.id = r.thread_id
             ORDER BY r.requested_at DESC
             LIMIT ?",
            [$limit]
        );
        foreach ($rows as &$row) {
            $row['event_count'] = (int) $row['event_count'];
            $row['cost_usd'] = (float) $row['cost_usd'];
        }
        return $rows;
    }

    /**
     * Events with a non-empty email_type_gap - places the model noted a gap
     * between what it expected and the email's classification.
     *
     * @return array<int, array> thread_id, thread_title, email_id, position,
     *   email_type, email_type_gap, run_id.
     */
    public static function getEmailTypeGaps(int $limit = 100): array {
        return Database::query(
            "SELECT ev.id AS event_id, ev.run_id, ev.email_id, ev.\"position\", ev.email_type, ev.email_type_gap,
                    r.thread_id, t.title AS thread_title
             FROM thread_analysis_events ev
             JOIN thread_analysis_runs r ON r.id = ev.run_id
             JOIN threads t ON t.id = r.thread_id
             WHERE ev.email_type_gap IS NOT NULL AND ev.email_type_gap != ''
             ORDER BY ev.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * For each thread's latest 'done' run, the events whose email_type
     * differs from thread_emails.status_type - i.e. where the analysis and
     * prod's own classification disagree. Leaves out prod values that mean
     * "not really classified" (unknown, and the legacy info/error/success
     * values), and NULL (never classified).
     *
     * @return array<int, array{thread_id:string, thread_title:string, run_id:int,
     *   email_id:string, position:int, analysis_email_type:string,
     *   prod_status_type:?string, prod_classification_source:string}> ordered
     *   by thread then position.
     */
    public static function getDisagreements(int $limit = 100): array {
        $placeholders = implode(', ', array_fill(0, count(self::NOT_A_REAL_CLASSIFICATION), '?'));
        $rows = Database::query(
            "WITH latest_done_run AS (
                SELECT DISTINCT ON (thread_id) id AS run_id, thread_id
                FROM thread_analysis_runs
                WHERE status = 'done'
                ORDER BY thread_id, finished_at DESC
             )
             SELECT ldr.thread_id, t.title AS thread_title, ldr.run_id,
                    ev.email_id, ev.\"position\", ev.email_type AS analysis_email_type,
                    te.status_type AS prod_status_type, te.auto_classification AS prod_auto_classification
             FROM latest_done_run ldr
             JOIN thread_analysis_events ev ON ev.run_id = ldr.run_id
             JOIN thread_emails te ON te.id = ev.email_id
             JOIN threads t ON t.id = ldr.thread_id
             WHERE ev.email_type IS NOT NULL
               AND ev.email_type IS DISTINCT FROM te.status_type
               AND te.status_type IS NOT NULL
               AND te.status_type NOT IN ($placeholders)
             ORDER BY ldr.thread_id, ev.\"position\"
             LIMIT ?",
            array_merge(self::NOT_A_REAL_CLASSIFICATION, [$limit])
        );
        foreach ($rows as &$row) {
            $row['position'] = (int) $row['position'];
            $row['prod_classification_source'] = self::classificationSource(
                $row['prod_status_type'],
                $row['prod_auto_classification']
            );
        }
        return $rows;
    }

    /**
     * 'manual' when prod's status_type was set by a human (no
     * auto_classification recorded); otherwise the auto_classification value
     * itself ('algo' or 'prompt'). Mirrors
     * ThreadExportService::classificationSource(), which is private there.
     */
    private static function classificationSource(?string $statusType, ?string $autoClassification): string {
        if ($autoClassification === 'algo' || $autoClassification === 'prompt') {
            return $autoClassification;
        }
        return 'manual';
    }
}
