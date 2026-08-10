<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
use App\Enums\ThreadEmailStatusType;

class ThreadEmailAttachment {
    /**
     * Placeholder status_text stamped on every attachment at ingest time by
     * ThreadEmailDatabaseSaver. Marks an attachment as still needing
     * classification; cleared once a real status type is chosen.
     */
    const UNCLASSIFIED_STATUS_TEXT = 'uklassifisert-dok';

    var $id;
    var $name;
    var $filename;
    var $filetype;
    var $location;
    var $status_type;
    var $status_text;
    var $content;

    public function getIconClass() {
        switch ($this->filetype) {
            case 'image/jpeg':
            case 'image/png':
            case 'image/gif':
                return 'icon-image';
            case 'application/pdf':
                return 'icon-pdf';
            default:
                return '';
        }
    }

    /**
     * Drop the ingest placeholder once the attachment has a real status type.
     * The classify form pre-fills the placeholder, so without this it survives
     * a classification and the attachment keeps reading as unclassified.
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
