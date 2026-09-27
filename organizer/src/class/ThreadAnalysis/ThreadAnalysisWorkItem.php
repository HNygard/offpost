<?php
// organizer/src/class/ThreadAnalysis/ThreadAnalysisWorkItem.php
// Pure logic for organizer/src/api/admin/analysis_claim.php - see
// docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md, "Change 2:
// the endpoints". No database access: this works entirely on the 'emails'
// array from ThreadExportService::exportThread().

class ThreadAnalysisWorkItem {
    /**
     * Chooses which of a thread's emails a worker should analyse for a run.
     *
     * Emails with `ignore = true` are left out. The rest are ordered by
     * `datetime_received`, then `id`, before either mode is applied:
     *
     * - `full`: every remaining email, `start_state` and
     *   `start_after_email_id` both null.
     * - `incremental`: finds the last email (in that order) whose
     *   `thread_state` is not null. `start_state` is that state and
     *   `start_after_email_id` its id; `email_ids` are the emails after it.
     *   With no such email, behaves like `full`. With nothing after it,
     *   `email_ids` is empty.
     *
     * @param array $exportEmails The 'emails' array from
     *   ThreadExportService::exportThread() - each element has at least
     *   'id', 'datetime_received', 'ignore' and 'thread_state'.
     * @param string $mode 'full' or 'incremental'.
     * @return array{start_state: ?array, start_after_email_id: ?string, email_ids: array<int, string>}
     */
    public static function selectEmails(array $exportEmails, string $mode): array {
        $emails = array_values(array_filter($exportEmails, fn (array $email): bool => !$email['ignore']));

        usort($emails, function (array $a, array $b): int {
            $cmp = strcmp((string) $a['datetime_received'], (string) $b['datetime_received']);
            return $cmp !== 0 ? $cmp : strcmp((string) $a['id'], (string) $b['id']);
        });

        if ($mode === 'full') {
            return [
                'start_state' => null,
                'start_after_email_id' => null,
                'email_ids' => array_map(fn (array $email): string => $email['id'], $emails),
            ];
        }

        $lastWithStateIndex = null;
        foreach ($emails as $index => $email) {
            if ($email['thread_state'] !== null) {
                $lastWithStateIndex = $index;
            }
        }

        if ($lastWithStateIndex === null) {
            return [
                'start_state' => null,
                'start_after_email_id' => null,
                'email_ids' => array_map(fn (array $email): string => $email['id'], $emails),
            ];
        }

        $startEmail = $emails[$lastWithStateIndex];
        $rest = array_slice($emails, $lastWithStateIndex + 1);

        return [
            'start_state' => $startEmail['thread_state'],
            'start_after_email_id' => $startEmail['id'],
            'email_ids' => array_map(fn (array $email): string => $email['id'], $rest),
        ];
    }
}
