<?php
// organizer/src/e2e-tests/pages/NpApiAttachmentTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../class/NpApiService.php';

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

    /**
     * Like get(), but also returns the raw response headers so tests can inspect
     * the Content-Disposition value (and confirm no header injection happened).
     */
    private function getWithHeaders(string $path, ?string $token): array {
        $ch = curl_init(self::BASE . $path);
        $headers = [];
        if ($token !== null) {
            $headers[] = 'X-Np-Api-Token: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [
            'status' => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
        ];
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

    /**
     * Regression test for a hostile stored attachment name (CR/LF + '"') that used
     * to crash header() with an ErrorException, giving a 500 with a leaked stack
     * trace and permanently breaking that attachment's fetch. Uses real committed
     * rows (this test runs against the live http server, a separate process from
     * phpunit, so an uncommitted transaction here would be invisible to it) and
     * cleans them up in finally.
     */
    public function testHostileAttachmentNameDoesNotInjectHeaderOrCrash(): void {
        $threadId = null;
        try {
            $created = NpApiService::createThread(
                '9999-test-entity-development',
                'Hostile filename test',
                'Body',
                ['norske_postlister_no', 'document', 'document_id:2020-' . random_int(1000, 999999) . '-1']
            );
            $threadId = $created['thread_id'];

            $ts = gmdate('Y-m-d\TH:i:sP', 1700000400);
            $emailId = Database::queryValue(
                "INSERT INTO thread_emails
                    (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
                 VALUES (?, ?, ?, ?, ?::bytea, NULL) RETURNING id",
                [$threadId, $ts, $ts, 'IN', 'content']
            );
            $hostileName = "evil\"\r\nX-Injected: 1.pdf";
            $attachmentId = Database::queryValue(
                "INSERT INTO thread_email_attachments (email_id, name, filename, filetype, location, content)
                 VALUES (?, ?, ?, ?, ?, ?::bytea) RETURNING id",
                [$emailId, $hostileName, $hostileName, 'pdf', $hostileName, 'BYTES']
            );

            $resp = $this->getWithHeaders(
                '/api/np/attachment?thread_id=' . $threadId . '&attachment_id=' . $attachmentId,
                $this->token()
            );

            $this->assertEquals(200, $resp['status'], 'must not 500 on a hostile filename');

            // The injected "X-Injected: 1" must not have become its own header line -
            // it's fine for the (harmless) substring to remain inside the filename value.
            $headerLines = explode("\r\n", trim($resp['headers']));
            $this->assertNotContains('X-Injected: 1', $headerLines, 'CR/LF must not have split the filename into a second header');
            foreach ($headerLines as $line) {
                $this->assertStringStartsNotWith('X-Injected:', $line);
            }

            $this->assertMatchesRegularExpression(
                '/^Content-Disposition: attachment; filename="[^"\r\n]*"\r?$/m',
                $resp['headers']
            );
            $this->assertEquals('BYTES', $resp['body']);
        } finally {
            if ($threadId !== null) {
                Database::execute("DELETE FROM thread_email_sendings WHERE thread_id = ?", [$threadId]);
                Database::execute(
                    "DELETE FROM thread_email_attachments WHERE email_id IN
                        (SELECT id FROM thread_emails WHERE thread_id = ?)",
                    [$threadId]
                );
                Database::execute("DELETE FROM thread_emails WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_history WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_authorizations WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM threads WHERE id = ?", [$threadId]);
            }
        }
    }
}
