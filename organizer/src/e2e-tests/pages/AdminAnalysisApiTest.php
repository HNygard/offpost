<?php
// organizer/src/e2e-tests/pages/AdminAnalysisApiTest.php
// Modeled on AdminExportApiTest.php (raw curl + header auth against
// api/admin/admin-api-auth.php) and NpApiThreadCreateTest.php (POSTing JSON
// via curl). See docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md,
// "Change 2: the endpoints".
//
// NOTE on scope: as in AdminExportApiTest.php, a genuinely non-admin
// *authenticated* session cannot be exercised here - the dev auth service
// hardcodes its OIDC interaction result to the single dev admin user. What
// this file does check is that adminApiRequireToken() rejects an admin
// session with no token at all (unlike adminApiRequireTokenOrAdminSession(),
// which the GET export endpoints use).
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../class/Database.php';
require_once __DIR__ . '/../../class/NpApiService.php';
require_once __DIR__ . '/common/E2ETestSetup.php';

class AdminAnalysisApiTest extends TestCase {
    const BASE = 'http://localhost:25081';

    private static ?string $adminSessionCookie = null;

    private function post(string $path, ?array $body, array $headers = [], ?string $cookie = null): array {
        $ch = curl_init(self::BASE . $path);
        $headers[] = 'Content-Type: application/json';
        if ($cookie !== null) {
            $headers[] = 'Cookie: ' . $cookie;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body === null ? '' : json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $respBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($respBody, true), 'body' => $respBody];
    }

