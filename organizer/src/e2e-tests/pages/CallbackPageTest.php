<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class CallbackPageTest extends E2EPageTestCase {

    public function testCallbackWithError() {
        // Test callback with error
        $response = $this->renderPage('/callback?error=access_denied', null, 'GET', '400 Bad Request');

        // :: Assert error message
        $this->assertStringContainsString('No authorization code provided', $response->body);
    }

    public function testCallbackWithoutParams() {
        // Should fail when no params provided
        $response = $this->renderPage('/callback', null, 'GET', '400 Bad Request');

        // :: Assert error message
        $this->assertStringContainsString('No authorization code provided', $response->body);
    }

    public function testCallbackWithMismatchingState() {
        // :: Setup
        // No login was started in this session, so no state is stored.

        // :: Act
        $response = $this->renderPage('/callback?code=some-code&state=not-the-stored-state', null, 'GET', '400 Bad Request');

        // :: Assert
        $this->assertEquals('Invalid login state', trim($response->body), 'Callback must be rejected before any token exchange');
    }

    public function testCallbackWithWrongStateInSessionThatStartedLogin() {
        // :: Setup
        // GET / without a session redirects to the login and stores the state in the session.
        $cookie = $this->startLoginAndGetSessionCookie();

        // :: Act
        $wrong = $this->getWithCookie('/callback?code=x&state=wrong', $cookie);

        // :: Assert
        $this->assertEquals('HTTP/1.1 400 Bad Request', $wrong['status'], 'Wrong state must be rejected');
        $this->assertEquals('Invalid login state', trim($wrong['body']), 'Rejected before any token exchange');
    }

    public function testCallbackWithMissingStateInSessionThatStartedLogin() {
        // :: Setup
        $cookie = $this->startLoginAndGetSessionCookie();

        // :: Act
        $missing = $this->getWithCookie('/callback?code=x', $cookie);

        // :: Assert
        $this->assertEquals('HTTP/1.1 400 Bad Request', $missing['status'], 'Missing state must be rejected');
        $this->assertEquals('Invalid login state', trim($missing['body']));
    }

    private function startLoginAndGetSessionCookie(): string {
        $start = $this->getWithCookie('/', null);
        $this->assertEquals('HTTP/1.1 302 Found', $start['status'], 'Unauthenticated request must redirect to login');
        $this->assertEquals(1, preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/mi', $start['headers'], $m),
            "Login start must set a session cookie. Headers:\n" . $start['headers']);
        return $m[1];
    }

    /** @return array{status: string, headers: string, body: string} */
    private function getWithCookie(string $path, ?string $cookie): array {
        $ch = curl_init('http://localhost:25081' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter([
            'User-Agent: Offpost E2E Test',
            $cookie !== null ? 'Cookie: ' . $cookie : null,
        ])));
        $res = curl_exec($ch);
        if ($res === false) {
            throw new Exception('Request to [' . $path . '] failed: ' . curl_error($ch));
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($res, 0, $headerSize);
        return [
            'status' => trim(explode("\n", $headers, 2)[0]),
            'headers' => $headers,
            'body' => substr($res, $headerSize),
        ];
    }

}
