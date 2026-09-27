<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a single item within a thread's cumulative state (`ThreadState`).
 * See docs/thread-state.md.
 */
enum ThreadStateItemStatus: string
{
    case NOT_ANSWERED = 'NOT_ANSWERED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case BEING_EVALUATED = 'BEING_EVALUATED';
    case WILL_RELEASE = 'WILL_RELEASE';
    case WILL_RELEASE_PARTLY = 'WILL_RELEASE_PARTLY';
    case PARTLY_RELEASED = 'PARTLY_RELEASED';
    case RELEASED = 'RELEASED';
    case ANSWERED_IN_TEXT = 'ANSWERED_IN_TEXT';
    case DENIED = 'DENIED';
    case NO_DOCUMENTS = 'NO_DOCUMENTS';
    case WITHDRAWN = 'WITHDRAWN';

    // Nothing more will change about this item on its own - the request is
    // done for it, short of a complaint reopening it.
    public function isFinal(): bool
    {
        return match ($this) {
            self::PARTLY_RELEASED,
            self::RELEASED,
            self::ANSWERED_IN_TEXT,
            self::DENIED,
            self::NO_DOCUMENTS,
            self::WITHDRAWN => true,

            self::NOT_ANSWERED,
            self::ACKNOWLEDGED,
            self::BEING_EVALUATED,
            self::WILL_RELEASE,
            self::WILL_RELEASE_PARTLY => false,
        };
    }

    // Used by ThreadStateTypeDeriver's rules 5-6: refused/fulfilled together
    // decide DENIED vs. PARTLY_DENIED_PARTLY_RELEASED. A lone PARTLY_RELEASED
    // item is both.
    public function isRefused(): bool
    {
        return $this === self::DENIED || $this === self::PARTLY_RELEASED;
    }

    // A document was actually delivered. ANSWERED_IN_TEXT is deliberately
    // excluded here - see isFulfilled() for the broader "counts as released
    // for the thread status" check used by the deriver.
    public function isReleased(): bool
    {
        return $this === self::RELEASED || $this === self::PARTLY_RELEASED;
    }

    // Used by ThreadStateTypeDeriver's rules 5-7: whether this item counts as
    // "released" for the thread status, even though ANSWERED_IN_TEXT never
    // delivered a document - the email body itself was the response.
    public function isFulfilled(): bool
    {
        return $this->isReleased() || $this === self::ANSWERED_IN_TEXT;
    }

    // Helper to get all values for e.g. validation
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
