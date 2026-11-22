<?php

/**
 * Loads and merges JWKS documents from URLs (cached) and local files.
 */
class McpJwksLoader {
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @param string[] $sources each an http(s) URL or a local file path
     * @return array merged JWKS: ['keys' => [...]]
     * @throws McpAuthException if a source cannot be read or is not a JWKS
     */
    public static function load(array $sources): array {
        $keys = [];
        foreach ($sources as $source) {
            $json = preg_match('#^https?://#', $source)
                ? self::fetchCached($source)
                : @file_get_contents($source);
            $decoded = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
                throw new McpAuthException('Could not load JWKS from ' . $source);
            }
            array_push($keys, ...$decoded['keys']);
        }
        return ['keys' => $keys];
    }

    private static function uid(): int {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** Private per-user cache directory path (not created). */
    public static function cacheDir(): string {
        return sys_get_temp_dir() . '/offpost-mcp-jwks-' . self::uid();
    }

    /** Creates the cache dir with mode 0700; null if it cannot be trusted. */
    public static function ensureCacheDir(): ?string {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700);
        }
        if (!is_dir($dir) || is_link($dir) || !self::isTrustedDir($dir)) {
            return null;
        }
        return $dir;
    }

    private static function isTrustedDir(string $dir): bool {
        $stat = @stat($dir);
        return $stat !== false && $stat['uid'] === self::uid() && ($stat['mode'] & 0022) === 0;
    }

    /** True if the file may be read as cache: trusted dir, owned by us, regular file. */
    public static function isTrustedCacheFile(string $dir, string $file): bool {
        if (!self::isTrustedDir($dir) || is_link($file) || !is_file($file)) {
            return false;
        }
        $stat = @stat($file);
        return $stat !== false && $stat['uid'] === self::uid();
    }

    private static function fetchCached(string $url): ?string {
        $dir = self::ensureCacheDir();
        $cacheFile = $dir === null ? null : $dir . '/' . md5($url) . '.json';
        if ($cacheFile !== null && self::isTrustedCacheFile($dir, $cacheFile)
            && time() - filemtime($cacheFile) < self::CACHE_TTL_SECONDS) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false) {
                return $cached;
            }
        }
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            return null;
        }
        if ($cacheFile !== null) {
            $tmp = tempnam($dir, 'tmp');
            if ($tmp !== false) {
                if (file_put_contents($tmp, $json) === strlen($json) && rename($tmp, $cacheFile)) {
                    return $json;
                }
                @unlink($tmp);
            }
        }
        return $json;
    }
}
