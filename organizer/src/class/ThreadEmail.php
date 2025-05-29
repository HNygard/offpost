<?php

use App\Enums\ThreadEmailStatusType;

class ThreadEmail {
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
}
