<?php

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase {
    private $server;
    private string $baseUrl;
    private string $sessionDir;

    protected function setUp(): void {
        $this->sessionDir = sys_get_temp_dir() . '/offpost-auth-test-' . getmypid();
        if (!is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0700);
        }
        foreach ([25185, 25186, 25187, 25188, 25189] as $port) {
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if ($socket === false) {
                continue;
            }
            fclose($socket);
            $this->baseUrl = 'http://127.0.0.1:' . $port;
            $this->server = proc_open([
                PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir,
                '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/auth-router.php',
            ], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
            $this->assertIsResource($this->server);
            fclose($pipes[0]);
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @stream_socket_client('tcp://127.0.0.1:' . $port);
                if ($connection !== false) {
                    fclose($connection);
                    return;
                }
                usleep(10000);
            }
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->fail('Could not start the authentication test HTTP server');
    }

    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->sessionDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->sessionDir);
    }

    public function testCallbackReturnsToExactPageAfterSessionLoss(): void {
        // :: Setup
        $originalUrl = '/thread-view?entityId=test-entity&threadId=test-thread&label=two%20words';
        $login = $this->request($originalUrl);
        $state = $this->state($login);
        $cookies = $this->withoutSession($login['cookies']);
        $this->deleteSessions();

        // :: Act
        $callback = $this->request('/callback?code=test-code&state=' . urlencode($state), $cookies);

        // :: Assert
        $this->assertEquals(302, $callback['status']);
        $this->assertEquals($originalUrl, $callback['location']);
        $this->assertEquals('deleted', $callback['cookies']['offpost_login_' . explode('.', $state)[0]]);
        $this->assertEquals('deleted', $callback['cookies']['offpost_login_recovery']);
    }

    public function testAuthorizeRecoveryPreservesPageAfterSessionLoss(): void {
        // :: Setup
        $originalUrl = '/thread-view?entityId=test-entity&threadId=test-thread';
        $login = $this->request($originalUrl);
        $cookies = $this->withoutSession($login['cookies']);
        $this->deleteSessions();

        // :: Act
        $recovery = $this->request('/authorize?iss=https%3A%2F%2Fauth.offpost.no%2F', $cookies);
        $cookies = array_merge($cookies, $this->withoutSession($recovery['cookies']));
        $this->deleteSessions();
        $callback = $this->request('/callback?code=test-code&state=' . urlencode($this->state($recovery)), $cookies);

        // :: Assert
        $this->assertEquals(302, $recovery['status']);
        $this->assertEquals(302, $callback['status']);
        $this->assertEquals($originalUrl, $callback['location']);
    }

    public function testConcurrentLoginsKeepTheirOwnDestinations(): void {
        // :: Setup
        $first = $this->request('/thread-view?threadId=first');
        $second = $this->request('/thread-view?threadId=second', $this->withoutSession($first['cookies']));
        $cookies = $this->withoutSession(array_merge($first['cookies'], $second['cookies']));
        $this->deleteSessions();

        // :: Act
        $firstCallback = $this->request('/callback?code=test-code&state=' . urlencode($this->state($first)), $cookies);
        $cookies = array_merge($cookies, $this->withoutSession($firstCallback['cookies']));
        $secondCallback = $this->request('/callback?code=test-code&state=' . urlencode($this->state($second)), $cookies);

        // :: Assert
        $this->assertEquals('/thread-view?threadId=first', $firstCallback['location']);
        $this->assertEquals('/thread-view?threadId=second', $secondCallback['location']);
    }

    public function testCallbackRejectsStateWithoutBrowserCookie(): void {
        // :: Setup
        $login = $this->request('/thread-view?threadId=original');

        // :: Act
        $callback = $this->request('/callback?code=test-code&state=' . urlencode($this->state($login)));

        // :: Assert
        $this->assertEquals(400, $callback['status']);
        $this->assertEquals('Invalid login state', $callback['body']);
    }

    public function testCallbackRejectsChangedDestination(): void {
        // :: Setup
        $login = $this->request('/thread-view?threadId=original');
        $parts = explode('.', $this->state($login));
        $parts[1] = rtrim(strtr(base64_encode('//other.example/'), '+/', '-_'), '=');

        // :: Act
        $callback = $this->request('/callback?code=test-code&state=' . urlencode(implode('.', $parts)), $this->withoutSession($login['cookies']));

        // :: Assert
        $this->assertEquals(400, $callback['status']);
        $this->assertEquals('Invalid login state', $callback['body']);
    }

    public function testCallbackRejectsMissingState(): void {
        // :: Setup

        // :: Act
        $callback = $this->request('/callback?code=test-code');

        // :: Assert
        $this->assertEquals(400, $callback['status']);
        $this->assertEquals('Invalid login state', $callback['body']);
    }

    public function testCallbackStateCannotBeReused(): void {
        // :: Setup
        $login = $this->request('/thread-view?threadId=original');
        $path = '/callback?code=test-code&state=' . urlencode($this->state($login));
        $cookies = $this->withoutSession($login['cookies']);
        $first = $this->request($path, $cookies);
        $cookies = array_merge($cookies, $this->withoutSession($first['cookies']));

        // :: Act
        $second = $this->request($path, $cookies);

        // :: Assert
        $this->assertEquals(302, $first['status']);
        $this->assertEquals(400, $second['status']);
        $this->assertEquals('Invalid login state', $second['body']);
    }

    public function testAuthorizeWithoutPendingLoginReturnsHomeAfterLogin(): void {
        // :: Setup
        $login = $this->request('/authorize');

        // :: Act
        $callback = $this->request('/callback?code=test-code&state=' . urlencode($this->state($login)), $this->withoutSession($login['cookies']));

        // :: Assert
        $this->assertEquals(302, $callback['status']);
        $this->assertEquals('/', $callback['location']);
    }

    public function testAuthenticatedAuthorizeRecoveryKeepsPendingDestination(): void {
        // :: Setup
        $first = $this->request('/thread-view?threadId=first');
        $this->request('/callback?code=test-code&state=' . urlencode($this->state($first)), $first['cookies']);
        $pending = $this->request('/thread-view?threadId=pending');
        $cookies = array_merge($pending['cookies'], ['PHPSESSID' => $first['cookies']['PHPSESSID']]);

        // :: Act
        $recovery = $this->request('/authorize', $cookies);

        // :: Assert
        $this->assertEquals(302, $recovery['status']);
        $this->assertEquals('/thread-view?threadId=pending', $recovery['location']);
    }

    public function testLoginCookiesAreHttpOnlyAndSurviveServerSessionExpiry(): void {
        // :: Setup

        // :: Act
        $login = $this->request('/thread-view?threadId=original');

        // :: Assert
        $cookies = array_values(array_filter($login['headers'], fn($header) => str_starts_with($header, 'Set-Cookie: offpost_login_')));
        $this->assertCount(2, $cookies, json_encode($cookies, JSON_PRETTY_PRINT));
        foreach ($cookies as $cookie) {
            $this->assertStringContainsString('; path=/; HttpOnly; SameSite=Lax', $cookie);
            $this->assertStringNotContainsString('expires=', $cookie);
        }
    }

    public function testOnlyLocalDestinationsAreAccepted(): void {
        // :: Setup
        $destinations = [
            '/', '/thread-view?label=two%20words',
            'https://other.example/', '//other.example/', '/%2fother.example/',
            '/\\other.example/', '/%5cother.example/', "/\r\nLocation:https://other.example/",
            '/%0d%0aLocation:other', 'relative-path', '',
        ];
        $code = 'require ' . var_export(__DIR__ . '/../auth.php', true) . ';'
            . 'echo json_encode(array_map("isLocalLoginRedirect", ' . var_export($destinations, true) . '));';

        // :: Act
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exitCode);

        // :: Assert
        $this->assertEquals(0, $exitCode, implode("\n", $output));
        $this->assertEquals([true, true, false, false, false, false, false, false, false, false, false], json_decode(implode("\n", $output), true));
    }

    private function deleteSessions(): void {
        foreach (glob($this->sessionDir . '/*') as $file) {
            unlink($file);
        }
    }

    private function withoutSession(array $cookies): array {
        unset($cookies['PHPSESSID']);
        return $cookies;
    }

    private function state(array $response): string {
        $this->assertEquals(302, $response['status'], $response['body']);
        parse_str(parse_url($response['location'], PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('state', $query);
        return $query['state'];
    }

    private function request(string $path, array $cookies = []): array {
        $cookieHeader = [];
        foreach ($cookies as $name => $value) {
            $cookieHeader[] = $name . '=' . $value;
        }
        $body = file_get_contents($this->baseUrl . $path, false, stream_context_create([
            'http' => [
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => 'Cookie: ' . implode('; ', $cookieHeader),
            ],
        ]));
        $this->assertNotFalse($body);
        $response = ['status' => (int) explode(' ', $http_response_header[0])[1], 'location' => null, 'cookies' => [], 'body' => $body, 'headers' => $http_response_header];
        foreach ($http_response_header as $header) {
            if (str_starts_with($header, 'Location: ')) {
                $response['location'] = substr($header, 10);
            } elseif (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/', $header, $match)) {
                $response['cookies'][$match[1]] = $match[2];
            }
        }
        return $response;
    }
}
