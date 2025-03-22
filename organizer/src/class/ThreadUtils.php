<?php

function getThreadId($thread) {
    $email_folder = str_replace(' ', '_', mb_strtolower($thread->title, 'UTF-8'));
    $email_folder = str_replace('/', '-', $email_folder);
    return $email_folder;
}

function getLabelType($type, $status_type) {
    if ($status_type == 'info') {
        $label_type = 'label';
    }
    elseif ($status_type == 'disabled') {
        $label_type = 'label label_disabled';
    }
    elseif ($status_type == 'danger') {
        $label_type = 'label label_warn';
    }
    elseif ($status_type == 'success') {
        $label_type = 'label label_ok';
    }
    elseif ($status_type == 'unknown' || $status_type == null) {
        $label_type = 'label';
    }
    else {
        throw new Exception('Unknown status_type[' . $type . ']: ' . $status_type);
    }
    return $label_type;
}
