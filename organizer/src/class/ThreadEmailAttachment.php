<?php

class ThreadEmailAttachment {
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
}
