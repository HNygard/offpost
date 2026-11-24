<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/OAuthCrypto.php';
require_once __DIR__ . '/OAuthError.php';

/**
 * Authorization code redemption and the store of opaque access tokens.
 * Tokens are only stored as hashes. A token stays valid while its row exists,
 * its resource matches and it was used within the last 180 days (sliding window).
 */
class OAuthTokenService {
    public const TOKEN_PREFIX = 'opmcp_';
    public const IDLE_SECONDS = 180 * 86400;
    public const TOUCH_INTERVAL_SECONDS = 3600;

    /** @var callable(): int */
    private $clock;

    /** @param callable(): int|null $clock epoch seconds, defaults to time() */
    public function __construct(private string $resource, ?callable $clock = null) {
        $this->clock = $clock ?? fn(): int => time();
    }

    /**
     * Redeems an authorization code for an access token. The code is single use: it is
     * burned by the first attempt, also when that attempt fails. Presenting a used code
     * again revokes the tokens issued from it.
     *
     * @param array $client the authenticated client row (from OAuthClientRegistry::authenticate)
     * @return array the RFC 6749 section 5.1 token response body
     * @throws OAuthError unsupported_grant_type, invalid_request, invalid_grant, invalid_target
     */
    public function redeemCode(array $client, array $post): array {
        if (($post['grant_type'] ?? null) !== 'authorization_code') {
            throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code');
        }
        foreach (['code', 'redirect_uri', 'code_verifier'] as $name) {
            if (!isset($post[$name]) || !is_string($post[$name]) || $post[$name] === '') {
                throw new OAuthError('invalid_request', "Missing or invalid $name");
            }
        }
        if (isset($post['resource']) && !is_string($post['resource'])) {
            throw new OAuthError('invalid_request', 'Invalid resource');
        }

        $now = ($this->clock)();
        // The transaction keeps the burned code row locked until the token exists, so a concurrent
        // redemption of the same code blocks, then finds the code used and revokes that token.
        // Callers that already hold a transaction (tests) provide the atomicity themselves.
        $ownTransaction = !Database::getInstance()->inTransaction();
        if ($ownTransaction) {
            Database::beginTransaction();
        }
        try {
            $token = $this->redeemInTransaction($client, $post, $now);
        } catch (OAuthError $e) {
            // Commit, not roll back: a failed redemption must leave the code burned.
            if ($ownTransaction) {
                Database::commit();
            }
            throw $e;
        } catch (Throwable $e) {
            if ($ownTransaction) {
                Database::rollBack();
            }
            throw $e;
        }
        if ($ownTransaction) {
            Database::commit();
        }

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'scope' => 'mcp',
        ];
    }

    private function redeemInTransaction(array $client, array $post, int $now): string {
        $codeHash = OAuthCrypto::hash($post['code']);
        // The guard is the UPDATE itself: of two concurrent redemptions only one gets the row.
        $code = Database::queryOneOrNone(
            'UPDATE oauth_authorization_codes SET used_at = to_timestamp(?)
             WHERE code_hash = ? AND used_at IS NULL AND expires_at > to_timestamp(?) RETURNING *',
            [$now, $codeHash, $now]
        );
        if ($code === null) {
            $existing = Database::queryOneOrNone('SELECT id, used_at FROM oauth_authorization_codes WHERE code_hash = ?', [$codeHash]);
            if ($existing !== null && $existing['used_at'] !== null) {
                Database::execute('DELETE FROM oauth_access_tokens WHERE code_id = ?', [$existing['id']]);
            }
            throw new OAuthError('invalid_grant', 'Invalid, expired or already used authorization code');
        }

        $matches = hash_equals($code['client_id'], $client['client_id'])
            && hash_equals($code['redirect_uri'], $post['redirect_uri'])
            && OAuthCrypto::isValidCodeVerifier($post['code_verifier'])
            && OAuthCrypto::pkceS256Matches($post['code_verifier'], $code['code_challenge'])
            && $code['resource'] === $this->resource;
        if (!$matches) {
            throw new OAuthError('invalid_grant', 'Authorization code does not match this request');
        }
        // RFC 8707 section 2.2. The code stays burned.
        if (($post['resource'] ?? $code['resource']) !== $code['resource']) {
            throw new OAuthError('invalid_target', 'resource does not match the authorization request');
        }

        $token = self::TOKEN_PREFIX . OAuthCrypto::randomToken(32);
        Database::execute(
            'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, resource, code_id, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, to_timestamp(?), to_timestamp(?))',
            [OAuthCrypto::hash($token), $code['client_id'], $code['user_id'], $code['resource'], $code['id'], $now, $now]
        );
        $this->markClientActive($code['client_id'], $now);
        return $token;
    }

    /**
     * The user id the token belongs to, or null when it is unknown, for another resource
     * or idle for more than 180 days. Refreshes last_used_at when it is over an hour old.
     */
    public function userIdForAccessToken(string $token): ?string {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }
        $now = ($this->clock)();
        $row = Database::queryOneOrNone(
            'SELECT id, client_id, user_id, last_used_at < to_timestamp(?) AS stale FROM oauth_access_tokens
             WHERE token_hash = ? AND resource = ? AND last_used_at > to_timestamp(?)',
            [$now - self::TOUCH_INTERVAL_SECONDS, OAuthCrypto::hash($token), $this->resource, $now - self::IDLE_SECONDS]
        );
        if ($row === null) {
            return null;
        }
        if ($row['stale']) {
            Database::execute('UPDATE oauth_access_tokens SET last_used_at = to_timestamp(?) WHERE id = ?', [$now, $row['id']]);
            $this->markClientActive($row['client_id'], $now);
        }
        return $row['user_id'];
    }

    /** Deletes the token when it belongs to $client; unknown or foreign tokens are ignored (RFC 7009). */
    public function revoke(array $client, string $token): void {
        $this->deleteTokens('token_hash = ? AND client_id = ?', [OAuthCrypto::hash($token), $client['client_id']], ($this->clock)());
    }

    private function markClientActive(string $clientId, int $now): void {
        Database::execute('UPDATE oauth_clients SET last_active_at = to_timestamp(?) WHERE client_id = ?', [$now, $clientId]);
    }

    /**
     * Deletes the tokens matching $where and marks their clients active at $now in the same
     * statement: the client cleanup rules count from the last activity, and a revoked or
     * idle-expired token is activity too. Returns the number of deleted tokens.
     */
    private function deleteTokens(string $where, array $params, int $now): int {
        return (int) Database::queryValue(
            "WITH deleted AS (DELETE FROM oauth_access_tokens WHERE $where RETURNING client_id),
                  marked AS (UPDATE oauth_clients SET last_active_at = to_timestamp(?)
                             WHERE client_id IN (SELECT client_id FROM deleted))
             SELECT count(*) FROM deleted",
            [...$params, $now]
        );
    }

    /** @return array[] rows {id, client_name, created_at, last_used_at}, newest first */
    public function listForUser(string $userId): array {
        return Database::query(
            'SELECT t.id, c.client_name, t.created_at, t.last_used_at
             FROM oauth_access_tokens t JOIN oauth_clients c ON c.client_id = t.client_id
             WHERE t.user_id = ? AND t.resource = ? ORDER BY t.created_at DESC, t.id DESC',
            [$userId, $this->resource]
        );
    }

    /** Deletes the token when it belongs to $userId; true if one was deleted. */
    public function revokeForUser(string $userId, int $tokenId): bool {
        return $this->deleteTokens('id = ? AND user_id = ?', [$tokenId, $userId], ($this->clock)()) > 0;
    }

    /**
     * Housekeeping. A client is kept for a year after its last activity (token issued, used,
     * revoked or idle-expired; oauth_clients.last_active_at), so claude.ai / Claude Code can
     * reconnect with the cached client_id long after a connection ended.
     * - unused_clients: never active (no token ever issued), no token, registered over 24 h ago.
     * - stale_clients: no token, last active over 365 days ago.
     * Codes: unused ones expire after a day, used ones are purged after 30 days; they no longer
     * affect clients.
     *
     * @return array{tokens: int, unused_clients: int, stale_clients: int, codes: int, registration_attempts: int}
     */
    public function cleanup(): array {
        $now = ($this->clock)();
        $day = 86400;
        $noTokens = 'NOT EXISTS (SELECT 1 FROM oauth_access_tokens t WHERE t.client_id = oauth_clients.client_id)';
        // Tokens first: it marks their clients active before the client rules run.
        $tokens = $this->deleteTokens('last_used_at <= to_timestamp(?)', [$now - self::IDLE_SECONDS], $now);
        return [
            'tokens' => $tokens,
            'unused_clients' => Database::execute(
                "DELETE FROM oauth_clients WHERE last_active_at IS NULL AND $noTokens AND created_at < to_timestamp(?)",
                [$now - $day]
            ),
            'stale_clients' => Database::execute(
                "DELETE FROM oauth_clients WHERE $noTokens AND last_active_at < to_timestamp(?)",
                [$now - 365 * $day]
            ),
            'codes' => Database::execute(
                'DELETE FROM oauth_authorization_codes WHERE (used_at IS NULL AND expires_at < to_timestamp(?)) OR used_at < to_timestamp(?)',
                [$now - $day, $now - 30 * $day]
            ),
            'registration_attempts' => Database::execute('DELETE FROM oauth_registration_attempts WHERE created_at < to_timestamp(?)', [$now - $day]),
        ];
    }
}
