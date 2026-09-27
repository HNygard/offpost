<?php
// organizer/src/class/ThreadExportSync.php
//
// Pure decision logic for the thread-export CLI (tools/pull-thread-export.php):
// given what the server's list endpoint returned and what is already on
// disk, decide which thread ids need to be (re)fetched. No I/O, no DB - so it
// is safe and cheap to unit test directly.

class ThreadExportSync {
    /**
     * @param array $listedThreads Threads as returned by
     *   GET /api/admin/export/threads, in list order. Each entry needs at
     *   least ['id' => string, 'fingerprint' => string].
     * @param array $localIndex Local index.json content: id => fingerprint
     *   for threads already downloaded.
     * @param bool $full When true, every listed thread is fetched regardless
     *   of fingerprint.
     * @param int|null $limit Cap on the number of ids to fetch. Candidates
     *   are kept in list order; null/0 means no cap.
     * @return array{toFetch: string[], unchanged: int} toFetch is the capped
     *   list of ids to fetch. unchanged is the count of listed threads whose
     *   fingerprint already matches locally (i.e. skipped for that reason,
     *   not because of the limit).
     */
    public static function planFetch(array $listedThreads, array $localIndex, bool $full, ?int $limit): array {
        $candidates = [];
        $unchanged = 0;

        foreach ($listedThreads as $thread) {
            $id = $thread['id'];
            $fingerprint = $thread['fingerprint'];

            if (!$full && array_key_exists($id, $localIndex) && $localIndex[$id] === $fingerprint) {
                $unchanged++;
                continue;
            }

            $candidates[] = $id;
        }

        if ($limit !== null && $limit > 0) {
            $toFetch = array_slice($candidates, 0, $limit);
        }
        else {
            $toFetch = $candidates;
        }

        return ['toFetch' => $toFetch, 'unchanged' => $unchanged];
    }

    /**
     * Builds the CLI's final summary line, e.g. "fetched 3, unchanged 5, failed 1".
     */
    public static function summaryLine(int $fetched, int $unchanged, int $failed): string {
        return "fetched $fetched, unchanged $unchanged, failed $failed";
    }

    /**
     * May the CLI send the admin token to this base URL? Only over https, or
     * plain http to the local dev stack. The token reads every thread.
     */
    public static function isSafeBaseUrl(string $baseUrl): bool {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);
    }
}
