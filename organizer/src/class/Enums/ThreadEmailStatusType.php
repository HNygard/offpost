<?php

declare(strict_types=1);

namespace App\Enums;

enum ThreadEmailStatusType: string
{
    // Declaration order is what the classify dropdown shows: labelSelect() in
    // classify-email.php iterates cases() directly. Grouped by who sends the
    // email - our side first, then the entity's, then legacy values.

    // Sent by us
    case OUR_REQUEST = 'OUR_REQUEST';
    case CLARIFICATION_SENT = 'CLARIFICATION_SENT';
    case COPY_SENT = 'COPY_SENT';

    // Sent by the public entity
    case REQUEST_RECEIPT = 'REQUEST_RECEIPT';
    case ASKING_FOR_MORE_TIME = 'ASKING_FOR_MORE_TIME';
    case ASKING_FOR_COPY = 'ASKING_FOR_COPY';
    case ASKING_FOR_CLARIFICATION = 'ASKING_FOR_CLARIFICATION';
    case RESPONSE_TO_REQUEST = 'RESPONSE_TO_REQUEST';
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

    // Which side of the correspondence a status belongs to. Used to prefix the
    // label so the classify dropdown reads as grouped. UNKNOWN belongs to
    // neither side - it is the absence of a classification.
    public function group(): ?string
    {
        return match ($this) {
            self::OUR_REQUEST,
            self::CLARIFICATION_SENT,
            self::COPY_SENT => 'From us',

            self::REQUEST_RECEIPT,
            self::ASKING_FOR_MORE_TIME,
            self::ASKING_FOR_COPY,
            self::ASKING_FOR_CLARIFICATION,
            self::RESPONSE_TO_REQUEST,
            self::REQUEST_REJECTED,
            self::INFORMATION_RELEASE => 'From entity',

            self::INFO,
            self::ERROR,
            self::SUCCESS => 'Legacy',

            self::UNKNOWN => null,
        };
    }

    // Helper method to get a label for display, prefixed with its group
    public function label(): string
    {
        $group = $this->group();

        return ($group === null ? '' : $group . ': ') . $this->baseLabel();
    }

    private function baseLabel(): string
    {
        return match ($this) {
            self::OUR_REQUEST => 'Our Request',
            self::CLARIFICATION_SENT => 'Clarification Sent',
            self::COPY_SENT => 'Copy Sent',
            self::REQUEST_RECEIPT => 'Receipt of Request',
            self::ASKING_FOR_MORE_TIME => 'Asking for More Time',
            self::ASKING_FOR_COPY => 'Asking for Copy',
            self::ASKING_FOR_CLARIFICATION => 'Asking for Clarification',
            self::RESPONSE_TO_REQUEST => 'Response to Request',
            self::REQUEST_REJECTED => 'Request Rejected',
            self::INFORMATION_RELEASE => 'Information Release',
            self::INFO => 'Info',
            self::ERROR => 'Error',
            self::SUCCESS => 'Success',
            self::UNKNOWN => 'Unknown',
        };
    }

    // Guidance shown in the classify UI: what the status means and whether
    // such emails should typically be marked Ignore. Ignored emails are
    // grayed out in listings and excluded from the NP integration.
    public function description(): string
    {
        return match ($this) {
            self::OUR_REQUEST => 'The request we sent to the entity. Never ignore.',
            self::CLARIFICATION_SENT => 'Our reply clarifying or narrowing the request. Generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::COPY_SENT => 'We sent the requested copy. Administrative — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::REQUEST_RECEIPT => 'The entity confirms it received our request ("Kvittering på mottatt innsynshenvendelse"), often automatically. An acknowledgement, not an answer — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::ASKING_FOR_MORE_TIME => 'The entity says it needs more time before answering. Not an answer — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::ASKING_FOR_COPY => 'The entity asks us to send a copy of something (e.g. earlier correspondence). Administrative back-and-forth — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::ASKING_FOR_CLARIFICATION => 'The entity asks us to clarify or narrow the request (e.g. which journal posts we want). Not a response — generally mark Ignore (hidden from listings and excluded from the NP integration).',
            self::RESPONSE_TO_REQUEST => 'The entity\'s formal reply to the innsynskrav ("Svar på innsynskrav") — a covering or decision letter. May be the whole email, or one attachment alongside the released documents. No general rule on Ignore; decide per email (ignoring hides it from listings and excludes it from the NP integration).',
            self::REQUEST_REJECTED => 'The entity rejected the request. A real response. Never ignore.',
            self::INFORMATION_RELEASE => 'The entity released the requested information or documents. A real response. Never ignore.',
            self::INFO => 'Legacy value. Do not use for new classifications.',
            self::ERROR => 'Legacy value. Do not use for new classifications.',
            self::SUCCESS => 'Legacy value. Do not use for new classifications.',
            self::UNKNOWN => 'Not yet classified.',
        };
    }

    // Helper to get all values for e.g. validation
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
