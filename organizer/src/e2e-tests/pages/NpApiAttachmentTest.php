<?php
// organizer/src/e2e-tests/pages/NpApiAttachmentTest.php
use PHPUnit\Framework\TestCase;

class NpApiAttachmentTest extends TestCase {
    const BASE = 'http://localhost:25081';

    private function get(string $path, ?string $token): array {
        $ch = curl_init(self::BASE . $path);
        $headers = [];
        if ($token !== null) {
            $headers[] = 'X-Np-Api-Token: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body];
    }

    private function token(): string {
        return trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
    }

    public function testMissingTokenGives401(): void {
        $resp = $this->get('/api/np/attachment?thread_id=00000000-0000-4000-8000-000000000000&attachment_id=1', null);
        $this->assertEquals(401, $resp['status']);
    }

    public function testUnknownThreadGives404(): void {
        $resp = $this->get('/api/np/attachment?thread_id=00000000-0000-4000-8000-000000000000&attachment_id=1', $this->token());
        $this->assertEquals(404, $resp['status']);
    }

    public function testMalformedThreadIdGives400(): void {
        $resp = $this->get('/api/np/attachment?thread_id=not-a-uuid&attachment_id=1', $this->token());
        $this->assertEquals(400, $resp['status']);
    }
}
