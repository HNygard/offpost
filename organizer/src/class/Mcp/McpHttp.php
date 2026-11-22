<?php

/**
 * Small HTTP helpers for the MCP endpoint (api/mcp/mcp.php).
 */
class McpHttp {
    /**
     * Extracts the bearer token from the Authorization header.
     * Sources, in order: $server['HTTP_AUTHORIZATION'], $server['REDIRECT_HTTP_AUTHORIZATION'],
     * then a case-insensitive "Authorization" entry in $headers (e.g. getallheaders()).
     * The first source that is present is used; the scheme must be Bearer (case-insensitive).
     */
    public static function bearerToken(array $server, array $headers): ?string {
        $value = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($value === null) {
            foreach ($headers as $name => $headerValue) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $value = $headerValue;
                    break;
                }
            }
        }
        if (!is_string($value) || !preg_match('/^Bearer\s+(\S+)\s*$/i', $value, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * @param string[] $scopes Scopes the client should request (MCP scope selection reads this
     *   first, then the metadata's scopes_supported). Empty: no scope parameter.
     */
    public static function wwwAuthenticate(string $metadataUrl, bool $invalidToken, array $scopes = []): string {
        $value = 'Bearer resource_metadata="' . $metadataUrl . '"';
        if (count($scopes) > 0) {
            $value .= ', scope="' . implode(' ', $scopes) . '"';
        }
        if ($invalidToken) {
            $value .= ', error="invalid_token"';
        }
        return $value;
    }
}
