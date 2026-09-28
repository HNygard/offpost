<?php
// organizer/src/class/ThreadState/ThreadState.php

require_once __DIR__ . '/../Enums/ThreadStateItemStatus.php';

use App\Enums\ThreadStateItemStatus;

/**
 * The cumulative thread state recorded on an email
 * (`thread_emails.thread_state`), schema_version 1. See docs/thread-state.md
 * for the model this validates.
 *
 * fromArray() validates the blob and throws InvalidArgumentException with a
 * precise message on the first problem found. toArray() returns the exact
 * validated array back, so a valid blob round-trips unchanged.
 */
class ThreadState {
    const SCHEMA_VERSION = 1;

    const WAITING_FOR_VALUES = ['ENTITY', 'US', 'NOBODY'];
    const DENIAL_ISSUE_VALUES = ['NO_REASON_GIVEN', 'NO_LEGAL_REFERENCE', 'INCOMPLETE_REFERENCE', 'NOT_MACHINE_READABLE'];
    const COMPLAINT_STATUS_VALUES = ['SENT', 'FORWARDED', 'DECIDED', 'OMBUD_SENT', 'OMBUD_DECIDED'];
    // A round is open when its status is one of these (docs/thread-state.md).
    const COMPLAINT_OPEN_STATUSES = ['SENT', 'FORWARDED', 'OMBUD_SENT'];

    const CORE_KEYS = [
        'schema_version', 'request', 'items', 'waiting_for', 'asks_to_us',
        'case_numbers', 'dates', 'complaints', 'notes', 'extra',
    ];

    private array $data;

    private function __construct(array $data) {
        $this->data = $data;
    }

    public static function fromArray(array $data): self {
        foreach (self::CORE_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException("ThreadState: missing required key '$key'");
            }
        }

        if ($data['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(
                "ThreadState: 'schema_version' must be " . self::SCHEMA_VERSION . ', got ' . json_encode($data['schema_version'])
            );
        }

        self::validateRequest($data['request']);
        self::validateItems($data['items']);
        self::validateWaitingFor($data['waiting_for']);
        self::validateStringList($data['asks_to_us'], 'asks_to_us');
        self::validateStringList($data['case_numbers'], 'case_numbers');
        self::validateDates($data['dates']);
        self::validateComplaints($data['complaints']);

        if (!is_string($data['notes'])) {
            throw new InvalidArgumentException("ThreadState: 'notes' must be a string");
        }
        if (!is_array($data['extra'])) {
            throw new InvalidArgumentException("ThreadState: 'extra' must be an object");
        }

