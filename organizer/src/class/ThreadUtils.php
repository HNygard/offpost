<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
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
        case 'UNKNOWN':
        case null:
            return 'label'; // Default label
        default:
            throw new Exception('Unknown status_type[' . $type . ']: ' . $status_type_input);
    }
}

/**
 * Extract valid reply recipient email addresses from a thread
 * 
 * @param Thread $thread The thread to extract emails from
 * @return array Array of unique valid email addresses
 */
function getThreadReplyRecipients($thread) {
    $recipients = [];
    
    // Add entity email if it exists and is valid
    $entity = $thread->getEntity();
    if ($entity && isset($entity->email) && isValidReplyEmail($entity->email, $thread->my_email)) {
        $recipients[] = $entity->email;
    }
    
    // Extract emails from incoming emails in the thread
    if (isset($thread->emails)) {
        foreach ($thread->emails as $email) {
            if ($email->email_type === 'IN' && isset($email->imap_headers)) {
                $emailAddresses = extractEmailAddressesFromHeaders($email->imap_headers);
                foreach ($emailAddresses as $emailAddr) {
                    if (isValidReplyEmail($emailAddr, $thread->my_email)) {
                        $recipients[] = $emailAddr;
                    }
                }
            }
        }
    }
    
    // Remove duplicates and return
    return array_values(array_unique(array_map('strtolower', $recipients)));
}

/**
 * Extract email addresses from IMAP headers stored as JSON
 * 
 * @param string|array $imapHeaders The IMAP headers as JSON string or array
 * @return array Array of email addresses
 */
function extractEmailAddressesFromHeaders($imapHeaders) {
    $addresses = [];
    
    // Parse JSON if it's a string
    if (is_string($imapHeaders)) {
        $headers = json_decode($imapHeaders, true);
    } else {
        $headers = $imapHeaders;
    }
    
    if (!$headers) {
        return $addresses;
    }
    
    // Extract from various header fields
    $fields = ['from', 'reply_to', 'sender'];
    
    foreach ($fields as $field) {
        if (isset($headers[$field]) && is_array($headers[$field])) {
            foreach ($headers[$field] as $emailObj) {
                if (isset($emailObj['mailbox']) && isset($emailObj['host'])) {
                    $addresses[] = strtolower($emailObj['mailbox'] . '@' . $emailObj['host']);
                }
            }
        }
    }
    
    return array_unique($addresses);
}

/**
 * Check if an email address is valid for replies
 * 
 * @param string $email The email address to check
 * @param string $myEmail The thread's own email address to exclude
 * @return bool True if the email is valid for replies
 */
function isValidReplyEmail($email, $myEmail) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    $email = strtolower(trim($email));
    $myEmail = strtolower(trim($myEmail));
    
    // Exclude our own email
    if ($email === $myEmail) {
        return false;
    }
    
    // Banned email patterns (case-insensitive)
    $bannedPatterns = [
        'noreply',
        'no-reply', 
        'ikke-svar',
        'donotreply',
        'do-not-reply'
    ];
    
    foreach ($bannedPatterns as $pattern) {
        if (strpos($email, $pattern) !== false) {
            return false;
        }
    }
    
    return true;
}
