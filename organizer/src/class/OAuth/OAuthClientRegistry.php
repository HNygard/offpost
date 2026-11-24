<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/OAuthCrypto.php';
require_once __DIR__ . '/OAuthError.php';

/**
 * Dynamic client registration (RFC 7591) and client authentication for the
 * OAuth server. Redirect URIs are restricted to https on an allowlist of
 * hosts, or http on loopback (RFC 8252), so a registered client can never
 * send an authorization code to an arbitrary site.
 */
class OAuthClientRegistry {
    public const MAX_REGISTRATIONS_PER_IP_PER_HOUR = 30;
    public const MAX_REGISTRATIONS_PER_HOUR = 200;
    private const MAX_REDIRECT_URIS = 10;
    private const MAX_REDIRECT_URI_LENGTH = 2000;
    private const MAX_CLIENT_NAME_LENGTH = 100;
    private const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @var callable(): int */
    private $clock;

    /**
     * @param string[] $redirectHostAllowlist exact (lowercase) hosts allowed for https redirect URIs
     * @param callable(): int|null $clock epoch seconds, defaults to time()
     */
    public function __construct(private array $redirectHostAllowlist, ?callable $clock = null) {
        $this->clock = $clock ?? fn(): int => time();
    }

    /**
     * Registers a client from RFC 7591 metadata and returns the RFC 7591 response.
     *
     * @throws OAuthError invalid_redirect_uri / invalid_client_metadata (400), too_many_requests (429)
     */
    public function register(array $metadata, string $clientIp): array {
        $now = ($this->clock)();
        $this->enforceRateLimit($clientIp, $now);
        // Every attempt counts, rejected ones too: they cost the same to receive.
        Database::execute(
            'INSERT INTO oauth_registration_attempts (ip, created_at) VALUES (?, to_timestamp(?))',
            [$clientIp, $now]
        );

        $redirectUris = $this->validatedRedirectUris($metadata);
        $clientName = $this->validatedClientName($metadata);
        $method = $this->validatedAuthMethod($metadata);
        $this->validateGrantTypes($metadata);
        $this->validateResponseTypes($metadata);
        $this->validateScope($metadata);

        $clientId = OAuthCrypto::randomToken(16);
        $secret = $method === 'none' ? null : OAuthCrypto::randomToken(32);
        Database::execute(
            'INSERT INTO oauth_clients (client_id, client_secret_hash, client_name, redirect_uris, token_endpoint_auth_method, registration_ip, created_at)
             VALUES (?, ?, ?, ?::jsonb, ?, ?, to_timestamp(?))',
            [
                $clientId,
                $secret === null ? null : OAuthCrypto::hash($secret),
                $clientName,
                json_encode($redirectUris, JSON_UNESCAPED_SLASHES),
                $method,
                $clientIp,
                $now,
            ]
        );

        $response = ['client_id' => $clientId];
        if ($secret !== null) {
            $response['client_secret'] = $secret;
        }
        $response['client_id_issued_at'] = $now;
        $response['client_secret_expires_at'] = 0;
        if ($clientName !== null) {
            $response['client_name'] = $clientName;
        }
        $response['redirect_uris'] = $redirectUris;
        $response['token_endpoint_auth_method'] = $method;
        $response['grant_types'] = ['authorization_code'];
        $response['response_types'] = ['code'];
        return $response;
    }

    /** The client row with redirect_uris decoded, or null. */
    public function find(string $clientId): ?array {
        $row = Database::queryOneOrNone('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            return null;
        }
        $row['redirect_uris'] = json_decode($row['redirect_uris'], true);
        return $row;
    }

