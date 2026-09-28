<?php
// tools/analysis/ThreadEventAnalysis.php
//
// Pure logic for tools/analyze-threads.php (docs/superpowers/plans/2026-09-27-step2-2-thread-analysis.md):
// building the per-event input text sent to claude, building the JSON schema
// from the ThreadState enums, validating the answer, deciding where a resumed
// run should continue, and totalling usage/cost. No I/O, no process spawning,
// so it is cheap and safe to unit test directly.

require_once __DIR__ . '/../../organizer/src/class/ThreadState/ThreadState.php';
require_once __DIR__ . '/../../organizer/src/class/ThreadState/ThreadStateTypeDeriver.php';
require_once __DIR__ . '/../../organizer/src/class/Enums/ThreadStateItemStatus.php';
require_once __DIR__ . '/../../organizer/src/class/Enums/ThreadStateType.php';
require_once __DIR__ . '/../../organizer/src/class/Enums/ThreadEmailStatusType.php';

use App\Enums\ThreadEmailStatusType;

class ThreadEventAnalysis {
    const BODY_MAX_CHARS = 15000;
    const ATTACHMENT_MAX_CHARS = 8000;

    // The per-event `usage` keys the CLI records (see usageOf() in
    // tools/analyze-threads.php). Headless Claude Code reports almost all
    // input as cache tokens (cache_creation/cache_read), not input_tokens,
    // so totals must sum all of these, not just input/output_tokens.
    const TOKEN_KEYS = ['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens', 'thinking_tokens'];

    // Legacy ThreadEmailStatusType values that are not a real email
    // classification and are excluded from what the model may answer with.
    const EXCLUDED_EMAIL_TYPES = ['info', 'error', 'success'];

    /**
     * The email_type values the model may answer with: every
     * ThreadEmailStatusType value except the legacy info/error/success ones
     * (so the current real types, plus 'unknown').
     *
     * @return string[]
     */
    public static function allowedEmailTypes(): array {
        return array_values(array_diff(ThreadEmailStatusType::values(), self::EXCLUDED_EMAIL_TYPES));
    }