    private function postRaw(string $path, string $rawBody, array $headers = []): array {
        $ch = curl_init(self::BASE . $path);
        $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $respBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($respBody, true), 'body' => $respBody];
    }

    private function get(string $path, array $headers = []): array {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($body, true), 'body' => $body];
    }

    private function adminToken(): string {
        return trim(file_get_contents(__DIR__ . '/../../../../secrets/admin_api_token'));
    }

    private function npToken(): string {
        return trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
    }

    /**
     * Dev-mode auto-login always authenticates as the one dev admin user (see
     * the NOTE at the top of this file and in AdminExportApiTest.php) - same
     * mechanism as AdminExportApiTest::adminSessionCookie(), reimplemented
     * here for the same reason (raw header-based requests).
     */
    private function adminSessionCookie(): string {
        if (self::$adminSessionCookie === null) {
            $ch = curl_init(self::BASE . '/?test-authenticate');
            $cookieJar = tempnam(sys_get_temp_dir(), 'cookie');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEJAR => $cookieJar,
            ]);
            $res = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($res, 0, $headerSize);
            curl_close($ch);

            $cookie = null;
            foreach (explode("\n", $header) as $line) {
                if (str_starts_with($line, 'Set-Cookie: PHPSESSID=')) {
                    $cookie = explode(': ', explode(';', $line)[0])[1];
                }
            }
            if ($cookie === null) {
                throw new Exception('No session cookie found from test-authenticate. Full headers: ' . $header);
            }
            self::$adminSessionCookie = $cookie;
        }
        return self::$adminSessionCookie;
    }

    /**
     * thread_analysis_* rows have no ON DELETE CASCADE onto threads/
     * thread_emails (migration 034), so a test that creates a run must clean
     * these up itself before E2ETestSetup::cleanupTestThread() deletes the
     * thread and its emails, or that delete fails on the foreign keys.
     */
    /**
     * A norske-postlister.no thread with one email at $datetimeReceived, for
     * /api/admin/analysis/request-next tests (Change 7). Use a far-future
     * datetime so it sorts newest-first ahead of any other unanalysed NP
     * thread already sitting in this shared database.
     */
    private function createNpThreadWithEmail(string $datetimeReceived): array {
        $thread = new Thread();
        $thread->title = 'AdminAnalysisApiTest NP thread ' . uniqid();
        $thread->my_name = 'Test Person';
        $thread->my_email = 'np-request-next-' . uniqid() . '@example.com';
        $thread->labels = [NpApiService::NP_LABEL];
        $thread->sent = false;
        $thread->archived = false;
        $thread->public = true;
        $created = createThread('000000000-test-entity-development', $thread);
        Database::execute(
            "INSERT INTO thread_emails (thread_id, timestamp_received, datetime_received, content)
             VALUES (?, ?, ?, ?::bytea)",
            [$created->id, $datetimeReceived, $datetimeReceived, 'Body text']
        );
        return ['thread_id' => $created->id, 'entity_id' => '000000000-test-entity-development'];
    }

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

    // --- 401: no auth at all (all three) ---

    public function testRequestWithoutAuthGives401(): void {
        $resp = $this->post('/api/admin/analysis/request', ['thread_id' => '00000000-0000-4000-8000-000000000000', 'mode' => 'full']);
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testClaimWithoutAuthGives401(): void {
        $resp = $this->post('/api/admin/analysis/claim', ['worker' => 'e2e-worker']);
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testResultWithoutAuthGives401(): void {
        $resp = $this->post('/api/admin/analysis/result', ['run_id' => 1, 'worker' => 'e2e-worker']);
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    // --- 401: an admin session with no token must not authenticate here
    // either - unlike the GET export endpoints, these accept the token only. ---

    public function testRequestWithAdminSessionAndNoTokenGives401(): void {
        $resp = $this->post(
            '/api/admin/analysis/request',
            ['thread_id' => '00000000-0000-4000-8000-000000000000', 'mode' => 'full'],
            [],
            $this->adminSessionCookie()
        );
        $this->assertEquals(401, $resp['status']);
    }

    public function testClaimWithAdminSessionAndNoTokenGives401(): void {
        $resp = $this->post('/api/admin/analysis/claim', ['worker' => 'e2e-worker'], [], $this->adminSessionCookie());
        $this->assertEquals(401, $resp['status']);
    }

    public function testResultWithAdminSessionAndNoTokenGives401(): void {
        $resp = $this->post('/api/admin/analysis/result', ['run_id' => 1, 'worker' => 'e2e-worker'], [], $this->adminSessionCookie());
        $this->assertEquals(401, $resp['status']);
    }

    // --- 401: the NP token must never authenticate here ---

    public function testRequestWithNpTokenGives401(): void {
        $resp = $this->post(
            '/api/admin/analysis/request',
            ['thread_id' => '00000000-0000-4000-8000-000000000000', 'mode' => 'full'],
            ['X-Admin-Api-Token: ' . $this->npToken()]
        );
        $this->assertEquals(401, $resp['status']);
    }

    // --- 405 on GET ---

    public function testRequestGetGives405(): void {
        $resp = $this->get('/api/admin/analysis/request', ['X-Admin-Api-Token: ' . $this->adminToken()]);
        $this->assertEquals(405, $resp['status']);
    }

    public function testClaimGetGives405(): void {
        $resp = $this->get('/api/admin/analysis/claim', ['X-Admin-Api-Token: ' . $this->adminToken()]);
        $this->assertEquals(405, $resp['status']);
    }

    public function testResultGetGives405(): void {
        $resp = $this->get('/api/admin/analysis/result', ['X-Admin-Api-Token: ' . $this->adminToken()]);
        $this->assertEquals(405, $resp['status']);
    }

    // --- 400: invalid JSON and a bad mode ---

    public function testRequestInvalidJsonGives400(): void {
        $resp = $this->postRaw('/api/admin/analysis/request', 'not json', ['X-Admin-Api-Token: ' . $this->adminToken()]);
        $this->assertEquals(400, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testRequestBadModeGives400(): void {
        $created = E2ETestSetup::createTestThread();
        $threadId = $created['thread']->id;
        try {
            $resp = $this->post(
                '/api/admin/analysis/request',
                ['thread_id' => $threadId, 'mode' => 'not-a-mode'],
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );
            $this->assertEquals(400, $resp['status']);
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }

    // --- 404: an unknown thread ---

    public function testRequestUnknownThreadGives404(): void {
        $resp = $this->post(
            '/api/admin/analysis/request',
            ['thread_id' => '00000000-0000-4000-8000-000000000000', 'mode' => 'full'],
            ['X-Admin-Api-Token: ' . $this->adminToken()]
        );
        $this->assertEquals(404, $resp['status']);
    }

    // --- 404: an unknown run ---

    public function testResultUnknownRunGives404(): void {
        $resp = $this->post(
            '/api/admin/analysis/result',
            ['run_id' => 999999999, 'worker' => 'e2e-worker', 'status' => 'done', 'error' => null, 'events' => []],
            ['X-Admin-Api-Token: ' . $this->adminToken()]
        );
        $this->assertEquals(404, $resp['status']);
    }

    // --- The whole flow, on a thread from E2ETestSetup: request, then claim
    // (checking email_ids and that there is no eml_base64), then post a done
    // result with one valid event and call, then check the run is done and
    // thread_emails.thread_state_source is 'auto'. ---

    public function testRequestClaimAndResultFlow(): void {
        $created = E2ETestSetup::createTestThread();
        $threadId = $created['thread']->id;
        $emailId = $created['email_id'];
        try {
            $requestResp = $this->post(
                '/api/admin/analysis/request',
                ['thread_id' => $threadId, 'mode' => 'full'],
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );
            $this->assertEquals(200, $requestResp['status'], $requestResp['body']);
            $this->assertArrayHasKey('run_id', $requestResp['json'], $requestResp['body']);
            $runId = $requestResp['json']['run_id'];

            $claimResp = $this->post(
                '/api/admin/analysis/claim',
                ['worker' => 'e2e-worker', 'thread_id' => $threadId],
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );
            $this->assertEquals(200, $claimResp['status'], $claimResp['body']);
            $claimJson = $claimResp['json'];
            $this->assertEquals($runId, $claimJson['run']['id'], json_encode($claimJson, JSON_PRETTY_PRINT));
            $this->assertEquals($threadId, $claimJson['run']['thread_id']);
            $this->assertEquals('full', $claimJson['run']['mode']);
            $this->assertEquals([$emailId], $claimJson['email_ids'], json_encode($claimJson, JSON_PRETTY_PRINT));
            $this->assertNull($claimJson['start_state']);
            $this->assertNull($claimJson['start_after_email_id']);
            $this->assertCount(1, $claimJson['thread']['emails'], json_encode($claimJson['thread']['emails'], JSON_PRETTY_PRINT));
            $this->assertArrayNotHasKey('eml_base64', $claimJson['thread']['emails'][0], json_encode($claimJson['thread']['emails'][0], JSON_PRETTY_PRINT));

            $threadState = [
                'schema_version' => 1,
                'request' => ['summary' => 'Valgprotokoll', 'law_basis' => 'offentleglova', 'sent_at' => null],
                'items' => [
                    ['id' => '1', 'asked_for' => 'Valgprotokoll', 'status' => 'NOT_ANSWERED',
                     'denial_basis' => null, 'released_in_email_ids' => [], 'note' => ''],
                ],
                'waiting_for' => 'ENTITY',
                'asks_to_us' => [],
                'case_numbers' => [],
                'dates' => [],
                'complaints' => [],
                'notes' => '',
                'extra' => [],
            ];
            $resultResp = $this->post(
                '/api/admin/analysis/result',
                [
                    'run_id' => $runId,
                    'worker' => 'e2e-worker',
                    'status' => 'done',
                    'error' => null,
                    'model' => 'claude-opus-5-5',
                    'system_prompt' => 'e2e system prompt',
                    'schema_version' => 1,
                    'events' => [
                        [
                            'email_id' => $emailId,
                            'position' => 1,
                            'email_type' => 'INFORMATION_RELEASE',
                            'email_note' => 'note',
                            'email_type_gap' => '',
                            'thread_state' => $threadState,
                            'attempts' => 1,
                            'error' => null,
                            'calls' => [
                                [
                                    'attempt' => 1,
                                    'input_text' => 'the input',
                                    'json_schema' => ['type' => 'object'],
                                    'response' => ['ok' => true],
                                    'model' => 'claude-opus-5-5',
                                    'model_resolved' => 'claude-opus-5-5',
                                    'session_id' => 'session-e2e',
                                    'claude_code_version' => '2.1.283',
                                    'input_tokens' => 1,
                                    'cache_creation_input_tokens' => 1,
                                    'cache_read_input_tokens' => 0,
                                    'output_tokens' => 1,
                                    'thinking_tokens' => 0,
                                    'cost_usd' => 0.01,
                                    'duration_ms' => 100,
                                    'duration_api_ms' => 90,
                                    'is_error' => false,
                                    'stop_reason' => 'end_turn',
                                ],
                            ],
                        ],
                    ],
                ],
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );
            $this->assertEquals(200, $resultResp['status'], $resultResp['body']);
            $this->assertEquals($runId, $resultResp['json']['run_id']);
            $this->assertEquals('done', $resultResp['json']['status']);

            $run = Database::queryOne("SELECT * FROM thread_analysis_runs WHERE id = ?", [$runId]);
            $this->assertEquals('done', $run['status'], json_encode($run, JSON_PRETTY_PRINT));

            $email = Database::queryOne("SELECT * FROM thread_emails WHERE id = ?", [$emailId]);
            $this->assertEquals('auto', $email['thread_state_source'], json_encode($email, JSON_PRETTY_PRINT));
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }

    // --- claim returns 204 when nothing is queued. Deterministic: restricted
    // to a freshly-created thread that has no analysis run at all. ---

    public function testClaimReturns204WhenNothingQueued(): void {
        $created = E2ETestSetup::createTestThread();
        $threadId = $created['thread']->id;
        try {
            $resp = $this->post(
                '/api/admin/analysis/claim',
                ['worker' => 'e2e-worker', 'thread_id' => $threadId],
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );
            $this->assertEquals(204, $resp['status']);
            $this->assertEquals('', $resp['body']);
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }

    // --- request-next (Change 7: "process next" for norske-postlister
    // threads) ---

    public function testRequestNextWithoutAuthGives401(): void {
        $resp = $this->post('/api/admin/analysis/request-next', ['kind' => 'np']);
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testRequestNextGetGives405(): void {
        $resp = $this->get('/api/admin/analysis/request-next', ['X-Admin-Api-Token: ' . $this->adminToken()]);
        $this->assertEquals(405, $resp['status']);
    }

    public function testRequestNextBadKindGives400(): void {
        $resp = $this->post(
            '/api/admin/analysis/request-next',
            ['kind' => 'not-np'],
            ['X-Admin-Api-Token: ' . $this->adminToken()]
        );
        $this->assertEquals(400, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testRequestNextGivesRunIdAndThreadId(): void {
        $created = $this->createNpThreadWithEmail('2099-01-01 10:00:00+00');
        $threadId = $created['thread_id'];
        try {
            $resp = $this->post(
                '/api/admin/analysis/request-next',
                ['kind' => 'np', 'mode' => 'full'],
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );
            $this->assertEquals(200, $resp['status'], $resp['body']);
            $this->assertArrayHasKey('run_id', $resp['json'], $resp['body']);
            $this->assertEquals($threadId, $resp['json']['thread_id'], $resp['body']);

            $run = Database::queryOne("SELECT * FROM thread_analysis_runs WHERE id = ?", [$resp['json']['run_id']]);
            $this->assertEquals('full', $run['mode'], json_encode($run, JSON_PRETTY_PRINT));
            $this->assertEquals($threadId, $run['thread_id']);
            $this->assertEquals('requested', $run['status']);
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }

    // --- GET /api/admin/analysis/reviews (step 2c "Change 9: review status
    // and notes per run") ---

    public function testReviewsWithoutAuthGives401(): void {
        $resp = $this->get('/api/admin/analysis/reviews');
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testReviewsWithAdminTokenGives200WithAFilter(): void {
        $created = E2ETestSetup::createTestThread();
        $threadId = $created['thread']->id;
        try {
            $runId = Database::queryValue(
                "INSERT INTO thread_analysis_runs
                    (thread_id, status, mode, requested_by, requested_at, finished_at, review_status, review_notes, reviewed_by, reviewed_at)
                 VALUES (?, 'done', 'full', 'test-user', '2026-01-01T08:00:00+00:00', '2026-01-01T08:00:20+00:00',
                         'WRONG', 'e2e reviews test note', 'admin-user', '2026-01-01T09:00:00+00:00')
                 RETURNING id",
                [$threadId]
            );

            $resp = $this->get(
                '/api/admin/analysis/reviews?status=WRONG,MINOR_ISSUES&limit=100',
                ['X-Admin-Api-Token: ' . $this->adminToken()]
            );

            $this->assertEquals(200, $resp['status'], $resp['body']);
            $this->assertArrayHasKey('reviews', $resp['json'], $resp['body']);
            $matching = array_values(array_filter($resp['json']['reviews'], fn(array $row): bool => (int) $row['id'] === $runId));
            $this->assertCount(1, $matching, $resp['body']);
            $this->assertEquals('WRONG', $matching[0]['review_status']);
            $this->assertEquals('e2e reviews test note', $matching[0]['review_notes']);
            $this->assertEquals($threadId, $matching[0]['thread_id']);
        } finally {
            $this->cleanupAnalysisRows($threadId);
            E2ETestSetup::cleanupTestThread($threadId, $created['entity_id']);
        }
    }
}
