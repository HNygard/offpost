<?php

declare(strict_types=1);

namespace App\Enums;

enum ThreadEmailStatusType: string
{
    case OUR_REQUEST = 'OUR_REQUEST';
    case ASKING_FOR_MORE_TIME = 'ASKING_FOR_MORE_TIME';
    case ASKING_FOR_COPY = 'ASKING_FOR_COPY';
    case COPY_SENT = 'COPY_SENT';
    case REQUEST_REJECTED = 'REQUEST_REJECTED';
    case INFORMATION_RELEASE = 'INFORMATION_RELEASE';

    // Existing values - to be reviewed/phased out if possible,
    // but included for now to avoid immediate breaks.
    // The task states "Old values will not be migrated",
    // implying new code should not use them, but they might be in the DB.
    case INFO = 'info';
    case ERROR = 'error';
    case SUCCESS = 'success';
    case UNKNOWN = 'unknown';

    // Helper method to get a label for display
    public function label(): string
    {
        return match ($this) {
            self::OUR_REQUEST => 'Our Request',
            self::ASKING_FOR_MORE_TIME => 'Asking for More Time',
            self::ASKING_FOR_COPY => 'Asking for Copy',
            self::COPY_SENT => 'Copy Sent',
            self::REQUEST_REJECTED => 'Request Rejected',
            self::INFORMATION_RELEASE => 'Information Release',
            self::INFO => 'Info',
            self::ERROR => 'Error',
            self::SUCCESS => 'Success',
            self::UNKNOWN => 'Unknown',
        };
    }

    // Helper to get all values for e.g. validation
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