        return new self($data);
    }

    public function toArray(): array {
        return $this->data;
    }

    public function getWaitingFor(): string {
        return $this->data['waiting_for'];
    }

    /**
     * @return array<int, array{id: string, asked_for: string, status: string,
     *   denial_basis: ?array, released_in_email_ids: string[], note: string}>
     */
    public function getItems(): array {
        return $this->data['items'];
    }

    /**
     * @return array<int, array{status: string, item_ids: string[],
     *   sent_email_id: ?string, decision_email_id: ?string, outcome: string}>
     */
    public function getComplaints(): array {
        return $this->data['complaints'];
    }

    private static function validateRequest($request): void {
        if (!is_array($request)) {
            throw new InvalidArgumentException("ThreadState: 'request' must be an object");
        }
        foreach (['summary', 'law_basis', 'sent_at'] as $key) {
            if (!array_key_exists($key, $request)) {
                throw new InvalidArgumentException("ThreadState: 'request' is missing key '$key'");
            }
        }
        if (!is_string($request['summary'])) {
            throw new InvalidArgumentException("ThreadState: 'request.summary' must be a string");
        }
        if (!is_string($request['law_basis'])) {
            throw new InvalidArgumentException("ThreadState: 'request.law_basis' must be a string");
        }
        if ($request['sent_at'] !== null && !is_string($request['sent_at'])) {
            throw new InvalidArgumentException("ThreadState: 'request.sent_at' must be a string or null");
        }
    }

    private static function validateItems($items): void {
        if (!is_array($items) || $items === []) {
            throw new InvalidArgumentException("ThreadState: 'items' must be a non-empty list");
        }

        $seenIds = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException("ThreadState: item at index $index must be an object");
            }
            foreach (['id', 'asked_for', 'status', 'denial_basis', 'released_in_email_ids', 'note'] as $key) {
                if (!array_key_exists($key, $item)) {
                    throw new InvalidArgumentException("ThreadState: item at index $index is missing key '$key'");
                }
            }
            if (!is_string($item['id']) || $item['id'] === '') {
                throw new InvalidArgumentException("ThreadState: item at index $index has an invalid 'id'");
            }
            if (isset($seenIds[$item['id']])) {
                throw new InvalidArgumentException("ThreadState: duplicate item id '{$item['id']}'");
            }
            $seenIds[$item['id']] = true;

            if (!is_string($item['asked_for'])) {
                throw new InvalidArgumentException("ThreadState: item '{$item['id']}' has an invalid 'asked_for'");
            }
            if (!is_string($item['status']) || ThreadStateItemStatus::tryFrom($item['status']) === null) {
                throw new InvalidArgumentException(
                    "ThreadState: item '{$item['id']}' has an invalid 'status': " . json_encode($item['status'])
                );
            }
            self::validateDenialBasis($item['denial_basis'], $item['id']);
            self::validateStringList($item['released_in_email_ids'], "item '{$item['id']}'.released_in_email_ids");
            if (!is_string($item['note'])) {
                throw new InvalidArgumentException("ThreadState: item '{$item['id']}' has an invalid 'note'");
            }
        }
    }

    private static function validateDenialBasis($denialBasis, string $itemId): void {
        if ($denialBasis === null) {
            // Not required: an entity may refuse without giving any basis.
            return;
        }
        if (!is_array($denialBasis)) {
            throw new InvalidArgumentException("ThreadState: item '$itemId' has an invalid 'denial_basis'");
        }
        foreach (['refs', 'text', 'issues'] as $key) {
            if (!array_key_exists($key, $denialBasis)) {
                throw new InvalidArgumentException("ThreadState: item '$itemId' denial_basis is missing key '$key'");
            }
        }
        self::validateStringList($denialBasis['refs'], "item '$itemId'.denial_basis.refs");
        if (!is_string($denialBasis['text'])) {
            throw new InvalidArgumentException("ThreadState: item '$itemId' denial_basis.text must be a string");
        }
        if (!is_array($denialBasis['issues'])) {
            throw new InvalidArgumentException("ThreadState: item '$itemId' denial_basis.issues must be a list");
        }
        foreach ($denialBasis['issues'] as $issue) {
            if (!is_string($issue) || !in_array($issue, self::DENIAL_ISSUE_VALUES, true)) {
                throw new InvalidArgumentException(
                    "ThreadState: item '$itemId' denial_basis.issues has an invalid value: " . json_encode($issue)
                );
            }
        }
    }

    private static function validateWaitingFor($waitingFor): void {
        if (!is_string($waitingFor) || !in_array($waitingFor, self::WAITING_FOR_VALUES, true)) {
            throw new InvalidArgumentException(
                "ThreadState: 'waiting_for' has an invalid value: " . json_encode($waitingFor)
            );
        }
    }

    private static function validateDates($dates): void {
        if (!is_array($dates)) {
            throw new InvalidArgumentException("ThreadState: 'dates' must be a list");
        }
        foreach ($dates as $index => $date) {
            if (!is_array($date)) {
                throw new InvalidArgumentException("ThreadState: date at index $index must be an object");
            }
            foreach (['date', 'what', 'email_id'] as $key) {
                if (!array_key_exists($key, $date)) {
                    throw new InvalidArgumentException("ThreadState: date at index $index is missing key '$key'");
                }
            }
            if (!is_string($date['date'])) {
                throw new InvalidArgumentException("ThreadState: date at index $index has an invalid 'date'");
            }
            if (!is_string($date['what'])) {
                throw new InvalidArgumentException("ThreadState: date at index $index has an invalid 'what'");
            }
            if ($date['email_id'] !== null && !is_string($date['email_id'])) {
                throw new InvalidArgumentException("ThreadState: date at index $index has an invalid 'email_id'");
            }
        }
    }

    private static function validateComplaints($complaints): void {
        if (!is_array($complaints)) {
            throw new InvalidArgumentException("ThreadState: 'complaints' must be a list");
        }
        foreach ($complaints as $index => $round) {
            if (!is_array($round)) {
                throw new InvalidArgumentException("ThreadState: complaint round at index $index must be an object");
            }
            foreach (['status', 'item_ids', 'sent_email_id', 'decision_email_id', 'outcome'] as $key) {
                if (!array_key_exists($key, $round)) {
                    throw new InvalidArgumentException("ThreadState: complaint round at index $index is missing key '$key'");
                }
            }
            if (!is_string($round['status']) || !in_array($round['status'], self::COMPLAINT_STATUS_VALUES, true)) {
                throw new InvalidArgumentException(
                    "ThreadState: complaint round at index $index has an invalid 'status': " . json_encode($round['status'])
                );
            }
            self::validateStringList($round['item_ids'], "complaint round at index $index.item_ids");
            if ($round['sent_email_id'] !== null && !is_string($round['sent_email_id'])) {
                throw new InvalidArgumentException("ThreadState: complaint round at index $index has an invalid 'sent_email_id'");
            }
            if ($round['decision_email_id'] !== null && !is_string($round['decision_email_id'])) {
                throw new InvalidArgumentException("ThreadState: complaint round at index $index has an invalid 'decision_email_id'");
            }
            if (!is_string($round['outcome'])) {
                throw new InvalidArgumentException("ThreadState: complaint round at index $index has an invalid 'outcome'");
            }
        }
    }

    private static function validateStringList($value, string $label): void {
        if (!is_array($value)) {
            throw new InvalidArgumentException("ThreadState: '$label' must be a list");
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException("ThreadState: '$label' must contain only strings");
            }
        }
    }
}
