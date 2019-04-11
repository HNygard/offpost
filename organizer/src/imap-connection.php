<?php

function exception_error_handler_mail_checker($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

set_error_handler("exception_error_handler_mail_checker");

$debug = true;
function logDebug($text) {
    global $debug;
    if ($debug) {
        echo $text . '<br>' . chr(10);
    }
}


function str_starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) == $needle;
}

function str_ends_with($haystack, $needle) {
    $length = strlen($needle);
    return $length === 0 || substr($haystack, -$length) === $needle;
}

function str_contains($stack, $needle) {
    return (strpos($stack, $needle) !== FALSE);
}

$server = '{imap.one.com:993/imap/ssl}';
function openConnection() {
    require __DIR__ . '/username-password-imap.php';

    global $server;
    try {
        $m = imap_open($server . 'INBOX', $yourEmail, $yourEmailPassword, NULL, 1,
            array('DISABLE_AUTHENTICATOR' => 'PLAIN'));
        checkForImapError();
        return $m;
    }
    catch (Exception $e) {
        checkForImapError();
        throw $e;
    }
}

function checkForImapError() {
    $error = imap_last_error();
    if (!empty($error)) {
        throw new Exception('IMAP error: ' . $error);
    }
}