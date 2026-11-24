<?php

require_once __DIR__ . '/../../../tests/bootstrap.php';

/**
 * Drives Offpost's own OAuth server over HTTP against the dev stack, for the e2e tests:
 * register, log in, consent and token exchange. Failures throw RuntimeException with the response.
 */
class OAuthTestFlow {
    const BASE = 'http://localhost:25081';
    const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    const LOOPBACK_REDIRECT = 'http://127.0.0.1:8123/callback';
    // S256 pair: CHALLENGE = base64url(sha256(VERIFIER)).
    const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /** @var string[] client_ids registered through request(), removed by cleanup() */
    private static array $registeredClientIds = [];

    /**
     * Deletes what this process registered: the clients and, for them, the tokens and codes
     * (the schema has ON DELETE CASCADE from clients to codes and tokens and SET NULL from tokens
     * to codes; deleting explicitly keeps this independent of that). Call from tearDown(); other
     * connections are left alone.
     */
    public static function cleanup(): void {
        foreach (self::$registeredClientIds as $clientId) {
            Database::execute('DELETE FROM oauth_access_tokens WHERE client_id = ?', [$clientId]);
            Database::execute('DELETE FROM oauth_authorization_codes WHERE client_id = ?', [$clientId]);
            Database::execute('DELETE FROM oauth_clients WHERE client_id = ?', [$clientId]);
        }
        self::$registeredClientIds = [];
    }

    /** Registers a public loopback client, runs login + consent and the token exchange; returns the access token. */
    public static function accessTokenFor(string $user = 'dev-user-id'): string {
        return self::connect($user)['access_token'];
    }

    /**
     * Like accessTokenFor, but with a choice of client type and name. A confidential client
     * (client_secret_post) also gets its credentials returned, e.g. to call /oauth/revoke.
     *
     * @return array{access_token: string, client_id: string, client_secret: ?string}
     */
    public static function connect(string $user = 'dev-user-id', bool $confidential = false, string $clientName = 'e2e loopback client'): array {
        $register = self::request('POST', '/oauth/register', json_encode([
            'client_name' => $clientName,
            'redirect_uris' => [self::LOOPBACK_REDIRECT],
            'token_endpoint_auth_method' => $confidential ? 'client_secret_post' : 'none',
        ]), ['Content-Type: application/json']);
        self::expectStatus(201, $register);
        $clientId = $register['json']['client_id'];
        $clientSecret = $confidential ? $register['json']['client_secret'] : null;

        $cookie = self::loginCookie($user);
        [, $pendingId, $csrf] = self::openConsentPage($cookie, $clientId, self::LOOPBACK_REDIRECT);
        $decision = self::postDecision($cookie, ['pending_id' => $pendingId, 'csrf' => $csrf, 'decision' => 'approve']);
        self::expectStatus(302, $decision);
        if (preg_match('/^location: .*[?&]code=([A-Za-z0-9_-]{43})/mi', $decision['raw_headers'], $m) !== 1) {
            throw new RuntimeException('No code in redirect: ' . $decision['raw_headers']);
        }

        $token = self::request('POST', '/oauth/token', http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $m[1],
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ] + ($confidential ? ['client_secret' => $clientSecret] : [])), ['Content-Type: application/x-www-form-urlencoded']);
        self::expectStatus(200, $token);
        return ['access_token' => $token['json']['access_token'], 'client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    /** Returns status, lowercased headers, raw_headers, body and decoded json. */
    public static function request(string $method, string $path, ?string $body = null, array $headers = []): array {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $json = json_decode($body, true);
        if ($path === '/oauth/register' && $status === 201 && isset($json['client_id'])) {
            self::$registeredClientIds[] = $json['client_id'];
        }
        return ['status' => $status, 'headers' => strtolower($rawHeaders), 'raw_headers' => $rawHeaders, 'body' => $body, 'json' => $json];
    }

    /** PHPSESSID cookie of a logged-in dev user, obtained like E2EPageTestCase does. */
    public static function loginCookie(string $user = 'dev-user-id'): string {
        if ($user !== 'dev-user-id') {
            throw new RuntimeException('Unknown user: ' . $user);
        }
        $ch = curl_init(self::BASE . '?test-authenticate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => tempnam(sys_get_temp_dir(), 'cookie'),
        ]);
        $response = curl_exec($ch);
        $rawHeaders = substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        curl_close($ch);
        if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/mi', $rawHeaders, $m) !== 1) {
            throw new RuntimeException('No session cookie: ' . $rawHeaders);
        }
        return $m[1];
    }

    public static function authorizeQuery(string $clientId, array $override = [], string $redirect = self::REDIRECT): string {
        return '/oauth/authorize?' . http_build_query(array_merge([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'state' => 'st-1',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ], $override));
    }

    /** GET the consent page and return [response, pending_id, csrf]. */
    public static function openConsentPage(string $cookie, string $clientId, string $redirect = self::REDIRECT): array {
        $page = self::request('GET', self::authorizeQuery($clientId, [], $redirect), null, ['Cookie: ' . $cookie]);
        self::expectStatus(200, $page);
        if (preg_match('/name="pending_id" value="([0-9a-f]+)"/', $page['body'], $p) !== 1
            || preg_match('/name="csrf" value="([0-9a-f]+)"/', $page['body'], $c) !== 1) {
            throw new RuntimeException('Consent page lacks pending_id/csrf: ' . $page['body']);
        }
        return [$page, $p[1], $c[1]];
    }

    public static function postDecision(string $cookie, array $fields): array {
        return self::request('POST', '/oauth/authorize', http_build_query($fields), [
            'Cookie: ' . $cookie,
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    private static function expectStatus(int $expected, array $response): void {
        if ($response['status'] !== $expected) {
            throw new RuntimeException("Expected HTTP $expected, got {$response['status']}: " . $response['raw_headers'] . $response['body']);
        }
    }
}
