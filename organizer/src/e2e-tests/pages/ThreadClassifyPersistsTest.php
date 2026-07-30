<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';
require_once __DIR__ . '/../../tests/bootstrap.php';

/**
 * End-to-end regression for the classify form: saving a classification must
 * persist to thread_emails (it was silently dropped - updateThread only wrote
 * the threads row) and show up in the NP API listing.
 */
class ThreadClassifyPersistsTest extends E2EPageTestCase {
    public function testClassifyFormPersistsToNpApi() {
        $token = trim(file_get_contents(__DIR__ . '/../../../../secrets/np_api_token'));
        $docLabel = 'document_id:2031-' . random_int(1000, 999999) . '-1';
        $threadId = null;
        try {
            // Create a thread via the API (committed, real HTTP)
            $ch = curl_init('http://localhost:25081/api/np/thread');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'entity_id_norske_postlister' => '9999-test-entity-development',
                    'title' => 'Classify persistence e2e', 'body' => 'B',
                    'labels' => ['norske_postlister_no', 'document', $docLabel],
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Np-Api-Token: ' . $token],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $threadId = json_decode(curl_exec($ch), true)['thread_id'];
            curl_close($ch);

            // Incoming email, committed (web server is a separate process)
            $emailId = Database::queryValue(
                "INSERT INTO thread_emails
                    (thread_id, timestamp_received, datetime_received, email_type, content, imap_headers)
                 VALUES (?, now(), now(), 'IN', ?::bytea, NULL) RETURNING id",
                [$threadId, 'content']
            );

            // Save the classify form as an authenticated admin session
            $this->renderPage('/thread-classify?threadId=' . $threadId . '&emailId=' . $emailId, 'dev-user-id', 'POST', '302 Found', [
                $emailId . '-status_type' => 'INFORMATION_RELEASE',
                $emailId . '-status_text' => 'Svar',
                $emailId . '-answer' => '',
                'submit' => 'Save',
            ]);

            // The NP API must now report the classification
            $ch = curl_init('http://localhost:25081/api/np/threads');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['X-Np-Api-Token: ' . $token],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $body = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $found = null;
            foreach ($body['threads'] as $t) {
                if ($t['thread_id'] === $threadId) { $found = $t; }
            }
            $this->assertNotNull($found, 'thread must be in NP listing');
            $inEmails = array_values(array_filter($found['emails'], fn($e) => $e['email_type'] === 'IN'));
            $this->assertCount(1, $inEmails);
            $this->assertEquals('INFORMATION_RELEASE', $inEmails[0]['status_type']);
        } finally {
            if ($threadId !== null) {
                Database::execute("DELETE FROM thread_email_sendings WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_emails WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_history WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_email_history WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM thread_authorizations WHERE thread_id = ?", [$threadId]);
                Database::execute("DELETE FROM threads WHERE id = ?", [$threadId]);
            }
        }
    }
}
