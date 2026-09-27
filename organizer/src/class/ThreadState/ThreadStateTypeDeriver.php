<?php
// organizer/src/class/ThreadState/ThreadStateTypeDeriver.php

require_once __DIR__ . '/ThreadState.php';
require_once __DIR__ . '/../Enums/ThreadStateItemStatus.php';
require_once __DIR__ . '/../Enums/ThreadStateType.php';

use App\Enums\ThreadStateItemStatus;
use App\Enums\ThreadStateType;

/**
 * Pure derivation of the thread's status from its cumulative ThreadState
 * blob. No database access, so it is tested directly. Rules are checked in
 * order, first match wins - see docs/thread-state.md.
 */
class ThreadStateTypeDeriver {
    public static function derive(ThreadState $state): ThreadStateType {
        // Rule 1: the latest complaint round is open.
        $openRoundStatus = self::openLatestComplaintRoundStatus($state);
        if ($openRoundStatus !== null) {
            return match ($openRoundStatus) {
                'SENT' => ThreadStateType::COMPLAINT_SENT,
                'FORWARDED' => ThreadStateType::COMPLAINT_FORWARDED,
                'OMBUD_SENT' => ThreadStateType::OMBUD_COMPLAINT_SENT,
            };
        }

        // Rule 2: waiting_for = US.
        if ($state->getWaitingFor() === 'US') {
            return ThreadStateType::WAITING_FOR_US;
        }

        $rawItems = $state->getItems();
        $statuses = array_map(
            fn(array $item): ThreadStateItemStatus => ThreadStateItemStatus::from($item['status']),
            $rawItems
        );

        if (self::allFinal($statuses)) {
            // Rule 3: every item final, and all WITHDRAWN.
            if (self::allWithdrawn($statuses)) {
                return ThreadStateType::CLOSED;
            }
            // Rule 4: every item final, and all non-withdrawn items NO_DOCUMENTS.
            if (self::allNonWithdrawnAreNoDocuments($statuses)) {
                return ThreadStateType::NO_DOCUMENTS;
            }

            $anyRefused = self::anyMatch($statuses, fn(ThreadStateItemStatus $s) => $s->isRefused());
            $anyReleased = self::anyMatch($statuses, fn(ThreadStateItemStatus $s) => $s->isReleased());

            // Rule 5: every item final, at least one refused, none released.
            if ($anyRefused && !$anyReleased) {
                return ThreadStateType::DENIED;
            }
            // Rule 6: every item final, at least one refused, and at least one released.
            if ($anyRefused && $anyReleased) {
                return ThreadStateType::PARTLY_DENIED_PARTLY_RELEASED;
            }
            // Rule 7: every item final, otherwise.
            return ThreadStateType::ANSWERED;
        }

        // Rule 8: at least one item final or with a non-empty
        // released_in_email_ids, and at least one not final (guaranteed here,
        // since allFinal() above was false).
        foreach ($rawItems as $index => $item) {
            if ($statuses[$index]->isFinal() || !empty($item['released_in_email_ids'])) {
                return ThreadStateType::PARTLY_ANSWERED;
            }
        }

        // Rule 9: otherwise.
        return ThreadStateType::WAITING_FOR_ENTITY;
    }

    /**
     * The latest complaint round's status, when that round is open; null
     * otherwise (including when there are no complaints at all).
     */
    private static function openLatestComplaintRoundStatus(ThreadState $state): ?string {
        $complaints = $state->getComplaints();
        if ($complaints === []) {
            return null;
        }
        $latest = $complaints[count($complaints) - 1];
        return in_array($latest['status'], ThreadState::COMPLAINT_OPEN_STATUSES, true) ? $latest['status'] : null;
    }

    /** @param ThreadStateItemStatus[] $statuses */
    private static function allFinal(array $statuses): bool {
        foreach ($statuses as $status) {
            if (!$status->isFinal()) {
                return false;
            }
        }
        return true;
    }

    /** @param ThreadStateItemStatus[] $statuses */
    private static function allWithdrawn(array $statuses): bool {
        foreach ($statuses as $status) {
            if ($status !== ThreadStateItemStatus::WITHDRAWN) {
                return false;
            }
        }
        return true;
    }

    /** @param ThreadStateItemStatus[] $statuses */
    private static function allNonWithdrawnAreNoDocuments(array $statuses): bool {
        $sawNonWithdrawn = false;
        foreach ($statuses as $status) {
            if ($status === ThreadStateItemStatus::WITHDRAWN) {
                continue;
            }
            $sawNonWithdrawn = true;
            if ($status !== ThreadStateItemStatus::NO_DOCUMENTS) {
                return false;
            }
        }
        return $sawNonWithdrawn;
    }

    /**
     * @param ThreadStateItemStatus[] $statuses
     * @param callable(ThreadStateItemStatus): bool $predicate
     */
    private static function anyMatch(array $statuses, callable $predicate): bool {
        foreach ($statuses as $status) {
            if ($predicate($status)) {
                return true;
            }
        }
        return false;
    }
}
