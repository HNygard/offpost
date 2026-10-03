<?php
// organizer/src/class/ThreadState/ThreadStateView.php

require_once __DIR__ . '/../Enums/ThreadStateType.php';
require_once __DIR__ . '/../Enums/ThreadStateItemStatus.php';
require_once __DIR__ . '/../Database.php';

use App\Enums\ThreadStateItemStatus;
use App\Enums\ThreadStateType;

/**
 * Renders the "Thread state" block and the per-email state badge shown in
 * view-thread.php, from a thread's cumulative ThreadState blob
 * (docs/thread-state.md). See "Shown in the thread view" in that doc.
 *
 * The rendering methods (renderBlock, renderEmailBadge) are pure: they take
 * plain arrays and return HTML, so they can be unit tested without a
 * database. All output is escaped with htmlspecialchars().
 *
 * loadEmailStates() is the one method that touches the database: it reads
 * thread_state/thread_state_type/thread_state_source directly, because
 * Thread::mapFromDatabase() does not copy those columns onto ThreadEmail.
 */
class ThreadStateView {
    // Bokmål labels for `waiting_for` (see docs/thread-state.md).
    const WAITING_FOR_LABELS = [
        'ENTITY' => 'Venter på offentlig organ',
        'US' => 'Venter på oss',
        'NOBODY' => 'Ingen venter',
    ];

    // Bokmål labels for a denial's `issues` - these are complaint grounds.
    const DENIAL_ISSUE_LABELS = [
        'NO_REASON_GIVEN' => 'Ingen grunn oppgitt',
        'NO_LEGAL_REFERENCE' => 'Ingen lovhenvisning',
        'INCOMPLETE_REFERENCE' => 'Mangelfull henvisning',
        'NOT_MACHINE_READABLE' => 'Ikke maskinlesbart',
    ];

    // Bokmål labels for a complaint round's `status`.
    const COMPLAINT_STATUS_LABELS = [
        'SENT' => 'Klage sendt',
        'FORWARDED' => 'Klage videresendt',
        'DECIDED' => 'Klage avgjort',
        'OMBUD_SENT' => 'Sendt til Sivilombudet',
        'OMBUD_DECIDED' => 'Avgjort av Sivilombudet',
    ];

    // Maps each review status (thread_analysis_runs.review_status, step 2c
    // "Change 9") to one of the badge styles in webroot/css/style.css
    // (span.label.label_*).
    const REVIEW_STATUS_LABEL_CLASS = [
        'NOT_REVIEWED' => 'label_pending',
        'CORRECT' => 'label_ok',
        'MINOR_ISSUES' => 'label_warn',
        'WRONG' => 'label_error',
    ];

    // Maps each ThreadStateType to one of the four badge styles in
    // webroot/css/style.css (span.label.classification.label_*).
    const STATUS_TYPE_LABEL_CLASS = [
        'COMPLAINT_SENT' => 'label_warn',
        'COMPLAINT_FORWARDED' => 'label_warn',
        'OMBUD_COMPLAINT_SENT' => 'label_warn',
        'WAITING_FOR_US' => 'label_warn',
        'CLOSED' => 'label_info',
        'NO_DOCUMENTS' => 'label_info',
        'DENIED' => 'label_error',
        'PARTLY_DENIED_PARTLY_RELEASED' => 'label_warn',
        'ANSWERED' => 'label_ok',
        'PARTLY_ANSWERED' => 'label_warn',
        'WAITING_FOR_ENTITY' => 'label_info',
    ];

