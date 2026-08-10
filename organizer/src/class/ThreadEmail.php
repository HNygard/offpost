<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
use App\Enums\ThreadEmailStatusType;

class ThreadEmail {
    /**
     * Placeholder status_text stamped on every email at ingest time by
     * ThreadEmailDatabaseSaver. Marks an email as still needing
     * classification; cleared once a real status type is chosen.
     */
    const UNCLASSIFIED_STATUS_TEXT = 'Uklassifisert';

    var $timestamp_received;
    var $id;
    var $id_old;
    var $datetime_received;
    var $ignore;
    var $email_type;
    /** @var ThreadEmailStatusType|string */ // Allow string for now for existing data
    var $status_type;
    var $status_text;
    var $description;
    var $answer;
    var $auto_classification;
    /* @var $attachments ThreadEmailAttachment[] */
    var $attachments;
    var $imap_headers; // JSONB field from database

    /**
     * Drop the ingest placeholder once the email has a real status type.
     * The classify form pre-fills the placeholder, so without this it survives
     * a classification and the email keeps reading as unclassified.
     *
     * @param ThreadEmailStatusType|string|null $statusType
     * @param string|null $statusText
     * @return string
     */
    public static function normalizeStatusText($statusType, $statusText) {
        $typeValue = $statusType instanceof ThreadEmailStatusType ? $statusType->value : $statusType;

        if ($statusText === self::UNCLASSIFIED_STATUS_TEXT
            && $typeValue !== ThreadEmailStatusType::UNKNOWN->value) {
            return '';
        }

        return $statusText === null ? '' : $statusText;
    }
}
