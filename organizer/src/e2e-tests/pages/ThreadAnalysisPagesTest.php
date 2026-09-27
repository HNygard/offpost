<?php
// organizer/src/e2e-tests/pages/ThreadAnalysisPagesTest.php
// E2E tests for the admin debug pages of step 2c "Change 4b: admin debug
// pages" (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md). See
// docs/thread-analysis.md, "Debug pages".
//
// Modeled on ThreadStatusOverviewPageTest.php (E2EPageTestCase: renderPage()
// authenticates as the single dev admin user, sub 'dev-user-id', or as
// anonymous with $user = null) and AdminAnalysisApiTest.php (cleaning up
// thread_analysis_* rows before E2ETestSetup::cleanupTestThread(), since
// migration 034 adds no ON DELETE CASCADE from those tables onto
// threads/thread_emails).

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/common/E2ETestSetup.php';
require_once __DIR__ . '/../../class/Database.php';
require_once __DIR__ . '/../../class/ThreadAnalysis/ThreadAnalysisRepository.php';

class ThreadAnalysisPagesTest extends E2EPageTestCase {

    private function cleanupAnalysisRows(string $threadId): void {
        Database::execute(
            "DELETE FROM thread_analysis_claude_code_calls
             WHERE run_id IN (SELECT id FROM thread_analysis_runs WHERE thread_id = ?)",
            [$threadId]
        );
        Database::execute(
            "DELETE FROM thread_analysis_events
             WHERE run_id IN (SELECT id FROM thread_analysis_runs WHERE thread_id = ?)",
            [$threadId]
        );
        Database::execute("DELETE FROM thread_analysis_runs WHERE thread_id = ?", [$threadId]);
    }

    // --- /thread-analysis ---

    public function testOverviewPageLoggedIn() {
        // :: Setup

        // :: Act
        $response = $this->renderPage('/thread-analysis');

        // :: Assert
        $this->assertStringContainsString('<h1>Thread Analysis</h1>', $response->body);
        $this->assertStringContainsString('<h2>Runs by status</h2>', $response->body);
        $this->assertStringContainsString('<h2>Cost and tokens</h2>', $response->body);
        $this->assertStringContainsString('<h2>Cost per model</h2>', $response->body);
        $this->assertStringContainsString('<h2>Cost per system prompt version</h2>', $response->body);
        $this->assertStringContainsString('Queue (', $response->body);
        $this->assertStringContainsString('Recent runs (up to 100)', $response->body);
        $this->assertStringContainsString('Email-type gaps (up to 100)', $response->body);
        $this->assertStringContainsString("Disagreements with prod's classification", $response->body);
    }

    public function testOverviewPageNotLoggedIn() {
        // :: Setup

        // :: Act
        $response = $this->renderPage('/thread-analysis', null, 'GET', '302 Found');

        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }

    // --- /thread-analysis/thread ---

