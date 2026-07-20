<?php
// organizer/src/e2e-tests/pages/NpApiThreadCreateTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../class/Database.php';

class NpApiThreadCreateTest extends TestCase {
    const BASE = 'http://localhost:25081';

    private function post(string $path, array $body, ?string $token): array {
        $ch = curl_init(self::BASE . $path);
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'X-Np-Api-Token: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $respBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($respBody, true)];
    }

    public function testMissingTokenGives401(): void {
        $resp = $this->post('/api/np/thread', [], null);
        $this->assertEquals(401, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testWrongTokenGives401(): void {
        $resp = $this->post('/api/np/thread', [], 'wrong');
        $this->assertEquals(401, $resp['status']);
    }

    /**
     * Commits a real thread against the live dev DB (this test runs against the
     * http server via curl, a separate process from phpunit, so nothing here can
     * be rolled back the way the unit tests are). Cleans up in finally, mirroring
     * NpApiAttachmentTest's pattern - without this, every run of the e2e suite
     * left a thread behind, accumulating in the dev DB and burning the real
     * daily creation cap NpApiService::DAILY_CAP enforces.
     */
    public function testCreateAndDedup(): void {
        $token = trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
        $docId = 'document_id:2020-' . random_int(1000, 999999) . '-1';
        $body = [
            'entity_id_norske_postlister' => '9999-test-entity-development',
            'title' => 'E2E test innsynskrav',
            'body' => "Kjære test\n\nSøker innsyn i: ...",
            'labels' => ['norske_postlister_no', 'document', $docId],
        ];

        $threadId = null;
        try {
            $resp = $this->post('/api/np/thread', $body, $token);
            $this->assertEquals(200, $resp['status']);
            $this->assertTrue($resp['json']['created']);
            $this->assertNotEmpty($resp['json']['thread_id']);
            $threadId = $resp['json']['thread_id'];

            $resp2 = $this->post('/api/np/thread', $body, $token);
            $this->assertEquals(200, $resp2['status']);
            $this->assertFalse($resp2['json']['created']);
            $this->assertEquals($resp['json']['thread_id'], $resp2['json']['thread_id']);
        } finally {
            if ($threadId !== null) {
                Database::execute("DELETE FROM thread_email_sendings WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_history WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_authorizations WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM threads WHERE id = ?", [$threadId]);
            }
        }
    }

    public function testNonStringLabelGives400(): void {
        $token = trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
        $resp = $this->post('/api/np/thread', [
            'entity_id_norske_postlister' => '9999-test-entity-development',
            'title' => 'T', 'body' => 'B',
            'labels' => ['norske_postlister_no', 'document', ['nested']],
        ], $token);
        $this->assertEquals(400, $resp['status']);
        $this->assertArrayHasKey('error', $resp['json']);
    }

    public function testUnknownEntityGives404(): void {
        $token = trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
        $resp = $this->post('/api/np/thread', [
            'entity_id_norske_postlister' => '0000-finnes-ikke',
            'title' => 'T', 'body' => 'B',
            'labels' => ['norske_postlister_no', 'document', 'document_id:2020-1-1'],
        ], $token);
        $this->assertEquals(404, $resp['status']);
    }
}
