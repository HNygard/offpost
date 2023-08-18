<?php

function exception_error_handler_mail_checker($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

set_error_handler("exception_error_handler_mail_checker");

$debug = true;
function logDebug($text) {
    global $debug;
    if ($debug) {
        echo $text . chr(10);
    }
}


$server = '{imap.one.com:993/imap/ssl}';
function openConnection($folder = 'INBOX') {
    require __DIR__ . '/username-password-imap.php';

    global $server;
    try {
        $m = imap_open($server . $folder, $yourEmail, $yourEmailPassword, 0, 1,
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