    /**
     * All emails of the thread that carry a thread_state, oldest first (by
     * datetime_received, then id) - so the last entry is the thread's
     * current state, per docs/thread-state.md.
     *
     * @return array<int, array{id: string, thread_state: array, thread_state_type: ?string, thread_state_source: ?string}>
     */
    public static function loadEmailStates(string $threadId): array {
        $rows = Database::query(
            "SELECT id, thread_state, thread_state_type, thread_state_source
             FROM thread_emails
             WHERE thread_id = ? AND thread_state IS NOT NULL
             ORDER BY datetime_received, id",
            [$threadId]
        );

        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'thread_state' => json_decode($row['thread_state'], true),
                'thread_state_type' => $row['thread_state_type'],
                'thread_state_source' => $row['thread_state_source'],
            ];
        }, $rows);
    }

    /**
     * The "Thread state" block, shown just before "Emails in Thread". Shown
     * only when the caller has a state to render - the caller decides that
     * from loadEmailStates() being non-empty.
     *
     * @param array $state a validated ThreadState blob (ThreadState::toArray())
     * @param string|null $stateType the derived ThreadStateType value
     * @param string|null $stateSource 'auto' or 'manual'
     * @param string|null $latestRunReviewStatus the thread's latest analysis
     *   run's review_status (step 2c "Change 9"), shown next to the
     *   "Analysis details" link for admins only; null when there is no run
     *   to show a review status for.
     * @param string|null $today 'Y-m-d' used to count days to a complaint
     *   deadline; null means the current date. Tests pass a fixed date.
     */
    public static function renderBlock(
        array $state,
        ?string $stateType,
        ?string $stateSource,
        bool $isAdmin,
        string $threadId,
        ?string $latestRunReviewStatus = null,
        ?string $today = null
    ): string {
        $html = '<div class="thread-state">';
        $html .= '<h2>Thread state</h2>';

        $html .= '<p class="thread-state-status">' . self::renderStatusBadge($stateType);
        if ($stateSource !== null) {
            $html .= ' <span class="thread-state-source">(' . self::e($stateSource) . ')</span>';
        }
        $html .= '</p>';

        // Complaint deadlines get a callout of their own, right under the
        // status, instead of a line in the dates list further down.
        $complaintDeadlines = array_values(array_filter($state['dates'], [self::class, 'isComplaintDeadline']));
        $otherDates = array_values(array_filter($state['dates'], fn($date) => !self::isComplaintDeadline($date)));
        foreach ($complaintDeadlines as $deadline) {
            $html .= self::renderComplaintDeadline($deadline, !empty($state['complaints']), $today ?? date('Y-m-d'));
        }

        $html .= '<p class="thread-state-waiting-for"><strong>Waiting for:</strong> '
            . self::e(self::WAITING_FOR_LABELS[$state['waiting_for']] ?? $state['waiting_for'])
            . '</p>';

        if (!empty($state['asks_to_us'])) {
            $html .= '<p class="thread-state-asks"><strong>Asks of us:</strong> '
                . self::e(implode(', ', $state['asks_to_us'])) . '</p>';
        }

        $html .= self::renderItemsTable($state['items']);

        if (!empty($state['case_numbers'])) {
            $html .= '<p class="thread-state-case-numbers"><strong>Case numbers:</strong> '
                . self::e(implode(', ', $state['case_numbers'])) . '</p>';
        }

        if (!empty($otherDates)) {
            $html .= self::renderDatesList($otherDates);
        }

        if (!empty($state['complaints'])) {
            $html .= self::renderComplaintsTable($state['complaints']);
        }

        if (!empty($state['notes'])) {
            $html .= '<p class="thread-state-notes"><strong>Notes:</strong> ' . self::e($state['notes']) . '</p>';
        }

        if ($isAdmin) {
            $html .= '<p class="thread-state-admin-link"><a href="/thread-analysis/thread?id='
                . self::e($threadId) . '">Analysis details</a>';
            if ($latestRunReviewStatus !== null) {
                $html .= ' ' . self::renderReviewStatusBadge($latestRunReviewStatus);
            }
            $html .= '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * The small per-email badge and "Show state" dialog trigger in
     * .email-header, shown when the email has a thread_state. Opens in the
     * shared ContentDialog (webroot/js/contentDialog.js) via a hidden
     * <template>, unique per email id.
     */
    public static function renderEmailBadge(array $state, ?string $stateType, string $emailId): string {
        $templateId = 'thread-state-' . self::e($emailId);

        $html = '<span class="thread-state-badge">' . self::renderStatusBadge($stateType, ' (after this email)') . '</span>';
        $html .= ' <a href="#" class="content-dialog-link" data-dialog-title="Thread state after this email"'
            . ' data-dialog-template="' . $templateId . '">Show state</a>';
        $html .= '<template id="' . $templateId . '"><pre>'
            . self::e(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></template>';

        return $html;
    }

    /**
     * The small badge next to "Analysis details" showing the thread's latest
     * analysis run's review status - step 2c "Change 9".
     */
    private static function renderReviewStatusBadge(string $reviewStatus): string {
        $cssClass = self::REVIEW_STATUS_LABEL_CLASS[$reviewStatus] ?? 'label_pending';
        return '<span class="label ' . $cssClass . '" title="review status">' . self::e($reviewStatus) . '</span>';
    }

    // Public so system-pages/thread-analysis-thread.php can show the same
    // badge for an analysis event's derived_thread_state_type.
    public static function renderStatusBadge(?string $stateType, string $suffix = ''): string {
        if ($stateType === null) {
            return '<span class="label classification" title="">Unknown</span>' . self::e($suffix);
        }

        $case = ThreadStateType::tryFrom($stateType);
        $label = $case !== null ? $case->label() : $stateType;
        $cssClass = self::STATUS_TYPE_LABEL_CLASS[$stateType] ?? 'label_info';

        return '<span class="label classification ' . $cssClass . '" title="' . self::e($stateType) . '">'
            . self::e($label . $suffix) . '</span>';
    }

    private static function renderItemsTable(array $items): string {
        $html = '<table class="thread-state-items">';
        $html .= '<thead><tr><th>Asked for</th><th>Status</th><th>Denial basis</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($items as $item) {
            $html .= '<tr>';
            $html .= '<td>' . self::e($item['asked_for']) . '</td>';
            $html .= '<td>' . self::renderItemStatus($item['status']) . '</td>';
            $html .= '<td>' . self::renderDenialBasis($item['denial_basis']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private static function renderItemStatus(string $status): string {
        $case = ThreadStateItemStatus::tryFrom($status);
        $label = $case !== null ? $case->label() : $status;

        return '<span title="' . self::e($status) . '">' . self::e($label) . '</span>';
    }

    private static function renderDenialBasis(?array $denialBasis): string {
        if ($denialBasis === null) {
            return '';
        }

        $parts = [];
        if (!empty($denialBasis['refs'])) {
            $parts[] = '<span class="denial-refs">' . self::e(implode(', ', $denialBasis['refs'])) . '</span>';
        }
        if (!empty($denialBasis['text'])) {
            $parts[] = '<span class="denial-text">' . self::e($denialBasis['text']) . '</span>';
        }
        foreach ($denialBasis['issues'] ?? [] as $issue) {
            $label = self::DENIAL_ISSUE_LABELS[$issue] ?? $issue;
            $parts[] = '<span class="label classification label_warn" title="' . self::e($issue) . '">'
                . self::e($label) . '</span>';
        }

        return implode(' ', $parts);
    }

    /**
     * `dates` has no type field, so a complaint deadline is recognised by
     * "klagefrist" in its free-text `what`.
     */
    private static function isComplaintDeadline(array $date): bool {
        return mb_stripos($date['what'], 'klagefrist') !== false;
    }

    /**
     * Prominent box for one complaint deadline: the date, how many days are
     * left (or how long ago it ran out), and the `what` text. Once a complaint
     * has been sent the countdown no longer matters, so it is shown muted.
     */
    private static function renderComplaintDeadline(array $deadline, bool $complaintSent, string $today): string {
        $deadlineDate = DateTimeImmutable::createFromFormat('!Y-m-d', $deadline['date']);
        $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today);

        $modifier = 'upcoming';
        $countdown = '';
        if ($complaintSent) {
            $modifier = 'done';
            $countdown = 'klage sendt';
        }
        elseif ($deadlineDate !== false && $todayDate !== false) {
            $days = (int)$todayDate->diff($deadlineDate)->format('%r%a');
            if ($days < 0) {
                $modifier = 'overdue';
                $countdown = 'utløpt for ' . (-$days) . ($days === -1 ? ' dag' : ' dager') . ' siden';
            }
            elseif ($days === 0) {
                $modifier = 'soon';
                $countdown = 'utløper i dag';
            }
            else {
                $modifier = $days <= 7 ? 'soon' : 'upcoming';
                $countdown = $days . ($days === 1 ? ' dag' : ' dager') . ' igjen';
            }
        }

        $html = '<div class="thread-state-complaint-deadline thread-state-complaint-deadline-' . $modifier . '">';
        $html .= '<strong>Klagefrist: ' . self::e($deadline['date']) . '</strong>';
        if ($countdown !== '') {
            $html .= ' <span class="thread-state-complaint-deadline-countdown">(' . self::e($countdown) . ')</span>';
        }
        $html .= '<div class="thread-state-complaint-deadline-what">' . self::e($deadline['what']) . '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function renderDatesList(array $dates): string {
        $html = '<ul class="thread-state-dates">';
        foreach ($dates as $date) {
            $html .= '<li>' . self::e($date['date']) . ': ' . self::e($date['what']) . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private static function renderComplaintsTable(array $complaints): string {
        $html = '<table class="thread-state-complaints">';
        $html .= '<thead><tr><th>Status</th><th>Items</th><th>Outcome</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($complaints as $round) {
            $label = self::COMPLAINT_STATUS_LABELS[$round['status']] ?? $round['status'];
            $html .= '<tr>';
            $html .= '<td><span title="' . self::e($round['status']) . '">' . self::e($label) . '</span></td>';
            $html .= '<td>' . self::e(implode(', ', $round['item_ids'])) . '</td>';
            $html .= '<td>' . self::e($round['outcome']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private static function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
