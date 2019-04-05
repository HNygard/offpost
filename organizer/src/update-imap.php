<?php /** @noinspection ALL */

require_once __DIR__ . '/class/Threads.php';

// sudo apt-get install php5-imap
// + enable imap in PHP

echo '<pre>';
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


$server = '{imap.one.com:993/imap/ssl}';
function openConnection() {
    require_once __DIR__ . '/username-password-imap.php';

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

$mailbox = openConnection();


echo '---- EXISTING FOLDERS ----' . chr(10);
$list = imap_list($mailbox, $server, "*");
$folders = array();
foreach ($list as $folder) {
    echo '-- ' . $folder . chr(10);
    $folders[$folder] = $folder;
}

echo chr(10) . '---- CREATING FOLDERS ----' . chr(10);
$threads = getThreads('/organizer-data/threads/threads-1129-forsand-kommune.json');
$folder_that_should_exist = array('Archive');
foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $title = $entity_threads->title_prefix . ' - ' . $thread->title;
        $folder_that_should_exist[] = $title;
    }
}
foreach ($folder_that_should_exist as $title) {
    echo '-- ' . $title . '        ';
    if (isset($folders[$server . 'INBOX.' . $title])) {
        echo '[OK]' . chr(10);
    }
    elseif (isset($folders[$server . 'INBOX.Archive.' . $title])) {
        echo '[OK]' . chr(10);
    }
    else {
        imap_createmailbox($mailbox, imap_utf7_encode($server . 'INBOX.' . $title));
        checkForImapError();
        echo '[CREATED]' . chr(10);
    }
}

echo chr(10) . '---- ARCHIVING FOLDERS ----' . chr(10);
foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        if ($thread->archived) {
            $title = $entity_threads->title_prefix . ' - ' . $thread->title;
            echo '-- ' . $title . '        ';
            if (isset($folders[$server . 'INBOX.' . $title])) {
                // -> Exists and should be moved
                imap_renamemailbox(
                    $mailbox,
                    imap_utf7_encode($server . 'INBOX.' . $title),
                    imap_utf7_encode($server . 'INBOX.Archive.' . $title)
                );
                echo '[ARCHIVED]' . chr(10);
            }
            elseif (isset($folders[$server . 'INBOX.Archive.' . $title])) {
                echo '[OK]' . chr(10);
            }
            else {
                // -> Don't exist
                echo '[N/A]' . chr(10);
            }
        }
    }
}


$mails = imap_search($mailbox, "ALL", SE_UID);
checkForImapError();
logDebug('E-mails: ' . print_r($mails, true));
if (!$mails) {
    logDebug('No email.');
    exit;
}
$new_emails_saved = false;
foreach ($mails as $mail) {
    logDebug('[' . $mail . '] Begin');
    $mail_headers = imap_headerinfo($mailbox, imap_msgno($mailbox, $mail));
    checkForImapError();
    $subject = $mail_headers->subject;
    $from = $mail_headers->from[0]->mailbox . '@' . $mail_headers->from[0]->host;
    if (!isset($customers[$from])) {
        echo 'UNKNOWN FROM ... : ' . $from . chr(10);
        echo 'SUBJECT: ' . $subject . chr(10);
        echo chr(10);
    }
    else {
        $account_id = $customers[$from];
        $structure = imap_fetchstructure($mailbox, $mail, FT_UID);
        checkForImapError();

        /*
        $attachments = array();
        if(isset($structure->parts) && count($structure->parts)) {

            for($i = 0; $i < count($structure->parts); $i++) {

                $attachments[$i] = array(
                    'is_attachment' => false,
                    'filename' => '',
                    'name' => '',
                    'attachment' => ''
                );

                if($structure->parts[$i]->ifdparameters) {
                    foreach($structure->parts[$i]->dparameters as $object) {
                        if(strtolower($object->attribute) == 'filename') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['filename'] = $object->value;
                        }
                    }
                }

                if($structure->parts[$i]->ifparameters) {
                    foreach($structure->parts[$i]->parameters as $object) {
                        if(strtolower($object->attribute) == 'name') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['name'] = $object->value;
                        }
                    }
                }

                if($attachments[$i]['is_attachment']) {
                    $attachments[$i]['attachment'] = imap_fetchbody($mailbox, $mail, $i+1, FT_UID);
                    checkForImapError();
                    if($structure->parts[$i]->encoding == 3) { // 3 = BASE64
                        $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                    }
                    elseif($structure->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
                        $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                    }
                }
            }
        }

        foreach ($attachments as $key => $attachment) {
            if (!$attachment['is_attachment']) {
                logDebug('Not attachment. '.print_r($attachment, true));
                continue;
            }
            $name = $attachment['name'];
            $contents = $attachment['attachment'];
            $recondo_mail_folder = __DIR__ . '/../../recondo-data/accounts/'.$account_id.'/email-attachments/';
            if (!file_exists($recondo_mail_folder)) {
                mkdir($recondo_mail_folder, 0777, true);
            }
            $path = $recondo_mail_folder.time().'-'.$name;
            logDebug('Saving attachment to ['.$path.']. '.print_r($attachment, true));
            file_put_contents($path, $contents);
        }
        */
        imap_setflag_full($mailbox, $mail, "\\Seen \\Flagged");
        checkForImapError();
        imap_mail_move($mailbox, $mail, "[Gmail]/All Mail", CP_UID);
        checkForImapError();

        $new_emails_saved = true;
    }
}
imap_close($mailbox);

function checkForImapError() {
    $error = imap_last_error();
    if (!empty($error)) {
        throw new Exception('IMAP error: ' . $error);
    }
}