    public function testThreadPageLoggedInShowsRunEventAndCall() {
        // :: Setup
        $created = E2ETestSetup::createTestThread();
        $threadId = $created['thread']->id;
        $emailId = $created['email_id'];
        try {
            $runId = Database::queryValue(
                "INSERT INTO thread_analysis_runs
                    (thread_id, status, mode, requested_by, requested_at, claimed_at, finished_at, worker, model)
                 VALUES (?, 'done', 'full', 'test-user', '2026-01-01T08:00:00+00:00', '2026-01-01T08:00:10+00:00',
                         '2026-01-01T08:00:20+00:00', 'worker-1', 'claude-opus-5-5')
                 RETURNING id",
                [$threadId]
            );
            $eventId = Database::queryValue(
                "INSERT INTO thread_analysis_events
                    (run_id, email_id, \"position\", email_type, email_note, email_type_gap, attempts, error)
                 VALUES (?, ?, 1, 'INFORMATION_RELEASE', 'a test note', '', 1, NULL)
                 RETURNING id",
                [$runId, $emailId]
            );
            Database::execute(
                "INSERT INTO thread_analysis_claude_code_calls
                    (run_id, event_id, attempt, input_text, response, model, model_resolved,
                     claude_code_version, input_tokens, output_tokens, cost_usd, duration_ms, is_error, stop_reason)
                 VALUES (?, ?, 1, 'the test input text', '{\"ok\":true}'::jsonb, 'claude-opus-5-5', 'claude-opus-5-5',
                         '2.1.283', 11, 22, 0.05, 1234, false, 'end_turn')",
                [$runId, $eventId]
            );

            // :: Act
            $response = $this->renderPage('/thread-analysis/thread?id=' . $threadId);

            // :: Assert
            $this->assertStringContainsString(htmlspecialchars($created['thread']->title), $response->body);
            $this->assertStringContainsString('Run #' . $runId, $response->body);
            $this->assertStringContainsString('claude-opus-5-5', $response->body);
            $this->assertStringContainsString('a test note', $response->body);
            $this->assertStringContainsString('the test input text', $response->body);
            $this->assertStringContainsString('2.1.283', $response->body);
            $this->assertStringContainsString('INFORMATION_RELEASE', $response->body);
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }

    public function testThreadPageNotLoggedIn() {
        // :: Setup

        // :: Act
        $response = $this->renderPage(
            '/thread-analysis/thread?id=00000000-0000-4000-8000-000000000000',
            null,
            'GET',
            '302 Found'
        );

        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }

    public function testThreadPageInvalidUuidGives400() {
        // :: Setup

        // :: Act
        $response = $this->renderPage('/thread-analysis/thread?id=not-a-uuid', 'dev-user-id', 'GET', '400 Bad Request');

        // :: Assert
        $this->assertStringContainsString('Invalid id parameter', $response->body);
    }

    public function testThreadPageUnknownThreadGives404() {
        // :: Setup

        // :: Act
        $response = $this->renderPage(
            '/thread-analysis/thread?id=00000000-0000-4000-8000-000000000000',
            'dev-user-id',
            'GET',
            '404 Not Found'
        );

        // :: Assert
        $this->assertStringContainsString('Thread not found', $response->body);
    }

    // --- The "Analyse" POST creates a requested run. ---

    public function testAnalysePostCreatesARequestedRun() {
        // :: Setup
        $created = E2ETestSetup::createTestThread();
        $threadId = $created['thread']->id;
        try {
            // :: Act
            $response = $this->renderPage(
                '/thread-analysis/thread?id=' . $threadId,
                'dev-user-id',
                'POST',
                '302 Found',
                ['action' => 'analyse_incremental']
            );

            // :: Assert
            $this->assertStringContainsString('Location:', $response->headers);
            $runs = Database::query("SELECT * FROM thread_analysis_runs WHERE thread_id = ?", [$threadId]);
            $this->assertCount(1, $runs, json_encode($runs, JSON_PRETTY_PRINT));
            $this->assertEquals('requested', $runs[0]['status']);
            $this->assertEquals('incremental', $runs[0]['mode']);
            $this->assertEquals('dev-user-id', $runs[0]['requested_by']);
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }

    // --- /thread-analysis/system-prompt ---

    public function testSystemPromptPageLoggedInShowsTextAndRunCount() {
        // :: Setup
        $promptText = 'e2e test system prompt ' . uniqid();
        $sha = ThreadAnalysisRepository::saveSystemPrompt($promptText);

        // :: Act
        $response = $this->renderPage('/thread-analysis/system-prompt?sha=' . $sha);

        // :: Assert
        $this->assertStringContainsString(htmlspecialchars($promptText), $response->body);
        $this->assertStringContainsString('Runs using this version:</strong> 0', $response->body);
    }

    public function testSystemPromptPageNotLoggedIn() {
        // :: Setup
        $sha = str_repeat('a', 64);

        // :: Act
        $response = $this->renderPage('/thread-analysis/system-prompt?sha=' . $sha, null, 'GET', '302 Found');

        // :: Assert
        $this->assertStringContainsString('Location:', $response->headers);
    }

    public function testSystemPromptPageBadShaGives400() {
        // :: Setup

        // :: Act
        $response = $this->renderPage('/thread-analysis/system-prompt?sha=not-a-sha', 'dev-user-id', 'GET', '400 Bad Request');

        // :: Assert
        $this->assertStringContainsString('Invalid sha parameter', $response->body);
    }

    public function testSystemPromptPageUnknownShaGives404() {
        // :: Setup
        $sha = str_repeat('a', 64);

        // :: Act
        $response = $this->renderPage('/thread-analysis/system-prompt?sha=' . $sha, 'dev-user-id', 'GET', '404 Not Found');

        // :: Assert
        $this->assertStringContainsString('System prompt not found', $response->body);
    }
}
