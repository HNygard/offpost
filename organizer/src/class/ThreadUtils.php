<?php

use App\Enums\ThreadEmailStatusType;

function getThreadId($thread) {
    $email_folder = str_replace(' ', '_', mb_strtolower($thread->title, 'UTF-8'));
    $email_folder = str_replace('/', '-', $email_folder);
    return $email_folder;
}

function getLabelType($type, $status_type_input) {
    // Handle both enum cases and raw string values from DB
    $status_type_value = $status_type_input instanceof ThreadEmailStatusType ? $status_type_input->value : $status_type_input;

    switch ($status_type_value) {
        case ThreadEmailStatusType::OUR_REQUEST->value:
            return 'label label_our_request'; // Existing style from your diff
        case ThreadEmailStatusType::ASKING_FOR_MORE_TIME->value:
            return 'label label_asking_for_more_time'; // Suggest new style
        case ThreadEmailStatusType::ASKING_FOR_COPY->value:
            return 'label label_asking_for_copy'; // Suggest new style
        case ThreadEmailStatusType::COPY_SENT->value:
            return 'label label_copy_sent'; // Suggest new style
        case ThreadEmailStatusType::REQUEST_REJECTED->value:
            return 'label label_request_rejected label_warn'; // Suggest new style, maybe warn
        case ThreadEmailStatusType::INFORMATION_RELEASE->value:
            return 'label label_information_release label_ok'; // Suggest new style, maybe ok
        
        // Handle old string values that might still be in use or DB
        case ThreadEmailStatusType::INFO->value:
        case 'info': // explicit string check
            return 'label';
        case 'disabled': // Not in enum, but was in old code
            return 'label label_disabled';
        case 'danger': // Not in enum, but was in old code
            return 'label label_warn';
        case ThreadEmailStatusType::SUCCESS->value:
        case 'success': // explicit string check
            return 'label label_ok';
        case ThreadEmailStatusType::UNKNOWN->value:
        case 'unknown': // explicit string check for old 'unknown'
        case null:
            return 'label'; // Default label
        default:
            // It's good practice to log this or handle it gracefully
            // For now, returning a default label to avoid breaking UI
            error_log('Unknown status_type_input in getLabelType: ' . $type . ' / ' . $status_type_input);
            return 'label'; 
            // Or re-throw, but this might break UI if new unmapped statuses appear
            // throw new Exception('Unknown status_type[' . $type . ']: ' . $status_type_value);
    }
}
