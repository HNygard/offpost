<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/OAuthClientRegistry.php';
require_once __DIR__ . '/OAuthCrypto.php';
require_once __DIR__ . '/OAuthError.php';

/**
 * Validation of authorization requests (OAuth 2.1 authorization code + PKCE S256,
 * RFC 8707 resource) and creation of authorization codes after user consent.
 */
class OAuthAuthorizationService {
    public const CODE_TTL_SECONDS = 60;
    public const SCOPES = ['mcp'];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private string $issuer,
        private string $resource,
        private OAuthClientRegistry $registry,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? fn(): int => time();
    }

    /**
     * @param array $query the authorization request parameters (GET query)
     * @return array{client: array, redirect_uri: string, state: ?string, code_challenge: string, resource: string, scope: string}
     * @throws OAuthError for an unknown client or unregistered redirect URI: must NOT be redirected
     * @throws OAuthRedirectError for every other problem: reported to the client by redirect
     */
    public function validate(array $query): array {
        $clientId = $query['client_id'] ?? null;
        $redirectUri = $query['redirect_uri'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new OAuthError('invalid_request', 'Missing or invalid client_id');
        }
        $client = $this->registry->find($clientId);
        if ($client === null) {
            throw new OAuthError('invalid_request', 'Unknown client_id');
        }
        if (!is_string($redirectUri) || !$this->registry->redirectUriMatches($client, $redirectUri)) {
            throw new OAuthError('invalid_request', 'redirect_uri is not registered for this client');
        }

        $state = isset($query['state']) && is_string($query['state']) ? $query['state'] : null;
        $fail = fn(string $error, string $description): OAuthRedirectError
            => new OAuthRedirectError($error, $description, $redirectUri, $state);

        if (($query['response_type'] ?? null) !== 'code') {
            throw $fail('unsupported_response_type', 'response_type must be "code"');
        }
        $challenge = $query['code_challenge'] ?? null;
        if (!is_string($challenge) || !OAuthCrypto::isValidCodeVerifier($challenge)) {
            throw $fail('invalid_request', 'code_challenge is required (PKCE)');
        }
        if (($query['code_challenge_method'] ?? null) !== 'S256') {
            throw $fail('invalid_request', 'code_challenge_method must be S256');
        }
        $requestedResource = $query['resource'] ?? $this->resource;
        if ($requestedResource !== $this->resource) {
            throw $fail('invalid_target', 'resource must be ' . $this->resource);
        }
        $scope = $query['scope'] ?? implode(' ', self::SCOPES);
        if (!is_string($scope) || array_diff(preg_split('/ +/', trim($scope), -1, PREG_SPLIT_NO_EMPTY), self::SCOPES) !== []) {
            throw $fail('invalid_scope', 'Only the scope "mcp" is supported');
        }

        return [
            'client' => $client,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'resource' => $this->resource,
            'scope' => implode(' ', self::SCOPES),
        ];
    }

    /** Appends $params to the redirect URI (using ? or & as fitting), always including iss (RFC 9207). */
    public function redirectUrl(string $redirectUri, array $params): string {
        $params['iss'] = $this->issuer;
        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Creates a single-use authorization code valid for 60 s and returns the plain code. */
    public function createCode(array $validated, string $userId): string {
        $code = OAuthCrypto::randomToken(32);
        $now = ($this->clock)();
        Database::execute(
            'INSERT INTO oauth_authorization_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, resource, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, to_timestamp(?), to_timestamp(?))',
            [
                OAuthCrypto::hash($code),
                $validated['client']['client_id'],
                $userId,
                $validated['redirect_uri'],
                $validated['code_challenge'],
                $validated['resource'],
                $now,
                $now + self::CODE_TTL_SECONDS,
            ]
        );
        return $code;
    }
}
