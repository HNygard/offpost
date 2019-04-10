<?php /** @noinspection ALL */

$server = '{imap.one.com:993/imap/ssl}';

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
$list_subscribed = imap_lsub($mailbox, $server, '*');

asort($list);
$folders = array();
foreach ($list as $folder) {
    echo '-- ' . $folder . chr(10);
    $folders[$folder] = $folder;
}

$folders_subscribed = array();
foreach ($list_subscribed as $folder) {
    $folders_subscribed[$folder] = $folder;
}

echo chr(10) . '---- CREATING FOLDERS ----' . chr(10);
$threads = getThreads('/organizer-data/threads/threads-1129-forsand-kommune.json');
$folder_that_should_exist = array('INBOX.Archive');
foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $title = $entity_threads->title_prefix . ' - ' . $thread->title;
        if ($thread->archived) {
            $folder_that_should_exist[] = 'INBOX.Archive.' . $title;

        }
        else {
            $folder_that_should_exist[] = 'INBOX.' . $title;
        }
    }
}
foreach ($folder_that_should_exist as $title) {
    echo '-- ' . $title . '        ';
    if (str_contains($title, 'INBOX.Archive.')) {
        echo '[ARCHIVED]' . chr(10);
    }
    else {
        if (isset($folders[$server . $title])) {
            echo '[OK]' . chr(10);
        }
        else {
            imap_createmailbox($mailbox, imap_utf7_encode($server . $title));
            checkForImapError();
            echo '[CREATED]' . chr(10);
        }
    }
}

echo chr(10) . '---- ARCHIVING FOLDERS ----' . chr(10);
$email_to_folder = array();
foreach ($threads as $entity_threads) {
    foreach ($entity_threads->threads as $thread) {
        $title = $entity_threads->title_prefix . ' - ' . $thread->title;
        $email_to_folder[$thread->my_email] = $thread->archived
            ? 'INBOX.Archive.' . $title
            : 'INBOX.' . $title;
        if ($thread->archived) {
            echo '-- ' . $title . '        ';
            if (isset($folders[$server . 'INBOX.' . str_replace('INBOX.Archive.', '', $title)])) {
                // -> Exists and should be moved
                imap_renamemailbox(
                    $mailbox,
                    imap_utf7_encode($server . 'INBOX.' . $title),
                    imap_utf7_encode($server . 'INBOX.Archive.' . $title)
                );
                checkForImapError();
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

echo chr(10) . '---- SUBSCRIBE TO FOLDERS ----' . chr(10);
echo '(only listing new subscriptions)' . chr(10);
foreach ($folder_that_should_exist as $title) {
    if (!isset($folders_subscribed[$server . $title])) {
        echo '-- ' . $title . '        ';
        imap_subscribe($mailbox, imap_utf7_encode($server . $title));
        checkForImapError();
        echo '[SUBSCRIBED]' . chr(10);
    }
}

echo chr(10) . '---- SEARCH EMAILS ----' . chr(10);

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

    $to_from = array();
    foreach ($mail_headers->to as $email) {
        $to_from[] = $email->mailbox . '@' . $email->host;
    }
    foreach ($mail_headers->from as $email) {
        $to_from[] = $email->mailbox . '@' . $email->host;
    }
    foreach ($mail_headers->reply_to as $email) {
        $to_from[] = $email->mailbox . '@' . $email->host;
    }
    foreach ($mail_headers->sender as $email) {
        $to_from[] = $email->mailbox . '@' . $email->host;
    }


    $should_be_moved_to = 'INBOX';
    foreach ($to_from as $email) {
        if (isset($email_to_folder[$email])) {
            $should_be_moved_to = $email_to_folder[$email];
        }
    }

    $from = $mail_headers->from[0]->mailbox . '@' . $mail_headers->from[0]->host;

    echo 'FROM ........... : ' . $from . chr(10);
    echo 'SUBJECT ........ : ' . $subject . chr(10);
    echo 'MOVE TO ........ : ' . $should_be_moved_to . chr(10);
    echo chr(10);

    imap_mail_move($mailbox, $mail, $should_be_moved_to, CP_UID);
    checkForImapError();

    if (!isset($customers[$from])) {
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

        /*
        imap_setflag_full($mailbox, $mail, "\\Seen \\Flagged");
        checkForImapError();
        imap_mail_move($mailbox, $mail, "[Gmail]/All Mail", CP_UID);
        checkForImapError();
*/
        $new_emails_saved = true;
    }
}
imap_close($mailbox, CL_EXPUNGE);

function checkForImapError() {
    $error = imap_last_error();
    if (!empty($error)) {
        throw new Exception('IMAP error: ' . $error);
    }
}
