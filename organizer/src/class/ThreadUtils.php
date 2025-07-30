<?php

require_once __DIR__ . '/Enums/ThreadEmailStatusType.php';
require_once __DIR__ . '/Imap/ImapEmail.php';
use App\Enums\ThreadEmailStatusType;
use Imap\ImapEmail;

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
                $emailAddresses = getEmailAddressesFromImapHeaders($email->imap_headers);
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
 * Extract email addresses from IMAP headers stored in database using existing ImapEmail functionality
 * 
 * @param string|array $imapHeaders The IMAP headers as JSON string or array from database
 * @return array Array of email addresses
 */
function getEmailAddressesFromImapHeaders($imapHeaders) {
    // Parse JSON if it's a string
    if (is_string($imapHeaders)) {
        $headers = json_decode($imapHeaders, false); // Use false to get object instead of array
    } else {
        $headers = (object) $imapHeaders;
    }
    
    if (!$headers) {
        return [];
    }
    
    // Create a temporary ImapEmail instance to use its getEmailAddresses method
    $tempEmail = new ImapEmail();
    $tempEmail->mailHeaders = $headers;
    
    return $tempEmail->getEmailAddresses();
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

/**
 * Extract email subject from IMAP headers stored in database
 * 
 * @param string|array $imapHeaders The IMAP headers as JSON string or array from database
 * @return string Email subject or empty string if not found
 */
function getEmailSubjectFromImapHeaders($imapHeaders) {
    // Parse JSON if it's a string
    if (is_string($imapHeaders)) {
        $headers = json_decode($imapHeaders, false); // Use false to get object instead of array
    } else {
        $headers = (object) $imapHeaders;
    }
    
    if (!$headers || !isset($headers->subject)) {
        return '';
    }
    
    return (string) $headers->subject;
}

/**
 * Extract from address from IMAP headers stored in database
 * 
 * @param string|array $imapHeaders The IMAP headers as JSON string or array from database
 * @return string From address or empty string if not found
 */
function getEmailFromAddressFromImapHeaders($imapHeaders) {
    // Parse JSON if it's a string
    if (is_string($imapHeaders)) {
        $headers = json_decode($imapHeaders, false); // Use false to get object instead of array
    } else {
        $headers = (object) $imapHeaders;
    }
    
    if (!$headers || !isset($headers->from) || !is_array($headers->from) || empty($headers->from)) {
        return '';
    }
    
    $from = $headers->from[0];
    if (isset($from->mailbox) && isset($from->host)) {
        return $from->mailbox . '@' . $from->host;
    }
    
    return '';
}

/**
 * Extract to addresses from IMAP headers stored in database
 * 
 * @param string|array $imapHeaders The IMAP headers as JSON string or array from database
 * @return array Array of to addresses
 */
function getEmailToAddressesFromImapHeaders($imapHeaders) {
    // Parse JSON if it's a string
    if (is_string($imapHeaders)) {
        $headers = json_decode($imapHeaders, false); // Use false to get object instead of array
    } else {
        $headers = (object) $imapHeaders;
    }
    
    if (!$headers || !isset($headers->to) || !is_array($headers->to)) {
        return [];
    }
    
    $addresses = [];
    foreach ($headers->to as $email) {
        if (isset($email->mailbox) && isset($email->host)) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
    }
    
    return $addresses;
}

/**
 * Extract CC addresses from IMAP headers stored in database
 * 
 * @param string|array $imapHeaders The IMAP headers as JSON string or array from database
 * @return array Array of CC addresses
 */
function getEmailCcAddressesFromImapHeaders($imapHeaders) {
    // Parse JSON if it's a string
    if (is_string($imapHeaders)) {
        $headers = json_decode($imapHeaders, false); // Use false to get object instead of array
    } else {
        $headers = (object) $imapHeaders;
    }
    
    if (!$headers || !isset($headers->cc) || !is_array($headers->cc)) {
        return [];
    }
    
    $addresses = [];
    foreach ($headers->cc as $email) {
        if (isset($email->mailbox) && isset($email->host)) {
            $addresses[] = $email->mailbox . '@' . $email->host;
        }
    }
    
    return $addresses;
}
