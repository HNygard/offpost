<?php

/**
 * The git commit the running code was deployed from.
 *
 * infrastructure/production/deploy-cronjob.sh builds the organizer image with
 * the GIT_SHA build arg, and the Dockerfile writes it to git-sha.txt next to
 * the code (gitignored, excluded from the build context). The .git directory is
 * not in the image, so this file is the only way the app knows its version.
 * Without the file (e.g. in development) there is no version to show.
 */
class GitVersion {
    public const GITHUB_COMMIT_URL = 'https://github.com/hnygard/offpost/commit/';

    public static function defaultFile(): string {
        return __DIR__ . '/../git-sha.txt';
    }

    /**
     * Full SHA from the file, or null if the file is missing or does not hold a SHA.
     */
    public static function getSha(?string $file = null): ?string {
        $file = $file ?? self::defaultFile();
        if (!is_readable($file)) {
            return null;
        }
        $sha = strtolower(trim((string) file_get_contents($file)));
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return null;
        }
        return $sha;
    }

    public static function shortSha(string $sha): string {
        return substr($sha, 0, 7);
    }

    public static function commitUrl(string $sha): string {
        return self::GITHUB_COMMIT_URL . $sha;
    }
}
