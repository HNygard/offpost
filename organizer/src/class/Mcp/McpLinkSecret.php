<?php

/**
 * Loads the HMAC secret that signs attachment download links.
 */
class McpLinkSecret {
    public const PRODUCTION_FILE = '/run/secrets/mcp_link_secret';
    private const MIN_LENGTH = 32;

    /**
     * Production: the secret file must exist and hold at least 32 characters.
     * Development/PHPUnit ($allowGenerated): when the file is missing, a random
     * secret is generated once into the temp dir (links die with the container).
     *
     * @throws RuntimeException when the secret is missing (production) or too short
     */
    public static function load(string $file, bool $allowGenerated, ?string $tempDir = null): string {
        $secret = null;
        if (file_exists($file)) {
            if (!is_file($file)) {
                throw new RuntimeException("MCP link secret is not a regular file: $file");
            }
            $content = is_readable($file) ? @file_get_contents($file) : false;
            if ($content === false) {
                throw new RuntimeException("MCP link secret file is unreadable: $file");
            }
            $secret = trim($content);
        }
        if ($secret === null && $allowGenerated) {
            return self::generated($tempDir ?? sys_get_temp_dir());
        }
        if ($secret === null) {
            throw new RuntimeException("MCP link secret file is missing: $file");
        }
        if (strlen($secret) < self::MIN_LENGTH) {
            throw new RuntimeException('MCP link secret must be at least ' . self::MIN_LENGTH . ' characters');
        }
        return $secret;
    }

    private static function generated(string $tempDir): string {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $path = $tempDir . '/offpost-mcp-link-secret-' . $uid;
        $existing = is_readable($path) && !is_link($path) ? trim((string)file_get_contents($path)) : '';
        if (strlen($existing) >= self::MIN_LENGTH) {
            return $existing;
        }
        $secret = bin2hex(random_bytes(32));
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $secret) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write the development MCP link secret to $path");
        }
        return $secret;
    }
}
