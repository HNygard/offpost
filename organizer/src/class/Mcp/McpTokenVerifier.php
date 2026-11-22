<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

class McpAuthException extends Exception {
}

/**
 * Verifies an OAuth bearer JWT for the MCP endpoint against a JWKS.
 * Only RS256 is accepted; issuer and audience must match exactly.
 */
class McpTokenVerifier {
    private const LEEWAY_SECONDS = 60;
    private string $issuer;
    private string $audience;
    private array $jwks;

    public function __construct(string $issuer, string $audience, array $jwks) {
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->jwks = $jwks;
    }

    /**
     * @return string The token's sub claim
     * @throws McpAuthException on any verification failure
     */
    public function verify(string $jwt): string {
        // Clock leeway for exp/nbf/iat; JWT::$leeway is global, so restore it afterwards.
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $keys = JWK::parseKeySet($this->jwks, 'RS256');
            $claims = JWT::decode($jwt, $keys);
        } catch (Throwable $e) {
            throw new McpAuthException('Invalid token: ' . $e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (($claims->iss ?? null) !== $this->issuer) {
            throw new McpAuthException('Invalid token: wrong issuer');
        }
        if (!isset($claims->exp) || !is_numeric($claims->exp)) {
            throw new McpAuthException('Invalid token: missing exp');
        }
        $aud = $claims->aud ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($this->audience, $audiences, true)) {
            throw new McpAuthException('Invalid token: wrong audience');
        }
        $sub = $claims->sub ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new McpAuthException('Invalid token: missing sub');
        }
        // Machine-to-machine tokens do not represent an Offpost user.
        if (($claims->gty ?? null) === 'client-credentials' || str_ends_with($sub, '@clients')) {
            throw new McpAuthException('Invalid token: machine-to-machine token');
        }
        return $sub;
    }
}
