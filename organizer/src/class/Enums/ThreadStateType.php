<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The thread's status, derived by `ThreadStateTypeDeriver` from its
 * `ThreadState` blob. See docs/thread-state.md.
 */
enum ThreadStateType: string
{
    case COMPLAINT_SENT = 'COMPLAINT_SENT';
    case COMPLAINT_FORWARDED = 'COMPLAINT_FORWARDED';
    case OMBUD_COMPLAINT_SENT = 'OMBUD_COMPLAINT_SENT';
    case WAITING_FOR_US = 'WAITING_FOR_US';
    case CLOSED = 'CLOSED';
    case NO_DOCUMENTS = 'NO_DOCUMENTS';
    case DENIED = 'DENIED';
    case PARTLY_DENIED_PARTLY_RELEASED = 'PARTLY_DENIED_PARTLY_RELEASED';
    case ANSWERED = 'ANSWERED';
    case PARTLY_ANSWERED = 'PARTLY_ANSWERED';
    case WAITING_FOR_ENTITY = 'WAITING_FOR_ENTITY';

    // Helper to get all values for e.g. validation
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    // Short, natural Bokmål label shown in the thread view (ThreadStateView).
    // The enum value itself is shown alongside it, in a `title` attribute.
    public function label(): string
    {
        return match ($this) {
            self::COMPLAINT_SENT => 'Klage sendt',
            self::COMPLAINT_FORWARDED => 'Klage videresendt',
            self::OMBUD_COMPLAINT_SENT => 'Klage sendt til Sivilombudet',
            self::WAITING_FOR_US => 'Venter på oss',
            self::CLOSED => 'Avsluttet',
            self::NO_DOCUMENTS => 'Ingen dokumenter',
            self::DENIED => 'Avslått',
            self::PARTLY_DENIED_PARTLY_RELEASED => 'Delvis avslått, delvis utlevert',
            self::ANSWERED => 'Besvart',
            self::PARTLY_ANSWERED => 'Delvis besvart',
            self::WAITING_FOR_ENTITY => 'Venter på offentlig organ',
        };
    }
}