    /**
     * Builds the input text for one event: the thread, the previous state (or
     * null), the email, and its attachments' extracted text. Pure formatting
     * - every long field is cut and marked [CUT], per the plan.
     *
     * @param array $thread ['id','title','entity_name','entity_id','initial_request']
     * @param array|null $previousState The previous thread_state blob (array), or null for the first event.
     * @param array $email ['id','direction','datetime_received','from','to','cc','subject','body_plain','body_html']
     * @param array $attachments List of ['filename','filetype','extractions' => [['extracted_text' => ?string, 'error_message' => ?string], ...]]
     */
    public static function buildEventInput(array $thread, ?array $previousState, array $email, array $attachments): string {
        $lines = [];

        $lines[] = '# Thread';
        $lines[] = 'id: ' . self::str($thread['id'] ?? null);
        $lines[] = 'title: ' . self::str($thread['title'] ?? null);
        $lines[] = 'entity: ' . self::str($thread['entity_name'] ?? null) . ' (' . self::str($thread['entity_id'] ?? null) . ')';
        $lines[] = 'initial_request: ' . self::strOrNone($thread['initial_request'] ?? null);
        $lines[] = '';

        $lines[] = '# Previous state';
        $lines[] = $previousState === null
            ? 'null'
            : json_encode($previousState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines[] = '';

        $lines[] = '# Email';
        $lines[] = 'id: ' . self::str($email['id'] ?? null);
        $lines[] = 'direction: ' . self::str($email['direction'] ?? null);
        $lines[] = 'date: ' . self::str($email['datetime_received'] ?? null);
        $lines[] = 'from: ' . self::str($email['from'] ?? null);
        $lines[] = 'to: ' . self::joinAddresses($email['to'] ?? []);
        $lines[] = 'cc: ' . self::joinAddresses($email['cc'] ?? []);
        $lines[] = 'subject: ' . self::str($email['subject'] ?? null);
        $lines[] = '';

        $lines[] = '# Body';
        $lines[] = self::cut(self::bodyText($email), self::BODY_MAX_CHARS);
        $lines[] = '';

        $lines[] = '# Attachments';
        if ($attachments === []) {
            $lines[] = '(none)';
        }
        else {
            foreach ($attachments as $attachment) {
                $lines[] = '## ' . self::str($attachment['filename'] ?? null) . ' (' . self::str($attachment['filetype'] ?? null) . ')';
                $lines[] = self::cut(self::attachmentText($attachment), self::ATTACHMENT_MAX_CHARS);
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private static function str($value): string {
        return $value === null ? '' : (string) $value;
    }

    private static function strOrNone($value): string {
        return ($value === null || $value === '') ? '(none)' : (string) $value;
    }

    private static function joinAddresses($addresses): string {
        if (!is_array($addresses)) {
            return '';
        }
        return implode(', ', array_map('strval', $addresses));
    }

    private static function bodyText(array $email): string {
        $plain = $email['body_plain'] ?? null;
        if (is_string($plain) && trim($plain) !== '') {
            return $plain;
        }
        $html = $email['body_html'] ?? null;
        if (is_string($html) && trim($html) !== '') {
            return self::stripHtml($html);
        }
        return '';
    }

    private static function stripHtml(string $html): string {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * The text to show for one attachment: the first extraction with
     * non-empty extracted_text, or one of four sentinels explaining why
     * there is none (see docs/thread-analysis.md and event-prompt.md's
     * "Judge from what we have", which these strings must match exactly).
     *
     * Order: a non-empty extracted_text always wins. Otherwise, for a PDF:
     * a successful extraction (no error_message) with no text means the PDF
     * has no text layer, and that wins over a failed extraction that may
     * also be present (e.g. a retried attachment: one failed attempt, then
     * a successful one that just found nothing). Only when there is no
     * successful extraction does a failed one's error show; only when there
     * is no extraction at all does "not extracted yet" show. A non-PDF
     * attachment always gets the "cannot read this file type" sentinel,
     * regardless of any extraction rows, since nothing in this codebase
     * extracts text from non-PDF attachments today.
     */
    private static function attachmentText(array $attachment): string {
        $extractions = $attachment['extractions'] ?? [];

        foreach ($extractions as $extraction) {
            $text = $extraction['extracted_text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        if (!self::isPdfAttachment($attachment)) {
            $ext = self::fileExtension($attachment);
            return $ext !== null
                ? "(no text: Offpost cannot read .$ext files yet)"
                : '(no text: Offpost cannot read this file type yet)';
        }

        $failedError = null;
        $hasSuccessfulExtraction = false;
        foreach ($extractions as $extraction) {
            $error = $extraction['error_message'] ?? null;
            if (is_string($error) && trim($error) !== '') {
                if ($failedError === null) {
                    $failedError = $error;
                }
                continue;
            }
            $hasSuccessfulExtraction = true;
        }

        if ($hasSuccessfulExtraction) {
            return '(no text: PDF has no text layer, likely a scan)';
        }
        if ($failedError !== null) {
            return '(no text: text extraction failed: ' . self::truncateErrorMessage($failedError) . ')';
        }
        return '(no text: not extracted yet)';
    }

    /**
     * Whether $attachment is a PDF, by filetype or filename extension.
     * thread_email_attachments.filetype is not reliably a MIME type (see
     * NpApiService::attachmentContentType()): it is usually a bare
     * extension like 'pdf', but some legacy rows hold 'application/pdf'.
     */
    private static function isPdfAttachment(array $attachment): bool {
        return self::fileExtension($attachment) === 'pdf';
    }

    /**
     * The lowercase file extension for $attachment, from its filename, or
     * from filetype as a fallback (itself either a bare extension or a
     * MIME type, e.g. 'application/pdf'). Null when neither gives one.
     */
    private static function fileExtension(array $attachment): ?string {
        $filename = $attachment['filename'] ?? null;
        if (is_string($filename) && $filename !== '') {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext !== '') {
                return strtolower($ext);
            }
        }
        $filetype = $attachment['filetype'] ?? null;
        if (is_string($filetype) && $filetype !== '') {
            if (str_contains($filetype, '/')) {
                $parts = explode('/', $filetype);
                return strtolower(end($parts));
            }
            return strtolower($filetype);
        }
        return null;
    }

    /**
     * $error's first line, trimmed and cut to 200 chars, for the
     * "text extraction failed: <error>" sentinel.
     */
    private static function truncateErrorMessage(string $error): string {
        $lines = preg_split('/\r\n|\r|\n/', trim($error));
        $firstLine = trim($lines[0] ?? '');
        return mb_strlen($firstLine) > 200 ? mb_substr($firstLine, 0, 200) : $firstLine;
    }

    /**
     * Cuts $text to $maxChars, appending a [CUT] marker with the original
     * length when it was cut. The "(no text: ...)" sentinels from
     * attachmentText() are all far shorter than any $maxChars used, so they
     * are never marked as cut.
     */
    private static function cut(string $text, int $maxChars): string {
        $length = mb_strlen($text);
        if ($length <= $maxChars) {
            return $text;
        }
        $cutText = mb_substr($text, 0, $maxChars);
        return $cutText . "\n[CUT, original length: $length chars]";
    }

    /**
     * The JSON schema passed to `claude --json-schema`, built from the
     * ThreadState/ThreadEmailStatusType enums. It is a guide for the model;
     * ThreadState::fromArray() is the real validator (see validateOutput()).
     */
    public static function buildJsonSchema(): array {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['email_type', 'email_note', 'email_type_gap', 'thread_state'],
            'properties' => [
                'email_type' => ['type' => 'string', 'enum' => self::allowedEmailTypes()],
                'email_note' => ['type' => 'string'],
                'email_type_gap' => ['type' => 'string'],
                'thread_state' => self::threadStateSchema(),
            ],
        ];
    }

    private static function threadStateSchema(): array {
        return [
            'type' => 'object',
            'required' => ThreadState::CORE_KEYS,
            'properties' => [
                'schema_version' => ['type' => 'integer', 'const' => ThreadState::SCHEMA_VERSION],
                'request' => [
                    'type' => 'object',
                    'required' => ['summary', 'law_basis', 'sent_at'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'law_basis' => ['type' => 'string'],
                        'sent_at' => ['type' => ['string', 'null']],
                    ],
                ],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'asked_for', 'status', 'denial_basis', 'released_in_email_ids', 'note'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'asked_for' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => \App\Enums\ThreadStateItemStatus::values()],
                            'denial_basis' => [
                                'type' => ['object', 'null'],
                                'required' => ['refs', 'text', 'issues'],
                                'properties' => [
                                    'refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'text' => ['type' => 'string'],
                                    'issues' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ThreadState::DENIAL_ISSUE_VALUES]],
                                ],
                            ],
                            'released_in_email_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'waiting_for' => ['type' => 'string', 'enum' => ThreadState::WAITING_FOR_VALUES],
                'asks_to_us' => ['type' => 'array', 'items' => ['type' => 'string']],
                'case_numbers' => ['type' => 'array', 'items' => ['type' => 'string']],
                'dates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['date', 'what', 'email_id'],
                        'properties' => [
                            'date' => ['type' => 'string'],
                            'what' => ['type' => 'string'],
                            'email_id' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'complaints' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['status', 'item_ids', 'sent_email_id', 'decision_email_id', 'outcome'],
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ThreadState::COMPLAINT_STATUS_VALUES],
                            'item_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'sent_email_id' => ['type' => ['string', 'null']],
                            'decision_email_id' => ['type' => ['string', 'null']],
                            'outcome' => ['type' => 'string'],
                        ],
                    ],
                ],
                'notes' => ['type' => 'string'],
                'extra' => ['type' => 'object'],
            ],
        ];
    }

    /**
     * Validates one answer from the model.
     *
     * @return array{valid: bool, error: ?string, derivedThreadStateType: ?string}
     */
    public static function validateOutput(array $output): array {
        if (!array_key_exists('email_type', $output) || !is_string($output['email_type']) || !in_array($output['email_type'], self::allowedEmailTypes(), true)) {
            return self::invalid("'email_type' must be one of " . json_encode(self::allowedEmailTypes()) . ', got ' . json_encode($output['email_type'] ?? null));
        }
        foreach (['email_note', 'email_type_gap'] as $key) {
            if (!array_key_exists($key, $output) || !is_string($output[$key])) {
                return self::invalid("'$key' must be a string");
            }
        }
        if (!array_key_exists('thread_state', $output) || !is_array($output['thread_state'])) {
            return self::invalid("'thread_state' must be an object");
        }

        try {
            $state = ThreadState::fromArray($output['thread_state']);
        }
        catch (InvalidArgumentException $e) {
            return self::invalid($e->getMessage());
        }

        $derivedType = ThreadStateTypeDeriver::derive($state);
        return ['valid' => true, 'error' => null, 'derivedThreadStateType' => $derivedType->value];
    }

    private static function invalid(string $error): array {
        return ['valid' => false, 'error' => $error, 'derivedThreadStateType' => null];
    }

    /**
     * The thread's events: emails ordered by datetime_received then id, with
     * ignored emails dropped.
     */
    public static function selectEvents(array $emails): array {
        $events = array_values(array_filter($emails, fn(array $email) => empty($email['ignore'])));
        usort($events, function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['datetime_received'] ?? ''), (string) ($b['datetime_received'] ?? ''));
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        return $events;
    }

    /**
     * Where a run should continue for one thread, given the thread's file
     * from a previous run of the same --run (or null, if there is none).
     *
     * @param array|null $existingThreadFile Decoded <out>/<run>/threads/<id>.json, or null.
     * @return array{skip: bool, keptEvents: array, startIndex: int} skip is
     *   true when the thread is already 'done' (nothing to do). keptEvents
     *   are the previously recorded events to keep as-is (in a 'failed'
     *   thread, the failed event is dropped so it is retried). startIndex is
     *   the index into the thread's ordered events to resume from.
     */
    public static function planResume(?array $existingThreadFile): array {
        if ($existingThreadFile === null) {
            return ['skip' => false, 'keptEvents' => [], 'startIndex' => 0];
        }

        $status = $existingThreadFile['status'] ?? null;
        $events = $existingThreadFile['events'] ?? [];

        if ($status === 'done') {
            return ['skip' => true, 'keptEvents' => $events, 'startIndex' => count($events)];
        }

        if ($status === 'failed' && count($events) > 0) {
            $kept = array_slice($events, 0, -1);
            return ['skip' => false, 'keptEvents' => $kept, 'startIndex' => count($kept)];
        }

        // in_progress (or any other/unknown status): continue after the last
        // recorded event.
        return ['skip' => false, 'keptEvents' => $events, 'startIndex' => count($events)];
    }

    /**
     * Totals across a thread's recorded events, for its "totals" block and
     * for the run-wide totals. Sums every key in TOKEN_KEYS (not just
     * input/output_tokens), plus 'total_input_tokens' (input +
     * cache_creation + cache_read) right after them - the figure humans
     * care about, since headless Claude Code reports almost all input as
     * cache tokens rather than input_tokens.
     *
     * @return array{events: int, cost_usd: float, input_tokens: int, cache_creation_input_tokens: int, cache_read_input_tokens: int, output_tokens: int, thinking_tokens: int, total_input_tokens: int}
     */
    public static function computeTotals(array $events): array {
        $totals = ['events' => 0, 'cost_usd' => 0.0];
        foreach (self::TOKEN_KEYS as $key) {
            $totals[$key] = 0;
        }
        foreach ($events as $event) {
            $totals['events']++;
            $totals['cost_usd'] += (float) ($event['cost_usd'] ?? 0.0);
            foreach (self::TOKEN_KEYS as $key) {
                $totals[$key] += (int) ($event['usage'][$key] ?? 0);
            }
        }
        $totals['total_input_tokens'] = $totals['input_tokens'] + $totals['cache_creation_input_tokens'] + $totals['cache_read_input_tokens'];
        return $totals;
    }
}