    /**
     * Exact string match against a registered URI. For loopback http URIs the
     * port is ignored (RFC 8252 section 7.3), everything else must be equal.
     */
    public function redirectUriMatches(array $client, string $uri): bool {
        if (in_array($uri, $client['redirect_uris'], true)) {
            return true;
        }
        $key = $this->loopbackKey($uri);
        if ($key === null) {
            return false;
        }
        foreach ($client['redirect_uris'] as $registered) {
            if ($this->loopbackKey($registered) === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Authenticates the client at the token/revocation endpoint with the method it registered.
     * client_secret_basic: PHP_AUTH_USER/PW or an Authorization: Basic header (form-urlencoded
     * credentials, RFC 6749 section 2.3.1). client_secret_post: client_id/client_secret in the
     * body. none: client_id only, and no secret may be sent.
     *
     * @throws OAuthError invalid_client (401)
     */
    public function authenticate(array $post, array $server): array {
        $basic = $this->basicCredentials($server);
        $bodyId = $post['client_id'] ?? null;
        $bodySecret = $post['client_secret'] ?? null;
        if ($bodyId !== null && !is_string($bodyId) || $bodySecret !== null && !is_string($bodySecret)) {
            throw $this->invalidClient();
        }

        $clientId = $basic !== null ? $basic[0] : $bodyId;
        $client = $clientId === null || $clientId === '' ? null : $this->find($clientId);
        if ($client === null) {
            throw $this->invalidClient();
        }

        switch ($client['token_endpoint_auth_method']) {
            case 'client_secret_basic':
                $presented = $basic === null || $bodySecret !== null ? null : $basic[1];
                break;
            case 'client_secret_post':
                $presented = $basic === null && $bodyId !== null ? $bodySecret : null;
                break;
            default: // none
                if ($basic !== null || $bodySecret !== null) {
                    throw $this->invalidClient();
                }
                return $client;
        }
        if ($presented === null || $client['client_secret_hash'] === null
            || !hash_equals($client['client_secret_hash'], OAuthCrypto::hash($presented))) {
            throw $this->invalidClient();
        }
        return $client;
    }

    private function invalidClient(): OAuthError {
        return new OAuthError('invalid_client', 'Client authentication failed', 401);
    }

    /** @return array{0: string, 1: string}|null [client_id, client_secret] */
    private function basicCredentials(array $server): ?array {
        if (isset($server['PHP_AUTH_USER']) && is_string($server['PHP_AUTH_USER'])) {
            return [$server['PHP_AUTH_USER'], (string) ($server['PHP_AUTH_PW'] ?? '')];
        }
        $header = $server['HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($header) || stripos($header, 'Basic ') !== 0) {
            return null;
        }
        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            // A malformed Basic header is a failed attempt, not "no credentials".
            return ['', ''];
        }
        [$id, $secret] = explode(':', $decoded, 2);
        return [urldecode($id), urldecode($secret)];
    }

    private function enforceRateLimit(string $clientIp, int $now): void {
        // Bounded on both sides so the count is a function of the clock only.
        $window = [$now - 3600, $now];
        $perIp = (int) Database::queryValue(
            'SELECT count(*) FROM oauth_registration_attempts WHERE ip = ? AND created_at > to_timestamp(?) AND created_at <= to_timestamp(?)',
            [$clientIp, ...$window]
        );
        $total = (int) Database::queryValue(
            'SELECT count(*) FROM oauth_registration_attempts WHERE created_at > to_timestamp(?) AND created_at <= to_timestamp(?)',
            $window
        );
        if ($perIp >= self::MAX_REGISTRATIONS_PER_IP_PER_HOUR || $total >= self::MAX_REGISTRATIONS_PER_HOUR) {
            throw new OAuthError('too_many_requests', 'Too many registrations, try again later', 429);
        }
    }

    /** @return string[] */
    private function validatedRedirectUris(array $metadata): array {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris) || !array_is_list($uris) || count($uris) < 1 || count($uris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthError('invalid_client_metadata', 'redirect_uris must be a list of 1 to ' . self::MAX_REDIRECT_URIS . ' URIs');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || $this->parseRedirectUri($uri) === null) {
                throw new OAuthError('invalid_redirect_uri', 'Redirect URI not allowed: only https on a supported host or http on loopback, without fragment or userinfo');
            }
        }
        return $uris;
    }

    /**
     * parse_url parts of a redirect URI (scheme and host lowercased) when it is
     * acceptable for registration, otherwise null.
     */
    private function parseRedirectUri(string $uri): ?array {
        // No whitespace/control chars or backslashes (browsers treat "\" as "/"), no fragment.
        if ($uri === '' || strlen($uri) > self::MAX_REDIRECT_URI_LENGTH
            || preg_match('/[\x00-\x20\x7f\\\\#]/', $uri) === 1) {
            return null;
        }
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'https') {
            // No port on an allowlisted host: that port is not necessarily served by the same party.
            if (isset($parts['port']) || !in_array($host, $this->redirectHostAllowlist, true)) {
                return null;
            }
        } elseif ($scheme !== 'http' || !in_array($host, self::LOOPBACK_HOSTS, true)) {
            return null;
        }
        $parts['scheme'] = $scheme;
        $parts['host'] = $host;
        return $parts;
    }

    /** Comparison key (everything except the port) for a registrable loopback http URI, else null. */
    private function loopbackKey(string $uri): ?string {
        $parts = $this->parseRedirectUri($uri);
        if ($parts === null || $parts['scheme'] !== 'http') {
            return null;
        }
        return $parts['host'] . '|' . ($parts['path'] ?? '') . '|' . ($parts['query'] ?? '');
    }

    private function validatedClientName(array $metadata): ?string {
        $name = $metadata['client_name'] ?? null;
        if ($name === null || $name === '') {
            return null;
        }
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::MAX_CLIENT_NAME_LENGTH) {
            throw new OAuthError('invalid_client_metadata', 'client_name must be a string of at most ' . self::MAX_CLIENT_NAME_LENGTH . ' characters');
        }
        return $name;
    }

    private function validatedAuthMethod(array $metadata): string {
        $method = $metadata['token_endpoint_auth_method'] ?? 'client_secret_post';
        if (!is_string($method) || !in_array($method, self::AUTH_METHODS, true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be one of: ' . implode(', ', self::AUTH_METHODS));
        }
        return $method;
    }

    /** authorization_code is required; refresh_token is tolerated (and ignored). */
    private function validateGrantTypes(array $metadata): void {
        if (!array_key_exists('grant_types', $metadata)) {
            return;
        }
        $grants = $metadata['grant_types'];
        if (!is_array($grants) || !in_array('authorization_code', $grants, true)
            || !$this->onlyContains($grants, ['authorization_code', 'refresh_token'])) {
            throw new OAuthError('invalid_client_metadata', 'grant_types must include authorization_code and may only add refresh_token');
        }
    }

    /** True when every element is (strictly) one of $allowed; tolerates non-string elements. */
    private function onlyContains(array $values, array $allowed): bool {
        foreach ($values as $value) {
            if (!in_array($value, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    private function validateResponseTypes(array $metadata): void {
        if (!array_key_exists('response_types', $metadata)) {
            return;
        }
        if (!is_array($metadata['response_types']) || !$this->onlyContains($metadata['response_types'], ['code'])) {
            throw new OAuthError('invalid_client_metadata', 'response_types may only be ["code"]');
        }
    }

    private function validateScope(array $metadata): void {
        if (!array_key_exists('scope', $metadata)) {
            return;
        }
        $scope = $metadata['scope'];
        if (!is_string($scope) || array_diff(preg_split('/ +/', trim($scope), -1, PREG_SPLIT_NO_EMPTY), ['mcp']) !== []) {
            throw new OAuthError('invalid_client_metadata', 'scope may only be "mcp"');
        }
    }
}
